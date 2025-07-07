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
$deck = null;
$media_code = null;
$target_field = 'front'; // Default target field for media insertion

// Kiểm tra ID bộ flashcard
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: flashcards.php");
    exit;
}

$deck_id = (int)$_GET['id'];

// Lấy thông tin bộ flashcard
$sql_deck = "SELECT * FROM flashcard_decks WHERE id = $deck_id AND user_id = $user_id";
$result_deck = mysqli_query($conn, $sql_deck);

if (mysqli_num_rows($result_deck) == 0) {
    header("Location: flashcards.php");
    exit;
}

$deck = mysqli_fetch_assoc($result_deck);

// Lấy danh sách flashcards trong bộ
$sql_cards = "SELECT * FROM flashcards WHERE deck_id = $deck_id ORDER BY id ASC";
$result_cards = mysqli_query($conn, $sql_cards);

// Xử lý cập nhật thông tin bộ flashcard
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_deck'])) {
    $deck_name = mysqli_real_escape_string($conn, $_POST['deck_name']);
    $deck_description = mysqli_real_escape_string($conn, $_POST['deck_description']);
    
    $sql = "UPDATE flashcard_decks SET name = '$deck_name', description = '$deck_description' WHERE id = $deck_id AND user_id = $user_id";
    
    if (mysqli_query($conn, $sql)) {
        $success_message = "Thông tin bộ flashcard đã được cập nhật!";
        $deck['name'] = $deck_name;
        $deck['description'] = $deck_description;
    } else {
        $error_message = "Lỗi: " . mysqli_error($conn);
    }
}

// Thêm xử lý UTF-8 cho form thêm/sửa flashcard
// Xử lý thêm flashcard mới
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_card'])) {
  $front = mysqli_real_escape_string($conn, $_POST['front']);
  $back = mysqli_real_escape_string($conn, $_POST['back']);
  
  $sql = "INSERT INTO flashcards (deck_id, front, back, created_at) VALUES ($deck_id, '$front', '$back', NOW())";
  
  if (mysqli_query($conn, $sql)) {
      $card_id = mysqli_insert_id($conn);
      
      // Thêm vào bảng tiến trình học tập
      $sql_progress = "INSERT INTO flashcard_progress (user_id, flashcard_id, deck_id, status, next_review_date) 
                       VALUES ($user_id, $card_id, $deck_id, 'new', CURDATE())";
      mysqli_query($conn, $sql_progress);
      
      $success_message = "Flashcard đã được thêm thành công!";
      
      // Refresh danh sách flashcards
      $result_cards = mysqli_query($conn, $sql_cards);
  } else {
      $error_message = "Lỗi: " . mysqli_error($conn);
  }
}

// Xử lý xóa flashcard
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_card'])) {
    $card_id = (int)$_POST['card_id'];
    
    // Xóa tiến trình học tập liên quan
    mysqli_query($conn, "DELETE FROM flashcard_progress WHERE flashcard_id = $card_id AND user_id = $user_id");
    
    // Xóa lịch sử học tập liên quan
    mysqli_query($conn, "DELETE FROM flashcard_study_history WHERE flashcard_id = $card_id AND user_id = $user_id");
    
    // Xóa flashcard
    $sql = "DELETE FROM flashcards WHERE id = $card_id AND deck_id = $deck_id";
    
    if (mysqli_query($conn, $sql)) {
        $success_message = "Flashcard đã được xóa thành công!";
        
        // Refresh danh sách flashcards
        $result_cards = mysqli_query($conn, $sql_cards);
    } else {
        $error_message = "Lỗi: " . mysqli_error($conn);
    }
}

// Xử lý cập nhật flashcard
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_card'])) {
  $card_id = (int)$_POST['card_id'];
  $front = mysqli_real_escape_string($conn, $_POST['front']);
  $back = mysqli_real_escape_string($conn, $_POST['back']);
  
  $sql = "UPDATE flashcards SET front = '$front', back = '$back' WHERE id = $card_id AND deck_id = $deck_id";
  
  if (mysqli_query($conn, $sql)) {
      $success_message = "Flashcard đã được cập nhật thành công!";
      
      // Refresh danh sách flashcards
      $result_cards = mysqli_query($conn, $sql_cards);
  } else {
      $error_message = "Lỗi: " . mysqli_error($conn);
  }
}

