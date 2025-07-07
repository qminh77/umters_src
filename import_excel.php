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

// Xử lý nhập file Excel
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['import_excel'])) {
    $target_quiz_id = (int)$_POST['target_quiz_id'];
    $is_multiple_choice = isset($_POST['is_multiple_choice']) ? 1 : 0;
    
    // Kiểm tra quyền sở hữu bài quizlet
    $sql_check_owner = "SELECT id FROM quiz_sets WHERE id = $target_quiz_id AND user_id = $user_id";
    $result_check_owner = mysqli_query($conn, $sql_check_owner);
    
    if (mysqli_num_rows($result_check_owner) == 0) {
        $error_message = "Bạn không có quyền chỉnh sửa bài quizlet này!";
    } else if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] == 0) {
        $file = $_FILES['excel_file']['tmp_name'];
        $file_type = $_FILES['excel_file']['type'];
        
        // Kiểm tra định dạng file
        $allowed_types = [
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        ];
        
        if (!in_array($file_type, $allowed_types)) {
            $error_message = "Vui lòng chọn file Excel hợp lệ (.xls hoặc .xlsx)!";
        } else {
            // Tạo thư mục tạm nếu chưa có
            $temp_dir = 'temp/';
            if (!file_exists($temp_dir)) {
                mkdir($temp_dir, 0755, true);
            }
            
            // Di chuyển file vào thư mục tạm
            $temp_file = $temp_dir . uniqid() . '_' . basename($_FILES['excel_file']['name']);
            move_uploaded_file($file, $temp_file);
            
            // Đọc file Excel
            require 'vendor/autoload.php';
            
            try {
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($temp_file);
                $worksheet = $spreadsheet->getActiveSheet();
                $rows = $worksheet->toArray();
                
                // Bỏ qua hàng tiêu đề nếu có
                $start_row = isset($_POST['has_header']) && $_POST['has_header'] == 1 ? 1 : 0;
                
                $imported_count = 0;
                $errors = [];
                
                for ($i = $start_row; $i < count($rows); $i++) {
                    $row = $rows[$i];
                    
                    // Kiểm tra dữ liệu
                    if (empty($row[0]) || empty($row[1]) || empty($row[2]) || empty($row[3]) || empty($row[4])) {
                        $errors[] = "Dòng " . ($i + 1) . ": Thiếu dữ liệu";
                        continue;
                    }
                    
                    $question = mysqli_real_escape_string($conn, $row[0]);
                    $option1 = mysqli_real_escape_string($conn, $row[1]);
                    $option2 = mysqli_real_escape_string($conn, $row[2]);
                    $option3 = mysqli_real_escape_string($conn, $row[3]);
                    $option4 = mysqli_real_escape_string($conn, $row[4]);
                    
                    if ($is_multiple_choice) {
                        // Xử lý nhiều đáp án đúng
                        $correct_answers = [];
                        for ($j = 1; $j <= 4; $j++) {
                            if (isset($row[4 + $j]) && strtolower(trim($row[4 + $j])) == 'x') {
                                $correct_answers[] = $j;
                            }
                        }
                        $correct_answers_str = !empty($correct_answers) ? implode(',', $correct_answers) : null;
                        
                        $sql = "INSERT INTO quiz_questions (
                            quiz_id, question, option1, option2, option3, option4,
                            is_multiple_choice, correct_answers, explanation
                        ) VALUES (
                            $target_quiz_id, '$question', '$option1', '$option2', '$option3', '$option4',
                            1, " . ($correct_answers_str ? "'$correct_answers_str'" : "NULL") . ",
                            " . (isset($row[9]) ? "'" . mysqli_real_escape_string($conn, $row[9]) . "'" : "NULL") . "
                        )";
                    } else {
                        // Xử lý một đáp án đúng
                        $correct_answer = isset($row[5]) ? (int)$row[5] : 1;
                        if ($correct_answer < 1 || $correct_answer > 4) {
                            $correct_answer = 1;
                        }
                        
                        $sql = "INSERT INTO quiz_questions (
                            quiz_id, question, option1, option2, option3, option4,
                            correct_answer, explanation
                        ) VALUES (
                            $target_quiz_id, '$question', '$option1', '$option2', '$option3', '$option4',
                            $correct_answer,
                            " . (isset($row[6]) ? "'" . mysqli_real_escape_string($conn, $row[6]) . "'" : "NULL") . "
                        )";
                    }
                    
                    if (mysqli_query($conn, $sql)) {
                        $imported_count++;
                    } else {
                        $errors[] = "Dòng " . ($i + 1) . ": " . mysqli_error($conn);
                    }
                }
                
                // Xóa file tạm
                unlink($temp_file);
                
                if ($imported_count > 0) {
                    $success_message = "Đã nhập thành công $imported_count câu hỏi!";
                    if (!empty($errors)) {
                        $error_message = "Có một số lỗi xảy ra: " . implode(", ", $errors);
                    }
                } else {
                    $error_message = "Không có câu hỏi nào được nhập. " . implode(", ", $errors);
                }
            } catch (Exception $e) {
                $error_message = "Lỗi khi đọc file Excel: " . $e->getMessage();
            }
        }
    } else {
        $error_message = "Vui lòng chọn file Excel hợp lệ!";
    }
}

