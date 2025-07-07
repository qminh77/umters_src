<?php
// Trang chơi game dành cho người chơi
session_start();
include 'kahoot_db.php';

// Kiểm tra mã game
$gameCode = isset($_GET['code']) ? $_GET['code'] : '';

if (empty($gameCode)) {
    header('Location: index.php');
    exit;
}

// Lấy thông tin phiên chơi
$gameSession = $kahootDB->getGameSessionByCode($gameCode);

if (!$gameSession) {
    $error = "Mã trò chơi không hợp lệ hoặc đã kết thúc";
} else {
    // Lấy thông tin quiz
    $quiz = $kahootDB->getQuizById($gameSession['quiz_id']);
    
    // Kiểm tra trạng thái phiên chơi
    if ($gameSession['status'] === 'completed') {
        $error = "Trò chơi này đã kết thúc";
    }
}

// Xử lý tham gia game
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'join_game') {
    $nickname = $_POST['nickname'] ?? '';
    
    if (empty($nickname)) {
        $joinError = "Vui lòng nhập biệt danh";
    } else {
        $playerResult = $kahootDB->addPlayerToGame($gameSession['id'], $nickname, isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null);
        
        if (isset($playerResult['error'])) {
            $joinError = $playerResult['error'];
        } else {
            // Lưu thông tin người chơi vào session
            $_SESSION['player_session_id'] = $playerResult['player_session_id'];
            $_SESSION['game_session_id'] = $gameSession['id'];
            $_SESSION['player_nickname'] = $nickname;
            
            // Chuyển hướng đến trang chơi game
            header('Location: kahoot_play.php?code=' . $gameCode . '&joined=1');
            exit;
        }
    }
}

// Kiểm tra xem người chơi đã tham gia chưa
$playerJoined = isset($_GET['joined']) && $_GET['joined'] === '1' && isset($_SESSION['player_session_id']) && isset($_SESSION['game_session_id']) && $_SESSION['game_session_id'] === $gameSession['id'];

