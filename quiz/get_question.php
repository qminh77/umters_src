<?php
session_start();
include '../db_config.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];

// Kiểm tra ID câu hỏi
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid question ID']);
    exit;
}

$question_id = (int)$_GET['id'];

// Lấy thông tin câu hỏi
$sql = "SELECT q.* FROM quiz_questions q 
        JOIN quiz_sets s ON q.quiz_id = s.id 
        WHERE q.id = $question_id AND s.user_id = $user_id";
$result = mysqli_query($conn, $sql);

if (mysqli_num_rows($result) == 0) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Question not found']);
    exit;
}

$question = mysqli_fetch_assoc($result);

// Trả về thông tin câu hỏi dưới dạng JSON
header('Content-Type: application/json');
echo json_encode(['success' => true, 'question' => $question]);
exit;
?>
