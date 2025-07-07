<?php
// Trang tạo và quản lý quiz
session_start();
include 'kahoot_db.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header('Location: kahoot_game.php');
    exit;
}

$userId = $_SESSION['user_id'];
$username = $_SESSION['username'];
$userRole = $_SESSION['role'];

// Kiểm tra quyền tạo quiz
if ($userRole === 'player') {
    header('Location: kahoot_game.php');
    exit;
}

// Khởi tạo biến
$quizId = null;
$quiz = null;
$questions = [];
$editMode = false;
$message = '';
$messageType = '';

// Xử lý chỉnh sửa quiz
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $quizId = (int)$_GET['edit'];
    $quiz = $kahootDB->getQuizById($quizId);
    
    // Kiểm tra quyền sở hữu quiz
    if ($quiz && $quiz['user_id'] == $userId) {
        $editMode = true;
        $questions = $kahootDB->getQuizQuestions($quizId);
        
        // Lấy đáp án cho mỗi câu hỏi
        foreach ($questions as &$question) {
            $question['answers'] = $kahootDB->getQuestionAnswers($question['id']);
        }
    } else {
        header('Location: kahoot_game.php');
        exit;
    }
}

// Xử lý tạo/cập nhật quiz
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    // Tạo quiz mới
    if ($action === 'create_quiz') {
        $title = $_POST['title'] ?? '';
        $description = $_POST['description'] ?? '';
        $isPublic = isset($_POST['is_public']) ? 1 : 0;
        $isDraft = isset($_POST['is_draft']) ? 1 : 0;
        $backgroundColor = $_POST['background_color'] ?? '#ffffff';
        
        // Xử lý upload ảnh bìa
        $coverImage = null;
        if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = 'uploads/quiz_covers/';
            
            // Tạo thư mục nếu chưa tồn tại
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $fileName = uniqid() . '_' . basename($_FILES['cover_image']['name']);
            $uploadFile = $uploadDir . $fileName;
            
            if (move_uploaded_file($_FILES['cover_image']['tmp_name'], $uploadFile)) {
                $coverImage = $uploadFile;
            }
        }
        
        if (empty($title)) {
            $message = 'Vui lòng nhập tiêu đề quiz';
            $messageType = 'danger';
        } else {
            if ($editMode && $quizId) {
                // Cập nhật quiz
                $data = [
                    'title' => $title,
                    'description' => $description,
                    'is_public' => $isPublic,
                    'is_draft' => $isDraft,
                    'background_color' => $backgroundColor
                ];
                
                if ($coverImage) {
                    $data['cover_image'] = $coverImage;
                }
                
                $result = $kahootDB->updateQuiz($quizId, $userId, $data);
                
                if ($result) {
                    $message = 'Quiz đã được cập nhật thành công';
                    $messageType = 'success';
                } else {
                    $message = 'Có lỗi xảy ra khi cập nhật quiz';
                    $messageType = 'danger';
                }
            } else {
                // Tạo quiz mới
                $quizId = $kahootDB->createQuiz($userId, $title, $description, $isPublic, $isDraft, $coverImage, $backgroundColor);
                
                if ($quizId) {
                    $editMode = true;
                    $quiz = $kahootDB->getQuizById($quizId);
                    $message = 'Quiz đã được tạo thành công. Bây giờ bạn có thể thêm câu hỏi.';
                    $messageType = 'success';
                } else {
                    $message = 'Có lỗi xảy ra khi tạo quiz';
                    $messageType = 'danger';
                }
            }
        }
    }
    
    // Thêm câu hỏi
    elseif ($action === 'add_question' && $quizId) {
        $questionText = $_POST['question_text'] ?? '';
        $questionType = $_POST['question_type'] ?? 'multiple_choice';
        $timeLimit = (int)($_POST['time_limit'] ?? 20);
        $points = (int)($_POST['points'] ?? 100);
        
        // Xử lý upload ảnh câu hỏi
        $questionImage = null;
        if (isset($_FILES['question_image']) && $_FILES['question_image']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = 'uploads/question_images/';
            
            // Tạo thư mục nếu chưa tồn tại
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $fileName = uniqid() . '_' . basename($_FILES['question_image']['name']);
            $uploadFile = $uploadDir . $fileName;
            
            if (move_uploaded_file($_FILES['question_image']['tmp_name'], $uploadFile)) {
                $questionImage = $uploadFile;
            }
        }
        
        if (empty($questionText)) {
            $message = 'Vui lòng nhập nội dung câu hỏi';
            $messageType = 'danger';
        } else {
            $questionId = $kahootDB->addQuestion($quizId, $questionText, $questionType, $timeLimit, $points, $questionImage);
            
            if ($questionId) {
                // Thêm các đáp án
                $answerTexts = $_POST['answer_text'] ?? [];
                $isCorrects = $_POST['is_correct'] ?? [];
                $answerColors = $_POST['answer_color'] ?? [];
                
                foreach ($answerTexts as $index => $text) {
                    if (!empty($text)) {
                        $isCorrect = in_array($index, $isCorrects) ? 1 : 0;
                        $color = $answerColors[$index] ?? null;
                        $kahootDB->addAnswer($questionId, $text, $isCorrect, $color);
                    }
                }
                
                $message = 'Câu hỏi đã được thêm thành công';
                $messageType = 'success';
                
                // Cập nhật danh sách câu hỏi
                $questions = $kahootDB->getQuizQuestions($quizId);
                foreach ($questions as &$question) {
                    $question['answers'] = $kahootDB->getQuestionAnswers($question['id']);
                }
            } else {
                $message = 'Có lỗi xảy ra khi thêm câu hỏi';
                $messageType = 'danger';
            }
        }
    }
    
    // Cập nhật câu hỏi
    elseif ($action === 'update_question' && isset($_POST['question_id'])) {
        $questionId = (int)$_POST['question_id'];
        $questionText = $_POST['question_text'] ?? '';
        $questionType = $_POST['question_type'] ?? 'multiple_choice';
        $timeLimit = (int)($_POST['time_limit'] ?? 20);
        $points = (int)($_POST['points'] ?? 100);
        
        // Xử lý upload ảnh câu hỏi
        $questionImage = null;
        if (isset($_FILES['question_image']) && $_FILES['question_image']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = 'uploads/question_images/';
            
            // Tạo thư mục nếu chưa tồn tại
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $fileName = uniqid() . '_' . basename($_FILES['question_image']['name']);
            $uploadFile = $uploadDir . $fileName;
            
            if (move_uploaded_file($_FILES['question_image']['tmp_name'], $uploadFile)) {
                $questionImage = $uploadFile;
            }
        }
        
        if (empty($questionText)) {
            $message = 'Vui lòng nhập nội dung câu hỏi';
            $messageType = 'danger';
        } else {
            $data = [
                'question_text' => $questionText,
                'question_type' => $questionType,
                'time_limit' => $timeLimit,
                'points' => $points
            ];
            
            if ($questionImage) {
                $data['question_image'] = $questionImage;
            }
            
            $result = $kahootDB->updateQuestion($questionId, $quizId, $data);
            
            if ($result) {
                // Xóa các đáp án cũ
                $currentAnswers = $kahootDB->getQuestionAnswers($questionId);
                foreach ($currentAnswers as $answer) {
                    $kahootDB->deleteAnswer($answer['id'], $questionId);
                }
                
                // Thêm các đáp án mới
                $answerTexts = $_POST['answer_text'] ?? [];
                $isCorrects = $_POST['is_correct'] ?? [];
                $answerColors = $_POST['answer_color'] ?? [];
                
                foreach ($answerTexts as $index => $text) {
                    if (!empty($text)) {
                        $isCorrect = in_array($index, $isCorrects) ? 1 : 0;
                        $color = $answerColors[$index] ?? null;
                        $kahootDB->addAnswer($questionId, $text, $isCorrect, $color);
                    }
                }
                
                $message = 'Câu hỏi đã được cập nhật thành công';
                $messageType = 'success';
                
                // Cập nhật danh sách câu hỏi
                $questions = $kahootDB->getQuizQuestions($quizId);
                foreach ($questions as &$question) {
                    $question['answers'] = $kahootDB->getQuestionAnswers($question['id']);
                }
            } else {
                $message = 'Có lỗi xảy ra khi cập nhật câu hỏi';
                $messageType = 'danger';
            }
        }
    }
    
    // Xóa câu hỏi
    elseif ($action === 'delete_question' && isset($_POST['question_id'])) {
        $questionId = (int)$_POST['question_id'];
        
        $result = $kahootDB->deleteQuestion($questionId, $quizId);
        
        if ($result) {
            $message = 'Câu hỏi đã được xóa thành công';
            $messageType = 'success';
            
            // Cập nhật danh sách câu hỏi
            $questions = $kahootDB->getQuizQuestions($quizId);
            foreach ($questions as &$question) {
                $question['answers'] = $kahootDB->getQuestionAnswers($question['id']);
            }
        } else {
            $message = 'Có lỗi xảy ra khi xóa câu hỏi';
            $messageType = 'danger';
        }
    }
    
    // Bắt đầu trò chơi
    elseif ($action === 'start_game' && $quizId) {
        $result = $kahootDB->createGameSession($quizId, $userId);
        
        if ($result) {
            $_SESSION['game_session_id'] = $result['session_id'];
            $_SESSION['game_pin'] = $result['game_pin'];
            header('Location: kahoot_host.php');
            exit;
        } else {
            $message = 'Có lỗi xảy ra khi tạo phiên chơi';
            $messageType = 'danger';
        }
    }
}