// Xử lý nhập flashcards từ CSV
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['import_csv'])) {
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
        $file = $_FILES['csv_file']['tmp_name'];
        $handle = fopen($file, "r");
        
        if ($handle !== FALSE) {
            $count = 0;
            
            // Bỏ qua dòng tiêu đề nếu có
            if (isset($_POST['has_header']) && $_POST['has_header'] == 1) {
                fgetcsv($handle, 1000, ",");
            }
            
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if (count($data) >= 2) {
                    $front = mysqli_real_escape_string($conn, $data[0]);
                    $back = mysqli_real_escape_string($conn, $data[1]);
                    
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
            
            // Refresh danh sách flashcards
            $result_cards = mysqli_query($conn, $sql_cards);
        } else {
            $error_message = "Không thể đọc file CSV!";
        }
    } else {
        $error_message = "Vui lòng chọn file CSV hợp lệ!";
    }
}

// For handling media uploads - Fixed to use AJAX
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_media'])) {
    if (isset($_POST['target_field'])) {
        $target_field = $_POST['target_field'];
    }
    
    if (isset($_FILES['media_file']) && $_FILES['media_file']['error'] == 0) {
        $upload_result = upload_media_file($_FILES['media_file']);
        
        if ($upload_result) {
            $media_type = $_FILES['media_file']['type'];
            
            if (strpos($media_type, 'image') !== false) {
                $media_code = "[img:{$upload_result}]";
                echo json_encode(['success' => true, 'media_code' => $media_code, 'target_field' => $target_field]);
                exit;
            } elseif (strpos($media_type, 'video') !== false) {
                $media_code = "[video:{$upload_result}]";
                echo json_encode(['success' => true, 'media_code' => $media_code, 'target_field' => $target_field]);
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

// For handling YouTube links - Fixed to use AJAX
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_youtube'])) {
    if (isset($_POST['target_field'])) {
        $target_field = $_POST['target_field'];
    }
    
    if (isset($_POST['youtube_url']) && !empty($_POST['youtube_url'])) {
        $youtube_url = $_POST['youtube_url'];
        
        // Extract video ID if full URL was provided
        if (preg_match('/^https?:\/\/(www\.)?(youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $youtube_url, $matches)) {
            $video_id = $matches[3];
        } else {
            $video_id = $youtube_url;
        }
        
        // Validate YouTube video ID (should be 11 characters)
        if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $video_id)) {
            $media_code = "[youtube:{$video_id}]";
            echo json_encode(['success' => true, 'media_code' => $media_code, 'target_field' => $target_field]);
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
    <title>Chỉnh sửa bộ Flashcard - <?php echo htmlspecialchars($deck['name']); ?></title>
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

        .card {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            padding: var(--padding);
            margin-bottom: 2rem;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .card-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-title i {
            color: var(--primary-gradient-start);
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
            padding: 0.4rem 0.8rem;
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

        .flashcard-list {
            margin-top: 1.5rem;
        }

        .flashcard-item {
            background: var(--form-bg);
            border-radius: var(--small-radius);
            padding: 1.5rem;
            margin-bottom: 1rem;
            position: relative;
            transition: transform var(--transition-speed) ease, box-shadow var(--transition-speed) ease;
        }

        .flashcard-item:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-sm);
        }

        .flashcard-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        .flashcard-side {
            background: white;
            border-radius: var(--small-radius);
            padding: 1rem;
            border: 1px solid rgba(0, 0, 0, 0.1);
            min-height: 100px;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            overflow-y: auto;
            max-height: 300px;
        }

        .flashcard-side h4 {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
            font-weight: 500;
            align-self: flex-start;
        }

        /* Cập nhật CSS cho media trong flashcard-side */
        .flashcard-side .flashcard-media {
            margin: 0.5rem auto;
            max-width: 100%;
            display: block;
        }

        .flashcard-side .flashcard-image img {
            max-height: 150px;
            margin: 0 auto;
        }

        .flashcard-side .flashcard-video video {
            max-height: 150px;
        }

        .flashcard-side .flashcard-youtube {
            max-height: 150px;
        }

        .flashcard-actions {
            position: absolute;
            top: 1rem;
            right: 1rem;
            display: flex;
            gap: 0.5rem;
        }

        .flashcard-btn {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all var(--transition-speed) ease;
            color: var(--text-secondary);
            background: white;
            border: 1px solid rgba(0, 0, 0, 0.1);
        }

        .flashcard-btn:hover {
            color: var(--text-color);
            background: var(--hover-bg);
            transform: translateY(-2px);
        }

        .flashcard-btn.edit:hover {
            color: var(--info-color);
        }

        .flashcard-btn.delete:hover {
            color: var(--error-color);
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
            max-width: 600px;
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

        .import-options {
            margin-top: 1rem;
            padding: 1rem;
            background: var(--form-bg);
            border-radius: var(--small-radius);
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
            border: 2px dashed rgba(116, 235, 213, 0.5);
            border-radius: var(--small-radius);
            font-size: 1rem;
            color: var(--text-color);
            transition: all var(--transition-speed) ease;
            background-color: rgba(116, 235, 213, 0.05);
            text-align: center;
            cursor: pointer;
        }

        .file-input:hover {
            border-color: var(--primary-gradient-start);
            background-color: rgba(116, 235, 213, 0.1);
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

        /* Media styles for flashcards */
        .flashcard-media {
            margin: 1rem 0;
            border-radius: 0.5rem;
            overflow: hidden;
            max-width: 100%;
            position: relative;
        }

        .flashcard-image {
            text-align: center;
            background-color: rgba(0, 0, 0, 0.03);
            min-height: 100px;
        }

        .flashcard-image img {
            max-width: 100%;
            height: auto;
            display: block;
            margin: 0 auto;
            object-fit: contain;
        }

        .flashcard-video {
            position: relative;
            min-height: 150px;
            background-color: rgba(0, 0, 0, 0.03);
        }

        .flashcard-video video {
            width: 100%;
            max-height: 300px;
            object-fit: contain;
        }

        .flashcard-youtube {
            position: relative;
            padding-bottom: 56.25%; /* 16:9 aspect ratio */
            height: 0;
            overflow: hidden;
            min-height: 150px;
            background-color: rgba(0, 0, 0, 0.03);
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
            background-color: rgba(0, 0, 0, 0.1);
            color: var(--text-color);
            font-size: 1.5rem;
        }

        .loaded .media-loading {
            display: none;
        }

        .media-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .target-field-selector {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
            gap: 1rem;
        }

        .target-field-selector label {
            font-weight: 500;
            margin-bottom: 0;
        }

        .target-field-selector .radio-group {
            display: flex;
            gap: 1rem;
        }

        .target-field-selector .radio-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        /* Text-to-speech button styles */
        .tts-container {
            margin-top: 0.5rem;
            text-align: right;
        }

        .tts-button {
            background: var(--form-bg);
            color: var(--text-color);
            border: 1px solid rgba(0, 0, 0, 0.1);
            border-radius: var(--small-radius);
            padding: 0.3rem 0.6rem;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all var(--transition-speed) ease;
            position: relative;
            z-index: 10;
        }

        .tts-button:hover {
            background: var(--hover-bg);
            transform: translateY(-2px);
        }

        /* Language selector for TTS */
        .tts-language-selector {
            position: absolute;
            bottom: 100%;
            right: 0;
            background: white;
            border: 1px solid rgba(0, 0, 0, 0.1);
            border-radius: var(--small-radius);
            padding: 0.5rem;
            box-shadow: var(--shadow-sm);
            z-index: 20;
            min-width: 150px;
            display: none;
        }

        .tts-language-selector.active {
            display: block;
        }

        .tts-language-option {
            padding: 0.3rem 0.5rem;
            cursor: pointer;
            border-radius: 0.25rem;
        }

        .tts-language-option:hover {
            background: var(--hover-bg);
        }

        .tts-language-option.selected {
            background: var(--primary-gradient-start);
            color: white;
        }
        
        /* Toast notification */
        .toast {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: var(--card-bg);
            color: var(--text-color);
            padding: 1rem;
            border-radius: var(--small-radius);
            box-shadow: var(--shadow);
            z-index: 1100;
            display: none;
            animation: fadeIn 0.3s ease;
        }
        
        .toast.success {
            border-left: 4px solid var(--success-color);
        }
        
        .toast.error {
            border-left: 4px solid var(--error-color);
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

            .flashcard-content {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
            }

            .form-actions .btn {
                width: 100%;
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

            .flashcard-actions {
                position: static;
                justify-content: flex-end;
                margin-top: 1rem;
            }

            .modal-content {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div class="logo">
                <i class="fas fa-layer-group"></i>
                <span>FlashMaster</span>
            </div>
            <div class="user-menu">
                <a href="dashboard.php"><i class="fas fa-home"></i> Trang chủ</a>
                <a href="flashcards.php"><i class="fas fa-layer-group"></i> Bộ thẻ</a>
                <a href="logout.php" class="btn"><i class="fas fa-sign-out-alt"></i> Đăng xuất</a>
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
                <h2 class="card-title"><i class="fas fa-edit"></i> Chỉnh sửa bộ Flashcard</h2>
                <div>
                    <button class="btn btn-secondary btn-sm" onclick="openModal('import-modal')">
                        <i class="fas fa-file-import"></i> Nhập từ CSV
                    </button>
                    <a href="study.php?deck_id=<?php echo $deck_id; ?>" class="btn btn-primary btn-sm">
                        <i class="fas fa-play"></i> Học ngay
                    </a>
                </div>
            </div>

            <div class="tabs">
                <div class="tab active" onclick="switchTab('deck-info')">Thông tin bộ thẻ</div>
                <div class="tab" onclick="switchTab('flashcards')">Danh sách thẻ</div>
                <div class="tab" onclick="switchTab('add-card')">Thêm thẻ mới</div>
            </div>

            <div id="deck-info" class="tab-content active">
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="deck_name">Tên bộ flashcard</label>
                        <input type="text" id="deck_name" name="deck_name" class="form-control" value="<?php echo htmlspecialchars($deck['name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="deck_description">Mô tả</label>
                        <textarea id="deck_description" name="deck_description" class="form-control"><?php echo htmlspecialchars($deck['description']); ?></textarea>
                    </div>
                    <div class="form-actions">
                        <a href="flashcards.php" class="btn btn-secondary">Quay lại</a>
                        <button type="submit" name="update_deck" class="btn btn-primary">Cập nhật thông tin</button>
                    </div>
                </form>
            </div>

            <div id="flashcards" class="tab-content">
                <?php if (mysqli_num_rows($result_cards) > 0): ?>
                    <div class="flashcard-list">
                        <?php while ($card = mysqli_fetch_assoc($result_cards)): ?>
                            <div class="flashcard-item" id="card-<?php echo $card['id']; ?>">
                                <div class="flashcard-content">
                                    <?php
                                        $front_content = process_media_content($card['front']);
                                        $back_content = process_media_content($card['back']);
                                    ?>
                                    <div class="flashcard-side">
                                        <h4>Mặt trước</h4>
                                        <div><?php echo $front_content; ?></div>
                                    </div>
                                    <div class="flashcard-side">
                                        <h4>Mặt sau</h4>
                                        <div><?php echo $back_content; ?></div>
                                    </div>
                                </div>
                                <div class="flashcard-actions">
                                    <button class="flashcard-btn edit" 
                                            data-card-id="<?php echo $card['id']; ?>" 
                                            data-front="<?php echo htmlspecialchars($card['front']); ?>" 
                                            data-back="<?php echo htmlspecialchars($card['back']); ?>"
                                            onclick="editCardFromButton(this)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="flashcard-btn delete" onclick="confirmDeleteCard(<?php echo $card['id']; ?>)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-layer-group"></i>
                        <p>Bộ flashcard này chưa có thẻ nào. Hãy thêm thẻ đầu tiên!</p>
                        <button class="btn btn-primary" onclick="switchTab('add-card')">
                            <i class="fas fa-plus"></i> Thêm thẻ mới
                        </button>
                    </div>
                <?php endif; ?>
            </div>

            <div id="add-card" class="tab-content">
                <div class="media-toolbar">
                    <button class="btn btn-secondary btn-sm" onclick="openModal('upload-media-modal')">
                        <i class="fas fa-image"></i> Thêm hình ảnh/video
                    </button>
                    <button class="btn btn-secondary btn-sm" onclick="openModal('youtube-modal')">
                        <i class="fab fa-youtube"></i> Thêm YouTube
                    </button>
                </div>
                
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="front">Mặt trước</label>
                        <textarea id="front" name="front" class="form-control" required></textarea>
                    </div>
                    <div class="form-group">
                        <label for="back">Mặt sau</label>
                        <textarea id="back" name="back" class="form-control" required></textarea>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="clearForm()">Xóa trắng</button>
                        <button type="submit" name="add_card" class="btn btn-primary">Thêm thẻ</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal chỉnh sửa flashcard -->
    <div id="edit-card-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Chỉnh sửa Flashcard</h3>
                <button class="modal-close" onclick="closeModal('edit-card-modal')">&times;</button>
            </div>
            <form method="POST" action="">
                <input type="hidden" id="edit_card_id" name="card_id">
                <div class="form-group">
                    <label for="edit_front">Mặt trước</label>
                    <textarea id="edit_front" name="front" class="form-control" required></textarea>
                </div>
                <div class="form-group">
                    <label for="edit_back">Mặt sau</label>
                    <textarea id="edit_back" name="back" class="form-control" required></textarea>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('edit-card-modal')">Hủy</button>
                    <button type="submit" name="update_card" class="btn btn-primary">Cập nhật</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal xác nhận xóa -->
    <div id="delete-card-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Xác nhận xóa</h3>
                <button class="modal-close" onclick="closeModal('delete-card-modal')">&times;</button>
            </div>
            <p>Bạn có chắc chắn muốn xóa flashcard này?</p>
            <form method="POST" action="">
                <input type="hidden" id="delete_card_id" name="card_id">
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('delete-card-modal')">Hủy</button>
                    <button type="submit" name="delete_card" class="btn btn-danger">Xóa vĩnh viễn</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal nhập từ CSV -->
    <div id="import-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Nhập Flashcards từ CSV</h3>
                <button class="modal-close" onclick="closeModal('import-modal')">&times;</button>
            </div>
            <form method="POST" action="" enctype="multipart/form-data">
                <p>Tải lên file CSV với định dạng: cột 1 = mặt trước, cột 2 = mặt sau</p>
                
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
                    
                    <p><small>Lưu ý: Các flashcard đã tồn tại sẽ không bị ghi đè.</small></p>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('import-modal')">Hủy</button>
                    <button type="submit" name="import_csv" class="btn btn-primary">Nhập dữ liệu</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal upload media -->
    <div id="upload-media-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Tải lên hình ảnh/video</h3>
                <button class="modal-close" onclick="closeModal('upload-media-modal')">&times;</button>
            </div>
            <form id="upload-media-form" enctype="multipart/form-data">
                <p>Tải lên hình ảnh hoặc video để chèn vào flashcard:</p>
                
                <div class="target-field-selector">
                    <label>Chèn vào:</label>
                    <div class="radio-group">
                        <div class="radio-item">
                            <input type="radio" id="target_front" name="target_field" value="front" checked>
                            <label for="target_front">Mặt trước</label>
                        </div>
                        <div class="radio-item">
                            <input type="radio" id="target_back" name="target_field" value="back">
                            <label for="target_back">Mặt sau</label>
                        </div>
                    </div>
                </div>
            
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

    <!-- Modal YouTube -->
    <div id="youtube-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Thêm video YouTube</h3>
                <button class="modal-close" onclick="closeModal('youtube-modal')">&times;</button>
            </div>
            <form id="youtube-form">
                <p>Nhập URL hoặc ID video YouTube:</p>
                
                <div class="target-field-selector">
                    <label>Chèn vào:</label>
                    <div class="radio-group">
                        <div class="radio-item">
                            <input type="radio" id="yt_target_front" name="target_field" value="front" checked>
                            <label for="yt_target_front">Mặt trước</label>
                        </div>
                        <div class="radio-item">
                            <input type="radio" id="yt_target_back" name="target_field" value="back">
                            <label for="yt_target_back">Mặt sau</label>
                        </div>
                    </div>
                </div>
                
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
    
    <!-- Toast notification -->
    <div id="toast" class="toast"></div>

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
        
        // Xóa trắng form
        function clearForm() {
            document.getElementById('front').value = '';
            document.getElementById('back').value = '';
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
        
        // Chỉnh sửa flashcard
        function editCard(cardId, front, back) {
            document.getElementById('edit_card_id').value = cardId;
            document.getElementById('edit_front').value = front;
            document.getElementById('edit_back').value = back;
            openModal('edit-card-modal');
        }

        // Function to edit card from button with data attributes
        function editCardFromButton(button) {
            const cardId = button.getAttribute('data-card-id');
            const front = button.getAttribute('data-front');
            const back = button.getAttribute('data-back');
            
            document.getElementById('edit_card_id').value = cardId;
            document.getElementById('edit_front').value = front;
            document.getElementById('edit_back').value = back;
            openModal('edit-card-modal');
        }
        
        // Xác nhận xóa flashcard
        function confirmDeleteCard(cardId) {
            document.getElementById('delete_card_id').value = cardId;
            openModal('delete-card-modal');
        }
        
        // Hiển thị thông báo toast
        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = 'toast ' + type;
            toast.style.display = 'block';
            
            setTimeout(() => {
                toast.style.display = 'none';
            }, 3000);
        }
        
        // Chèn mã media vào textarea
        function insertMediaCode(mediaCode, targetField) {
            const textarea = document.getElementById(targetField);
            
            if (textarea) {
                const startPos = textarea.selectionStart;
                const endPos = textarea.selectionEnd;
                textarea.value = textarea.value.substring(0, startPos) + mediaCode + textarea.value.substring(endPos);
                showToast('Đã chèn media thành công!');
            }
        }
        
        // Biến lưu trữ ngôn ngữ đã chọn
        let selectedLanguage = 'vi-VN';
        let availableVoices = [];
        
        // Text-to-speech function
        function speakText(button, event) {
            // Ngăn sự kiện click lan truyền lên các phần tử cha
            if (event) {
                event.stopPropagation();
            }
            
            const text = button.getAttribute('data-text');
            
            if ('speechSynthesis' in window) {
                // Kiểm tra xem có menu ngôn ngữ đang mở không
                const existingMenu = document.querySelector('.tts-language-selector.active');
                if (existingMenu) {
                    existingMenu.remove();
                    return;
                }
                
                // Tạo menu chọn ngôn ngữ
                const languageSelector = document.createElement('div');
                languageSelector.className = 'tts-language-selector active';
                
                // Thêm các tùy chọn ngôn ngữ phổ biến
                const commonLanguages = [
                    { code: 'vi-VN', name: 'Tiếng Việt' },
                    { code: 'en-US', name: 'English (US)' },
                    { code: 'en-GB', name: 'English (UK)' },
                    { code: 'fr-FR', name: 'Français' },
                    { code: 'de-DE', name: 'Deutsch' },
                    { code: 'ja-JP', name: 'Japanese' },
                    { code: 'ko-KR', name: 'Korean' },
                    { code: 'zh-CN', name: 'Chinese' },
                    { code: 'ru-RU', name: 'Russian' },
                    { code: 'es-ES', name: 'Spanish' }
                ];
                
                commonLanguages.forEach(lang => {
                    const option = document.createElement('div');
                    option.className = 'tts-language-option';
                    if (lang.code === selectedLanguage) {
                        option.classList.add('selected');
                    }
                    option.textContent = lang.name;
                    option.setAttribute('data-lang', lang.code);
                    option.addEventListener('click', function(e) {
                        e.stopPropagation();
                        selectedLanguage = this.getAttribute('data-lang');
                        
                        // Phát âm với ngôn ngữ đã chọn
                        const utterance = new SpeechSynthesisUtterance(text);
                        utterance.lang = selectedLanguage;
                        
                        // Tìm giọng phù hợp với ngôn ngữ đã chọn
                        const voice = availableVoices.find(v => v.lang.includes(selectedLanguage.split('-')[0]));
                        if (voice) {
                            utterance.voice = voice;
                        }
                        
                        window.speechSynthesis.speak(utterance);
                        
                        // Đóng menu
                        languageSelector.remove();
                    });
                    languageSelector.appendChild(option);
                });
                
                // Thêm menu vào nút
                button.appendChild(languageSelector);
                
                // Đóng menu khi click bên ngoài
                document.addEventListener('click', function closeMenu() {
                    languageSelector.remove();
                    document.removeEventListener('click', closeMenu);
                });
            } else {
                showToast('Trình duyệt của bạn không hỗ trợ Text-to-Speech', 'error');
            }
        }
        
        // Upload media using AJAX
        document.getElementById('upload-media-form').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('upload_media', '1');
            
            fetch('edit_deck.php?id=<?php echo $deck_id; ?>', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    insertMediaCode(data.media_code, data.target_field);
                    closeModal('upload-media-modal');
                    
                    // Reset form
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
        
        // Add YouTube using AJAX
        document.getElementById('youtube-form').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('add_youtube', '1');
            
            fetch('edit_deck.php?id=<?php echo $deck_id; ?>', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    insertMediaCode(data.media_code, data.target_field);
                    closeModal('youtube-modal');
                    
                    // Reset form
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
        
        // Initialize speech synthesis
        window.speechSynthesis.onvoiceschanged = function() {
            // Lưu danh sách giọng đọc
            availableVoices = window.speechSynthesis.getVoices();
        };
        
        // Gọi ngay để lấy giọng đọc nếu đã có sẵn
        if ('speechSynthesis' in window) {
            availableVoices = window.speechSynthesis.getVoices();
        }
    </script>
<script>
    // Thêm đoạn mã JavaScript để xử lý hiển thị media
    document.addEventListener('DOMContentLoaded', function() {
        // Xử lý hiển thị ảnh
        const images = document.querySelectorAll('.flashcard-image img');
        images.forEach(img => {
            img.onload = function() {
                this.parentNode.classList.add('loaded');
            };
            // Nếu ảnh đã được tải trước đó
            if (img.complete) {
                img.parentNode.classList.add('loaded');
            }
        });
        
        // Xử lý hiển thị video
        const videos = document.querySelectorAll('.flashcard-video video');
        videos.forEach(video => {
            video.oncanplay = function() {
                this.parentNode.classList.add('loaded');
            };
            // Nếu video đã được tải trước đó
            if (video.readyState >= 3) {
                video.parentNode.classList.add('loaded');
            }
        });
        
        // Xử lý hiển thị YouTube
        const iframes = document.querySelectorAll('.flashcard-youtube iframe');
        iframes.forEach(iframe => {
            iframe.onload = function() {
                this.parentNode.classList.add('loaded');
            };
        });
    });
</script>
</body>
</html>
