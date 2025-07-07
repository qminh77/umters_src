<?php
ob_start();
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../db_config.php';

// Check login
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Create table for browser profiles
$sql_browser_profiles = "CREATE TABLE IF NOT EXISTS browser_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(50) NOT NULL,
    icon VARCHAR(50) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
)";
mysqli_query($conn, $sql_browser_profiles) or die("Error creating browser_profiles: " . mysqli_error($conn));

// Create table for browsing history
$sql_browsing_history = "CREATE TABLE IF NOT EXISTS browsing_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    profile_id INT NOT NULL,
    url VARCHAR(255) NOT NULL,
    title VARCHAR(255) DEFAULT NULL,
    visited_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (profile_id) REFERENCES browser_profiles(id)
)";
mysqli_query($conn, $sql_browsing_history) or die("Error creating browsing_history: " . mysqli_error($conn));

// Handle profile operations
$success_message = '';
$error_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'create_profile') {
        $name = mysqli_real_escape_string($conn, $_POST['name']);
        $icon = mysqli_real_escape_string($conn, $_POST['icon']);
        
        $sql = "INSERT INTO browser_profiles (user_id, name, icon) VALUES (?, ?, ?)";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "iss", $user_id, $name, $icon);
        
        if (mysqli_stmt_execute($stmt)) {
            $success_message = "Created new browser profile!";
        } else {
            $error_message = "Error creating profile: " . mysqli_error($conn);
        }
    }
    
    if ($_POST['action'] === 'edit_profile') {
        $profile_id = (int)$_POST['profile_id'];
        $name = mysqli_real_escape_string($conn, $_POST['name']);
        $icon = mysqli_real_escape_string($conn, $_POST['icon']);
        
        $sql = "UPDATE browser_profiles SET name = ?, icon = ? WHERE id = ? AND user_id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ssii", $name, $icon, $profile_id, $user_id);
        
        if (mysqli_stmt_execute($stmt)) {
            $success_message = "Updated browser profile!";
        } else {
            $error_message = "Error updating profile: " . mysqli_error($conn);
        }
    }
    
    if ($_POST['action'] === 'delete_profile') {
        $profile_id = (int)$_POST['profile_id'];
        
        // Delete associated history
        $sql_history = "DELETE FROM browsing_history WHERE profile_id = ? AND user_id = ?";
        $stmt_history = mysqli_prepare($conn, $sql_history);
        mysqli_stmt_bind_param($stmt_history, "ii", $profile_id, $user_id);
        mysqli_stmt_execute($stmt_history);
        
        // Delete profile
        $sql = "DELETE FROM browser_profiles WHERE id = ? AND user_id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ii", $profile_id, $user_id);
        
        if (mysqli_stmt_execute($stmt)) {
            echo json_encode(['success' => true]);
            exit;
        } else {
            echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
            exit;
        }
    }
    
    if ($_POST['action'] === 'add_history') {
        $profile_id = (int)$_POST['profile_id'];
        $url = mysqli_real_escape_string($conn, $_POST['url']);
        $title = mysqli_real_escape_string($conn, $_POST['title']);
        
        $sql = "INSERT INTO browsing_history (user_id, profile_id, url, title) VALUES (?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "iiss", $user_id, $profile_id, $url, $title);
        
        if (mysqli_stmt_execute($stmt)) {
            echo json_encode(['success' => true]);
            exit;
        } else {
            echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
            exit;
        }
    }
}

// Get user's browser profiles
$sql = "SELECT * FROM browser_profiles WHERE user_id = ?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$profiles_result = mysqli_stmt_get_result($stmt);
$profiles = [];
while ($profile = mysqli_fetch_assoc($profiles_result)) {
    $profiles[] = $profile;
}