// Lấy danh sách bộ câu hỏi của người dùng
$sql_quizzes = "SELECT id, name FROM quiz_sets WHERE user_id = $user_id ORDER BY name ASC";
$result_quizzes = mysqli_query($conn, $sql_quizzes);
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nhập câu hỏi từ Excel</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .import-form {
            max-width: 800px;
            margin: 2rem auto;
            padding: 2rem;
            background: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-color);
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: var(--small-radius);
            font-size: 1rem;
            transition: border-color 0.2s ease;
        }
        
        .form-control:focus {
            border-color: var(--primary-gradient-start);
            outline: none;
        }
        
        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
        }
        
        .file-input-wrapper {
            position: relative;
            overflow: hidden;
            display: inline-block;
        }
        
        .file-input {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            background: var(--primary-gradient-start);
            color: white;
            border-radius: var(--small-radius);
            cursor: pointer;
            transition: background-color 0.2s ease;
        }
        
        .file-input:hover {
            background: var(--primary-gradient-end);
        }
        
        .file-input input[type="file"] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            cursor: pointer;
        }
        
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 2rem;
        }
        
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--small-radius);
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .btn-primary {
            background: var(--primary-gradient-start);
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--primary-gradient-end);
        }
        
        .btn-secondary {
            background: var(--secondary-gradient-start);
            color: white;
        }
        
        .btn-secondary:hover {
            background: var(--secondary-gradient-end);
        }
        
        .alert {
            padding: 1rem;
            border-radius: var(--small-radius);
            margin-bottom: 1rem;
        }
        
        .alert-success {
            background: var(--success-color);
            color: white;
        }
        
        .alert-error {
            background: var(--error-color);
            color: white;
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

        <div class="import-form">
            <h2 class="page-title"><i class="fas fa-file-excel"></i> Nhập câu hỏi từ Excel</h2>
            
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="import_excel" value="1">
                
                <div class="form-group">
                    <label for="target_quiz_id">Chọn bài Quizlet</label>
                    <select id="target_quiz_id" name="target_quiz_id" class="form-control" required>
                        <option value="">-- Chọn bài quizlet --</option>
                        <?php while ($quiz = mysqli_fetch_assoc($result_quizzes)): ?>
                            <option value="<?php echo $quiz['id']; ?>">
                                <?php echo htmlspecialchars($quiz['name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="is_multiple_choice" value="1">
                        Cho phép chọn nhiều đáp án
                    </label>
                </div>
                
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="has_header" value="1" checked>
                        File có hàng tiêu đề
                    </label>
                </div>
                
                <div class="form-group">
                    <label>Chọn file Excel</label>
                    <div class="file-input-wrapper">
                        <span class="file-input">
                            Chọn file Excel (.xls, .xlsx)
                            <input type="file" name="excel_file" accept=".xls,.xlsx" required>
                        </span>
                    </div>
                </div>
                
                <div class="form-group">
                    <h3>Hướng dẫn định dạng file Excel:</h3>
                    <p>Đối với câu hỏi một đáp án:</p>
                    <ul>
                        <li>Cột 1: Câu hỏi</li>
                        <li>Cột 2-5: Các phương án A, B, C, D</li>
                        <li>Cột 6: Đáp án đúng (1-4)</li>
                        <li>Cột 7: Giải thích (tùy chọn)</li>
                    </ul>
                    
                    <p>Đối với câu hỏi nhiều đáp án:</p>
                    <ul>
                        <li>Cột 1: Câu hỏi</li>
                        <li>Cột 2-5: Các phương án A, B, C, D</li>
                        <li>Cột 6-9: Đánh dấu X vào các đáp án đúng</li>
                        <li>Cột 10: Giải thích (tùy chọn)</li>
                    </ul>
                </div>
                
                <div class="form-actions">
                    <a href="quizlet.php" class="btn btn-secondary">Quay lại</a>
                    <button type="submit" class="btn btn-primary">Nhập câu hỏi</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html> 