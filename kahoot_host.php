<?php
// Trang host trò chơi
session_start();
include 'kahoot_db.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=kahoot_start.php');
    exit;
}

// Kiểm tra id phiên chơi
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: kahoot_start.php');
    exit;
}

$gameSessionId = (int)$_GET['id'];
$gameSession = $kahootDB->getGameSession($gameSessionId);

// Kiểm tra quyền host
if (!$gameSession || $gameSession['host_id'] != $_SESSION['user_id']) {
    header('Location: kahoot_start.php');
    exit;
}

// Lấy thông tin quiz
$quiz = $kahootDB->getQuizById($gameSession['quiz_id']);
$questions = $kahootDB->getQuizQuestions($gameSession['quiz_id']);

// Xử lý các hành động
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'start_game':
                // Bắt đầu trò chơi
                $kahootDB->updateGameSession($gameSessionId, $_SESSION['user_id'], ['status' => 'active']);
                
                // Tự động chọn câu hỏi đầu tiên
                if (count($questions) > 0) {
                    $firstQuestion = $questions[0];
                    $kahootDB->updateGameSession($gameSessionId, $_SESSION['user_id'], ['current_question' => $firstQuestion['id']]);
                }
                
                // Tải lại trang để hiển thị câu hỏi
                header('Location: kahoot_host.php?id=' . $gameSessionId);
                exit;
                break;
                
            case 'next_question':
                // Chuyển đến câu hỏi tiếp theo
                $currentQuestionId = isset($_POST['current_question_id']) ? (int)$_POST['current_question_id'] : 0;
                $nextQuestion = null;
                
                // Tìm câu hỏi tiếp theo
                $foundCurrent = false;
                foreach ($questions as $question) {
                    if ($foundCurrent) {
                        $nextQuestion = $question;
                        break;
                    }
                    
                    if ($question['id'] == $currentQuestionId) {
                        $foundCurrent = true;
                    }
                }
                
                if ($nextQuestion) {
                    $kahootDB->updateGameSession($gameSessionId, $_SESSION['user_id'], ['current_question' => $nextQuestion['id']]);
                    
                    // Đặt trạng thái câu hỏi mới
                    $kahootDB->setQuestionState($gameSessionId, $nextQuestion['id'], 'active');
                    
                    // Tải lại trang để hiển thị câu hỏi mới
                    header('Location: kahoot_host.php?id=' . $gameSessionId);
                    exit;
                } else {
                    // Nếu không còn câu hỏi, kết thúc trò chơi
                    $kahootDB->saveGameResults($gameSessionId);
                    header('Location: kahoot_results.php?id=' . $gameSessionId);
                    exit;
                }
                break;
                
            case 'end_game':
                // Kết thúc trò chơi
                $kahootDB->saveGameResults($gameSessionId);
                header('Location: kahoot_results.php?id=' . $gameSessionId);
                exit;
                break;
        }
    }
}

// Lấy danh sách người chơi
$players = $kahootDB->getGamePlayers($gameSessionId);

// Lấy câu hỏi hiện tại
$currentQuestion = null;
if ($gameSession['status'] === 'active' && $gameSession['current_question']) {
    foreach ($questions as $question) {
        if ($question['id'] == $gameSession['current_question']) {
            $currentQuestion = $question;
            $currentQuestion['answers'] = $kahootDB->getQuestionAnswers($question['id']);
            break;
        }
    }
}