// Lấy danh sách quiz của người dùng
$userQuizzes = $kahootDB->getUserQuizzes($userId, 100, 0);
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $editMode ? 'Chỉnh sửa Quiz' : 'Tạo Quiz Mới'; ?> - Kahoot Clone</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-colorpicker@3.2.0/dist/css/bootstrap-colorpicker.min.css">
    <style>
        :root {
            --primary-color: #f2c75c;
            --secondary-color: #46178f;
            --accent-color: #e91e63;
            --success-color: #26890c;
            --danger-color: #e21b3c;
            --info-color: #1368ce;
            --warning-color: #ff9500;
            --light-color: #f8f9fa;
            --dark-color: #333333;
        }
        
        body {
            font-family: 'Montserrat', sans-serif;
            background-color: #f8f9fa;
            color: #333;
        }
        
        .navbar {
            background-color: var(--secondary-color);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .navbar-brand {
            font-weight: bold;
            color: var(--primary-color) !important;
            font-size: 1.5rem;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: var(--secondary-color);
            font-weight: bold;
        }
        
        .btn-primary:hover {
            background-color: #e0b84e;
            border-color: #e0b84e;
            color: var(--secondary-color);
        }
        
        .btn-secondary {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
        }
        
        .btn-secondary:hover {
            background-color: #3a1275;
            border-color: #3a1275;
        }
        
        .card {
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }
        
        .card-header {
            background-color: var(--secondary-color);
            color: white;
            font-weight: bold;
        }
        
        .quiz-list {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .quiz-item {
            padding: 10px;
            border-bottom: 1px solid #eee;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .quiz-item:hover {
            background-color: #f5f5f5;
        }
        
        .quiz-item.active {
            background-color: #e9ecef;
            border-left: 4px solid var(--primary-color);
        }
        
        .question-list {
            margin-top: 20px;
        }
        
        .question-card {
            margin-bottom: 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .question-header {
            padding: 10px 15px;
            background-color: #f5f5f5;
            border-bottom: 1px solid #ddd;
            font-weight: bold;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .question-body {
            padding: 15px;
        }
        
        .question-actions {
            display: flex;
            gap: 5px;
        }
        
        .answer-option {
            margin-bottom: 10px;
            padding: 10px;
            border-radius: 5px;
            position: relative;
        }
        
        .answer-option.correct {
            background-color: rgba(38, 137, 12, 0.1);
            border: 1px solid var(--success-color);
        }
        
        .answer-option.incorrect {
            background-color: rgba(226, 27, 60, 0.1);
            border: 1px solid var(--danger-color);
        }
        
        .answer-color {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 10px;
            vertical-align: middle;
        }
        
        .answer-form-group {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .answer-form-group .form-check {
            margin-right: 10px;
        }
        
        .answer-form-group .colorpicker-component {
            width: 80px;
        }
        
        .preview-image {
            max-width: 100%;
            max-height: 200px;
            margin-top: 10px;
            border-radius: 5px;
        }
        
        .footer {
            background-color: var(--secondary-color);
            color: white;
            padding: 30px 0;
            margin-top: 50px;
        }
        
        .footer a {
            color: var(--primary-color);
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .question-actions {
                flex-direction: column;
            }
        }
        
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .animated {
            animation: fadeIn 0.5s ease-out;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="kahoot_game.php">
                <i class="fas fa-gamepad mr-2"></i>Kahoot Clone
            </a>
            <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ml-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="kahoot_game.php">Trang chủ</a>
                    </li>
                    <li class="nav-item active">
                        <a class="nav-link" href="kahoot_create.php">Tạo Quiz</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="kahoot_reports.php">Báo cáo</a>
                    </li>
                    <?php if ($userRole === 'admin'): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="kahoot_admin.php">Quản trị</a>
                        </li>
                    <?php endif; ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-toggle="dropdown">
                            <i class="fas fa-user-circle mr-1"></i><?php echo htmlspecialchars($username); ?>
                        </a>
                        <div class="dropdown-menu">
                            <a class="dropdown-item" href="kahoot_profile.php">Hồ sơ cá nhân</a>
                            <a class="dropdown-item" href="kahoot_game.php?action=logout">Đăng xuất</a>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="container mt-4">
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                <?php echo $message; ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Quiz List Sidebar -->
            <div class="col-md-3">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-list mr-2"></i>Quiz của bạn
                    </div>
                    <div class="card-body p-0">
                        <div class="quiz-list">
                            <div class="quiz-item <?php echo !$editMode ? 'active' : ''; ?>">
                                <a href="kahoot_create.php" class="d-block text-decoration-none text-dark">
                                    <i class="fas fa-plus-circle mr-2 text-success"></i>Tạo Quiz mới
                                </a>
                            </div>
                            <?php foreach ($userQuizzes as $userQuiz): ?>
                                <div class="quiz-item <?php echo ($editMode && $quizId == $userQuiz['id']) ? 'active' : ''; ?>">
                                    <a href="kahoot_create.php?edit=<?php echo $userQuiz['id']; ?>" class="d-block text-decoration-none text-dark">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <i class="fas fa-file-alt mr-2 text-primary"></i>
                                                <?php echo htmlspecialchars($userQuiz['title']); ?>
                                            </div>
                                            <small class="text-muted"><?php echo $userQuiz['play_count']; ?> lượt chơi</small>
                                        </div>
                                        <small class="text-muted">
                                            <?php echo $userQuiz['is_draft'] ? '<span class="badge badge-warning">Nháp</span>' : ''; ?>
                                            <?php echo $userQuiz['is_public'] ? '<span class="badge badge-success">Công khai</span>' : '<span class="badge badge-secondary">Riêng tư</span>'; ?>
                                        </small>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quiz Editor -->
            <div class="col-md-9">
                <?php if (!$editMode): ?>
                    <!-- Create New Quiz Form -->
                    <div class="card animated">
                        <div class="card-header">
                            <i class="fas fa-plus-circle mr-2"></i>Tạo Quiz mới
                        </div>
                        <div class="card-body">
                            <form method="POST" action="" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="create_quiz">
                                
                                <div class="form-group">
                                    <label for="title">Tiêu đề Quiz <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="title" name="title" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="description">Mô tả</label>
                                    <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                                </div>
                                
                                <div class="form-group">
                                    <label for="cover_image">Ảnh bìa</label>
                                    <input type="file" class="form-control-file" id="cover_image" name="cover_image" accept="image/*">
                                    <small class="form-text text-muted">Kích thước khuyến nghị: 1200x630 pixels</small>
                                </div>
                                
                                <div class="form-group">
                                    <label for="background_color">Màu nền</label>
                                    <div class="input-group colorpicker-component">
                                        <input type="text" class="form-control" id="background_color" name="background_color" value="#ffffff">
                                        <div class="input-group-append">
                                            <span class="input-group-text"><i></i></span>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="is_public" name="is_public">
                                        <label class="form-check-label" for="is_public">
                                            Công khai (Cho phép người khác tìm thấy quiz này)
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="is_draft" name="is_draft" checked>
                                        <label class="form-check-label" for="is_draft">
                                            Lưu dưới dạng nháp
                                        </label>
                                    </div>
                                </div>
                                
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save mr-2"></i>Tạo Quiz
                                </button>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Edit Quiz Form -->
                    <div class="card animated">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <div>
                                <i class="fas fa-edit mr-2"></i>Chỉnh sửa Quiz
                            </div>
                            <div>
                                <form method="POST" action="" class="d-inline">
                                    <input type="hidden" name="action" value="start_game">
                                    <button type="submit" class="btn btn-success btn-sm">
                                        <i class="fas fa-play mr-1"></i>Bắt đầu trò chơi
                                    </button>
                                </form>
                            </div>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="create_quiz">
                                
                                <div class="form-group">
                                    <label for="title">Tiêu đề Quiz <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="title" name="title" value="<?php echo htmlspecialchars($quiz['title']); ?>" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="description">Mô tả</label>
                                    <textarea class="form-control" id="description" name="description" rows="3"><?php echo htmlspecialchars($quiz['description'] ?? ''); ?></textarea>
                                </div>
                                
                                <div class="form-group">
                                    <label for="cover_image">Ảnh bìa</label>
                                    <?php if (!empty($quiz['cover_image'])): ?>
                                        <div class="mb-2">
                                            <img src="<?php echo htmlspecialchars($quiz['cover_image']); ?>" alt="Cover Image" class="preview-image">
                                        </div>
                                    <?php endif; ?>
                                    <input type="file" class="form-control-file" id="cover_image" name="cover_image" accept="image/*">
                                    <small class="form-text text-muted">Kích thước khuyến nghị: 1200x630 pixels</small>
                                </div>
                                
                                <div class="form-group">
                                    <label for="background_color">Màu nền</label>
                                    <div class="input-group colorpicker-component">
                                        <input type="text" class="form-control" id="background_color" name="background_color" value="<?php echo htmlspecialchars($quiz['background_color'] ?? '#ffffff'); ?>">
                                        <div class="input-group-append">
                                            <span class="input-group-text"><i></i></span>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="is_public" name="is_public" <?php echo $quiz['is_public'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="is_public">
                                            Công khai (Cho phép người khác tìm thấy quiz này)
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="is_draft" name="is_draft" <?php echo $quiz['is_draft'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="is_draft">
                                            Lưu dưới dạng nháp
                                        </label>
                                    </div>
                                </div>
                                
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save mr-2"></i>Cập nhật Quiz
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Question List -->
                    <div class="question-list">
                        <h4>Danh sách câu hỏi</h4>
                        
                        <?php if (empty($questions)): ?>
                            <div class="alert alert-info">
                                Chưa có câu hỏi nào. Hãy thêm câu hỏi đầu tiên cho quiz của bạn.
                            </div>
                        <?php else: ?>
                            <?php foreach ($questions as $index => $question): ?>
                                <div class="question-card">
                                    <div class="question-header">
                                        <div>
                                            <span class="badge badge-secondary mr-2"><?php echo $index + 1; ?></span>
                                            <?php echo htmlspecialchars($question['question_text']); ?>
                                        </div>
                                        <div class="question-actions">
                                            <button type="button" class="btn btn-sm btn-info edit-question" data-question-id="<?php echo $question['id']; ?>">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <form method="POST" action="" class="d-inline delete-question-form">
                                                <input type="hidden" name="action" value="delete_question">
                                                <input type="hidden" name="question_id" value="<?php echo $question['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                    <div class="question-body">
                                        <div class="row">
                                            <div class="col-md-8">
                                                <div class="mb-2">
                                                    <strong>Loại câu hỏi:</strong> 
                                                    <?php 
                                                        switch ($question['question_type']) {
                                                            case 'multiple_choice':
                                                                echo 'Trắc nghiệm';
                                                                break;
                                                            case 'true_false':
                                                                echo 'Đúng/Sai';
                                                                break;
                                                            case 'fill_blank':
                                                                echo 'Điền vào chỗ trống';
                                                                break;
                                                            default:
                                                                echo $question['question_type'];
                                                        }
                                                    ?>
                                                </div>
                                                <div class="mb-2">
                                                    <strong>Thời gian:</strong> <?php echo $question['time_limit']; ?> giây
                                                </div>
                                                <div class="mb-3">
                                                    <strong>Điểm:</strong> <?php echo $question['points']; ?> điểm
                                                </div>
                                                
                                                <div class="answer-options">
                                                    <strong>Đáp án:</strong>
                                                    <?php foreach ($question['answers'] as $answer): ?>
                                                        <div class="answer-option <?php echo $answer['is_correct'] ? 'correct' : 'incorrect'; ?>">
                                                            <?php if ($answer['answer_color']): ?>
                                                                <span class="answer-color" style="background-color: <?php echo htmlspecialchars($answer['answer_color']); ?>"></span>
                                                            <?php endif; ?>
                                                            <?php echo htmlspecialchars($answer['answer_text']); ?>
                                                            <?php if ($answer['is_correct']): ?>
                                                                <span class="badge badge-success float-right">Đúng</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <?php if (!empty($question['question_image'])): ?>
                                                    <img src="<?php echo htmlspecialchars($question['question_image']); ?>" alt="Question Image" class="img-fluid preview-image">
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <!-- Add Question Button -->
                        <button type="button" class="btn btn-primary mt-3" data-toggle="modal" data-target="#addQuestionModal">
                            <i class="fas fa-plus-circle mr-2"></i>Thêm câu hỏi
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Add Question Modal -->
    <div class="modal fade" id="addQuestionModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Thêm câu hỏi mới</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <form id="questionForm" method="POST" action="" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="add_question">
                        <input type="hidden" id="question_id" name="question_id" value="">
                        
                        <div class="form-group">
                            <label for="question_text">Nội dung câu hỏi <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="question_text" name="question_text" rows="2" required></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label for="question_type">Loại câu hỏi</label>
                            <select class="form-control" id="question_type" name="question_type">
                                <option value="multiple_choice">Trắc nghiệm</option>
                                <option value="true_false">Đúng/Sai</option>
                                <option value="fill_blank">Điền vào chỗ trống</option>
                            </select>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="time_limit">Thời gian (giây)</label>
                                    <select class="form-control" id="time_limit" name="time_limit">
                                        <option value="5">5 giây</option>
                                        <option value="10">10 giây</option>
                                        <option value="20" selected>20 giây</option>
                                        <option value="30">30 giây</option>
                                        <option value="60">60 giây</option>
                                        <option value="90">90 giây</option>
                                        <option value="120">120 giây</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="points">Điểm</label>
                                    <select class="form-control" id="points" name="points">
                                        <option value="50">50 điểm</option>
                                        <option value="100" selected>100 điểm</option>
                                        <option value="200">200 điểm</option>
                                        <option value="500">500 điểm</option>
                                        <option value="1000">1000 điểm</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="question_image">Hình ảnh (tùy chọn)</label>
                            <input type="file" class="form-control-file" id="question_image" name="question_image" accept="image/*">
                            <div id="image_preview_container" class="mt-2" style="display: none;">
                                <img id="image_preview" src="/placeholder.svg" alt="Preview" class="img-fluid preview-image">
                            </div>
                        </div>
                        
                        <hr>
                        
                        <div id="answers_container">
                            <h5>Đáp án</h5>
                            <div id="true_false_answers" style="display: none;">
                                <div class="answer-form-group">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="is_correct[]" value="0" id="true_answer" checked>
                                        <label class="form-check-label" for="true_answer">Đúng</label>
                                    </div>
                                    <div class="input-group">
                                        <input type="text" class="form-control" name="answer_text[0]" value="Đúng" readonly>
                                        <div class="input-group-append colorpicker-component">
                                            <input type="text" class="form-control" name="answer_color[0]" value="#26890c" style="width: 80px;">
                                            <div class="input-group-text"><i></i></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="answer-form-group">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="is_correct[]" value="1" id="false_answer">
                                        <label class="form-check-label" for="false_answer">Sai</label>
                                    </div>
                                    <div class="input-group">
                                        <input type="text" class="form-control" name="answer_text[1]" value="Sai" readonly>
                                        <div class="input-group-append colorpicker-component">
                                            <input type="text" class="form-control" name="answer_color[1]" value="#e21b3c" style="width: 80px;">
                                            <div class="input-group-text"><i></i></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div id="multiple_choice_answers">
                                <div class="answer-form-group">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="is_correct[]" value="0" id="answer_0">
                                        <label class="form-check-label" for="answer_0">Đáp án 1</label>
                                    </div>
                                    <div class="input-group">
                                        <input type="text" class="form-control" name="answer_text[0]" placeholder="Nhập đáp án">
                                        <div class="input-group-append colorpicker-component">
                                            <input type="text" class="form-control" name="answer_color[0]" value="#e21b3c" style="width: 80px;">
                                            <div class="input-group-text"><i></i></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="answer-form-group">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="is_correct[]" value="1" id="answer_1">
                                        <label class="form-check-label" for="answer_1">Đáp án 2</label>
                                    </div>
                                    <div class="input-group">
                                        <input type="text" class="form-control" name="answer_text[1]" placeholder="Nhập đáp án">
                                        <div class="input-group-append colorpicker-component">
                                            <input type="text" class="form-control" name="answer_color[1]" value="#1368ce" style="width: 80px;">
                                            <div class="input-group-text"><i></i></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="answer-form-group">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="is_correct[]" value="2" id="answer_2">
                                        <label class="form-check-label" for="answer_2">Đáp án 3</label>
                                    </div>
                                    <div class="input-group">
                                        <input type="text" class="form-control" name="answer_text[2]" placeholder="Nhập đáp án">
                                        <div class="input-group-append colorpicker-component">
                                            <input type="text" class="form-control" name="answer_color[2]" value="#26890c" style="width: 80px;">
                                            <div class="input-group-text"><i></i></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="answer-form-group">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="is_correct[]" value="3" id="answer_3">
                                        <label class="form-check-label" for="answer_3">Đáp án 4</label>
                                    </div>
                                    <div class="input-group">
                                        <input type="text" class="form-control" name="answer_text[3]" placeholder="Nhập đáp án">
                                        <div class="input-group-append colorpicker-component">
                                            <input type="text" class="form-control" name="answer_color[3]" value="#ff9500" style="width: 80px;">
                                            <div class="input-group-text"><i></i></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div id="fill_blank_answers" style="display: none;">
                                <div class="answer-form-group">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="is_correct[]" value="0" id="blank_answer" checked disabled>
                                        <label class="form-check-label" for="blank_answer">Đáp án đúng</label>
                                    </div>
                                    <div class="input-group">
                                        <input type="text" class="form-control" name="answer_text[0]" placeholder="Nhập đáp án đúng">
                                    </div>
                                </div>
                                <small class="form-text text-muted">Người chơi sẽ phải nhập chính xác đáp án này.</small>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Hủy</button>
                    <button type="button" class="btn btn-primary" id="saveQuestionBtn">Lưu câu hỏi</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <h4>Kahoot Clone</h4>
                    <p>Nền tảng học tập tương tác dành cho mọi người</p>
                </div>
                <div class="col-md-3">
                    <h5>Liên kết</h5>
                    <ul class="list-unstyled">
                        <li><a href="kahoot_game.php">Trang chủ</a></li>
                        <li><a href="#">Về chúng tôi</a></li>
                        <li><a href="#">Hướng dẫn</a></li>
                        <li><a href="#">Liên hệ</a></li>
                    </ul>
                </div>
                <div class="col-md-3">
                    <h5>Theo dõi</h5>
                    <div class="social-icons">
                        <a href="#" class="mr-2"><i class="fab fa-facebook-f"></i></a>
                        <a href="#" class="mr-2"><i class="fab fa-twitter"></i></a>
                        <a href="#" class="mr-2"><i class="fab fa-instagram"></i></a>
                        <a href="#"><i class="fab fa-youtube"></i></a>
                    </div>
                </div>
            </div>
            <hr class="mt-4 mb-4" style="background-color: rgba(255,255,255,0.1);">
            <p class="text-center mb-0">&copy; <?php echo date('Y'); ?> Kahoot Clone. Đã đăng ký bản quyền.</p>
        </div>
    </footer>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap-colorpicker@3.2.0/dist/js/bootstrap-colorpicker.min.js"></script>
    <script>
        $(document).ready(function() {
            // Khởi tạo colorpicker
            $('.colorpicker-component').colorpicker();
            
            // Xử lý loại câu hỏi
            $('#question_type').change(function() {
                var questionType = $(this).val();
                
                if (questionType === 'multiple_choice') {
                    $('#multiple_choice_answers').show();
                    $('#true_false_answers').hide();
                    $('#fill_blank_answers').hide();
                } else if (questionType === 'true_false') {
                    $('#multiple_choice_answers').hide();
                    $('#true_false_answers').show();
                    $('#fill_blank_answers').hide();
                } else if (questionType === 'fill_blank') {
                    $('#multiple_choice_answers').hide();
                    $('#true_false_answers').hide();
                    $('#fill_blank_answers').show();
                }
            });
            
            // Xử lý xem trước hình ảnh
            $('#question_image').change(function() {
                var file = this.files[0];
                if (file) {
                    var reader = new FileReader();
                    reader.onload = function(e) {
                        $('#image_preview').attr('src', e.target.result);
                        $('#image_preview_container').show();
                    }
                    reader.readAsDataURL(file);
                } else {
                    $('#image_preview_container').hide();
                }
            });
            
            // Xử lý lưu câu hỏi
            $('#saveQuestionBtn').click(function() {
                $('#questionForm').submit();
            });
            
            // Xử lý chỉnh sửa câu hỏi
            $('.edit-question').click(function() {
                var questionId = $(this).data('question-id');
                // Tải dữ liệu câu hỏi bằng AJAX và điền vào form
                $.ajax({
                    url: 'get_question.php',
                    type: 'GET',
                    data: { id: questionId },
                    dataType: 'json',
                    success: function(data) {
                        $('#question_id').val(data.id);
                        $('#question_text').val(data.question_text);
                        $('#question_type').val(data.question_type).trigger('change');
                        $('#time_limit').val(data.time_limit);
                        $('#points').val(data.points);
                        
                        // Điền đáp án
                        if (data.answers) {
                            for (var i = 0; i < data.answers.length; i++) {
                                $('input[name="answer_text[' + i + ']"]').val(data.answers[i].answer_text);
                                $('input[name="answer_color[' + i + ']"]').val(data.answers[i].answer_color);
                                
                                if (data.answers[i].is_correct == 1) {
                                    if (data.question_type === 'true_false') {
                                        $('input[name="is_correct[]"][value="' + i + '"]').prop('checked', true);
                                    } else {
                                        $('#answer_' + i).prop('checked', true);
                                    }
                                }
                            }
                        }
                        
                        // Thay đổi action form
                        $('input[name="action"]').val('update_question');
                        
                        // Hiển thị modal
                        $('#addQuestionModal').modal('show');
                    },
                    error: function() {
                        alert('Không thể tải thông tin câu hỏi');
                    }
                });
            });
            
            // Xác nhận xóa câu hỏi
            $('.delete-question-form').submit(function(e) {
                if (!confirm('Bạn có chắc chắn muốn xóa câu hỏi này?')) {
                    e.preventDefault();
                }
            });
        });
    </script>
</body>
</html>