// Lấy câu hỏi hiện tại nếu trò chơi đã bắt đầu
$currentQuestion = null;
if ($gameSession['status'] === 'active' && $gameSession['current_question']) {
    $questions = $kahootDB->getQuizQuestions($gameSession['quiz_id']);
    foreach ($questions as $question) {
        if ($question['id'] == $gameSession['current_question']) {
            $currentQuestion = $question;
            $currentQuestion['answers'] = $kahootDB->getQuestionAnswers($question['id']);
            break;
        }
    }
    
    // Kiểm tra xem người chơi đã trả lời câu hỏi này chưa
    if ($playerJoined && $currentQuestion) {
        $playerAnswer = $kahootDB->getPlayerAnswerForQuestion($_SESSION['player_session_id'], $currentQuestion['id']);
        if ($playerAnswer) {
            $currentQuestion['answered'] = true;
            $currentQuestion['is_correct'] = $playerAnswer['is_correct'];
            $currentQuestion['points_earned'] = $playerAnswer['points_earned'];
            $currentQuestion['selected_answer_id'] = $playerAnswer['answer_id'];
        } else {
            $currentQuestion['answered'] = false;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($quiz) ? htmlspecialchars($quiz['title']) : 'Tham gia trò chơi'; ?></title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #46178f;
            --secondary-color: #7b1fa2;
            --accent-color: #ff3355;
            --success-color: #26890c;
            --danger-color: #e21b3c;
            --info-color: #1368ce;
            --warning-color: #ff9500;
            --light-color: #f8f9fa;
            --dark-color: #333333;
            --border-radius: 10px;
            --box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            --transition: all 0.3s ease;
        }
        
        body {
            font-family: 'Montserrat', sans-serif;
            background-color: #f0f2f5;
            color: var(--dark-color);
            padding: 20px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
            flex: 1;
        }
        
        .card {
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            border: none;
            margin-bottom: 20px;
            overflow: hidden;
        }
        
        .card-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            font-weight: 600;
            padding: 15px 20px;
            border-bottom: none;
        }
        
        .card-body {
            padding: 25px;
        }
        
        .nickname-form {
            max-width: 400px;
            margin: 0 auto;
        }
        
        .form-control {
            border-radius: 30px;
            padding: 12px 20px;
            height: auto;
            border: 2px solid #e9ecef;
            transition: var(--transition);
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(70, 23, 143, 0.25);
        }
        
        .btn {
            border-radius: 30px;
            padding: 12px 25px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: var(--transition);
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border: none;
        }
        
        .waiting-screen {
            text-align: center;
            padding: 40px 0;
        }
        
        .waiting-screen h3 {
            margin-top: 20px;
            font-weight: 600;
        }
        
        .waiting-screen p {
            color: #6c757d;
            margin-top: 10px;
        }
        
        .spinner-border {
            width: 3rem;
            height: 3rem;
        }
        
        .answer-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin: 20px 0;
        }
        
        .answer-box {
            padding: 25px 20px;
            border-radius: var(--border-radius);
            color: white;
            font-weight: 600;
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
            box-shadow: var(--box-shadow);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100px;
            position: relative;
            overflow: hidden;
        }
        
        .answer-box:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.15);
        }
        
        .answer-box.red {
            background-color: var(--danger-color);
        }
        
        .answer-box.blue {
            background-color: var(--info-color);
        }
        
        .answer-box.green {
            background-color: var(--success-color);
        }
        
        .answer-box.yellow {
            background-color: var(--warning-color);
        }
        
        .answer-box.disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
            box-shadow: var(--box-shadow);
        }
        
        .answer-box.selected {
            border: 3px solid white;
            box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.5);
        }
        
        .timer-bar {
            height: 10px;
            background-color: #e9ecef;
            border-radius: 5px;
            margin-bottom: 20px;
            overflow: hidden;
            box-shadow: inset 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .timer-progress {
            height: 100%;
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
            width: 100%;
            border-radius: 5px;
        }
        
        .result-screen {
            text-align: center;
            padding: 30px 0;
        }
        
        .result-icon {
            font-size: 5rem;
            margin-bottom: 20px;
        }
        
        .result-icon.correct {
            color: var(--success-color);
        }
        
        .result-icon.incorrect {
            color: var(--danger-color);
        }
        
        .points-badge {
            display: inline-block;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            font-size: 1.5rem;
            padding: 8px 20px;
            border-radius: 30px;
            margin-top: 15px;
            box-shadow: var(--box-shadow);
        }
        
        /* True/False specific styling */
        .true-false-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin: 20px 0;
        }
        
        .true-box, .false-box {
            background-color: var(--success-color);
            color: white;
            padding: 30px;
            border-radius: var(--border-radius);
            text-align: center;
            font-size: 1.5rem;
            font-weight: 700;
            cursor: pointer;
            box-shadow: var(--box-shadow);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .true-box:active, .false-box:active {
            transform: scale(0.98);
        }

        .true-box::after, .false-box::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 5px;
            height: 5px;
            background: rgba(255, 255, 255, 0.5);
            opacity: 0;
            border-radius: 100%;
            transform: scale(1, 1) translate(-50%);
            transform-origin: 50% 50%;
        }

        .true-box:active::after, .false-box:active::after {
            animation: ripple 1s ease-out;
        }

        @keyframes ripple {
            0% {
                transform: scale(0, 0);
                opacity: 0.5;
            }
            20% {
                transform: scale(25, 25);
                opacity: 0.3;
            }
            100% {
                opacity: 0;
                transform: scale(40, 40);
            }
        }
        
        .false-box {
            background-color: var(--danger-color);
            color: white;
            padding: 30px;
            border-radius: var(--border-radius);
            text-align: center;
            font-size: 1.5rem;
            font-weight: 700;
            cursor: pointer;
            box-shadow: var(--box-shadow);
            transition: var(--transition);
        }
        
        .true-box:hover, .false-box:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.15);
        }
        
        .true-box.disabled, .false-box.disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }
        
        .true-box.selected, .false-box.selected {
            border: 3px solid white;
            box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.5);
        }
        
        /* Animations */
        @keyframes pulse {
            0% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
            }
            100% {
                transform: scale(1);
            }
        }
        
        .pulse {
            animation: pulse 2s infinite;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease-out forwards;
        }
        
        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% {
                transform: translateY(0);
            }
            40% {
                transform: translateY(-20px);
            }
            60% {
                transform: translateY(-10px);
            }
        }
        
        .bounce {
            animation: bounce 1s;
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .answer-grid, .true-false-grid {
                grid-template-columns: 1fr;
            }
            
            .card-body {
                padding: 20px;
            }
            
            .btn {
                padding: 10px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if (isset($error)): ?>
            <div class="card fade-in">
                <div class="card-header">
                    <h4 class="mb-0">Lỗi</h4>
                </div>
                <div class="card-body text-center">
                    <div class="alert alert-danger">
                        <?php echo $error; ?>
                    </div>
                    <a href="index.php" class="btn btn-primary">
                        <i class="fas fa-home"></i> Quay lại trang chủ
                    </a>
                </div>
            </div>
        <?php elseif (!$playerJoined): ?>
            <!-- Màn hình nhập biệt danh -->
            <div class="card fade-in">
                <div class="card-header">
                    <h4 class="mb-0">Tham gia trò chơi</h4>
                </div>
                <div class="card-body">
                    <div class="text-center mb-4">
                        <h2><?php echo htmlspecialchars($quiz['title']); ?></h2>
                        <?php if (!empty($quiz['description'])): ?>
                            <p class="text-muted"><?php echo htmlspecialchars($quiz['description']); ?></p>
                        <?php endif; ?>
                    </div>
                    
                    <div class="nickname-form">
                        <?php if (isset($joinError)): ?>
                            <div class="alert alert-danger">
                                <?php echo $joinError; ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="join_game">
                            <div class="form-group">
                                <label for="nickname">Nhập biệt danh của bạn</label>
                                <input type="text" class="form-control form-control-lg" id="nickname" name="nickname" maxlength="15" required autofocus>
                            </div>
                            <button type="submit" class="btn btn-primary btn-lg btn-block">
                                <i class="fas fa-play"></i> Vào chơi
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        <?php elseif ($gameSession['status'] === 'waiting'): ?>
            <!-- Màn hình chờ -->
            <div class="card fade-in">
                <div class="card-header">
                    <h4 class="mb-0">Đang chờ bắt đầu</h4>
                </div>
                <div class="card-body">
                    <div class="waiting-screen">
                        <div class="spinner-border text-primary mb-3" role="status">
                            <span class="sr-only">Đang chờ...</span>
                        </div>
                        <h3>Xin chào, <?php echo htmlspecialchars($_SESSION['player_nickname']); ?>!</h3>
                        <p class="lead">Bạn đã tham gia thành công.</p>
                        <p>Vui lòng chờ host bắt đầu trò chơi...</p>
                    </div>
                </div>
            </div>
        <?php elseif ($gameSession['status'] === 'active'): ?>
            <?php if ($currentQuestion): ?>
                <?php if (!$currentQuestion['answered']): ?>
                    <!-- Màn hình câu hỏi -->
                    <div class="card fade-in">
                        <div class="card-header">
                            <h4 class="mb-0">Câu hỏi</h4>
                        </div>
                        <div class="card-body">
                            <div class="timer-bar">
                                <div class="timer-progress" id="timerProgress"></div>
                            </div>
                            
                            <h3 class="text-center mb-4"><?php echo htmlspecialchars($currentQuestion['question_text']); ?></h3>
                            
                            <?php if ($currentQuestion['question_type'] === 'true_false'): ?>
                                <!-- Hiển thị câu hỏi đúng/sai -->
                                <div class="true-false-grid">
                                    <?php 
                                    $trueAnswer = null;
                                    $falseAnswer = null;
                                    foreach ($currentQuestion['answers'] as $answer) {
                                        if (strtolower(trim($answer['answer_text'])) === 'true' || strtolower(trim($answer['answer_text'])) === 'đúng') {
                                            $trueAnswer = $answer;
                                        } elseif (strtolower(trim($answer['answer_text'])) === 'false' || strtolower(trim($answer['answer_text'])) === 'sai') {
                                            $falseAnswer = $answer;
                                        }
                                    }
                                    ?>
                                    <div class="true-box" data-id="<?php echo $trueAnswer ? $trueAnswer['id'] : ''; ?>">
                                        <span>ĐÚNG</span>
                                    </div>
                                    <div class="false-box" data-id="<?php echo $falseAnswer ? $falseAnswer['id'] : ''; ?>">
                                        <span>SAI</span>
                                    </div>
                                </div>
                                
                                <!-- Debug info - Xóa sau khi sửa xong -->
                                <div class="d-none">
                                    <?php foreach ($currentQuestion['answers'] as $answer): ?>
                                        <div>ID: <?php echo $answer['id']; ?>, Text: <?php echo htmlspecialchars($answer['answer_text']); ?></div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <!-- Hiển thị câu hỏi trắc nghiệm thông thường -->
                                <div class="answer-grid">
                                    <?php 
                                    $answerColors = ['red', 'blue', 'green', 'yellow'];
                                    foreach ($currentQuestion['answers'] as $index => $answer): 
                                    ?>
                                        <div class="answer-box <?php echo $answerColors[$index % 4]; ?>" data-id="<?php echo $answer['id']; ?>">
                                            <?php echo htmlspecialchars($answer['answer_text']); ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="text-center mt-4">
                                <p>Thời gian: <span id="timeLeft"><?php echo $currentQuestion['time_limit']; ?></span> giây</p>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Màn hình kết quả câu trả lời -->
                    <div class="card fade-in">
                        <div class="card-header">
                            <h4 class="mb-0">Kết quả</h4>
                        </div>
                        <div class="card-body">
                            <div class="result-screen">
                                <?php if ($currentQuestion['is_correct']): ?>
                                    <div class="result-icon correct bounce">
                                        <i class="fas fa-check-circle"></i>
                                    </div>
                                    <h3>Chính xác!</h3>
                                    <p>Câu trả lời của bạn đúng</p>
                                    <div class="points-badge pulse">
                                        +<?php echo $currentQuestion['points_earned']; ?> điểm
                                    </div>
                                <?php else: ?>
                                    <div class="result-icon incorrect bounce">
                                        <i class="fas fa-times-circle"></i>
                                    </div>
                                    <h3>Không chính xác</h3>
                                    <p>Rất tiếc, câu trả lời của bạn không đúng</p>
                                    <div class="points-badge">
                                        +0 điểm
                                    </div>
                                <?php endif; ?>
                                
                                <div class="mt-4">
                                    <p>Vui lòng chờ câu hỏi tiếp theo...</p>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <!-- Màn hình chờ câu hỏi tiếp theo -->
                <div class="card fade-in">
                    <div class="card-header">
                        <h4 class="mb-0">Đang chờ</h4>
                    </div>
                    <div class="card-body">
                        <div class="waiting-screen">
                            <div class="spinner-border text-primary mb-3" role="status">
                                <span class="sr-only">Đang chờ...</span>
                            </div>
                            <h3>Chờ câu hỏi tiếp theo</h3>
                            <p>Host đang chuẩn bị câu hỏi tiếp theo...</p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script>
    $(document).ready(function() {
        <?php if ($playerJoined && $gameSession['status'] === 'waiting'): ?>
            // Kiểm tra trạng thái trò chơi mỗi 2 giây
            var gameCheckInterval = setInterval(function() {
                $.ajax({
                    url: 'kahoot_ajax.php',
                    type: 'GET',
                    data: {
                        action: 'check_game_status',
                        game_id: <?php echo $gameSession['id']; ?>
                    },
                    dataType: 'json',
                    success: function(data) {
                        if (data.status === 'active') {
                            // Tải lại trang khi trò chơi bắt đầu
                            clearInterval(gameCheckInterval);
                            location.reload();
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error("AJAX Error: " + status + " - " + error);
                    }
                });
            }, 2000);
        <?php endif; ?>
        
        <?php if ($playerJoined && $gameSession['status'] === 'active' && $currentQuestion && !$currentQuestion['answered']): ?>
            // Biến toàn cục
            var questionId = <?php echo $currentQuestion['id']; ?>;
            var timeLimit = <?php echo $currentQuestion['time_limit']; ?>;
            var timeLeft = timeLimit;
            var timerWidth = 100;
            var timerStep = 100 / timeLimit;
            var startTime = new Date().getTime();
            var answered = false;
            var questionType = "<?php echo $currentQuestion['question_type']; ?>";
            
            // Bắt đầu đếm ngược
            var timer = setInterval(function() {
                timeLeft--;
                timerWidth -= timerStep;
                
                $('#timerProgress').css('width', timerWidth + '%');
                $('#timeLeft').text(timeLeft);
                
                if (timeLeft <= 0) {
                    clearInterval(timer);
                    
                    // Tự động tải lại trang sau khi hết thời gian
                    if (!answered) {
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    }
                }
            }, 1000);
            
            // Xử lý khi chọn đáp án (cho cả câu hỏi trắc nghiệm và đúng/sai)
            $('.answer-box, .true-box, .false-box').click(function() {
                if (!answered && $(this).data('id')) {
                    answered = true;
                    
                    // Hiệu ứng khi chọn
                    $(this).addClass('selected');
                    
                    // Vô hiệu hóa các đáp án
                    $('.answer-box, .true-box, .false-box').addClass('disabled');
                    
                    // Tính thời gian trả lời
                    var endTime = new Date().getTime();
                    var responseTime = endTime - startTime;
                    
                    // Gửi câu trả lời lên server
                    submitAnswer($(this).data('id'), responseTime);
                }
            });
            
            // Hàm gửi câu trả lời
            function submitAnswer(answerId, responseTime) {
                $.ajax({
                    url: 'kahoot_ajax.php',
                    type: 'POST',
                    data: {
                        action: 'submit_answer',
                        player_session_id: <?php echo $_SESSION['player_session_id']; ?>,
                        question_id: questionId,
                        answer_id: answerId,
                        response_time: responseTime
                    },
                    dataType: 'json',
                    success: function(data) {
                        // Tải lại trang để hiển thị kết quả
                        setTimeout(function() {
                            location.reload();
                        }, 500);
                    },
                    error: function(xhr, status, error) {
                        console.error("AJAX Error: " + status + " - " + error);
                        // Tải lại trang ngay cả khi có lỗi
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    }
                });
            }
            
            // Debug cho câu hỏi đúng/sai
            if (questionType === 'true_false') {
                console.log('True/False question detected');
                console.log('True box ID:', $('.true-box').data('id'));
                console.log('False box ID:', $('.false-box').data('id'));
                
                // Kiểm tra nếu không có ID
                if (!$('.true-box').data('id') || !$('.false-box').data('id')) {
                    console.warn('Missing data-id for true/false buttons');
                }
            }
            
            // Kiểm tra xem câu hỏi có thay đổi không
            var questionCheckInterval = setInterval(function() {
                if (!answered) {
                    $.ajax({
                        url: 'kahoot_ajax.php',
                        type: 'GET',
                        data: {
                            action: 'check_current_question',
                            game_id: <?php echo $gameSession['id']; ?>,
                            current_question_id: questionId
                        },
                        dataType: 'json',
                        success: function(data) {
                            if (data.changed) {
                                // Tải lại trang khi câu hỏi thay đổi
                                clearInterval(questionCheckInterval);
                                location.reload();
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error("AJAX Error: " + status + " - " + error);
                        }
                    });
                }
            }, 2000);
        <?php elseif ($playerJoined && $gameSession['status'] === 'active' && ($currentQuestion === null || $currentQuestion['answered'])): ?>
            // Kiểm tra câu hỏi mới mỗi 2 giây
            var questionCheckInterval = setInterval(function() {
                $.ajax({
                    url: 'kahoot_ajax.php',
                    type: 'GET',
                    data: {
                        action: 'check_current_question',
                        game_id: <?php echo $gameSession['id']; ?>,
                        current_question_id: <?php echo $currentQuestion ? $currentQuestion['id'] : 0; ?>
                    },
                    dataType: 'json',
                    success: function(data) {
                        if (data.changed) {
                            // Tải lại trang khi có câu hỏi mới
                            clearInterval(questionCheckInterval);
                            location.reload();
                        } else if (data.game_ended) {
                            // Chuyển đến trang kết quả khi trò chơi kết thúc
                            clearInterval(questionCheckInterval);
                            location.href = 'kahoot_results.php?id=<?php echo $gameSession['id']; ?>';
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error("AJAX Error: " + status + " - " + error);
                    }
                });
            }, 2000);
        <?php endif; ?>
    });
</script>
</body>
</html>
