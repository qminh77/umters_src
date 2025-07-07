<?php
session_start();
require_once '../db_config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Check if presentation ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: presentation.php");
    exit();
}

$presentation_id = (int)$_GET['id'];

// Verify that the presentation belongs to the current user
$stmt = $conn->prepare("SELECT id FROM presentations WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $presentation_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: presentation.php");
    exit();
}

$stmt->close();

// Start a transaction
$conn->begin_transaction();

try {
    // End any active sessions
    $stmt = $conn->prepare("UPDATE presentation_sessions SET is_active = 0, ended_at = NOW() WHERE presentation_id = ? AND is_active = 1");
    $stmt->bind_param("i", $presentation_id);
    $stmt->execute();
    $stmt->close();
    
    // Delete the presentation (cascading will delete slides, images, sessions, and viewers)
    $stmt = $conn->prepare("DELETE FROM presentations WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $presentation_id, $user_id);
    $stmt->execute();
    $stmt->close();
    
    $conn->commit();
    
    header("Location: presentation.php");
    exit();
} catch (Exception $e) {
    $conn->rollback();
    echo "Error deleting presentation: " . $e->getMessage();
}
?>
