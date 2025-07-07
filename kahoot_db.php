<?php
// Tệp kết nối và xử lý cơ sở dữ liệu cho hệ thống Kahoot
include 'db_config.php';

// Lớp xử lý cơ sở dữ liệu cho hệ thống Kahoot
class KahootDB {
    private $conn;
    
    public function __construct($connection) {
        $this->conn = $connection;
    }
    
    // ===== USER MANAGEMENT =====
    
    // Đăng ký người dùng mới
    public function registerUser($username, $password, $email, $fullName, $role = 'player') {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $this->conn->prepare("INSERT INTO users (username, password, email, full_name, role) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $username, $hashedPassword, $email, $fullName, $role);
        
        if ($stmt->execute()) {
            return $this->conn->insert_id;
        } else {
            return false;
        }
    }
    
    // Đăng nhập người dùng
    public function loginUser($username, $password) {
        $stmt = $this->conn->prepare("SELECT id, username, password, role FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            if (password_verify($password, $user['password'])) {
                // Cập nhật thời gian đăng nhập
                $updateStmt = $this->conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                $updateStmt->bind_param("i", $user['id']);
                $updateStmt->execute();
                
                return [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'role' => $user['role']
                ];
            }
        }
        
        return false;
    }
    
    // Lấy thông tin người dùng
    public function getUserById($userId) {
        $stmt = $this->conn->prepare("SELECT id, username, email, full_name, role, created_at, last_login FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            return $result->fetch_assoc();
        }
        
        return false;
    }
    
    // ===== QUIZ MANAGEMENT =====
    
