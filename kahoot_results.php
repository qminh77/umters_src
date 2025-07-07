<?php
// Trang hiển thị kết quả trò chơi
session_start();
include 'kahoot_db.php';

// Kiểm tra id phiên chơi
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: index.php');
    exit;
}

$gameSessionId = (int)$_GET['id'];
$gameSession = $kahootDB->getGameSession($gameSessionId);

if (!$gameSession) {
    $error = "Không tìm thấy phiên chơi";
} else {
    // Lấy thông tin quiz
    $quiz = $kahootDB->getQuizById($gameSession['quiz_id']);
    
    // Lấy danh sách người chơi
    $players = $kahootDB->getGamePlayers($gameSessionId);
    
    // Lấy danh sách câu hỏi
    $questions = $kahootDB->getQuizQuestions($gameSession['quiz_id']);
    
    // Tính số câu trả lời đúng cho mỗi người chơi
    foreach ($players as &$player) {
        $correctAnswers = 0;
        $totalAnswered = 0;
        
        foreach ($questions as $question) {
            $answer = $kahootDB->getPlayerAnswerForQuestion($player['id'], $question['id']);
            if ($answer) {
                $totalAnswered++;
                if ($answer['is_correct']) {
                    $correctAnswers++;
                }
            }
        }
        
        $player['correct_answers'] = $correctAnswers;
        $player['total_answered'] = $totalAnswered;
    }
    
    // Lấy thông tin người chơi hiện tại (nếu có)
    $currentPlayer = null;
    if (isset($_SESSION['player_session_id'])) {
        foreach ($players as $player) {
            if ($player['id'] == $_SESSION['player_session_id']) {
                $currentPlayer = $player;
                break;
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kết quả trò chơi - <?php echo isset($quiz) ? htmlspecialchars($quiz['title']) : 'Kahoot Clone'; ?></title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.1/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            padding-top: 20px;
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
        .podium {
            display: flex;
            justify-content: center;
            align-items: flex-end;
            margin: 30px 0;
            height: 200px;
        }
        .podium-item {
            text-align: center;
            padding: 10px;
            width: 120px;
        }
        .podium-1 {
            order: 2;
            height: 100%;
            background-color: gold;
            z-index: 3;
        }
        .podium-2 {
            order: 1;
            height: 70%;
            background-color: silver;
            z-index: 2;
        }
        .podium-3 {
            order: 3;
            height: 50%;
            background-color: #cd7f32;
            z-index: 1;
        }
        .podium-rank {
            font-size: 2rem;
            font-weight: bold;
        }
        .podium-name {
            font-weight: bold;
            word-break: break-word;
        }
        .podium-score {
            font-size: 0.9rem;
        }
        .player-highlight {
            background-color: #fff3cd;
        }
        .results-chart {
            height: 300px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if (isset($error)): ?>
            <div class="row justify-content-center">
                <div class="col-md-8">
                    <div class="card">
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
                </div>
            </div>
        <?php else: ?>
            <div class="row mb-4">
                <div class="col-md-8">
                    <h1>Kết quả trò chơi</h1>
                    <h3><?php echo htmlspecialchars($quiz['title']); ?></h3>
                </div>
                <div class="col-md-4 text-right">
                    <a href="index.php" class="btn btn-primary">
                        <i class="fas fa-home"></i> Trang chủ
                    </a>
                </div>
            </div>

            <div class="row">
                <div class="col-md-8">
                    <!-- Bảng xếp hạng -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-trophy mr-2"></i>Bảng xếp hạng</h5>
                        </div>
                        <div class="card-body">
                            <?php if (count($players) > 0): ?>
                                <!-- Podium cho top 3 -->
                                <?php if (count($players) >= 3): ?>
                                    <div class="podium">
                                        <div class="podium-item podium-2">
                                            <div class="podium-rank">2</div>
                                            <div class="podium-name"><?php echo htmlspecialchars($players[1]['nickname']); ?></div>
                                            <div class="podium-score"><?php echo $players[1]['score']; ?> điểm</div>
                                        </div>
                                        <div class="podium-item podium-1">
                                            <div class="podium-rank">1</div>
                                            <div class="podium-name"><?php echo htmlspecialchars($players[0]['nickname']); ?></div>
                                            <div class="podium-score"><?php echo $players[0]['score']; ?> điểm</div>
                                        </div>
                                        <div class="podium-item podium-3">
                                            <div class="podium-rank">3</div>
                                            <div class="podium-name"><?php echo htmlspecialchars($players[2]['nickname']); ?></div>
                                            <div class="podium-score"><?php echo $players[2]['score']; ?> điểm</div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <!-- Bảng xếp hạng đầy đủ -->
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>Thứ hạng</th>
                                                <th>Người chơi</th>
                                                <th>Điểm số</th>
                                                <th>Đúng/Tổng</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($players as $index => $player): ?>
                                                <tr class="<?php echo $currentPlayer && $player['id'] == $currentPlayer['id'] ? 'player-highlight' : ''; ?>">
                                                    <td><?php echo $index + 1; ?></td>
                                                    <td>
                                                        <?php echo htmlspecialchars($player['nickname']); ?>
                                                        <?php if ($currentPlayer && $player['id'] == $currentPlayer['id']): ?>
                                                            <span class="badge badge-info">Bạn</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo $player['score']; ?></td>
                                                    <td><?php echo $player['correct_answers']; ?>/<?php echo count($questions); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info">
                                    Không có người chơi nào tham gia trò chơi này.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Thống kê câu hỏi -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-chart-bar mr-2"></i>Thống kê câu hỏi</h5>
                        </div>
                        <div class="card-body">
                            <?php if (count($questions) > 0): ?>
                                <div class="results-chart">
                                    <canvas id="questionChart"></canvas>
                                </div>
                                
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>Câu hỏi</th>
                                                <th>Tỷ lệ đúng</th>
                                                <th>Thời gian trung bình</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($questions as $index => $question): ?>
                                                <?php 
                                                $stats = $kahootDB->getQuestionStatistics($gameSessionId, $question['id']); 
                                                ?>
                                                <tr>
                                                    <td><?php echo $index + 1; ?>. <?php echo htmlspecialchars($question['question_text']); ?></td>
                                                    <td><?php echo $stats['correct_percentage']; ?>%</td>
                                                    <td><?php echo $stats['avg_response_time_ms'] ? round($stats['avg_response_time_ms'] / 1000, 2) . 's' : 'N/A'; ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info">
                                    Không có câu hỏi nào trong trò chơi này.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <!-- Thông tin trò chơi -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-info-circle mr-2"></i>Thông tin trò chơi</h5>
                        </div>
                        <div class="card-body">
                            <p><strong>Tên trò chơi:</strong> <?php echo htmlspecialchars($quiz['title']); ?></p>
                            <?php if (!empty($quiz['description'])): ?>
                                <p><strong>Mô tả:</strong> <?php echo htmlspecialchars($quiz['description']); ?></p>
                            <?php endif; ?>
                            <p><strong>Số câu hỏi:</strong> <?php echo count($questions); ?></p>
                            <p><strong>Số người chơi:</strong> <?php echo count($players); ?></p>
                            <p><strong>Thời gian bắt đầu:</strong> <?php echo date('H:i:s d/m/Y', strtotime($gameSession['started_at'])); ?></p>
                            <?php if ($gameSession['ended_at']): ?>
                                <p><strong>Thời gian kết thúc:</strong> <?php echo date('H:i:s d/m/Y', strtotime($gameSession['ended_at'])); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        $(document).ready(function() {
            <?php if (isset($questions) && count($questions) > 0): ?>
                // Tạo biểu đồ thống kê
                var ctx = document.getElementById('questionChart').getContext('2d');
                var questionLabels = [];
                var correctData = [];
                var incorrectData = [];
                
                <?php foreach ($questions as $index => $question): ?>
                    <?php $stats = $kahootDB->getQuestionStatistics($gameSessionId, $question['id']); ?>
                    questionLabels.push('Câu <?php echo $index + 1; ?>');
                    correctData.push(<?php echo $stats['correct_percentage']; ?>);
                    incorrectData.push(<?php echo 100 - $stats['correct_percentage']; ?>);
                <?php endforeach; ?>
                
                var questionChart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: questionLabels,
                        datasets: [
                            {
                                label: 'Tỷ lệ đúng (%)',
                                data: correctData,
                                backgroundColor: 'rgba(40, 167, 69, 0.7)',
                                borderColor: 'rgba(40, 167, 69, 1)',
                                borderWidth: 1
                            },
                            {
                                label: 'Tỷ lệ sai (%)',
                                data: incorrectData,
                                backgroundColor: 'rgba(220, 53, 69, 0.7)',
                                borderColor: 'rgba(220, 53, 69, 1)',
                                borderWidth: 1
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                max: 100,
                                title: {
                                    display: true,
                                    text: 'Phần trăm (%)'
                                }
                            },
                            x: {
                                title: {
                                    display: true,
                                    text: 'Câu hỏi'
                                }
                            }
                        }
                    }
                });
            <?php endif; ?>
        });
    </script>
</body>
</html>
