<?php
// Trang chính cho hệ thống Kahoot
session_start();
include 'kahoot_db.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=kahoot_game.php');
    exit;
}

$userId = $_SESSION['user_id'];
$username = $_SESSION['username'];
$userRole = $_SESSION['role'];

// Xử lý đăng xuất
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header('Location: login.php');
    exit;
}

// Xử lý tạo game mới
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_game') {
    $title = $_POST['title'] ?? '';
    $description = $_POST['description'] ?? '';
    
    if (empty($title)) {
        $error = "Vui lòng nhập tiêu đề cho trò chơi";
    } else {
        $quizId = $kahootDB->createQuiz($userId, $title, $description);
        
        if ($quizId) {
            header('Location: kahoot_edit.php?id=' . $quizId);
            exit;
        } else {
            $error = "Có lỗi xảy ra khi tạo trò chơi";
        }
    }
}

// Lấy danh sách quiz của người dùng
$userQuizzes = $kahootDB->getUserQuizzes($userId);

// Lấy lịch sử chơi game của người dùng
$gameHistory = $kahootDB->getUserGameHistory($userId);
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý trò chơi</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.1/css/all.min.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .card {
            background: white;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            padding: 15px;
        }
        .card-header {
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 15px;
            font-weight: bold;
        }
        .btn-primary {
            background-color: #4285f4;
            border-color: #4285f4;
        }
        .btn-danger {
            background-color: #ea4335;
            border-color: #ea4335;
        }
        .btn-success {
            background-color: #34a853;
            border-color: #34a853;
        }
        .quiz-item {
            border-bottom: 1px solid #eee;
            padding: 10px 0;
        }
        .quiz-item:last-child {
            border-bottom: none;
        }
        .game-code {
            font-family: monospace;
            font-weight: bold;
            color: #4285f4;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        table th, table td {
            padding: 8px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        table th {
            background-color: #f8f9fa;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Quản lý trò chơi</h1>
            <div>
                <a href="index.php" class="btn btn-outline-primary">
                    <i class="fas fa-home"></i> Trang chủ
                </a>
                <a href="kahoot_game.php?action=logout" class="btn btn-outline-danger">
                    <i class="fas fa-sign-out-alt"></i> Đăng xuất
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
                <!-- Danh sách trò chơi -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-gamepad mr-2"></i>Trò chơi của bạn
                    </div>
                    <div class="card-body">
                        <?php if (empty($userQuizzes)): ?>
                            <p class="text-muted">Bạn chưa tạo trò chơi nào.</p>
                        <?php else: ?>
                            <div class="list-group">
                                <?php foreach ($userQuizzes as $quiz): ?>
                                    <div class="list-group-item quiz-item">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h5 class="mb-1"><?php echo htmlspecialchars($quiz['title']); ?></h5>
                                                <p class="mb-1 text-muted"><?php echo htmlspecialchars($quiz['description'] ?? 'Không có mô tả'); ?></p>
                                                <small>Đã chơi: <?php echo $quiz['play_count']; ?> lần</small>
                                            </div>
                                            <div>
                                                <a href="kahoot_edit.php?id=<?php echo $quiz['id']; ?>" class="btn btn-sm btn-primary">
                                                    <i class="fas fa-edit"></i> Sửa
                                                </a>
                                                <a href="kahoot_start.php?id=<?php echo $quiz['id']; ?>" class="btn btn-sm btn-success">
                                                    <i class="fas fa-play"></i> Bắt đầu
                                                </a>
                                                <button type="button" class="btn btn-sm btn-danger delete-quiz" data-id="<?php echo $quiz['id']; ?>">
                                                    <i class="fas fa-trash"></i> Xóa
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        
                        <button type="button" class="btn btn-primary mt-3" data-toggle="modal" data-target="#createGameModal">
                            <i class="fas fa-plus-circle"></i> Tạo trò chơi mới
                        </button>
                    </div>
                </div>

                <!-- Lịch sử chơi game -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-history mr-2"></i>Lịch sử chơi game
                    </div>
                    <div class="card-body">
                        <?php if (empty($gameHistory)): ?>
                            <p class="text-muted">Bạn chưa tham gia trò chơi nào.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Trò chơi</th>
                                            <th>Vai trò</th>
                                            <th>Thời gian</th>
                                            <th>Điểm số</th>
                                            <th>Thứ hạng</th>
                                            <th>Hành động</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($gameHistory as $game): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($game['quiz_title']); ?></td>
                                                <td>
                                                    <?php if ($game['host_id'] == $userId): ?>
                                                        <span class="badge badge-primary">Host</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-info">Người chơi</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo date('d/m/Y H:i', strtotime($game['created_at'])); ?></td>
                                                <td><?php echo isset($game['score']) ? $game['score'] : 'N/A'; ?></td>
                                                <td><?php echo isset($game['rank']) ? $game['rank'] . '/' . $game['total_players'] : 'N/A'; ?></td>
                                                <td>
                                                    <a href="kahoot_results.php?id=<?php echo $game['id']; ?>" class="btn btn-sm btn-info">
                                                        <i class="fas fa-chart-bar"></i> Kết quả
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <!-- Hướng dẫn -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-info-circle mr-2"></i>Hướng dẫn
                    </div>
                    <div class="card-body">
                        <h5>Cách tạo trò chơi:</h5>
                        <ol>
                            <li>Nhấn nút "Tạo trò chơi mới"</li>
                            <li>Nhập tiêu đề và mô tả</li>
                            <li>Thêm các câu hỏi và đáp án</li>
                            <li>Nhấn "Bắt đầu" để tạo phiên chơi</li>
                            <li>Chia sẻ đường dẫn cho người chơi</li>
                        </ol>
                        
                        <h5>Cách chơi:</h5>
                        <ol>
                            <li>Người chơi truy cập đường dẫn</li>
                            <li>Nhập biệt danh</li>
                            <li>Chờ host bắt đầu trò chơi</li>
                            <li>Trả lời các câu hỏi càng nhanh càng tốt</li>
                            <li>Xem kết quả cuối cùng</li>
                        </ol>
                    </div>
                </div>

                <!-- Phiên đang hoạt động -->
                <?php
                // Lấy các phiên đang hoạt động của người dùng
                $activeGames = array_filter($gameHistory, function($game) use ($userId) {
                    return $game['host_id'] == $userId && $game['status'] != 'completed';
                });
                
                if (!empty($activeGames)):
                ?>
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <i class="fas fa-play-circle mr-2"></i>Phiên đang hoạt động
                    </div>
                    <div class="card-body">
                        <?php foreach ($activeGames as $game): ?>
                            <div class="mb-3">
                                <h5><?php echo htmlspecialchars($game['quiz_title']); ?></h5>
                                <p>Mã trò chơi: <span class="game-code"><?php echo $game['game_code']; ?></span></p>
                                <p>Đường dẫn: <a href="<?php echo 'https://' . $_SERVER['HTTP_HOST'] . '/' . $game['game_code']; ?>" target="_blank">
                                    <?php echo 'https://' . $_SERVER['HTTP_HOST'] . '/' . $game['game_code']; ?>
                                </a></p>
                                <div>
                                    <a href="kahoot_host.php?id=<?php echo $game['id']; ?>" class="btn btn-primary btn-sm">
                                        <i class="fas fa-desktop"></i> Màn hình host
                                    </a>
                                    <button class="btn btn-danger btn-sm end-game" data-id="<?php echo $game['id']; ?>">
                                        <i class="fas fa-stop-circle"></i> Kết thúc
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Modal tạo trò chơi mới -->
    <div class="modal fade" id="createGameModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Tạo trò chơi mới</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="create_game">
                        <div class="form-group">
                            <label for="title">Tiêu đề trò chơi</label>
                            <input type="text" class="form-control" id="title" name="title" required>
                        </div>
                        <div class="form-group">
                            <label for="description">Mô tả (tùy chọn)</label>
                            <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">Tạo trò chơi</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script>
        $(document).ready(function() {
            // Xử lý xóa quiz
            $('.delete-quiz').click(function() {
                if (confirm('Bạn có chắc chắn muốn xóa trò chơi này?')) {
                    var quizId = $(this).data('id');
                    $.ajax({
                        url: 'kahoot_ajax.php',
                        type: 'POST',
                        data: {
                            action: 'delete_quiz',
                            quiz_id: quizId
                        },
                        success: function(response) {
                            location.reload();
                        },
                        error: function() {
                            alert('Có lỗi xảy ra khi xóa trò chơi');
                        }
                    });
                }
            });
            
            // Xử lý kết thúc game
            $('.end-game').click(function() {
                if (confirm('Bạn có chắc chắn muốn kết thúc phiên chơi này?')) {
                    var gameId = $(this).data('id');
                    $.ajax({
                        url: 'kahoot_ajax.php',
                        type: 'POST',
                        data: {
                            action: 'end_game',
                            game_id: gameId
                        },
                        success: function(response) {
                            location.reload();
                        },
                        error: function() {
                            alert('Có lỗi xảy ra khi kết thúc phiên chơi');
                        }
                    });
                }
            });
        });
    </script>
</body>
</html>
