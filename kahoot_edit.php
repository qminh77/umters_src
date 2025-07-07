<?php
// Trang chỉnh sửa quiz
session_start();
include 'kahoot_db.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=kahoot_edit.php' . (isset($_GET['id']) ? '?id=' . $_GET['id'] : ''));
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
foreach ($questions as &$question) {
    $question['answers'] = $kahootDB->getQuestionAnswers($question['id']);
}

// Xử lý cập nhật quiz
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    // Cập nhật thông tin quiz
    if ($action === 'update_quiz') {
        $title = $_POST['title'] ?? '';
        $description = $_POST['description'] ?? '';
        
        if (empty($title)) {
            $error = "Vui lòng nhập tiêu đề cho trò chơi";
        } else {
            $data = [
                'title' => $title,
                'description' => $description
            ];
            
            $result = $kahootDB->updateQuiz($quizId, $userId, $data);
            
            if ($result) {
                $success = "Đã cập nhật thông tin trò chơi";
                $quiz = $kahootDB->getQuizById($quizId); // Cập nhật lại thông tin quiz
            } else {
                $error = "Có lỗi xảy ra khi cập nhật trò chơi";
            }
        }
    }
    
    // Thêm câu hỏi mới
    elseif ($action === 'add_question') {
        $questionText = $_POST['question_text'] ?? '';
        $questionType = $_POST['question_type'] ?? 'multiple_choice';
        $timeLimit = (int)($_POST['time_limit'] ?? 20);
        $points = (int)($_POST['points'] ?? 100);
        
        if (empty($questionText)) {
            $error = "Vui lòng nhập nội dung câu hỏi";
        } else {
            $questionId = $kahootDB->addQuestion($quizId, $questionText, $questionType, $timeLimit, $points);
            
            if ($questionId) {
                // Thêm các đáp án
                $answerTexts = $_POST['answer_text'] ?? [];
                $isCorrects = $_POST['is_correct'] ?? [];
                
                foreach ($answerTexts as $index => $text) {
                    if (!empty($text)) {
                        $isCorrect = in_array($index, $isCorrects) ? 1 : 0;
                        $kahootDB->addAnswer($questionId, $text, $isCorrect);
                    }
                }
                
                $success = "Đã thêm câu hỏi mới";
                
                // Cập nhật lại danh sách câu hỏi
                $questions = $kahootDB->getQuizQuestions($quizId);
                foreach ($questions as &$question) {
                    $question['answers'] = $kahootDB->getQuestionAnswers($question['id']);
                }
            } else {
                $error = "Có lỗi xảy ra khi thêm câu hỏi";
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
        
        if (empty($questionText)) {
            $error = "Vui lòng nhập nội dung câu hỏi";
        } else {
            $data = [
                'question_text' => $questionText,
                'question_type' => $questionType,
                'time_limit' => $timeLimit,
                'points' => $points
            ];
            
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
                
                foreach ($answerTexts as $index => $text) {
                    if (!empty($text)) {
                        $isCorrect = in_array($index, $isCorrects) ? 1 : 0;
                        $kahootDB->addAnswer($questionId, $text, $isCorrect);
                    }
                }
                
                $success = "Đã cập nhật câu hỏi";
                
                // Cập nhật lại danh sách câu hỏi
                $questions = $kahootDB->getQuizQuestions($quizId);
                foreach ($questions as &$question) {
                    $question['answers'] = $kahootDB->getQuestionAnswers($question['id']);
                }
            } else {
                $error = "Có lỗi xảy ra khi cập nhật câu hỏi";
            }
        }
    }
    
    // Xóa câu hỏi
    elseif ($action === 'delete_question' && isset($_POST['question_id'])) {
        $questionId = (int)$_POST['question_id'];
        
        $result = $kahootDB->deleteQuestion($questionId, $quizId);
        
        if ($result) {
            $success = "Đã xóa câu hỏi";
            
            // Cập nhật lại danh sách câu hỏi
            $questions = $kahootDB->getQuizQuestions($quizId);
            foreach ($questions as &$question) {
                $question['answers'] = $kahootDB->getQuestionAnswers($question['id']);
            }
        } else {
            $error = "Có lỗi xảy ra khi xóa câu hỏi";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chỉnh sửa trò chơi - <?php echo htmlspecialchars($quiz['title']); ?></title>
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
        .question-card {
            border: 1px solid #ddd;
            border-radius: 5px;
            margin-bottom: 15px;
            background-color: white;
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
        .answer-option {
            margin-bottom: 10px;
            padding: 10px;
            border-radius: 5px;
        }
        .answer-option.correct {
            background-color: rgba(40, 167, 69, 0.1);
            border: 1px solid #28a745;
        }
        .answer-option.incorrect {
            background-color: rgba(220, 53, 69, 0.1);
            border: 1px solid #dc3545;
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
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1>Chỉnh sửa trò chơi</h1>
                <h3><?php echo htmlspecialchars($quiz['title']); ?></h3>
            </div>
            <div>
                <a href="kahoot_game.php" class="btn btn-outline-primary">
                    <i class="fas fa-arrow-left"></i> Quay lại
                </a>
                <a href="kahoot_start.php?id=<?php echo $quizId; ?>" class="btn btn-success">
                    <i class="fas fa-play"></i> Bắt đầu trò chơi
                </a>
            </div>
        </div>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <?php if (isset($success)): ?>
            <div class="alert alert-success">
                <?php echo $success; ?>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-4">
                <!-- Thông tin trò chơi -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-info-circle mr-2"></i>Thông tin trò chơi
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="update_quiz">
                            <div class="form-group">
                                <label for="title">Tiêu đề</label>
                                <input type="text" class="form-control" id="title" name="title" value="<?php echo htmlspecialchars($quiz['title']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="description">Mô tả</label>
                                <textarea class="form-control" id="description" name="description" rows="3"><?php echo htmlspecialchars($quiz['description'] ?? ''); ?></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Lưu thông tin
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Thống kê -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-chart-bar mr-2"></i>Thống kê
                    </div>
                    <div class="card-body">
                        <p><strong>Số câu hỏi:</strong> <?php echo count($questions); ?></p>
                        <p><strong>Đã chơi:</strong> <?php echo $quiz['play_count']; ?> lần</p>
                        <p><strong>Ngày tạo:</strong> <?php echo date('d/m/Y H:i', strtotime($quiz['created_at'])); ?></p>
                        <p><strong>Cập nhật lần cuối:</strong> <?php echo date('d/m/Y H:i', strtotime($quiz['updated_at'])); ?></p>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <!-- Danh sách câu hỏi -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div><i class="fas fa-question-circle mr-2"></i>Danh sách câu hỏi</div>
                        <button type="button" class="btn btn-sm btn-primary" data-toggle="modal" data-target="#addQuestionModal">
                            <i class="fas fa-plus"></i> Thêm câu hỏi
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if (empty($questions)): ?>
                            <p class="text-muted">Chưa có câu hỏi nào. Hãy thêm câu hỏi đầu tiên cho trò chơi của bạn.</p>
                        <?php else: ?>
                            <?php foreach ($questions as $index => $question): ?>
                                <div class="question-card">
                                    <div class="question-header">
                                        <div>
                                            <span class="badge badge-secondary mr-2"><?php echo $index + 1; ?></span>
                                            <?php echo htmlspecialchars($question['question_text']); ?>
                                        </div>
                                        <div>
                                            <button type="button" class="btn btn-sm btn-info edit-question" data-id="<?php echo $question['id']; ?>">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-danger delete-question" data-id="<?php echo $question['id']; ?>">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="question-body">
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
                                                    <?php echo htmlspecialchars($answer['answer_text']); ?>
                                                    <?php if ($answer['is_correct']): ?>
                                                        <span class="badge badge-success float-right">Đúng</span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal thêm câu hỏi -->
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
                    <form id="questionForm" method="POST" action="">
                        <input type="hidden" name="action" value="add_question">
                        <input type="hidden" id="question_id" name="question_id" value="">
                        
                        <div class="form-group">
                            <label for="question_text">Nội dung câu hỏi</label>
                            <textarea class="form-control" id="question_text" name="question_text" rows="2" required></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label for="question_type">Loại câu hỏi</label>
                            <select class="form-control" id="question_type" name="question_type">
                                <option value="multiple_choice">Trắc nghiệm</option>
                                <option value="true_false">Đúng/Sai</option>
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
                        
                        <hr>
                        
                        <div id="answers_container">
                            <h5>Đáp án</h5>
                            <div id="true_false_answers" style="display: none;">
                                <div class="form-group">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="is_correct[]" value="0" id="true_answer" checked>
                                        <label class="form-check-label" for="true_answer">Đúng</label>
                                    </div>
                                    <input type="text" class="form-control" name="answer_text[0]" value="Đúng" readonly>
                                </div>
                                <div class="form-group">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="is_correct[]" value="1" id="false_answer">
                                        <label class="form-check-label" for="false_answer">Sai</label>
                                    </div>
                                    <input type="text" class="form-control" name="answer_text[1]" value="Sai" readonly>
                                </div>
                            </div>
                            
                            <div id="multiple_choice_answers">
                                <div class="form-group">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="is_correct[]" value="0" id="answer_0">
                                        <label class="form-check-label" for="answer_0">Đáp án 1</label>
                                    </div>
                                    <input type="text" class="form-control" name="answer_text[0]" placeholder="Nhập đáp án">
                                </div>
                                <div class="form-group">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="is_correct[]" value="1" id="answer_1">
                                        <label class="form-check-label" for="answer_1">Đáp án 2</label>
                                    </div>
                                    <input type="text" class="form-control" name="answer_text[1]" placeholder="Nhập đáp án">
                                </div>
                                <div class="form-group">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="is_correct[]" value="2" id="answer_2">
                                        <label class="form-check-label" for="answer_2">Đáp án 3</label>
                                    </div>
                                    <input type="text" class="form-control" name="answer_text[2]" placeholder="Nhập đáp án">
                                </div>
                                <div class="form-group">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="is_correct[]" value="3" id="answer_3">
                                        <label class="form-check-label" for="answer_3">Đáp án 4</label>
                                    </div>
                                    <input type="text" class="form-control" name="answer_text[3]" placeholder="Nhập đáp án">
                                </div>
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

    <!-- Form xóa câu hỏi (ẩn) -->
    <form id="deleteQuestionForm" method="POST" action="" style="display: none;">
        <input type="hidden" name="action" value="delete_question">
        <input type="hidden" name="question_id" id="delete_question_id" value="">
    </form>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script>
        $(document).ready(function() {
            // Xử lý loại câu hỏi
            $('#question_type').change(function() {
                var questionType = $(this).val();
                
                if (questionType === 'multiple_choice') {
                    $('#multiple_choice_answers').show();
                    $('#true_false_answers').hide();
                } else if (questionType === 'true_false') {
                    $('#multiple_choice_answers').hide();
                    $('#true_false_answers').show();
                }
            });
            
            // Xử lý lưu câu hỏi
            $('#saveQuestionBtn').click(function() {
                $('#questionForm').submit();
            });
            
            // Xử lý chỉnh sửa câu hỏi
            $('.edit-question').click(function() {
                var questionId = $(this).data('id');
                
                // Tải dữ liệu câu hỏi bằng AJAX
                $.ajax({
                    url: 'kahoot_ajax.php',
                    type: 'GET',
                    data: {
                        action: 'get_question',
                        question_id: questionId
                    },
                    dataType: 'json',
                    success: function(data) {
                        // Điền dữ liệu vào form
                        $('#question_id').val(data.id);
                        $('#question_text').val(data.question_text);
                        $('#question_type').val(data.question_type).trigger('change');
                        $('#time_limit').val(data.time_limit);
                        $('#points').val(data.points);
                        
                        // Điền đáp án
                        if (data.answers) {
                            for (var i = 0; i < data.answers.length; i++) {
                                $('input[name="answer_text[' + i + ']"]').val(data.answers[i].answer_text);
                                
                                if (data.answers[i].is_correct == 1) {
                                    if (data.question_type === 'true_false') {
                                        $('input[name="is_correct[]"][value="' + i + '"]').prop('checked', true);
                                    } else {
                                        $('#answer_' + i).prop('checked', true);
                                    }
                                } else {
                                    $('#answer_' + i).prop('checked', false);
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
            
            // Xử lý xóa câu hỏi
            $('.delete-question').click(function() {
                if (confirm('Bạn có chắc chắn muốn xóa câu hỏi này?')) {
                    var questionId = $(this).data('id');
                    $('#delete_question_id').val(questionId);
                    $('#deleteQuestionForm').submit();
                }
            });
            
            // Reset form khi mở modal thêm câu hỏi mới
            $('#addQuestionModal').on('show.bs.modal', function (e) {
                if (!$(e.relatedTarget).hasClass('edit-question')) {
                    $('#questionForm')[0].reset();
                    $('#question_id').val('');
                    $('input[name="action"]').val('add_question');
                    $('#question_type').val('multiple_choice').trigger('change');
                }
            });
        });
    </script>
</body>
</html>