// Get browsing history for each profile
$history = [];
foreach ($profiles as $profile) {
    $sql = "SELECT * FROM browsing_history WHERE profile_id = ? AND user_id = ? ORDER BY visited_at DESC LIMIT 50";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ii", $profile['id'], $user_id);
    mysqli_stmt_execute($stmt);
    $history_result = mysqli_stmt_get_result($stmt);
    $history[$profile['id']] = [];
    while ($entry = mysqli_fetch_assoc($history_result)) {
        $history[$profile['id']][] = $entry;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Browser Tabs</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #7000FF;
            --primary-light: #9D50FF;
            --primary-dark: #4A00B0;
            --secondary: #00E0FF;
            --secondary-light: #70EFFF;
            --secondary-dark: #00B0C7;
            --accent: #FF3DFF;
            --accent-light: #FF7DFF;
            --accent-dark: #C700C7;
            --danger: #FF3D57;
            --danger-light: #FF5D77;
            --danger-dark: #E01F3D;
            --success: #00E080;
            --warning: #FFB800;
            --background: #0A0A1A;
            --surface: #12122A;
            --surface-light: #1A1A3A;
            --foreground: #FFFFFF;
            --foreground-muted: rgba(255, 255, 255, 0.7);
            --foreground-subtle: rgba(255, 255, 255, 0.5);
            --card: rgba(30, 30, 60, 0.6);
            --card-hover: rgba(40, 40, 80, 0.8);
            --card-active: rgba(50, 50, 100, 0.9);
            --border: rgba(255, 255, 255, 0.1);
            --border-light: rgba(255, 255, 255, 0.05);
            --shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            --shadow-sm: 0 4px 16px rgba(0, 0, 0, 0.2);
            --shadow-lg: 0 16px 48px rgba(0, 0, 0, 0.4);
            --glow: 0 0 20px rgba(112, 0, 255, 0.5);
            --glow-accent: 0 0 20px rgba(255, 61, 255, 0.5);
            --glow-secondary: 0 0 20px rgba(0, 224, 255, 0.5);
            --glow-danger: 0 0 20px rgba(255, 61, 87, 0.5);
            --radius-sm: 0.75rem;
            --radius: 1.5rem;
            --radius-lg: 2rem;
            --radius-xl: 3rem;
            --radius-full: 9999px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Outfit', sans-serif;
            background-color: var(--background);
            color: var(--foreground);
            min-height: 100vh;
            line-height: 1.6;
            overflow-x: hidden;
            background-image: 
                radial-gradient(circle at 20% 30%, rgba(112, 0, 255, 0.15) 0%, transparent 40%),
                radial-gradient(circle at 80% 70%, rgba(0, 224, 255, 0.15) 0%, transparent 40%),
                radial-gradient(circle at 50% 50%, rgba(255, 61, 255, 0.1) 0%, transparent 60%);
            background-attachment: fixed;
        }

        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: var(--surface);
            border-radius: var(--radius-full);
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(to bottom, var(--primary), var(--secondary));
            border-radius: var(--radius-full);
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(to bottom, var(--primary-light), var(--secondary-light));
        }

        .browser-container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 1.5rem;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding: 1.5rem 2rem;
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, var(--primary), var(--secondary), var(--accent), var(--primary));
            background-size: 300% 100%;
            animation: gradientBorder 3s linear infinite;
        }

        @keyframes gradientBorder {
            0% { background-position: 0% 0%; }
            100% { background-position: 300% 0%; }
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 700;
            background: linear-gradient(to right, var(--secondary), var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .page-title i {
            font-size: 1.5rem;
            color: var(--primary-light);
        }

        .browser-sidebar {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            height: 100%;
            margin-bottom: 1.5rem;
        }

        .sidebar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .sidebar-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--foreground);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .sidebar-title i {
            color: var(--primary-light);
        }

        .add-profile-btn {
            padding: 0.75rem 1.25rem;
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            color: white;
            border: none;
            border-radius: var(--radius);
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .add-profile-btn:hover {
            background: linear-gradient(90deg, var(--primary-light), var(--primary));
            transform: translateY(-2px);
            box-shadow: var(--glow);
            color: white;
        }

        .profile-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .profile-item {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
        }

        .profile-item:hover {
            background: rgba(255, 255, 255, 0.08);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
            border-color: var(--primary-light);
        }

        .profile-item.active {
            background: rgba(112, 0, 255, 0.1);
            border-color: var(--primary-light);
        }

        .profile-actions {
            display: flex;
            gap: 0.5rem;
            position: absolute;
            right: 1rem;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .profile-item:hover .profile-actions {
            opacity: 1;
        }

        .profile-action {
            padding: 0.25rem 0.5rem;
            border-radius: var(--radius-sm);
            cursor: pointer;
            color: var(--foreground-muted);
            transition: all 0.3s ease;
        }

        .profile-action.edit {
            background: rgba(0, 224, 255, 0.1);
        }

        .profile-action.edit:hover {
            background: rgba(0, 224, 255, 0.2);
            color: var(--secondary);
        }

        .profile-action.delete {
            background: rgba(255, 61, 87, 0.1);
        }

        .profile-action.delete:hover {
            background: rgba(255, 61, 87, 0.2);
            color: var(--danger);
        }

        .profile-icon {
            width: 40px;
            height: 40px;
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            background: var(--primary);
        }

        .profile-info {
            flex: 1;
        }

        .profile-name {
            font-weight: 500;
            color: var(--foreground);
            margin-bottom: 0.25rem;
        }

        .profile-count {
            font-size: 0.75rem;
            color: var(--foreground-muted);
        }

        .browser-container-main {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            height: 100%;
            margin-bottom: 1.5rem;
        }

        .browser-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .browser-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--foreground);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .browser-title i {
            color: var(--primary-light);
        }

        .browser-mode-selector {
            display: flex;
            gap: 0.5rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: var(--radius);
            padding: 0.25rem;
        }

        .mode-btn {
            padding: 0.5rem 1rem;
            border: none;
            background: transparent;
            color: var(--foreground-muted);
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .mode-btn.active {
            background: var(--primary);
            color: white;
            box-shadow: var(--glow);
        }

        .mode-btn:hover:not(.active) {
            background: rgba(255, 255, 255, 0.1);
            color: var(--foreground);
        }

        .url-bar {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .url-input {
            flex: 1;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            color: var(--foreground);
            padding: 0.75rem 1rem;
            transition: all 0.3s ease;
        }

        .url-input:focus {
            background: rgba(255, 255, 255, 0.08);
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(112, 0, 255, 0.2);
            outline: none;
        }

        .go-btn {
            padding: 0.75rem 1.25rem;
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            color: white;
            border: none;
            border-radius: var(--radius);
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
        }

        .go-btn:hover {
            background: linear-gradient(90deg, var(--primary-light), var(--primary));
            transform: translateY(-2px);
            box-shadow: var(--glow);
        }

        .browser-iframe {
            width: 100%;
            height: 600px;
            border: none;
            border-radius: var(--radius);
            background: white;
            position: relative;
        }

        .iframe-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(30, 30, 60, 0.9);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius);
            z-index: 10;
        }

        .iframe-overlay.hidden {
            display: none;
        }

        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 3px solid var(--border);
            border-top: 3px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 1rem;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .error-message {
            color: var(--danger);
            text-align: center;
            margin-bottom: 1rem;
        }

        .retry-btn {
            padding: 0.5rem 1rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .retry-btn:hover {
            background: var(--primary-light);
        }

        .history-container {
            margin-top: 1.5rem;
        }

        .history-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--foreground);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .history-title i {
            color: var(--primary-light);
        }

        .history-list {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            max-height: 300px;
            overflow-y: auto;
        }

        .history-item {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 0.75rem 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .history-item:hover {
            background: rgba(255, 255, 255, 0.08);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .history-icon {
            color: var(--primary-light);
        }

        .history-info {
            flex: 1;
        }

        .history-title-text {
            font-size: 0.875rem;
            color: var(--foreground);
            margin-bottom: 0.25rem;
        }

        .history-url {
            font-size: 0.75rem;
            color: var(--foreground-muted);
            word-break: break-all;
        }

        .modal-content {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            color: var(--foreground);
        }

        .modal-header {
            border-bottom: 1px solid var(--border);
            padding: 1.5rem;
        }

        .modal-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--foreground);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .modal-title i {
            color: var(--primary-light);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .form-label {
            color: var(--foreground);
            font-weight: 500;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-label i {
            color: var(--primary-light);
        }

        .form-control {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            color: var(--foreground);
            padding: 0.75rem 1rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            background: rgba(255, 255, 255, 0.08);
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(112, 0, 255, 0.2);
            outline: none;
        }

        .btn-close {
            filter: invert(1) grayscale(100%) brightness(200%);
        }

        .alert-container {
            position: fixed;
            top: 2rem;
            right: 2rem;
            z-index: 1060;
        }

        .alert-message {
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1rem;
            box-shadow: var(--shadow);
            transform: translateX(100%);
            opacity: 0;
            animation: slideIn 0.3s forwards, fadeOut 0.3s 3s forwards;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        @keyframes slideIn {
            to { transform: translateX(0); opacity: 1; }
        }

        @keyframes fadeOut {
            to { opacity: 0; visibility: hidden; }
        }

        .alert-success {
            background: rgba(0, 224, 0, 0.1);
            border: 1px solid rgba(0, 224, 0, 0.3);
            color: #4ADE80;
        }

        .alert-error {
            background: rgba(255, 61, 87, 0.1);
            border: 1px solid rgba(255, 61, 87, 0.3);
            color: #FF5D77;
        }

        .alert-warning {
            background: rgba(255, 184, 0, 0.1);
            border: 1px solid rgba(255, 184, 0, 0.3);
            color: #FFB800;
        }

        .particles {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 0;
        }

        .particle {
            position: absolute;
            border-radius: 50%;
            opacity: 0.3;
            pointer-events: none;
        }

        .icon-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(60px, 1fr));
            gap: 10px;
            max-height: 400px;
            overflow-y: auto;
            padding: 10px;
        }

        .icon-item {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 60px;
            height: 60px;
            border-radius: var(--radius);
            background: rgba(255, 255, 255, 0.05);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .icon-item:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .icon-item.selected {
            background: var(--primary);
            color: white;
        }

        .status-indicator {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            margin-bottom: 1rem;
        }

        .status-indicator.iframe {
            background: rgba(0, 224, 128, 0.1);
            border: 1px solid rgba(0, 224, 128, 0.3);
            color: var(--success);
        }

        .status-indicator.proxy {
            background: rgba(255, 184, 0, 0.1);
            border: 1px solid rgba(255, 184, 0, 0.3);
            color: var(--warning);
        }

        .status-indicator.external {
            background: rgba(255, 184, 0, 0.1);
            border: 1px solid rgba(255, 184, 0, 0.3);
            color: var(--warning);
        }

        .status-indicator.popup {
            background: rgba(0, 224, 255, 0.1);
            border: 1px solid rgba(0, 224, 255, 0.3);
            color: var(--secondary);
        }

        @media (max-width: 992px) {
            .browser-container {
                padding: 0 1rem;
            }

            .page-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
                padding: 1.25rem;
            }

            .browser-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
        }

        @media (max-width: 576px) {
            .page-title {
                font-size: 1.5rem;
            }

            .add-profile-btn {
                padding: 0.5rem 1rem;
            }

            .url-bar {
                flex-direction: column;
            }

            .go-btn {
                width: 100%;
            }

            .browser-mode-selector {
                width: 100%;
                justify-content: space-between;
            }
        }
    </style>
</head>
<body>
    <div class="particles" id="particles"></div>

    <div class="alert-container" id="alertContainer"></div>

    <div class="browser-container">
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-globe"></i>
                Browser Tabs
            </h1>
            <a href="dashboard.php" class="add-profile-btn">
                <i class="fas fa-arrow-left"></i>
                Back to Dashboard
            </a>
        </div>

        <div class="row">
            <div class="col-lg-4 col-md-6">
                <div class="browser-sidebar">
                    <div class="sidebar-header">
                        <h2 class="sidebar-title">
                            <i class="fas fa-user"></i>
                            Browser Profiles
                        </h2>
                        <button class="add-profile-btn" data-bs-toggle="modal" data-bs-target="#addProfileModal">
                            <i class="fas fa-plus"></i>
                            New Profile
                        </button>
                    </div>
                    
                    <div class="profile-list">
                        <?php foreach ($profiles as $profile): ?>
                        <div class="profile-item" data-profile-id="<?php echo $profile['id']; ?>">
                            <div class="profile-icon">
                                <?php if ($profile['icon']): ?>
                                    <i class="<?php echo htmlspecialchars($profile['icon']); ?>"></i>
                                <?php else: ?>
                                    <i class="fas fa-user"></i>
                                <?php endif; ?>
                            </div>
                            <div class="profile-info">
                                <div class="profile-name"><?php echo htmlspecialchars($profile['name']); ?></div>
                                <div class="profile-count">
                                    <?php echo count($history[$profile['id']]) . " history entries"; ?>
                                </div>
                            </div>
                            <div class="profile-actions">
                                <i class="fas fa-edit profile-action edit" data-bs-toggle="modal" data-bs-target="#editProfileModal" data-id="<?php echo $profile['id']; ?>" data-name="<?php echo htmlspecialchars($profile['name']); ?>" data-icon="<?php echo htmlspecialchars($profile['icon']); ?>"></i>
                                <i class="fas fa-trash-alt profile-action delete" data-id="<?php echo $profile['id']; ?>"></i>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-8 col-md-6">
                <div class="browser-container-main">
                    <div class="browser-header">
                        <h2 class="browser-title">
                            <i class="fas fa-globe"></i>
                            Embedded Browser
                        </h2>
                        <div class="browser-mode-selector">
                            <button class="mode-btn active" data-mode="iframe">
                                <i class="fas fa-window-maximize"></i> Direct
                            </button>
                            <button class="mode-btn" data-mode="proxy">
                                <i class="fas fa-shield-alt"></i> Server Proxy
                            </button>
                            <button class="mode-btn" data-mode="external">
                                <i class="fas fa-globe"></i> External Proxy
                            </button>
                            <button class="mode-btn" data-mode="popup">
                                <i class="fas fa-external-link-alt"></i> Popup
                            </button>
                        </div>
                    </div>

                    <div class="status-indicator iframe" id="statusIndicator">
                        <i class="fas fa-info-circle"></i>
                        <span>Direct mode - Loading websites directly in iframe</span>
                        <a href="proxy_status.php" style="margin-left: auto; color: var(--primary-light); text-decoration: none;">
                            <i class="fas fa-chart-line"></i> Proxy Status
                        </a>
                    </div>
                    
                    <div class="url-bar">
                        <input type="text" class="url-input" id="urlInput" placeholder="Enter URL (e.g., https://example.com)">
                        <button class="go-btn" id="goBtn">Go</button>
                    </div>

                    <div style="position: relative;">
                        <iframe class="browser-iframe" id="browserIframe" sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-downloads"></iframe>
                        <div class="iframe-overlay hidden" id="iframeOverlay">
                            <div class="loading-spinner" id="loadingSpinner"></div>
                            <div class="error-message" id="errorMessage"></div>
                            <button class="retry-btn hidden" id="retryBtn">Try External Proxy</button>
                        </div>
                    </div>

                    <div class="history-container">
                        <h3 class="history-title">
                            <i class="fas fa-history"></i>
                            Browsing History
                        </h3>
                        <div class="history-list" id="historyList"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modals -->
    <div class="modal fade" id="addProfileModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus-circle"></i>
                        New Profile
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="addProfileForm">
                        <input type="hidden" name="action" value="create_profile">
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-tag"></i>
                                Profile Name
                            </label>
                            <input type="text" class="form-control" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-icons"></i>
                                Icon
                            </label>
                            <div class="input-group">
                                <input type="text" class="form-control" name="icon" id="selectedIcon" placeholder="fas fa-user" readonly>
                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#iconPickerModal">
                                    <i class="fas fa-icons"></i> Pick Icon
                                </button>
                            </div>
                            <div id="iconPreview" class="mt-2 text-center" style="font-size: 2rem;"></div>
                        </div>
                        <button type="submit" class="add-profile-btn w-100">
                            <i class="fas fa-plus"></i>
                            Create Profile
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editProfileModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit"></i>
                        Edit Profile
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="editProfileForm">
                        <input type="hidden" name="action" value="edit_profile">
                        <input type="hidden" name="profile_id" id="editProfileId">
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-tag"></i>
                                Profile Name
                            </label>
                            <input type="text" class="form-control" name="name" id="editProfileName" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-icons"></i>
                                Icon
                            </label>
                            <div class="input-group">
                                <input type="text" class="form-control" name="icon" id="editSelectedIcon" readonly>
                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#iconPickerModalEdit">
                                    <i class="fas fa-icons"></i> Pick Icon
                                </button>
                            </div>
                            <div id="editIconPreview" class="mt-2 text-center" style="font-size: 2rem;"></div>
                        </div>
                        <button type="submit" class="add-profile-btn w-100">
                            <i class="fas fa-save"></i>
                            Save Changes
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="iconPickerModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-icons"></i>
                        Pick Icon
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <input type="text" class="form-control" id="iconSearch" placeholder="Search icons...">
                    </div>
                    <div class="icon-grid" id="iconGrid"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="iconPickerModalEdit" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-icons"></i>
                        Pick Icon
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <input type="text" class="form-control" id="iconSearchEdit" placeholder="Search icons...">
                    </div>
                    <div class="icon-grid" id="iconGridEdit"></div>
                </div>
            </div>
        </div>
    </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        let currentProfileId = null;
        let currentMode = 'iframe';
        let authWindow = null;
        let loadTimeout = null;

        // Show alerts
        <?php if ($success_message): ?>
            showAlert('<?php echo addslashes($success_message); ?>', 'success');
        <?php endif; ?>
        
        <?php if ($error_message): ?>
            showAlert('<?php echo addslashes($error_message); ?>', 'error');
        <?php endif; ?>

        // Initialize particles
        createParticles();

        // Initialize icon picker
        initializeIconPicker();

        // Handle mode selection
        document.querySelectorAll('.mode-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.mode-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                currentMode = this.getAttribute('data-mode');
                updateStatusIndicator();
            });
        });

        // Update status indicator
        function updateStatusIndicator() {
            const indicator = document.getElementById('statusIndicator');
            const icon = indicator.querySelector('i');
            const text = indicator.querySelector('span');
            
            indicator.className = 'status-indicator ' + currentMode;
            
            switch(currentMode) {
                case 'iframe':
                    icon.className = 'fas fa-window-maximize';
                    text.textContent = 'Direct mode - Loading websites directly in iframe';
                    break;
                case 'proxy':
                    icon.className = 'fas fa-shield-alt';
                    text.textContent = 'Server Proxy mode - Using your server as proxy';
                    break;
                case 'external':
                    icon.className = 'fas fa-globe';
                    text.textContent = 'External Proxy mode - Using free public proxies (⚠️ Security Risk)';
                    break;
                case 'popup':
                    icon.className = 'fas fa-external-link-alt';
                    text.textContent = 'Popup mode - Opening websites in new windows';
                    break;
            }
        }

        // Handle profile selection
        document.querySelectorAll('.profile-item').forEach(item => {
            item.addEventListener('click', function(e) {
                if (e.target.classList.contains('profile-action')) return;
                document.querySelectorAll('.profile-item').forEach(i => i.classList.remove('active'));
                this.classList.add('active');
                currentProfileId = this.getAttribute('data-profile-id');
                loadProfileHistory(currentProfileId);
                loadLastUrl();
            });
        });

        // Handle edit profile
        document.querySelectorAll('.profile-action.edit').forEach(btn => {
            btn.addEventListener('click', function() {
                const profileId = this.getAttribute('data-id');
                const name = this.getAttribute('data-name');
                const icon = this.getAttribute('data-icon');
                
                document.getElementById('editProfileId').value = profileId;
                document.getElementById('editProfileName').value = name;
                document.getElementById('editSelectedIcon').value = icon;
                document.getElementById('editIconPreview').innerHTML = icon ? `<i class="${icon}"></i>` : '';
            });
        });

        // Handle delete profile
        document.querySelectorAll('.profile-action.delete').forEach(btn => {
            btn.addEventListener('click', function() {
                if (confirm('Are you sure you want to delete this profile?')) {
                    const profileId = this.getAttribute('data-id');
                    
                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', window.location.href, true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onload = function() {
                        if (xhr.status === 200) {
                            try {
                                const response = JSON.parse(xhr.responseText);
                                if (response.success) {
                                    showAlert('Profile deleted successfully!', 'success');
                                    setTimeout(() => {
                                        window.location.reload();
                                    }, 1000);
                                } else {
                                    showAlert(response.error, 'error');
                                }
                            } catch (e) {
                                showAlert('An error occurred, please try again!', 'error');
                            }
                        }
                    };
                    xhr.send(`action=delete_profile&profile_id=${profileId}`);
                }
            });
        });

        // Websites known to block iframe embedding
        const blockedSites = [
            'login.microsoftonline.com',
            'accounts.google.com',
            'www.facebook.com',
            'login.yahoo.com',
            'signin.aws.amazon.com',
            'github.com/login',
            'twitter.com',
            'linkedin.com'
        ];

        // Check if URL is likely to be blocked
        function isLikelyBlocked(url) {
            try {
                const urlObj = new URL(url);
                return blockedSites.some(blocked => urlObj.hostname.includes(blocked));
            } catch {
                return false;
            }
        }

        // Check if URL is likely an authentication page
        function isAuthUrl(url) {
            const authPatterns = [
                /login/i,
                /auth/i,
                /signin/i,
                /sign-in/i,
                /log-in/i,
                /oauth/i,
                /sso/i
            ];
            return authPatterns.some(pattern => pattern.test(url)) || isLikelyBlocked(url);
        }

        // Show loading overlay
        function showLoading(message = 'Loading...') {
            const overlay = document.getElementById('iframeOverlay');
            const spinner = document.getElementById('loadingSpinner');
            const errorMsg = document.getElementById('errorMessage');
            const retryBtn = document.getElementById('retryBtn');
            
            overlay.classList.remove('hidden');
            spinner.classList.remove('hidden');
            errorMsg.textContent = message;
            errorMsg.style.color = 'var(--foreground-muted)';
            retryBtn.classList.add('hidden');
        }

        // Show error overlay
        function showError(message, showRetry = true) {
            const overlay = document.getElementById('iframeOverlay');
            const spinner = document.getElementById('loadingSpinner');
            const errorMsg = document.getElementById('errorMessage');
            const retryBtn = document.getElementById('retryBtn');
            
            overlay.classList.remove('hidden');
            spinner.classList.add('hidden');
            errorMsg.textContent = message;
            errorMsg.style.color = 'var(--danger)';
            if (showRetry) {
                retryBtn.classList.remove('hidden');
            } else {
                retryBtn.classList.add('hidden');
            }
        }

        // Hide overlay
        function hideOverlay() {
            const overlay = document.getElementById('iframeOverlay');
            overlay.classList.add('hidden');
        }

        // Load URL based on current mode
        function loadUrl(url) {
            if (!currentProfileId) {
                showAlert('Please select a profile first!', 'error');
                return;
            }

            // Ensure URL has protocol
            if (!url.startsWith('http://') && !url.startsWith('https://')) {
                url = 'https://' + url;
            }

            switch(currentMode) {
                case 'iframe':
                    loadInIframe(url);
                    break;
                case 'proxy':
                    loadWithProxy(url, false);
                    break;
                case 'external':
                    loadWithProxy(url, true);
                    break;
                case 'popup':
                    loadInPopup(url);
                    break;
            }
        }

        // Load URL in iframe
        function loadInIframe(url) {
            const iframe = document.getElementById('browserIframe');
            
            showLoading('Loading website...');
            
            // Set timeout for X-Frame-Options detection
            loadTimeout = setTimeout(() => {
                showError('Website blocked iframe embedding (X-Frame-Options)', true);
                showAlert('Website blocked direct embedding. Try External Proxy mode.', 'warning');
            }, 5000);

            iframe.onload = function() {
                clearTimeout(loadTimeout);
                hideOverlay();
                saveUrl(url);
            };

            iframe.onerror = function() {
                clearTimeout(loadTimeout);
                showError('Failed to load website', true);
            };

            iframe.src = url;
        }

        // Load URL with proxy
        function loadWithProxy(url, useExternal = false) {
            const iframe = document.getElementById('browserIframe');
            const externalParam = useExternal ? '&external=yes' : '';
            const proxyUrl = `proxy.php?url=${encodeURIComponent(url)}${externalParam}`;
            
            if (useExternal) {
                showLoading('Loading through external proxy...');
                showAlert('⚠️ Using external proxy - Data may be monitored by third parties!', 'warning');
            } else {
                showLoading('Loading through server proxy...');
            }

            loadTimeout = setTimeout(() => {
                showError('Proxy request timed out', false);
            }, 15000); // Longer timeout for external proxies

            iframe.onload = function() {
                clearTimeout(loadTimeout);
                hideOverlay();
                const proxyType = useExternal ? 'External Proxy' : 'Server Proxy';
                saveUrl(url, `Loaded via ${proxyType}`);
            };

            iframe.onerror = function() {
                clearTimeout(loadTimeout);
                showError('Proxy failed to load website', false);
            };

            iframe.src = proxyUrl;
        }

        // Load URL in popup
        function loadInPopup(url) {
            hideOverlay();
            authWindow = window.open(url, '_blank', 'width=1200,height=800,scrollbars=yes,resizable=yes');
            saveUrl(url, 'Opened in Popup');
            
            if (authWindow) {
                showAlert('Website opened in new window', 'success');
                
                // Check if popup is closed
                const checkClosed = setInterval(() => {
                    if (authWindow.closed) {
                        clearInterval(checkClosed);
                        showAlert('Popup window closed', 'success');
                        loadProfileHistory(currentProfileId);
                    }
                }, 1000);
            } else {
                showAlert('Popup blocked by browser. Please allow popups for this site.', 'error');
            }
        }

        // Handle retry button
        document.getElementById('retryBtn').addEventListener('click', function() {
            const url = document.getElementById('urlInput').value.trim();
            if (url) {
                // Switch to external proxy mode and retry
                document.querySelector('.mode-btn[data-mode="external"]').click();
                setTimeout(() => loadUrl(url), 100);
            }
        });

        // Handle URL navigation
        document.getElementById('goBtn').addEventListener('click', function() {
            const urlInput = document.getElementById('urlInput');
            let url = urlInput.value.trim();
            if (!url) return;

            // Auto-suggest external proxy mode for known blocked sites
            if (currentMode === 'iframe' && isLikelyBlocked(url)) {
                showAlert('This site likely blocks iframe embedding. Switching to External Proxy mode.', 'warning');
                document.querySelector('.mode-btn[data-mode="external"]').click();
                setTimeout(() => loadUrl(url), 100);
                return;
            }

            loadUrl(url);
        });

        // Handle Enter key in URL input
        document.getElementById('urlInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                document.getElementById('goBtn').click();
            }
        });

        // Handle history item click
        document.getElementById('historyList').addEventListener('click', function(e) {
            const item = e.target.closest('.history-item');
            if (item) {
                const url = item.getAttribute('data-url');
                document.getElementById('urlInput').value = url;
                loadUrl(url);
            }
        });

        // Listen for proxy load completion
        window.addEventListener('message', function(event) {
            if (event.data && event.data.type === 'proxy_loaded') {
                clearTimeout(loadTimeout);
                hideOverlay();
            }
        });

        // Save URL to history
        function saveUrl(url, title = null) {
            if (!title) {
                try {
                    const iframe = document.getElementById('browserIframe');
                    title = iframe.contentDocument ? iframe.contentDocument.title : new URL(url).hostname;
                } catch {
                    title = new URL(url).hostname;
                }
            }
            
            const xhr = new XMLHttpRequest();
            xhr.open('POST', window.location.href, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                if (xhr.status === 200) {
                    loadProfileHistory(currentProfileId);
                }
            };
            xhr.send(`action=add_history&profile_id=${currentProfileId}&url=${encodeURIComponent(url)}&title=${encodeURIComponent(title)}`);
            
            // Save to localStorage
            let localHistory = JSON.parse(localStorage.getItem(`history_${currentProfileId}`) || '[]');
            localHistory.unshift({ url, title, visited_at: new Date().toISOString() });
            localStorage.setItem(`history_${currentProfileId}`, JSON.stringify(localHistory));
        }

        // Load profile history
        function loadProfileHistory(profileId) {
            const historyList = document.getElementById('historyList');
            historyList.innerHTML = '';
            
            const history = <?php echo json_encode($history); ?>[profileId] || [];
            history.forEach(entry => {
                const div = document.createElement('div');
                div.className = 'history-item';
                div.setAttribute('data-url', entry.url);
                div.innerHTML = `
                    <i class="fas fa-link history-icon"></i>
                    <div class="history-info">
                        <div class="history-title-text">${entry.title || entry.url}</div>
                        <div class="history-url">${entry.url}</div>
                    </div>
                `;
                historyList.appendChild(div);
            });
        }

        // Load last URL from localStorage
        function loadLastUrl() {
            const lastUrl = localStorage.getItem(`last_url_${currentProfileId}`);
            if (lastUrl && !isAuthUrl(lastUrl)) {
                document.getElementById('urlInput').value = lastUrl;
                if (currentMode === 'iframe') {
                    loadInIframe(lastUrl);
                }
            }
        }

        // Show alert
        function showAlert(message, type = 'success') {
            const alertContainer = document.getElementById('alertContainer');
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert-message alert-${type}`;
            const iconClass = type === 'success' ? 'check-circle' : 
                             type === 'error' ? 'exclamation-circle' : 
                             type === 'warning' ? 'exclamation-triangle' : 'info-circle';
            alertDiv.innerHTML = `
                <i class="fas fa-${iconClass}"></i>
                ${message}
            `;
            alertContainer.appendChild(alertDiv);
            
            setTimeout(() => {
                alertDiv.remove();
            }, 4000);
        }

        // Initialize icon picker
        function initializeIconPicker() {
            const iconPickerModal = document.getElementById('iconPickerModal');
            const iconGrid = document.getElementById('iconGrid');
            const iconSearch = document.getElementById('iconSearch');
            const selectedIcon = document.getElementById('selectedIcon');
            const iconPreview = document.getElementById('iconPreview');
            
            const iconPickerModalEdit = document.getElementById('iconPickerModalEdit');
            const iconGridEdit = document.getElementById('iconGridEdit');
            const iconSearchEdit = document.getElementById('iconSearchEdit');
            const selectedIconEdit = document.getElementById('editSelectedIcon');
            const iconPreviewEdit = document.getElementById('editIconPreview');
            
            const commonIcons = [
                'fas fa-user', 'fas fa-globe', 'fas fa-laptop', 'fas fa-star', 'fas fa-heart',
                'fas fa-bookmark', 'fas fa-folder', 'fas fa-lock', 'fas fa-unlock', 'fas fa-key',
                'fas fa-shield-alt', 'fas fa-cog', 'fas fa-wrench', 'fas fa-tools', 'fas fa-desktop',
                'fas fa-mobile-alt', 'fas fa-tablet-alt', 'fas fa-gamepad', 'fas fa-music', 'fas fa-video'
            ];
            
            function populateIconGrid(icons, grid, selectCallback) {
                grid.innerHTML = '';
                icons.forEach(icon => {
                    const div = document.createElement('div');
                    div.className = 'icon-item';
                    div.innerHTML = `<i class="${icon}"></i>`;
                    div.onclick = () => selectCallback(icon);
                    grid.appendChild(div);
                });
            }
            
            function selectIcon(icon) {
                selectedIcon.value = icon;
                iconPreview.innerHTML = `<i class="${icon}"></i>`;
                bootstrap.Modal.getInstance(iconPickerModal).hide();
            }
            
            function selectIconEdit(icon) {
                selectedIconEdit.value = icon;
                iconPreviewEdit.innerHTML = `<i class="${icon}"></i>`;
                bootstrap.Modal.getInstance(iconPickerModalEdit).hide();
            }
            
            iconSearch.addEventListener('input', (e) => {
                const search = e.target.value.toLowerCase();
                const filteredIcons = commonIcons.filter(icon => 
                    icon.toLowerCase().includes(search)
                );
                populateIconGrid(filteredIcons, iconGrid, selectIcon);
            });
            
            iconSearchEdit.addEventListener('input', (e) => {
                const search = e.target.value.toLowerCase();
                const filteredIcons = commonIcons.filter(icon => 
                    icon.toLowerCase().includes(search)
                );
                populateIconGrid(filteredIcons, iconGridEdit, selectIconEdit);
            });
            
            populateIconGrid(commonIcons, iconGrid, selectIcon);
            populateIconGrid(commonIcons, iconGridEdit, selectIconEdit);
        }

        // Create particles
        function createParticles() {
            const particlesContainer = document.getElementById('particles');
            const particleCount = 50;
            
            for (let i = 0; i < particleCount; i++) {
                const particle = document.createElement('div');
                particle.classList.add('particle');
                
                const size = Math.random() * 5 + 1;
                particle.style.width = `${size}px`;
                particle.style.height = `${size}px`;
                
                const posX = Math.random() * 100;
                const posY = Math.random() * 100;
                particle.style.left = `${posX}%`;
                particle.style.top = `${posY}%`;
                
                const colors = ['#7000FF', '#00E0FF', '#FF3DFF'];
                const color = colors[Math.floor(Math.random() * colors.length)];
                particle.style.backgroundColor = color;
                particle.style.boxShadow = `0 0 ${size * 2}px ${color}`;
                
                const duration = Math.random() * 20 + 10;
                const delay = Math.random() * 10;
                
                particle.style.animation = `float ${duration}s ease-in-out ${delay}s infinite`;
                particle.style.opacity = 0;
                
                particlesContainer.appendChild(particle);
                
                setTimeout(() => {
                    particle.style.transition = 'opacity 1s ease';
                    particle.style.opacity = 0.3;
                    
                    setInterval(() => {
                        const newPosX = parseFloat(particle.style.left) + (Math.random() - 0.5) * 0.2;
                        const newPosY = parseFloat(particle.style.top) + (Math.random() - 0.5) * 0.2;
                        
                        if (newPosX >= 0 && newPosX <= 100) particle.style.left = `${newPosX}%`;
                        if (newPosY >= 0 && newPosY <= 100) particle.style.top = `${newPosY}%`;
                    }, 2000);
                }, delay * 1000);
            }
        }

        // Animation for elements
        function animateElements(selector, delay = 100) {
            const elements = document.querySelectorAll(selector);
            const observer = new IntersectionObserver((entries) => {
                entries.forEach((entry, index) => {
                    if (entry.isIntersecting) {
                        setTimeout(() => {
                            entry.target.style.opacity = '1';
                            entry.target.style.transform = 'translateY(0)';
                        }, index * delay);
                        observer.unobserve(entry.target);
                    }
                });
            });
            
            elements.forEach(el => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(20px)';
                el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                observer.observe(el);
            });
        }

        animateElements('.profile-item', 100);
        animateElements('.browser-container-main > *', 100);

        // Handle form submissions with AJAX
        document.getElementById('addProfileForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            const xhr = new XMLHttpRequest();
            xhr.open('POST', window.location.href, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    showAlert('Profile created successfully!', 'success');
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    showAlert('Error creating profile!', 'error');
                }
            };
            xhr.send(formData);
        });

        document.getElementById('editProfileForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            const xhr = new XMLHttpRequest();
            xhr.open('POST', window.location.href, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    showAlert('Profile updated successfully!', 'success');
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    showAlert('Error updating profile!', 'error');
                }
            };
            xhr.send(formData);
        });

        // Save last URL to localStorage
        document.getElementById('browserIframe').addEventListener('load', function() {
            const url = this.src;
            if (url && currentProfileId && url !== 'about:blank' && !url.includes('proxy.php')) {
                localStorage.setItem(`last_url_${currentProfileId}`, url);
            }
        });

        // Float animation
        document.head.appendChild(document.createElement('style')).textContent = `
            @keyframes float {
                0% { transform: translateY(0px); }
                50% { transform: translateY(-20px); }
                100% { transform: translateY(0px); }
            }
        `;
    });
</script>
</body>
</html>