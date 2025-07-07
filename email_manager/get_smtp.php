<?php
session_start();
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

include '../db_config.php';

// Validate and sanitize input
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($id === false || $id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid ID parameter']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Use prepared statement to prevent SQL injection
try {
    $stmt = $conn->prepare("SELECT id, smtp_name, smtp_host, smtp_port, smtp_username, smtp_password, smtp_encryption, from_name, from_email, is_default FROM smtp_configs WHERE id = ? AND user_id = ?");
    
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("ii", $id, $user_id);
    
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $config = $result->fetch_assoc();
        
        // Sanitize output data
        $response = [
            'success' => true,
            'smtp_name' => htmlspecialchars($config['smtp_name'], ENT_QUOTES, 'UTF-8'),
            'smtp_host' => htmlspecialchars($config['smtp_host'], ENT_QUOTES, 'UTF-8'),
            'smtp_port' => (int)$config['smtp_port'],
            'smtp_username' => htmlspecialchars($config['smtp_username'], ENT_QUOTES, 'UTF-8'),
            'smtp_password' => $config['smtp_password'], // Will be handled carefully in frontend
            'smtp_encryption' => htmlspecialchars($config['smtp_encryption'], ENT_QUOTES, 'UTF-8'),
            'from_name' => htmlspecialchars($config['from_name'], ENT_QUOTES, 'UTF-8'),
            'from_email' => htmlspecialchars($config['from_email'], ENT_QUOTES, 'UTF-8'),
            'is_default' => (bool)$config['is_default']
        ];
        
        echo json_encode($response);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Configuration not found']);
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    // Log error securely (don't expose to user)
    error_log("SMTP Config Error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>