<?php
require_once 'db_config.php';

// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Log file
define('LOG_FILE', 'telegram_bot.log');

// Function to log messages
function logMessage($message) {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message\n";
    file_put_contents(LOG_FILE, $logMessage, FILE_APPEND);
}

// Telegram Bot Token
define('BOT_TOKEN', '6838901745:AAF0FrJ60PO7vFJzjO-WpHOw6D_Ns0SOx7o');
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');

// Test bot connection
function testBotConnection() {
    $url = API_URL . "getMe";
    $result = file_get_contents($url);
    $response = json_decode($result, true);
    
    if ($response['ok']) {
        logMessage("Bot connection successful. Bot username: " . $response['result']['username']);
        return true;
    } else {
        logMessage("Bot connection failed: " . $response['description']);
        return false;
    }
}

// Function to send message to Telegram
function sendMessage($chat_id, $text, $reply_markup = null) {
    $url = API_URL . "sendMessage";
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    
    if ($reply_markup) {
        $data['reply_markup'] = json_encode($reply_markup);
    }
    
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data)
        ]
    ];
    
    $context = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    
    // Log the response
    logMessage("Send message to $chat_id: $text");
    logMessage("Response: $result");
    
    return $result;
}

// Function to handle /new command
function handleNewUser($chat_id, $params) {
    global $conn;
    
    if (count($params) < 4) {
        return sendMessage($chat_id, "❌ Invalid format. Use: /new username password full_name [email] [phone]");
    }
    
    $username = $params[0];
    $password = password_hash($params[1], PASSWORD_DEFAULT);
    $full_name = $params[2];
    $email = $params[3] ?? null;
    $phone = $params[4] ?? null;
    
    // Check if username exists
    $check = mysqli_query($conn, "SELECT id FROM users WHERE username = '$username'");
    if (mysqli_num_rows($check) > 0) {
        return sendMessage($chat_id, "❌ Username already exists!");
    }
    
    $sql = "INSERT INTO users (username, password, full_name, email, phone) 
            VALUES ('$username', '$password', '$full_name', '$email', '$phone')";
    
    if (mysqli_query($conn, $sql)) {
        return sendMessage($chat_id, "✅ User created successfully!");
    } else {
        return sendMessage($chat_id, "❌ Error creating user: " . mysqli_error($conn));
    }
}

// Function to handle /edit command
function handleEditUser($chat_id, $params) {
    global $conn;
    
    if (count($params) < 2) {
        return sendMessage($chat_id, "❌ Invalid format. Use: /edit username [field=value]");
    }
    
    $username = $params[0];
    $updates = [];
    
    for ($i = 1; $i < count($params); $i++) {
        $parts = explode('=', $params[$i]);
        if (count($parts) == 2) {
            $field = $parts[0];
            $value = $parts[1];
            $updates[] = "$field = '$value'";
        }
    }
    
    if (empty($updates)) {
        return sendMessage($chat_id, "❌ No valid fields to update");
    }
    
    $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE username = '$username'";
    
    if (mysqli_query($conn, $sql)) {
        return sendMessage($chat_id, "✅ User updated successfully!");
    } else {
        return sendMessage($chat_id, "❌ Error updating user: " . mysqli_error($conn));
    }
}

// Function to handle /delete command
function handleDeleteUser($chat_id, $params) {
    global $conn;
    
    if (count($params) != 1) {
        return sendMessage($chat_id, "❌ Invalid format. Use: /delete username");
    }
    
    $username = $params[0];
    
    $sql = "DELETE FROM users WHERE username = '$username'";
    
    if (mysqli_query($conn, $sql)) {
        return sendMessage($chat_id, "✅ User deleted successfully!");
    } else {
        return sendMessage($chat_id, "❌ Error deleting user: " . mysqli_error($conn));
    }
}

// Function to show user list for password change
function showUserListForPassword($chat_id) {
    global $conn;
    
    $result = mysqli_query($conn, "SELECT id, username, full_name FROM users ORDER BY username");
    $users = [];
    
    while ($row = mysqli_fetch_assoc($result)) {
        $users[] = [
            'text' => $row['username'] . ' (' . $row['full_name'] . ')',
            'callback_data' => 'change_pass_' . $row['id']
        ];
    }
    
    // Create keyboard with user buttons
    $keyboard = [];
    foreach (array_chunk($users, 2) as $chunk) {
        $keyboard[] = $chunk;
    }
    
    $reply_markup = [
        'inline_keyboard' => $keyboard
    ];
    
    return sendMessage($chat_id, "📋 Select a user to change password:", $reply_markup);
}

// Function to handle password change for selected user
function handlePasswordChange($chat_id, $user_id, $new_password) {
    global $conn;
    
    $new_password = password_hash($new_password, PASSWORD_DEFAULT);
    $sql = "UPDATE users SET password = '$new_password' WHERE id = '$user_id'";
    
    if (mysqli_query($conn, $sql)) {
        return sendMessage($chat_id, "✅ Password updated successfully!");
    } else {
        return sendMessage($chat_id, "❌ Error updating password: " . mysqli_error($conn));
    }
}

// Main webhook handler
$update = json_decode(file_get_contents('php://input'), true);

// Log the incoming update
logMessage("Received update: " . json_encode($update));

if (empty($update)) {
    logMessage("No update received. Testing bot connection...");
    testBotConnection();
    exit;
}

if (isset($update['message'])) {
    $message = $update['message'];
    $chat_id = $message['chat']['id'];
    $text = $message['text'];
    
    // Log the message
    logMessage("Received message from $chat_id: $text");
    
    // Parse command
    $parts = explode(' ', $text);
    $command = strtolower($parts[0]);
    $params = array_slice($parts, 1);
    
    switch ($command) {
        case '/new':
            handleNewUser($chat_id, $params);
            break;
        case '/edit':
            handleEditUser($chat_id, $params);
            break;
        case '/delete':
            handleDeleteUser($chat_id, $params);
            break;
        case '/edit_password':
            showUserListForPassword($chat_id);
            break;
        case '/test':
            testBotConnection();
            sendMessage($chat_id, "Bot connection test completed. Check log file for details.");
            break;
        default:
            sendMessage($chat_id, "❌ Unknown command. Available commands:\n/new username password full_name [email] [phone]\n/edit username [field=value]\n/delete username\n/edit_password\n/test");
    }
} elseif (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $chat_id = $callback['message']['chat']['id'];
    $data = $callback['data'];
    
    logMessage("Received callback query: $data");
    
    if (strpos($data, 'change_pass_') === 0) {
        $user_id = substr($data, 11);
        sendMessage($chat_id, "Please enter the new password for this user:");
        
        // Store the user_id in a session or database for the next message
        $sql = "INSERT INTO temp_password_changes (chat_id, user_id) VALUES ('$chat_id', '$user_id')";
        mysqli_query($conn, $sql);
    }
} elseif (isset($update['message']) && isset($update['message']['reply_to_message'])) {
    // Handle password input
    $chat_id = $update['message']['chat']['id'];
    $new_password = $update['message']['text'];
    
    // Get the user_id from temp storage
    $result = mysqli_query($conn, "SELECT user_id FROM temp_password_changes WHERE chat_id = '$chat_id'");
    if ($row = mysqli_fetch_assoc($result)) {
        $user_id = $row['user_id'];
        handlePasswordChange($chat_id, $user_id, $new_password);
        
        // Clean up temp storage
        mysqli_query($conn, "DELETE FROM temp_password_changes WHERE chat_id = '$chat_id'");
    }
}
?> 