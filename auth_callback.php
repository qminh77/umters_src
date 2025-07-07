<?php
ob_start();
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'db_config.php';

// Check login
if (!isset($_SESSION['user_id'])) {
    die("Unauthorized access");
}

$user_id = (int)$_SESSION['user_id'];

// Handle authentication callback
$profile_id = isset($_GET['state']) ? (int)$_GET['state'] : 0;
$token = isset($_GET['access_token']) ? mysqli_real_escape_string($conn, $_GET['access_token']) : '';
$code = isset($_GET['code']) ? mysqli_real_escape_string($conn, $_GET['code']) : '';
$error = isset($_GET['error']) ? mysqli_real_escape_string($conn, $_GET['error']) : '';

if ($error) {
    echo "<script>window.opener.postMessage({ type: 'auth_error', message: 'Authentication failed: $error' }, '*'); window.close();</script>";
    exit;
}

if ($token || $code) {
    $token_type = $token ? 'access_token' : 'auth_code';
    $token_value = $token ?: $code;
    
    $sql = "INSERT INTO auth_tokens (user_id, profile_id, token_type, token_value) VALUES (?, ?, ?, ?)";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "iiss", $user_id, $profile_id, $token_type, $token_value);
    
    if (mysqli_stmt_execute($stmt)) {
        echo "<script>
            window.opener.postMessage({ type: 'auth_success' }, '*');
            window.close();
        </script>";
    } else {
        echo "<script>window.opener.postMessage({ type: 'auth_error', message: 'Error saving token' }, '*'); window.close();</script>";
    }
} else {
    echo "<script>window.opener.postMessage({ type: 'auth_error', message: 'No token or code received' }, '*'); window.close();</script>";
}
?>