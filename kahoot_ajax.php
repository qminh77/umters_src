<?php
// Xử lý các yêu cầu AJAX cho hệ thống Kahoot
session_start();
include 'kahoot_db.php';

header('Content-Type: application/json');

// Kiểm tra action
if (!isset($_REQUEST['action'])) {
    echo json_encode(['error' => 'Thiếu tham số action']);
    exit;
}

$action = $_REQUEST['action'];

switch ($action) {
    // Kiểm tra trạng thái trò chơi
    case 'check_game_status':
        if (!isset($_REQUEST['game_id'])) {
            echo json_encode(['error' => 'Thiếu tham số game_id']);
            exit;
        }
        
        $gameId = (int)$_REQUEST['game_id'];
        $gameSession = $kahootDB->getGameSession($gameId);
        
        if (!$gameSession) {
            echo json_encode(['error' => 'Không tìm thấy phiên chơi']);
            exit;
        }
        
        // Lấy thêm thông tin câu hỏi hiện tại nếu có
        $currentQuestion = null;
        if ($gameSession['status'] === 'active' && $gameSession['current_question']) {
            $questions = $kahootDB->getQuizQuestions($gameSession['quiz_id']);
            foreach ($questions as $question) {
                if ($question['id'] == $gameSession['current_question']) {
                    $currentQuestion = $question['id'];
                    break;
                }
            }
        }
        
        echo json_encode([
            'status' => $gameSession['status'],
            'current_question' => $gameSession['current_question'],
            'has_question' => ($currentQuestion !== null),
            'timestamp' => time()
        ]);
        break;
    
    // Kiểm tra câu hỏi hiện tại
    case 'check_current_question':
        if (!isset($_REQUEST['game_id']) || !isset($_REQUEST['current_question_id'])) {
            echo json_encode(['error' => 'Thiếu tham số game_id hoặc current_question_id']);
            exit;
        }
        
        $gameId = (int)$_REQUEST['game_id'];
        $currentQuestionId = (int)$_REQUEST['current_question_id'];
        $gameSession = $kahootDB->getGameSession($gameId);
        
        if (!$gameSession) {
            echo json_encode(['error' => 'Không tìm thấy phiên chơi']);
            exit;
        }
        
        $changed = false;
        $gameEnded = false;
        
        if ($gameSession['status'] === 'completed') {
            $gameEnded = true;
        } elseif ($gameSession['current_question'] != $currentQuestionId) {
            $changed = true;
        }
        
        // Lấy trạng thái câu hỏi
        $questionState = null;
        if ($gameSession['current_question']) {
            $questionState = $kahootDB->getQuestionState($gameId, $gameSession['current_question']);
        }
        
        echo json_encode([
            'changed' => $changed,
            'game_ended' => $gameEnded,
            'current_question' => $gameSession['current_question'],
            'question_state' => $questionState,
            'timestamp' => time()
        ]);
        break;
    
    // Gửi câu trả lời
    case 'submit_answer':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['error' => 'Phương thức không được hỗ trợ']);
            exit;
        }
        
        if (!isset($_POST['player_session_id']) || !isset($_POST['question_id']) || !isset($_POST['answer_id'])) {
            echo json_encode(['error' => 'Thiếu tham số bắt buộc']);
            exit;
        }
        
        $playerSessionId = (int)$_POST['player_session_id'];
        $questionId = (int)$_POST['question_id'];
        $answerId = (int)$_POST['answer_id'];
        $responseTime = isset($_POST['response_time']) ? (int)$_POST['response_time'] : null;
        
        // Kiểm tra xem người chơi đã trả lời câu hỏi này chưa
        $existingAnswer = $kahootDB->getPlayerAnswerForQuestion($playerSessionId, $questionId);
        if ($existingAnswer) {
            echo json_encode(['error' => 'Bạn đã trả lời câu hỏi này rồi']);
            exit;
        }
        
        $result = $kahootDB->recordPlayerAnswer($playerSessionId, $questionId, $answerId, $responseTime);
        
        if (!$result) {
            echo json_encode(['error' => 'Không thể ghi nhận câu trả lời']);
            exit;
        }
        
        echo json_encode($result);
        break;
    
    // Lấy danh sách người chơi
    case 'get_players':
        if (!isset($_REQUEST['game_id'])) {
            echo json_encode(['error' => 'Thiếu tham số game_id']);
            exit;
        }
        
        $gameId = (int)$_REQUEST['game_id'];
        $players = $kahootDB->getGamePlayers($gameId);
        
        echo json_encode(['players' => $players, 'timestamp' => time()]);
        break;
    
    // Lấy thống kê câu hỏi
    case 'get_question_stats':
        if (!isset($_REQUEST['game_id']) || !isset($_REQUEST['question_id'])) {
            echo json_encode(['error' => 'Thiếu tham số game_id hoặc question_id']);
            exit;
        }
        
        $gameId = (int)$_REQUEST['game_id'];
        $questionId = (int)$_REQUEST['question_id'];
        
        $stats = $kahootDB->getQuestionStatistics($gameId, $questionId);
        $players = $kahootDB->getGamePlayers($gameId);
        
        echo json_encode([
            'stats' => $stats,
            'players' => $players,
            'timestamp' => time()
        ]);
        break;
    
    default:
        echo json_encode(['error' => 'Action không hợp lệ']);
        break;
}
?>
