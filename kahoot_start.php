<?php
// Trang bắt đầu trò chơi mới
session_start();
include 'kahoot_db.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=kahoot_start.php');
    exit;
}

$userId = $_SESSION['user_id'];
$username = $_SESSION['username'];

// Kiểm tra id quiz
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: kahoot_game.php');
    exit;
}

$quizId = (int)$_GET['id'];
$quiz = $kahootDB->getQuizById($quizId);

// Kiểm tra quyền sở hữu quiz
if (!$quiz || $quiz['user_id'] != $userId) {
    header('Location: kahoot_game.php');
    exit;
}

// Lấy danh sách câu hỏi
$questions = $kahootDB->getQuizQuestions($quizId);

// Kiểm tra xem quiz có câu hỏi không
if (empty($questions)) {
    $error = "Quiz này chưa có câu hỏi nào. Vui lòng thêm câu hỏi trước khi bắt đầu trò chơi.";
} else {
    // Tạo phiên chơi mới
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_game') {
        $result = $kahootDB->createGameSession($quizId, $userId);
        
        if ($result) {
            // Chuyển đến trang host
            header('Location: kahoot_host.php?id=' . $result['session_id']);
            exit;
        } else {
            $error = "Có lỗi xảy ra khi tạo phiên chơi mới";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bắt đầu trò chơi - <?php echo htmlspecialchars($quiz['title']); ?></title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.1/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            padding-top: 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        .card {
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .card-header {
            background-color: #007bff;
            color: white;
            border-radius: 10px 10px 0 0;
        }
        .question-list {
            max-height: 400px;
            overflow-y: auto;
        }
        .question-item {
            padding: 10px;
            border-bottom: 1px solid #eee;
        }
        .question-item:last-child {
            border-bottom: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row mb-4">
            <div class="col-md-8">
                <h1><?php echo htmlspecialchars($quiz['title']); ?></h1>
                <p><?php echo htmlspecialchars($quiz['description']); ?></p>
            </div>
            <div class="col-md-4 text-right">
                <a href="kahoot_game.php" class="btn btn-outline-primary">
                    <i class="fas fa-arrow-left"></i> Quay lại
                </a>
            </div>
        </div>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-list mr-2"></i>Danh sách câu hỏi</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="question-list">
                            <?php if (count($questions) > 0): ?>
                                <?php foreach ($questions as $index => $question): ?>
                                    <div class="question-item">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <strong>Câu <?php echo $index + 1; ?>:</strong> 
                                                <?php echo htmlspecialchars($question['question_text']); ?>
                                            </div>
                                            <div>
                                                <span class="badge badge-primary"><?php echo $question['time_limit']; ?>s</span>
                                                <span class="badge badge-success"><?php echo $question['points']; ?> điểm</span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="p-3 text-center text-muted">
                                    Chưa có câu hỏi nào. Vui lòng thêm câu hỏi trước khi bắt đầu trò chơi.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-play-circle mr-2"></i>Bắt đầu trò chơi</h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($questions) > 0): ?>
                            <p>Sẵn sàng bắt đầu trò chơi với <?php echo count($questions); ?> câu hỏi?</p>
                            <form method="POST" action="">
                                <input type="hidden" name="action" value="create_game">
                                <button type="submit" class="btn btn-success btn-block">
                                    <i class="fas fa-play"></i> Bắt đầu ngay
                                </button>
                            </form>
                        <?php else: ?>
                            <p class="text-danger">Bạn cần thêm ít nhất một câu hỏi trước khi bắt đầu trò chơi.</p>
                            <a href="kahoot_edit.php?id=<?php echo $quizId; ?>" class="btn btn-primary btn-block">
                                <i class="fas fa-plus-circle"></i> Thêm câu hỏi
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card mt-3">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-info-circle mr-2"></i>Thông tin</h5>
                    </div>
                    <div class="card-body">
                        <p><strong>Tổng số câu hỏi:</strong> <?php echo count($questions); ?></p>
                        <p><strong>Tổng điểm:</strong> 
                            <?php
                            $totalPoints = 0;
                            foreach ($questions as $question) {
                                $totalPoints += $question['points'];
                            }
                            echo $totalPoints;
                            ?>
                        </p>
                        <p><strong>Thời gian trung bình:</strong> 
                            <?php
                            $totalTime = 0;
                            foreach ($questions as $question) {
                                $totalTime += $question['time_limit'];
                            }
                            echo count($questions) > 0 ? round($totalTime / count($questions), 1) : 0;
                            ?> giây/câu
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>