    // Tạo quiz mới
    public function createQuiz($userId, $title, $description = '') {
        $stmt = $this->conn->prepare("INSERT INTO quizzes (user_id, title, description) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $userId, $title, $description);
        
        if ($stmt->execute()) {
            return $this->conn->insert_id;
        } else {
            return false;
        }
    }
    
    // Cập nhật quiz
    public function updateQuiz($quizId, $userId, $data) {
        $allowedFields = ['title', 'description'];
        $updates = [];
        $types = "";
        $values = [];
        
        foreach ($data as $field => $value) {
            if (in_array($field, $allowedFields)) {
                $updates[] = "$field = ?";
                $types .= "s";
                $values[] = $value;
            }
        }
        
        if (empty($updates)) {
            return false;
        }
        
        $sql = "UPDATE quizzes SET " . implode(", ", $updates) . " WHERE id = ? AND user_id = ?";
        $types .= "ii";
        $values[] = $quizId;
        $values[] = $userId;
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
        
        return $stmt->execute();
    }
    
    // Xóa quiz
    public function deleteQuiz($quizId, $userId) {
        // Xóa tất cả các phiên chơi liên quan đến quiz này
        $gameSessionsStmt = $this->conn->prepare("SELECT id FROM game_sessions WHERE quiz_id = ?");
        $gameSessionsStmt->bind_param("i", $quizId);
        $gameSessionsStmt->execute();
        $gameSessionsResult = $gameSessionsStmt->get_result();
        
        while ($gameSession = $gameSessionsResult->fetch_assoc()) {
            $gameSessionId = $gameSession['id'];
            
            // Xóa tất cả câu trả lời của người chơi
            $this->conn->query("DELETE FROM player_answers WHERE player_session_id IN (SELECT id FROM player_sessions WHERE game_session_id = $gameSessionId)");
            
            // Xóa tất cả phiên người chơi
            $this->conn->query("DELETE FROM player_sessions WHERE game_session_id = $gameSessionId");
            
            // Xóa kết quả trò chơi
            $this->conn->query("DELETE FROM game_results WHERE game_session_id = $gameSessionId");
            
            // Xóa trạng thái câu hỏi
            $this->conn->query("DELETE FROM question_states WHERE game_session_id = $gameSessionId");
        }
        
        // Xóa tất cả phiên chơi
        $this->conn->query("DELETE FROM game_sessions WHERE quiz_id = $quizId");
        
        // Xóa tất cả đáp án
        $this->conn->query("DELETE FROM answers WHERE question_id IN (SELECT id FROM questions WHERE quiz_id = $quizId)");
        
        // Xóa tất cả câu hỏi
        $this->conn->query("DELETE FROM questions WHERE quiz_id = $quizId");
        
        // Cuối cùng xóa quiz
        $stmt = $this->conn->prepare("DELETE FROM quizzes WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $quizId, $userId);
        
        return $stmt->execute();
    }
    
    // Lấy danh sách quiz của người dùng
    public function getUserQuizzes($userId) {
        $stmt = $this->conn->prepare("SELECT * FROM quizzes WHERE user_id = ? ORDER BY updated_at DESC");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $quizzes = [];
        while ($row = $result->fetch_assoc()) {
            $quizzes[] = $row;
        }
        
        return $quizzes;
    }
    
    // Lấy thông tin chi tiết quiz
    public function getQuizById($quizId) {
        $stmt = $this->conn->prepare("SELECT * FROM quizzes WHERE id = ?");
        $stmt->bind_param("i", $quizId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            return $result->fetch_assoc();
        }
        
        return false;
    }
    
    // ===== QUESTION MANAGEMENT =====
    
    // Thêm câu hỏi mới
    public function addQuestion($quizId, $questionText, $questionType, $timeLimit = 20, $points = 100) {
        // Lấy vị trí cao nhất hiện tại
        $posStmt = $this->conn->prepare("SELECT MAX(position) as max_pos FROM questions WHERE quiz_id = ?");
        $posStmt->bind_param("i", $quizId);
        $posStmt->execute();
        $posResult = $posStmt->get_result();
        $posRow = $posResult->fetch_assoc();
        $position = ($posRow['max_pos'] !== null) ? $posRow['max_pos'] + 1 : 0;
        
        $stmt = $this->conn->prepare("INSERT INTO questions (quiz_id, question_text, question_type, time_limit, points, position) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("issiii", $quizId, $questionText, $questionType, $timeLimit, $points, $position);
        
        if ($stmt->execute()) {
            return $this->conn->insert_id;
        } else {
            return false;
        }
    }
    
    // Cập nhật câu hỏi
    public function updateQuestion($questionId, $quizId, $data) {
        $allowedFields = ['question_text', 'question_type', 'time_limit', 'points', 'position'];
        $updates = [];
        $types = "";
        $values = [];
        
        foreach ($data as $field => $value) {
            if (in_array($field, $allowedFields)) {
                $updates[] = "$field = ?";
                if ($field == 'time_limit' || $field == 'points' || $field == 'position') {
                    $types .= "i";
                } else {
                    $types .= "s";
                }
                $values[] = $value;
            }
        }
        
        if (empty($updates)) {
            return false;
        }
        
        $sql = "UPDATE questions SET " . implode(", ", $updates) . " WHERE id = ? AND quiz_id = ?";
        $types .= "ii";
        $values[] = $questionId;
        $values[] = $quizId;
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
        
        return $stmt->execute();
    }
    
    // Xóa câu hỏi
    public function deleteQuestion($questionId, $quizId) {
        // Xóa tất cả đáp án của câu hỏi
        $this->conn->query("DELETE FROM answers WHERE question_id = $questionId");
        
        // Xóa tất cả câu trả lời của người chơi
        $this->conn->query("DELETE FROM player_answers WHERE question_id = $questionId");
        
        // Xóa trạng thái câu hỏi
        $this->conn->query("DELETE FROM question_states WHERE question_id = $questionId");
        
        // Xóa câu hỏi
        $stmt = $this->conn->prepare("DELETE FROM questions WHERE id = ? AND quiz_id = ?");
        $stmt->bind_param("ii", $questionId, $quizId);
        
        return $stmt->execute();
    }
    
    // Lấy danh sách câu hỏi của quiz
    public function getQuizQuestions($quizId) {
        $stmt = $this->conn->prepare("SELECT * FROM questions WHERE quiz_id = ? ORDER BY position ASC");
        $stmt->bind_param("i", $quizId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $questions = [];
        while ($row = $result->fetch_assoc()) {
            $questions[] = $row;
        }
        
        return $questions;
    }
    
    // ===== ANSWER MANAGEMENT =====
    
    // Thêm đáp án cho câu hỏi
    public function addAnswer($questionId, $answerText, $isCorrect = 0) {
        // Lấy vị trí cao nhất hiện tại
        $posStmt = $this->conn->prepare("SELECT MAX(position) as max_pos FROM answers WHERE question_id = ?");
        $posStmt->bind_param("i", $questionId);
        $posStmt->execute();
        $posResult = $posStmt->get_result();
        $posRow = $posResult->fetch_assoc();
        $position = ($posRow['max_pos'] !== null) ? $posRow['max_pos'] + 1 : 0;
        
        $stmt = $this->conn->prepare("INSERT INTO answers (question_id, answer_text, is_correct, position) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isii", $questionId, $answerText, $isCorrect, $position);
        
        if ($stmt->execute()) {
            return $this->conn->insert_id;
        } else {
            return false;
        }
    }
    
    // Cập nhật đáp án
    public function updateAnswer($answerId, $questionId, $data) {
        $allowedFields = ['answer_text', 'is_correct', 'position'];
        $updates = [];
        $types = "";
        $values = [];
        
        foreach ($data as $field => $value) {
            if (in_array($field, $allowedFields)) {
                $updates[] = "$field = ?";
                if ($field == 'is_correct' || $field == 'position') {
                    $types .= "i";
                } else {
                    $types .= "s";
                }
                $values[] = $value;
            }
        }
        
        if (empty($updates)) {
            return false;
        }
        
        $sql = "UPDATE answers SET " . implode(", ", $updates) . " WHERE id = ? AND question_id = ?";
        $types .= "ii";
        $values[] = $answerId;
        $values[] = $questionId;
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
        
        return $stmt->execute();
    }
    
    // Xóa đáp án
    public function deleteAnswer($answerId, $questionId) {
        $stmt = $this->conn->prepare("DELETE FROM answers WHERE id = ? AND question_id = ?");
        $stmt->bind_param("ii", $answerId, $questionId);
        
        return $stmt->execute();
    }
    
    // Lấy danh sách đáp án của câu hỏi
    public function getQuestionAnswers($questionId) {
        $stmt = $this->conn->prepare("SELECT * FROM answers WHERE question_id = ? ORDER BY position ASC");
        $stmt->bind_param("i", $questionId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $answers = [];
        while ($row = $result->fetch_assoc()) {
            $answers[] = $row;
        }
        
        return $answers;
    }
    
    // ===== GAME SESSION MANAGEMENT =====
    
    // Tạo phiên chơi mới
    public function createGameSession($quizId, $hostId) {
        // Tạo game code ngẫu nhiên 6 ký tự
        $characters = '0123456789abcdefghijklmnopqrstuvwxyz';
        $gamePin = '';
        for ($i = 0; $i < 6; $i++) {
            $gamePin .= $characters[rand(0, strlen($characters) - 1)];
        }
        
        // Kiểm tra xem code đã tồn tại chưa
        $checkStmt = $this->conn->prepare("SELECT id FROM game_sessions WHERE game_pin = ?");
        $checkStmt->bind_param("s", $gamePin);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        
        // Nếu code đã tồn tại, tạo code mới
        while ($checkResult->num_rows > 0) {
            $gamePin = '';
            for ($i = 0; $i < 6; $i++) {
                $gamePin .= $characters[rand(0, strlen($characters) - 1)];
            }
            $checkStmt->bind_param("s", $gamePin);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
        }
        
        $stmt = $this->conn->prepare("INSERT INTO game_sessions (quiz_id, host_id, game_pin, status) VALUES (?, ?, ?, 'waiting')");
        $stmt->bind_param("iis", $quizId, $hostId, $gamePin);
        
        if ($stmt->execute()) {
            // Tăng số lần chơi của quiz
            $updateQuizStmt = $this->conn->prepare("UPDATE quizzes SET play_count = play_count + 1 WHERE id = ?");
            $updateQuizStmt->bind_param("i", $quizId);
            $updateQuizStmt->execute();
            
            return [
                'session_id' => $this->conn->insert_id,
                'game_pin' => $gamePin
            ];
        } else {
            return false;
        }
    }
    
    // Cập nhật trạng thái phiên chơi
    public function updateGameSession($sessionId, $hostId, $data) {
        $allowedFields = ['status', 'current_question'];
        $updates = [];
        $types = "";
        $values = [];
        
        foreach ($data as $field => $value) {
            if (in_array($field, $allowedFields)) {
                $updates[] = "$field = ?";
                if ($field == 'current_question') {
                    $types .= "i";
                } else {
                    $types .= "s";
                }
                $values[] = $value;
            }
        }
        
        if (empty($updates)) {
            return false;
        }
        
        // Xử lý các trường đặc biệt
        if (isset($data['status'])) {
            if ($data['status'] == 'active' && !isset($data['started_at'])) {
                $updates[] = "started_at = NOW()";
            } elseif ($data['status'] == 'completed' && !isset($data['ended_at'])) {
                $updates[] = "ended_at = NOW()";
            }
        }
        
        $sql = "UPDATE game_sessions SET " . implode(", ", $updates) . " WHERE id = ? AND host_id = ?";
        $types .= "ii";
        $values[] = $sessionId;
        $values[] = $hostId;
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
        
        return $stmt->execute();
    }
    
    // Lấy thông tin phiên chơi
    public function getGameSession($sessionId) {
        $stmt = $this->conn->prepare("SELECT * FROM game_sessions WHERE id = ?");
        $stmt->bind_param("i", $sessionId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            return $result->fetch_assoc();
        }
        
        return false;
    }
    
    // Lấy phiên chơi theo game code
    public function getGameSessionByCode($gameCode) {
        $stmt = $this->conn->prepare("SELECT * FROM game_sessions WHERE game_pin = ?");
        $stmt->bind_param("s", $gameCode);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            return $result->fetch_assoc();
        }
        
        return false;
    }
    
    // ===== QUESTION STATE MANAGEMENT =====
    
    // Đặt trạng thái câu hỏi
    public function setQuestionState($gameSessionId, $questionId, $state) {
        // Kiểm tra xem đã có trạng thái cho câu hỏi này chưa
        $checkStmt = $this->conn->prepare("SELECT id FROM question_states WHERE game_session_id = ? AND question_id = ?");
        $checkStmt->bind_param("ii", $gameSessionId, $questionId);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        
        if ($checkResult->num_rows > 0) {
            // Cập nhật trạng thái hiện có
            $stateId = $checkResult->fetch_assoc()['id'];
            $updateStmt = $this->conn->prepare("UPDATE question_states SET state = ?, updated_at = NOW() WHERE id = ?");
            $updateStmt->bind_param("si", $state, $stateId);
            return $updateStmt->execute();
        } else {
            // Tạo trạng thái mới
            $insertStmt = $this->conn->prepare("INSERT INTO question_states (game_session_id, question_id, state) VALUES (?, ?, ?)");
            $insertStmt->bind_param("iis", $gameSessionId, $questionId, $state);
            return $insertStmt->execute();
        }
    }
    
    // Lấy trạng thái câu hỏi
    public function getQuestionState($gameSessionId, $questionId) {
        $stmt = $this->conn->prepare("SELECT * FROM question_states WHERE game_session_id = ? AND question_id = ?");
        $stmt->bind_param("ii", $gameSessionId, $questionId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            return $result->fetch_assoc();
        }
        
        return null;
    }
    
    // ===== PLAYER SESSION MANAGEMENT =====
    
    // Thêm người chơi vào phiên
    public function addPlayerToGame($gameSessionId, $nickname, $userId = null) {
        // Kiểm tra xem nickname đã tồn tại trong phiên chưa
        $checkStmt = $this->conn->prepare("SELECT id FROM player_sessions WHERE game_session_id = ? AND nickname = ?");
        $checkStmt->bind_param("is", $gameSessionId, $nickname);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        
        if ($checkResult->num_rows > 0) {
            return ['error' => 'Nickname đã được sử dụng trong phiên này'];
        }
        
        $stmt = $this->conn->prepare("INSERT INTO player_sessions (game_session_id, user_id, nickname) VALUES (?, ?, ?)");
        $stmt->bind_param("iis", $gameSessionId, $userId, $nickname);
        
        if ($stmt->execute()) {
            return [
                'player_session_id' => $this->conn->insert_id,
                'nickname' => $nickname
            ];
        } else {
            return false;
        }
    }
    
    // Cập nhật điểm và thứ hạng người chơi
    public function updatePlayerScore($playerSessionId, $score) {
        $sql = "UPDATE player_sessions SET score = ? WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $score, $playerSessionId);
        
        return $stmt->execute();
    }
    
    // Lấy danh sách người chơi trong phiên
    public function getGamePlayers($gameSessionId) {
        $stmt = $this->conn->prepare("SELECT * FROM player_sessions WHERE game_session_id = ? ORDER BY score DESC, joined_at ASC");
        $stmt->bind_param("i", $gameSessionId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $players = [];
        while ($row = $result->fetch_assoc()) {
            $players[] = $row;
        }
        
        return $players;
    }
    
    // ===== PLAYER ANSWER MANAGEMENT =====
    
    // Ghi nhận câu trả lời của người chơi
    public function recordPlayerAnswer($playerSessionId, $questionId, $answerId, $responseTimeMs = null) {
        // Kiểm tra xem câu trả lời có đúng không
        $isCorrect = 0;
        $pointsEarned = 0;
        
        $checkStmt = $this->conn->prepare("SELECT is_correct FROM answers WHERE id = ? AND question_id = ?");
        $checkStmt->bind_param("ii", $answerId, $questionId);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        
        if ($checkResult->num_rows === 1) {
            $answerData = $checkResult->fetch_assoc();
            $isCorrect = $answerData['is_correct'];
        }
        
        // Tính điểm dựa trên thời gian trả lời
        if ($isCorrect) {
            // Lấy thông tin câu hỏi để tính điểm
            $questionStmt = $this->conn->prepare("SELECT time_limit, points FROM questions WHERE id = ?");
            $questionStmt->bind_param("i", $questionId);
            $questionStmt->execute();
            $questionResult = $questionStmt->get_result();
            
            if ($questionResult->num_rows === 1) {
                $questionData = $questionResult->fetch_assoc();
                $timeLimit = $questionData['time_limit'] * 1000; // Chuyển sang milliseconds
                $maxPoints = $questionData['points'];
                
                // Công thức tính điểm: càng nhanh càng nhiều điểm
                if ($responseTimeMs !== null && $responseTimeMs <= $timeLimit) {
                    $timeRatio = 1 - ($responseTimeMs / $timeLimit);
                    $pointsEarned = round($maxPoints * (0.5 + 0.5 * $timeRatio));
                } else {
                    $pointsEarned = round($maxPoints * 0.5); // Nếu không có thời gian, cho 50% điểm
                }
            }
        }
        
        // Ghi nhận câu trả lời
        $stmt = $this->conn->prepare("INSERT INTO player_answers (player_session_id, question_id, answer_id, is_correct, points_earned, response_time_ms) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiiiii", $playerSessionId, $questionId, $answerId, $isCorrect, $pointsEarned, $responseTimeMs);
        
        if ($stmt->execute()) {
            // Cập nhật điểm cho người chơi
            $updateScoreStmt = $this->conn->prepare("UPDATE player_sessions SET score = score + ? WHERE id = ?");
            $updateScoreStmt->bind_param("ii", $pointsEarned, $playerSessionId);
            $updateScoreStmt->execute();
            
            // Cập nhật thứ hạng cho tất cả người chơi trong phiên
            $this->updatePlayerRankings($this->getGameSessionIdByPlayerSessionId($playerSessionId));
            
            return [
                'answer_id' => $this->conn->insert_id,
                'is_correct' => $isCorrect,
                'points_earned' => $pointsEarned
            ];
        } else {
            return false;
        }
    }
    
    // Lấy game_session_id từ player_session_id
    private function getGameSessionIdByPlayerSessionId($playerSessionId) {
        $stmt = $this->conn->prepare("SELECT game_session_id FROM player_sessions WHERE id = ?");
        $stmt->bind_param("i", $playerSessionId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            return $result->fetch_assoc()['game_session_id'];
        }
        
        return false;
    }
    
    // Cập nhật thứ hạng cho tất cả người chơi trong phiên
    private function updatePlayerRankings($gameSessionId) {
        // Lấy danh sách người chơi theo điểm giảm dần
        $stmt = $this->conn->prepare("SELECT id, score FROM player_sessions WHERE game_session_id = ? ORDER BY score DESC, joined_at ASC");
        $stmt->bind_param("i", $gameSessionId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $rank = 1;
        while ($player = $result->fetch_assoc()) {
            $updateStmt = $this->conn->prepare("UPDATE player_sessions SET rank = ? WHERE id = ?");
            $updateStmt->bind_param("ii", $rank, $player['id']);
            $updateStmt->execute();
            $rank++;
        }
        
        return true;
    }
    
    // Lấy câu trả lời của người chơi cho một câu hỏi
    public function getPlayerAnswerForQuestion($playerSessionId, $questionId) {
        $stmt = $this->conn->prepare("SELECT * FROM player_answers WHERE player_session_id = ? AND question_id = ?");
        $stmt->bind_param("ii", $playerSessionId, $questionId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            return $result->fetch_assoc();
        }
        
        return false;
    }
    
    // Lấy tất cả câu trả lời cho một câu hỏi trong phiên
    public function getAllAnswersForQuestion($gameSessionId, $questionId) {
        $sql = "SELECT pa.* FROM player_answers pa 
                JOIN player_sessions ps ON pa.player_session_id = ps.id 
                WHERE ps.game_session_id = ? AND pa.question_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $gameSessionId, $questionId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $answers = [];
        while ($row = $result->fetch_assoc()) {
            $answers[] = $row;
        }
        
        return $answers;
    }
    
    // ===== GAME RESULTS & STATISTICS =====
    
    // Lưu kết quả trò chơi
    public function saveGameResults($gameSessionId) {
        // Lấy thông tin người chơi
        $playersStmt = $this->conn->prepare("SELECT COUNT(*) as total_players, AVG(score) as average_score, MAX(score) as highest_score FROM player_sessions WHERE game_session_id = ?");
        $playersStmt->bind_param("i", $gameSessionId);
        $playersStmt->execute();
        $playersResult = $playersStmt->get_result();
        $playersData = $playersResult->fetch_assoc();
        
        // Lưu kết quả
        $stmt = $this->conn->prepare("INSERT INTO game_results (game_session_id, total_players, average_score, highest_score) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iddi", $gameSessionId, $playersData['total_players'], $playersData['average_score'], $playersData['highest_score']);
        
        if ($stmt->execute()) {
            // Cập nhật trạng thái phiên thành 'completed'
            $updateStmt = $this->conn->prepare("UPDATE game_sessions SET status = 'completed', ended_at = NOW() WHERE id = ?");
            $updateStmt->bind_param("i", $gameSessionId);
            $updateStmt->execute();
            
            return $this->conn->insert_id;
        } else {
            return false;
        }
    }
    
    // Lấy thống kê câu hỏi
    public function getQuestionStatistics($gameSessionId, $questionId) {
        // Tổng số người chơi
        $totalPlayersStmt = $this->conn->prepare("SELECT COUNT(*) as total FROM player_sessions WHERE game_session_id = ?");
        $totalPlayersStmt->bind_param("i", $gameSessionId);
        $totalPlayersStmt->execute();
        $totalPlayersResult = $totalPlayersStmt->get_result();
        $totalPlayers = $totalPlayersResult->fetch_assoc()['total'];
        
        // Số người trả lời đúng
        $correctAnswersStmt = $this->conn->prepare("SELECT COUNT(*) as correct FROM player_answers pa 
                                                   JOIN player_sessions ps ON pa.player_session_id = ps.id 
                                                   WHERE ps.game_session_id = ? AND pa.question_id = ? AND pa.is_correct = 1");
        $correctAnswersStmt->bind_param("ii", $gameSessionId, $questionId);
        $correctAnswersStmt->execute();
        $correctAnswersResult = $correctAnswersStmt->get_result();
        $correctAnswers = $correctAnswersResult->fetch_assoc()['correct'];
        
        // Thời gian trả lời trung bình
        $avgTimeStmt = $this->conn->prepare("SELECT AVG(response_time_ms) as avg_time FROM player_answers pa 
                                            JOIN player_sessions ps ON pa.player_session_id = ps.id 
                                            WHERE ps.game_session_id = ? AND pa.question_id = ?");
        $avgTimeStmt->bind_param("ii", $gameSessionId, $questionId);
        $avgTimeStmt->execute();
        $avgTimeResult = $avgTimeStmt->get_result();
        $avgTime = $avgTimeResult->fetch_assoc()['avg_time'];
        
        // Thống kê theo từng đáp án
        $answerStatsStmt = $this->conn->prepare("SELECT a.id, a.answer_text, COUNT(pa.id) as count 
                                               FROM answers a 
                                               LEFT JOIN player_answers pa ON a.id = pa.answer_id 
                                               LEFT JOIN player_sessions ps ON pa.player_session_id = ps.id AND ps.game_session_id = ? 
                                               WHERE a.question_id = ? 
                                               GROUP BY a.id");
        $answerStatsStmt->bind_param("ii", $gameSessionId, $questionId);
        $answerStatsStmt->execute();
        $answerStatsResult = $answerStatsStmt->get_result();
        
        $answerStats = [];
        while ($row = $answerStatsResult->fetch_assoc()) {
            $answerStats[] = $row;
        }
        
        return [
            'total_players' => $totalPlayers,
            'correct_answers' => $correctAnswers,
            'correct_percentage' => ($totalPlayers > 0) ? round(($correctAnswers / $totalPlayers) * 100, 2) : 0,
            'avg_response_time_ms' => $avgTime,
            'answer_stats' => $answerStats
        ];
    }
    
    // Lấy kết quả trò chơi
    public function getGameResults($gameSessionId) {
        $stmt = $this->conn->prepare("SELECT * FROM game_results WHERE game_session_id = ?");
        $stmt->bind_param("i", $gameSessionId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            return $result->fetch_assoc();
        }
        
        return false;
    }
    
    // Lấy lịch sử trò chơi của người dùng
    public function getUserGameHistory($userId) {
        $sql = "SELECT gs.*, q.title as quiz_title, q.description as quiz_description, 
                ps.score, ps.rank, COUNT(pss.id) as total_players 
                FROM game_sessions gs 
                JOIN quizzes q ON gs.quiz_id = q.id 
                LEFT JOIN player_sessions ps ON gs.id = ps.game_session_id AND ps.user_id = ? 
                LEFT JOIN player_sessions pss ON gs.id = pss.game_session_id 
                WHERE gs.host_id = ? OR ps.id IS NOT NULL 
                GROUP BY gs.id 
                ORDER BY gs.created_at DESC";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $userId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $history = [];
        while ($row = $result->fetch_assoc()) {
            $history[] = $row;
        }
        
        return $history;
    }
}

// Khởi tạo đối tượng KahootDB
$kahootDB = new KahootDB($conn);

// Tạo bảng question_states nếu chưa tồn tại
$createQuestionStatesTable = "CREATE TABLE IF NOT EXISTS question_states (
    id INT AUTO_INCREMENT PRIMARY KEY,
    game_session_id INT NOT NULL,
    question_id INT NOT NULL,
    state VARCHAR(50) NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (game_session_id) REFERENCES game_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE,
    UNIQUE KEY unique_question_state (game_session_id, question_id)
)";

$conn->query($createQuestionStatesTable);
?>