// Tạo URL cho người chơi tham gia
$gameUrl = "https://" . $_SERVER['HTTP_HOST'] . "/kahoot_play.php?code=" . $gameSession['game_pin'];
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Host trò chơi - <?php echo htmlspecialchars($quiz['title']); ?></title>
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
            padding-top: 20px;
            padding-bottom: 20px;
        }
        
        .container {
            max-width: 1200px;
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
            padding: 20px;
        }
        
        .btn {
            border-radius: 30px;
            padding: 10px 20px;
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
        
        .btn-success {
            background: var(--success-color);
            border: none;
        }
        
        .btn-danger {
            background: var(--danger-color);
            border: none;
        }
        
        .btn-outline-primary {
            color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-outline-primary:hover {
            background-color: var(--primary-color);
            color: white;
        }
        
        .player-list {
            max-height: 400px;
            overflow-y: auto;
            padding: 0;
        }
        
        .player-item {
            padding: 12px 15px;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            transition: var(--transition);
        }
        
        .player-item:hover {
            background-color: rgba(0,0,0,0.02);
        }
        
        .player-item:last-child {
            border-bottom: none;
        }
        
        .game-code {
            font-size: 2.5rem;
            font-weight: 700;
            text-align: center;
            margin: 20px 0;
            letter-spacing: 5px;
            color: var(--primary-color);
            background-color: rgba(70, 23, 143, 0.1);
            padding: 15px;
            border-radius: var(--border-radius);
        }
        
        .qr-code {
            text-align: center;
            margin: 20px 0;
        }
        
        .qr-code img {
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }
        
        .question-display {
            background-color: white;
            padding: 25px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            box-shadow: var(--box-shadow);
        }
        
        .question-text {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 25px;
            color: var(--dark-color);
            text-align: center;
        }
        
        .answer-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .answer-box {
            padding: 20px;
            border-radius: var(--border-radius);
            color: white;
            font-weight: 600;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100px;
            position: relative;
            overflow: hidden;
            box-shadow: var(--box-shadow);
            transition: var(--transition);
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
        
        .answer-box .correct-mark {
            position: absolute;
            top: 10px;
            right: 10px;
            background-color: white;
            color: var(--success-color);
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .answer-stats {
            margin-top: 30px;
        }
        
        .progress {
            height: 25px;
            margin-bottom: 15px;
            border-radius: 30px;
            background-color: #e9ecef;
            box-shadow: inset 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .progress-bar {
            border-radius: 30px;
            font-weight: 600;
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
        
        .badge {
            padding: 8px 12px;
            font-weight: 600;
            border-radius: 20px;
        }
        
        .badge-primary {
            background-color: var(--primary-color);
        }
        
        .badge-success {
            background-color: var(--success-color);
        }
        
        .badge-warning {
            background-color: var(--warning-color);
            color: white;
        }
        
        .badge-secondary {
            background-color: #6c757d;
        }
        
        .spinner-border {
            width: 3rem;
            height: 3rem;
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
        
        /* True/False specific styling */
        .true-false-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .true-box {
            background-color: var(--success-color);
            color: white;
            padding: 30px;
            border-radius: var(--border-radius);
            text-align: center;
            font-size: 1.5rem;
            font-weight: 700;
            box-shadow: var(--box-shadow);
            transition: var(--transition);
        }
        
        .false-box {
            background-color: var(--danger-color);
            color: white;
            padding: 30px;
            border-radius: var(--border-radius);
            text-align: center;
            font-size: 1.5rem;
            font-weight: 700;
            box-shadow: var(--box-shadow);
            transition: var(--transition);
        }
        
        .true-box:hover, .false-box:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.15);
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .answer-grid, .true-false-grid {
                grid-template-columns: 1fr;
            }
            
            .game-code {
                font-size: 2rem;
                letter-spacing: 3px;
            }
            
            .question-text {
                font-size: 1.2rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row mb-4">
            <div class="col-md-8">
                <h1 class="mb-0"><?php echo htmlspecialchars($quiz['title']); ?></h1>
                <p class="text-muted"><?php echo htmlspecialchars($quiz['description']); ?></p>
            </div>
            <div class="col-md-4 text-right">
                <a href="index.php" class="btn btn-outline-primary mr-2">
                    <i class="fas fa-home"></i> Trang chủ
                </a>
                <?php if ($gameSession['status'] === 'active'): ?>
                    <form method="POST" action="" class="d-inline">
                        <input type="hidden" name="action" value="end_game">
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-stop-circle"></i> Kết thúc
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="row">
            <div class="col-md-4">
                <!-- Thông tin trò chơi -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-gamepad mr-2"></i>Thông tin trò chơi</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($gameSession['status'] === 'waiting'): ?>
                            <div class="game-code pulse"><?php echo $gameSession['game_pin']; ?></div>
                            <div class="qr-code">
                                <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?php echo urlencode($gameUrl); ?>" alt="QR Code">
                            </div>
                            <div class="text-center mb-3">
                                <a href="<?php echo $gameUrl; ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-external-link-alt"></i> Mở link
                                </a>
                            </div>
                            <p class="text-center">Người chơi có thể quét mã QR hoặc truy cập link để tham gia.</p>
                            
                            <form method="POST" action="">
                                <input type="hidden" name="action" value="start_game">
                                <button type="submit" class="btn btn-success btn-block">
                                    <i class="fas fa-play"></i> Bắt đầu trò chơi
                                </button>
                            </form>
                        <?php else: ?>
                            <div class="mb-3">
                                <p><strong>Mã trò chơi:</strong> <span class="badge badge-primary"><?php echo $gameSession['game_pin']; ?></span></p>
                                <p><strong>Trạng thái:</strong> 
                                    <?php if ($gameSession['status'] === 'active'): ?>
                                        <span class="badge badge-success">Đang diễn ra</span>
                                    <?php elseif ($gameSession['status'] === 'completed'): ?>
                                        <span class="badge badge-secondary">Đã kết thúc</span>
                                    <?php endif; ?>
                                </p>
                                <p><strong>Số người chơi:</strong> <span id="playerCount"><?php echo count($players); ?></span></p>
                                <p><strong>Số câu hỏi:</strong> <?php echo count($questions); ?></p>
                                <?php if ($currentQuestion): ?>
                                    <p><strong>Câu hỏi hiện tại:</strong> <?php echo $currentQuestion['position'] + 1; ?>/<?php echo count($questions); ?></p>
                                <?php endif; ?>
                            </div>
                            
                            <div class="text-center">
                                <a href="<?php echo $gameUrl; ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-external-link-alt"></i> Link tham gia
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Danh sách người chơi -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-users mr-2"></i>Người chơi (<span id="playerCountHeader"><?php echo count($players); ?></span>)</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="player-list" id="playerList">
                            <?php if (count($players) > 0): ?>
                                <?php foreach ($players as $player): ?>
                                    <div class="player-item d-flex justify-content-between align-items-center">
                                        <div>
                                            <i class="fas fa-user mr-2"></i>
                                            <?php echo htmlspecialchars($player['nickname']); ?>
                                        </div>
                                        <?php if ($gameSession['status'] === 'active'): ?>
                                            <div>
                                                <span class="badge badge-primary"><?php echo $player['score']; ?> điểm</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="p-3 text-center text-muted">
                                    Chưa có người chơi nào tham gia
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <?php if ($gameSession['status'] === 'waiting'): ?>
                    <!-- Màn hình chờ -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-hourglass-half mr-2"></i>Đang chờ người chơi</h5>
                        </div>
                        <div class="card-body">
                            <div class="waiting-screen">
                                <div class="spinner-border text-primary mb-3" role="status">
                                    <span class="sr-only">Đang chờ...</span>
                                </div>
                                <h3>Đang chờ người chơi tham gia...</h3>
                                <p>Nhấn "Bắt đầu trò chơi" khi đã có đủ người chơi.</p>
                            </div>
                        </div>
                    </div>
                <?php elseif ($gameSession['status'] === 'active'): ?>
                    <?php if ($currentQuestion): ?>
                        <!-- Hiển thị câu hỏi hiện tại -->
                        <div class="card fade-in">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-question-circle mr-2"></i>Câu hỏi <?php echo $currentQuestion['position'] + 1; ?>/<?php echo count($questions); ?></h5>
                            </div>
                            <div class="card-body">
                                <div class="timer-bar">
                                    <div class="timer-progress" id="timerProgress"></div>
                                </div>
                                
                                <div class="question-display">
                                    <div class="question-text"><?php echo htmlspecialchars($currentQuestion['question_text']); ?></div>
                                    
                                    <?php if ($currentQuestion['question_type'] === 'true_false'): ?>
                                        <!-- Hiển thị câu hỏi đúng/sai -->
                                        <div class="true-false-grid">
                                            <?php 
                                            $trueAnswer = null;
                                            $falseAnswer = null;
                                            foreach ($currentQuestion['answers'] as $answer) {
                                                if (strtolower($answer['answer_text']) === 'true' || strtolower($answer['answer_text']) === 'đúng') {
                                                    $trueAnswer = $answer;
                                                } elseif (strtolower($answer['answer_text']) === 'false' || strtolower($answer['answer_text']) === 'sai') {
                                                    $falseAnswer = $answer;
                                                }
                                            }
                                            ?>
                                            <div class="true-box">
                                                ĐÚNG
                                                <?php if ($trueAnswer && $trueAnswer['is_correct']): ?>
                                                    <div class="correct-mark"><i class="fas fa-check"></i></div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="false-box">
                                                SAI
                                                <?php if ($falseAnswer && $falseAnswer['is_correct']): ?>
                                                    <div class="correct-mark"><i class="fas fa-check"></i></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <!-- Hiển thị câu hỏi trắc nghiệm thông thường -->
                                        <div class="answer-grid">
                                            <?php 
                                            $answerColors = ['red', 'blue', 'green', 'yellow'];
                                            foreach ($currentQuestion['answers'] as $index => $answer): 
                                            ?>
                                                <div class="answer-box <?php echo $answerColors[$index % 4]; ?>">
                                                    <?php echo htmlspecialchars($answer['answer_text']); ?>
                                                    <?php if ($answer['is_correct']): ?>
                                                        <div class="correct-mark"><i class="fas fa-check"></i></div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="answer-stats">
                                    <h5 class="mb-3">Thống kê câu trả lời</h5>
                                    <div id="answerStats">
                                        <?php 
                                        $stats = $kahootDB->getQuestionStatistics($gameSessionId, $currentQuestion['id']);
                                        foreach ($currentQuestion['answers'] as $index => $answer): 
                                            $count = 0;
                                            foreach ($stats['answer_stats'] as $stat) {
                                                if ($stat['id'] == $answer['id']) {
                                                    $count = $stat['count'];
                                                    break;
                                                }
                                            }
                                            $percentage = ($stats['total_players'] > 0) ? round(($count / $stats['total_players']) * 100) : 0;
                                            $colorClass = '';
                                            switch ($index % 4) {
                                                case 0: $colorClass = 'bg-danger'; break;
                                                case 1: $colorClass = 'bg-primary'; break;
                                                case 2: $colorClass = 'bg-success'; break;
                                                case 3: $colorClass = 'bg-warning'; break;
                                            }
                                        ?>
                                            <div class="mb-3">
                                                <div class="d-flex justify-content-between mb-1">
                                                    <span><?php echo htmlspecialchars($answer['answer_text']); ?></span>
                                                    <span><?php echo $count; ?> người (<?php echo $percentage; ?>%)</span>
                                                </div>
                                                <div class="progress">
                                                    <div class="progress-bar <?php echo $colorClass; ?>" 
                                                         role="progressbar" style="width: <?php echo $percentage; ?>%" 
                                                         aria-valuenow="<?php echo $percentage; ?>" aria-valuemin="0" aria-valuemax="100">
                                                        <?php if ($percentage > 10): echo $percentage . '%'; endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                
                                <div class="text-center mt-4">
                                    <p>Thời gian: <span id="timeLeft"><?php echo $currentQuestion['time_limit']; ?></span> giây</p>
                                    <form method="POST" action="" id="nextQuestionForm">
                                        <input type="hidden" name="action" value="next_question">
                                        <input type="hidden" name="current_question_id" value="<?php echo $currentQuestion['id']; ?>">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-arrow-right"></i> Câu hỏi tiếp theo
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- Màn hình kết thúc -->
                        <div class="card fade-in">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-flag-checkered mr-2"></i>Kết thúc trò chơi</h5>
                            </div>
                            <div class="card-body text-center">
                                <h3>Tất cả câu hỏi đã được trả lời!</h3>
                                <p>Nhấn nút bên dưới để xem kết quả trò chơi.</p>
                                <form method="POST" action="">
                                    <input type="hidden" name="action" value="end_game">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-trophy"></i> Xem kết quả
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script>
        $(document).ready(function() {
            <?php if ($gameSession['status'] === 'waiting'): ?>
                // Cập nhật danh sách người chơi mỗi 2 giây
                setInterval(function() {
                    $.ajax({
                        url: 'kahoot_ajax.php',
                        type: 'GET',
                        data: {
                            action: 'get_players',
                            game_id: <?php echo $gameSessionId; ?>
                        },
                        dataType: 'json',
                        success: function(data) {
                            if (data.players) {
                                var playerHtml = '';
                                $.each(data.players, function(index, player) {
                                    playerHtml += '<div class="player-item d-flex justify-content-between align-items-center">';
                                    playerHtml += '<div><i class="fas fa-user mr-2"></i>' + player.nickname + '</div>';
                                    playerHtml += '</div>';
                                });
                                
                                if (playerHtml === '') {
                                    playerHtml = '<div class="p-3 text-center text-muted">Chưa có người chơi nào tham gia</div>';
                                }
                                
                                $('#playerList').html(playerHtml);
                                $('#playerCountHeader, #playerCount').text(data.players.length);
                                
                                // Kích hoạt nút bắt đầu nếu có người chơi
                                if (data.players.length > 0) {
                                    $('button[name="action"][value="start_game"]').prop('disabled', false);
                                } else {
                                    $('button[name="action"][value="start_game"]').prop('disabled', true);
                                }
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error("AJAX Error: " + status + " - " + error);
                        }
                    });
                }, 2000);
            <?php elseif ($gameSession['status'] === 'active' && $currentQuestion): ?>
                // Biến toàn cục
                var timeLimit = <?php echo $currentQuestion['time_limit']; ?>;
                var timeLeft = timeLimit;
                var timerWidth = 100;
                var timerStep = 100 / timeLimit;
                var timerInterval;
                var statsInterval;
                
                // Bắt đầu đếm ngược
                function startTimer() {
                    timerInterval = setInterval(function() {
                        timeLeft--;
                        timerWidth -= timerStep;
                        
                        $('#timerProgress').css('width', timerWidth + '%');
                        $('#timeLeft').text(timeLeft);
                        
                        if (timeLeft <= 0) {
                            clearInterval(timerInterval);
                            // Tự động chuyển câu hỏi sau khi hết thời gian và hiển thị kết quả
                            setTimeout(function() {
                                $('#nextQuestionForm').submit();
                            }, 5000); // Chờ 5 giây để người chơi xem kết quả
                        }
                    }, 1000);
                }
                
                // Cập nhật thống kê câu trả lời mỗi 1 giây
                function startStatsUpdates() {
                    statsInterval = setInterval(function() {
                        $.ajax({
                            url: 'kahoot_ajax.php',
                            type: 'GET',
                            data: {
                                action: 'get_question_stats',
                                game_id: <?php echo $gameSessionId; ?>,
                                question_id: <?php echo $currentQuestion['id']; ?>
                            },
                            dataType: 'json',
                            success: function(data) {
                                if (data.stats) {
                                    var statsHtml = '';
                                    var answers = <?php echo json_encode($currentQuestion['answers']); ?>;
                                    var colors = ['bg-danger', 'bg-primary', 'bg-success', 'bg-warning'];
                                    
                                    $.each(answers, function(index, answer) {
                                        var count = 0;
                                        $.each(data.stats.answer_stats, function(i, stat) {
                                            if (stat.id == answer.id) {
                                                count = stat.count;
                                                return false;
                                            }
                                        });
                                        
                                        var percentage = (data.stats.total_players > 0) ? Math.round((count / data.stats.total_players) * 100) : 0;
                                        
                                        statsHtml += '<div class="mb-3">';
                                        statsHtml += '<div class="d-flex justify-content-between mb-1">';
                                        statsHtml += '<span>' + answer.answer_text + '</span>';
                                        statsHtml += '<span>' + count + ' người (' + percentage + '%)</span>';
                                        statsHtml += '</div>';
                                        statsHtml += '<div class="progress">';
                                        statsHtml += '<div class="progress-bar ' + colors[index % 4] + '" role="progressbar" style="width: ' + percentage + '%" ';
                                        statsHtml += 'aria-valuenow="' + percentage + '" aria-valuemin="0" aria-valuemax="100">';
                                        if (percentage > 10) {
                                            statsHtml += percentage + '%';
                                        }
                                        statsHtml += '</div>';
                                        statsHtml += '</div>';
                                        statsHtml += '</div>';
                                    });
                                    
                                    $('#answerStats').html(statsHtml);
                                }
                                
                                // Cập nhật danh sách người chơi
                                if (data.players) {
                                    var playerHtml = '';
                                    $.each(data.players, function(index, player) {
                                        playerHtml += '<div class="player-item d-flex justify-content-between align-items-center">';
                                        playerHtml += '<div><i class="fas fa-user mr-2"></i>' + player.nickname + '</div>';
                                        playerHtml += '<div><span class="badge badge-primary">' + player.score + ' điểm</span></div>';
                                        playerHtml += '</div>';
                                    });
                                    
                                    if (playerHtml === '') {
                                        playerHtml = '<div class="p-3 text-center text-muted">Chưa có người chơi nào tham gia</div>';
                                    }
                                    
                                    $('#playerList').html(playerHtml);
                                    $('#playerCountHeader, #playerCount').text(data.players.length);
                                }
                            },
                            error: function(xhr, status, error) {
                                console.error("AJAX Error: " + status + " - " + error);
                            }
                        });
                    }, 1000);
                }
                
                // Khởi động bộ đếm thời gian và cập nhật thống kê
                startTimer();
                startStatsUpdates();
                
                // Dừng các interval khi rời khỏi trang
                $(window).on('beforeunload', function() {
                    clearInterval(timerInterval);
                    clearInterval(statsInterval);
                });
            <?php endif; ?>
        });
    </script>
</body>
</html>
