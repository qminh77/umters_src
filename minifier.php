<?php
session_start();

// Enable error reporting for debugging (disable in production)
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

// Check db_config.php file
if (!file_exists('db_config.php') || !is_readable('db_config.php')) {
    error_log("File db_config.php not found or not readable");
    die("System error: Cannot find or read database configuration file.");
}
include 'db_config.php';

// Check database connection
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    die("System error: Cannot connect to database.");
}

// Check login
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header("Location: /login");
    exit;
}

// Create CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get user_id from session
$user_id = (int)$_SESSION['user_id'];

// Initialize variables
$edit_error = '';
$message = '';
$result = '';

// Get user info safely
$stmt = $conn->prepare("SELECT username, full_name, is_main_admin, is_super_admin FROM users WHERE id = ?");
if (!$stmt) {
    error_log("Prepare failed: " . $conn->error);
    $edit_error = "Database connection error. Please try again later.";
} else {
    $stmt->bind_param("i", $user_id);
    if (!$stmt->execute()) {
        error_log("Execute failed: " . $stmt->error);
        $edit_error = "Database query error. Please try again later.";
    } else {
        $result_user = $stmt->get_result();
        if ($result_user && $result_user->num_rows > 0) {
            $user = $result_user->fetch_assoc();
        } else {
            $edit_error = "Error getting user information.";
            $user = [
                'username' => 'Unknown',
                'full_name' => '',
                'is_main_admin' => 0,
                'is_super_admin' => 0
            ];
        }
    }
    $stmt->close();
}

// Check Minify and SqlFormatter libraries
$minify_available = false;
$sql_formatter_available = false;
if (file_exists('vendor/autoload.php') && is_readable('vendor/autoload.php')) {
    try {
        require_once 'vendor/autoload.php';
        if (class_exists('MatthiasMullie\Minify\CSS') && class_exists('MatthiasMullie\Minify\JS')) {
            $minify_available = true;
        }
        if (class_exists('SqlFormatter')) {
            $sql_formatter_available = true;
        }
    } catch (Exception $e) {
        error_log("Failed to load vendor/autoload.php: " . $e->getMessage());
        $edit_error = "System error: Cannot load required libraries.";
    }
} else {
    error_log("vendor/autoload.php not found or not readable");
    $edit_error = "System error: Composer libraries not installed or not readable.";
}

// Helper functions
function htmlMinify($input) {
    $search = array(
        '/\>[^\S ]+/s',     // Remove whitespace after >
        '/[^\S ]+\</s',     // Remove whitespace before <
        '/(\s)+/s',         // Remove multiple whitespace
        '/<!--[\s\S]*?-->/' // Remove comments
    );
    return preg_replace($search, array('>', '<', '\\1', ''), trim($input));
}

function cssMinify($input) {
    global $minify_available;
    if (!$minify_available) {
        return "Minify library not available! Please install via Composer.";
    }
    try {
        $minifier = new MatthiasMullie\Minify\CSS($input);
        return $minifier->minify();
    } catch (Exception $e) {
        error_log("CSS Minify error: " . $e->getMessage());
        return "Error minifying CSS: " . htmlspecialchars($e->getMessage());
    }
}

function jsMinify($input) {
    global $minify_available;
    if (!$minify_available) {
        return "Minify library not available! Please install via Composer.";
    }
    try {
        $minifier = new MatthiasMullie\Minify\JS($input);
        return $minifier->minify();
    } catch (Exception $e) {
        error_log("JS Minify error: " . $e->getMessage());
        return "Error minifying JS: " . htmlspecialchars($e->getMessage());
    }
}

function jsonValidateAndBeautify($input) {
    try {
        $decoded = json_decode($input);
        if (json_last_error() === JSON_ERROR_NONE) {
            return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }
        return "Invalid JSON: " . json_last_error_msg();
    } catch (Exception $e) {
        error_log("JSON error: " . $e->getMessage());
        return "Error processing JSON: " . htmlspecialchars($e->getMessage());
    }
}

function sqlFormat($input) {
    global $sql_formatter_available;
    if (!$sql_formatter_available) {
        return "SqlFormatter library not available! Please install via Composer.";
    }
    try {
        return SqlFormatter::format($input);
    } catch (Exception $e) {
        error_log("SQL Formatter error: " . $e->getMessage());
        return "Error formatting SQL: " . htmlspecialchars($e->getMessage());
    }
}

function htmlEntityConvert($input, $mode = 'encode') {
    try {
        return $mode === 'encode' ? htmlentities($input, ENT_QUOTES, 'UTF-8') : html_entity_decode($input, ENT_QUOTES, 'UTF-8');
    } catch (Exception $e) {
        error_log("HTML Entity error: " . $e->getMessage());
        return "Error processing HTML entity: " . htmlspecialchars($e->getMessage());
    }
}

function bbcodeToHtml($input) {
    try {
        $search = [
            '/\[b\](.*?)\[\/b\]/s' => '<strong>$1</strong>',
            '/\[i\](.*?)\[\/i\]/s' => '<em>$1</em>',
            '/\[u\](.*?)\[\/u\]/s' => '<u>$1</u>',
            '/\[url=(.*?)\](.*?)\[\/url\]/s' => '<a href="$1">$2</a>',
            '/\[img\](.*?)\[\/img\]/s' => '<img src="$1" alt="Image">'
        ];
        return preg_replace(array_keys($search), array_values($search), $input);
    } catch (Exception $e) {
        error_log("BBCode error: " . $e->getMessage());
        return "Error converting BBCode: " . htmlspecialchars($e->getMessage());
    }
}

function removeHtmlTags($input) {
    try {
        return strip_tags($input);
    } catch (Exception $e) {
        error_log("Strip tags error: " . $e->getMessage());
        return "Error removing HTML tags: " . htmlspecialchars($e->getMessage());
    }
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['convert']) && isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $type = filter_input(INPUT_POST, 'convert_type', FILTER_SANITIZE_STRING);
    $input = $_POST['input'] ?? ''; // Don't sanitize input as it may contain code
    $mode = filter_input(INPUT_POST, 'mode', FILTER_SANITIZE_STRING);

    // Limit input length (2MB)
    if (strlen($input) > 2097152) {
        $message = "Input data too large! Maximum 2MB.";
    } elseif (empty($type)) {
        $message = "Please select a function type!";
    } elseif (empty($input)) {
        $message = "Please enter data!";
    } else {
        switch ($type) {
            case 'html_minifier':
                $result = htmlMinify($input);
                $message = "HTML minified successfully!";
                break;
            case 'css_minifier':
                $result = cssMinify($input);
                $message = $minify_available ? "CSS minified successfully!" : $result;
                break;
            case 'js_minifier':
                $result = jsMinify($input);
                $message = $minify_available ? "JS minified successfully!" : $result;
                break;
            case 'json_validator':
                $result = jsonValidateAndBeautify($input);
                $message = strpos($result, "Invalid") === false ? "JSON validated and formatted!" : $result;
                break;
            case 'sql_formatter':
                $result = sqlFormat($input);
                $message = $sql_formatter_available ? "SQL formatted successfully!" : $result;
                break;
            case 'html_entity_converter':
                $mode = in_array($mode, ['encode', 'decode']) ? $mode : 'encode';
                $result = htmlEntityConvert($input, $mode);
                $message = ($mode === 'encode' ? "HTML entities encoded!" : "HTML entities decoded!");
                break;
            case 'bbcode_to_html':
                $result = bbcodeToHtml($input);
                $message = "BBCode converted to HTML successfully!";
                break;
            case 'html_tags_remover':
                $result = removeHtmlTags($input);
                $message = "HTML tags removed successfully!";
                break;
            default:
                $message = "Invalid function!";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Advanced Minifier Tools - Modern Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
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
            --success: #00E0B0;
            --success-light: #50F0C0;
            --success-dark: #00B090;
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
            --glow-success: 0 0 20px rgba(0, 224, 176, 0.5);
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

        .dashboard-container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 1.5rem;
            position: relative;
            z-index: 1;
        }

        .dashboard-header {
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

        .dashboard-header::before {
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

        .dashboard-title {
            font-size: 1.875rem;
            font-weight: 700;
            background: linear-gradient(to right, var(--secondary), var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .dashboard-title i {
            font-size: 1.75rem;
            color: var(--primary-light);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-avatar {
            width: 3.5rem;
            height: 3.5rem;
            border-radius: var(--radius);
            background: linear-gradient(135deg, var(--primary), var(--accent));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 1.5rem;
            box-shadow: var(--glow);
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            position: relative;
        }

        .user-avatar::before {
            content: '';
            position: absolute;
            inset: -2px;
            background: linear-gradient(45deg, var(--primary), var(--secondary), var(--accent));
            border-radius: var(--radius);
            z-index: -1;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .user-avatar:hover {
            transform: scale(1.1) rotate(5deg);
        }

        .user-avatar:hover::before {
            opacity: 1;
        }

        .user-details {
            text-align: right;
        }

        .user-name {
            font-weight: 600;
            font-size: 1.1rem;
            color: var(--foreground);
            margin-bottom: 0.25rem;
        }

        .user-role {
            font-size: 0.875rem;
            color: var(--foreground-muted);
            padding: 0.25rem 0.75rem;
            background: rgba(112, 0, 255, 0.2);
            border-radius: var(--radius-full);
            border: 1px solid var(--primary);
        }

        .content-section {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 2rem;
            margin-bottom: 2rem;
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            position: relative;
            overflow: hidden;
        }

        .content-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, var(--accent), var(--secondary), var(--primary));
            opacity: 0.8;
        }

        .content-section:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary-light);
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: var(--foreground);
        }

        .section-title i {
            color: var(--primary-light);
            font-size: 1.25rem;
            padding: 0.5rem;
            background: rgba(112, 0, 255, 0.1);
            border-radius: var(--radius);
            border: 1px solid var(--primary);
        }

        .form-container {
            background: rgba(255, 255, 255, 0.03);
            border-radius: var(--radius);
            padding: 1.5rem;
            border: 1px solid var(--border);
            transition: all 0.3s ease;
        }

        .form-container:hover {
            background: rgba(255, 255, 255, 0.05);
            border-color: var(--primary-light);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            font-weight: 500;
            margin-bottom: 0.75rem;
            color: var(--foreground);
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-group label i {
            color: var(--primary-light);
        }

        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 1rem;
            background: rgba(20, 20, 40, 0.8);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            color: var(--foreground);
            font-size: 0.9rem;
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            font-family: inherit;
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }

        .form-group select {
            background: rgba(20, 20, 40, 0.9);
            cursor: pointer;
        }

        .form-group select option {
            background: var(--surface);
            color: var(--foreground);
            padding: 0.5rem;
        }

        .form-group textarea {
            min-height: 120px;
            resize: vertical;
            font-family: 'Cascadia Code', 'Monaco', 'Menlo', monospace;
            background: rgba(10, 10, 25, 0.9);
            line-height: 1.5;
        }

        .form-group select:focus,
        .form-group textarea:focus {
            background: rgba(30, 30, 50, 0.95);
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(112, 0, 255, 0.3), var(--glow);
            outline: none;
            transform: translateY(-2px);
        }

        .form-group select:hover,
        .form-group textarea:hover {
            background: rgba(25, 25, 45, 0.9);
            border-color: var(--primary);
        }

        .form-group button {
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            color: white;
            border: none;
            padding: 1rem 2rem;
            border-radius: var(--radius);
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            position: relative;
            overflow: hidden;
        }

        .form-group button::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s ease;
        }

        .form-group button:hover {
            background: linear-gradient(90deg, var(--primary-light), var(--primary));
            transform: translateY(-2px);
            box-shadow: var(--glow);
        }

        .form-group button:hover::before {
            left: 100%;
        }

        .field-group {
            display: none;
            animation: fadeIn 0.5s ease;
        }

        .field-group.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .result-section {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 2rem;
            margin-top: 2rem;
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            position: relative;
            overflow: hidden;
        }

        .result-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, var(--success), var(--secondary));
            opacity: 0.8;
        }

        .result-section:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .result-section h3 {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--foreground);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .result-section h3::before {
            content: '🎯';
            font-size: 1.5rem;
        }

        .result-section pre {
            background: rgba(0, 0, 0, 0.3);
            padding: 1.5rem;
            border-radius: var(--radius);
            font-size: 0.875rem;
            white-space: pre-wrap;
            word-wrap: break-word;
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid var(--border);
            color: var(--foreground);
            font-family: 'Cascadia Code', 'Monaco', 'Menlo', monospace;
            position: relative;
        }

        .result-section pre::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, var(--success), transparent);
        }

        .error-message,
        .success-message {
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            margin: 1rem 0;
            font-weight: 500;
            position: relative;
            padding-left: 3rem;
            animation: slideInFromLeft 0.5s cubic-bezier(0.22, 1, 0.36, 1);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid;
        }

        .error-message {
            background: rgba(255, 61, 87, 0.1);
            color: var(--danger-light);
            border-color: var(--danger);
            box-shadow: var(--glow-danger);
        }

        .error-message::before {
            content: "⚠️";
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1.25rem;
        }

        .success-message {
            background: rgba(0, 224, 176, 0.1);
            color: var(--success-light);
            border-color: var(--success);
            box-shadow: var(--glow-success);
        }

        .success-message::before {
            content: "✅";
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1.25rem;
        }

        @keyframes slideInFromLeft {
            from { transform: translateX(-20px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: linear-gradient(90deg, var(--secondary), var(--secondary-dark));
            color: white;
            text-decoration: none;
            border-radius: var(--radius);
            font-weight: 600;
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            border: 1px solid var(--secondary);
        }

        .back-btn:hover {
            background: linear-gradient(90deg, var(--secondary-light), var(--secondary));
            transform: translateY(-2px);
            box-shadow: var(--glow-secondary);
            color: white;
            text-decoration: none;
        }

        .status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 1rem;
        }

        .status-available {
            background: rgba(0, 224, 176, 0.1);
            color: var(--success-light);
            border: 1px solid var(--success);
        }

        .status-unavailable {
            background: rgba(255, 61, 87, 0.1);
            color: var(--danger-light);
            border: 1px solid var(--danger);
        }

        @media (max-width: 768px) {
            .dashboard-container {
                margin: 1rem;
                padding: 0 1rem;
            }

            .dashboard-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
                padding: 1.25rem;
            }

            .user-info {
                width: 100%;
                justify-content: space-between;
            }

            .dashboard-title {
                font-size: 1.5rem;
            }

            .content-section {
                padding: 1.5rem;
            }

            .section-title {
                font-size: 1.25rem;
            }

            .form-container {
                padding: 1rem;
            }

            .form-group button {
                padding: 0.875rem 1.5rem;
            }
        }

        @media (max-width: 480px) {
            .dashboard-title {
                font-size: 1.25rem;
            }

            .section-title {
                font-size: 1.125rem;
            }

            .form-group select,
            .form-group textarea,
            .form-group button {
                font-size: 0.875rem;
            }

            .result-section pre {
                font-size: 0.75rem;
            }
        }

        /* Float animation for particles */
        @keyframes float {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
            100% { transform: translateY(0px); }
        }
    </style>
</head>
<body>
    <div class="particles" id="particles"></div>

    <div class="dashboard-container">
        <div class="dashboard-header">
            <h1 class="dashboard-title">
                <i class="fas fa-compress-alt"></i>
                Advanced Minifier Tools
            </h1>
            <div class="user-info">
                <a href="dashboard.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i>
                    Back to Dashboard
                </a>
                <div class="user-avatar"><?php echo strtoupper(substr($user['username'], 0, 1)); ?></div>
                <div class="user-details">
                    <p class="user-name"><?php echo htmlspecialchars($user['full_name'] ?: $user['username']); ?></p>
                    <p class="user-role">
                        <?php echo $user['is_super_admin'] ? 'Super Admin' : ($user['is_main_admin'] ? 'Main Admin' : 'Admin'); ?>
                    </p>
                </div>
            </div>
        </div>

        <?php if (!empty($edit_error)): ?>
            <div class="error-message"><?php echo htmlspecialchars($edit_error); ?></div>
        <?php endif; ?>

        <?php if (!empty($message)): ?>
            <div class="<?php echo (strpos($message, 'successfully') !== false || strpos($message, 'encoded') !== false || strpos($message, 'decoded') !== false || strpos($message, 'formatted') !== false || strpos($message, 'converted') !== false || strpos($message, 'removed') !== false || strpos($message, 'validated') !== false) ? 'success-message' : 'error-message'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="content-section">
            <h2 class="section-title">
                <i class="fas fa-tools"></i>
                Advanced Conversion Tools
                <span class="status-indicator <?php echo $minify_available && $sql_formatter_available ? 'status-available' : 'status-unavailable'; ?>">
                    <i class="fas fa-circle"></i>
                    <?php echo $minify_available && $sql_formatter_available ? 'All Libraries Available' : 'Some Libraries Missing'; ?>
                </span>
            </h2>
            
            <div class="form-container">
                <form method="POST" id="converterForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    
                    <div class="form-group">
                        <label for="convert_type">
                            <i class="fas fa-cog"></i>
                            Select Function
                        </label>
                        <select name="convert_type" id="convert_type" onchange="toggleFields()" required>
                            <option value="">Choose a function</option>
                            <option value="html_minifier" <?php echo isset($_POST['convert_type']) && $_POST['convert_type'] === 'html_minifier' ? 'selected' : ''; ?>>HTML Minifier</option>
                            <option value="css_minifier" <?php echo isset($_POST['convert_type']) && $_POST['convert_type'] === 'css_minifier' ? 'selected' : ''; ?>>CSS Minifier</option>
                            <option value="js_minifier" <?php echo isset($_POST['convert_type']) && $_POST['convert_type'] === 'js_minifier' ? 'selected' : ''; ?>>JavaScript Minifier</option>
                            <option value="json_validator" <?php echo isset($_POST['convert_type']) && $_POST['convert_type'] === 'json_validator' ? 'selected' : ''; ?>>JSON Validator & Beautifier</option>
                            <option value="sql_formatter" <?php echo isset($_POST['convert_type']) && $_POST['convert_type'] === 'sql_formatter' ? 'selected' : ''; ?>>SQL Formatter</option>
                            <option value="html_entity_converter" <?php echo isset($_POST['convert_type']) && $_POST['convert_type'] === 'html_entity_converter' ? 'selected' : ''; ?>>HTML Entity Converter</option>
                            <option value="bbcode_to_html" <?php echo isset($_POST['convert_type']) && $_POST['convert_type'] === 'bbcode_to_html' ? 'selected' : ''; ?>>BBCode to HTML</option>
                            <option value="html_tags_remover" <?php echo isset($_POST['convert_type']) && $_POST['convert_type'] === 'html_tags_remover' ? 'selected' : ''; ?>>HTML Tags Remover</option>
                        </select>
                    </div>

                    <div id="html_minifier_fields" class="field-group">
                        <div class="form-group">
                            <label for="html_input">
                                <i class="fab fa-html5"></i>
                                Enter HTML to minify
                            </label>
                            <textarea name="input" id="html_input" placeholder="Enter HTML code to minify..." rows="6"><?php echo isset($_POST['input']) && $_POST['convert_type'] === 'html_minifier' ? htmlspecialchars($_POST['input']) : ''; ?></textarea>
                        </div>
                    </div>

                    <div id="css_minifier_fields" class="field-group">
                        <div class="form-group">
                            <label for="css_input">
                                <i class="fab fa-css3-alt"></i>
                                Enter CSS to minify
                            </label>
                            <textarea name="input" id="css_input" placeholder="Enter CSS code to minify..." rows="6"><?php echo isset($_POST['input']) && $_POST['convert_type'] === 'css_minifier' ? htmlspecialchars($_POST['input']) : ''; ?></textarea>
                        </div>
                    </div>

                    <div id="js_minifier_fields" class="field-group">
                        <div class="form-group">
                            <label for="js_input">
                                <i class="fab fa-js-square"></i>
                                Enter JavaScript to minify
                            </label>
                            <textarea name="input" id="js_input" placeholder="Enter JavaScript code to minify..." rows="6"><?php echo isset($_POST['input']) && $_POST['convert_type'] === 'js_minifier' ? htmlspecialchars($_POST['input']) : ''; ?></textarea>
                        </div>
                    </div>

                    <div id="json_validator_fields" class="field-group">
                        <div class="form-group">
                            <label for="json_input">
                                <i class="fas fa-code"></i>
                                Enter JSON to validate and format
                            </label>
                            <textarea name="input" id="json_input" placeholder="Enter JSON to validate and beautify..." rows="6"><?php echo isset($_POST['input']) && $_POST['convert_type'] === 'json_validator' ? htmlspecialchars($_POST['input']) : ''; ?></textarea>
                        </div>
                    </div>

                    <div id="sql_formatter_fields" class="field-group">
                        <div class="form-group">
                            <label for="sql_input">
                                <i class="fas fa-database"></i>
                                Enter SQL to format
                            </label>
                            <textarea name="input" id="sql_input" placeholder="Enter SQL code to format..." rows="6"><?php echo isset($_POST['input']) && $_POST['convert_type'] === 'sql_formatter' ? htmlspecialchars($_POST['input']) : ''; ?></textarea>
                        </div>
                    </div>

                    <div id="html_entity_converter_fields" class="field-group">
                        <div class="form-group">
                            <label for="html_entity_input">
                                <i class="fas fa-exchange-alt"></i>
                                Enter text to encode/decode HTML entities
                            </label>
                            <textarea name="input" id="html_entity_input" placeholder="Enter text to encode/decode HTML entities..." rows="6"><?php echo isset($_POST['input']) && $_POST['convert_type'] === 'html_entity_converter' ? htmlspecialchars($_POST['input']) : ''; ?></textarea>
                        </div>
                        <div class="form-group">
                            <label for="mode">
                                <i class="fas fa-toggle-on"></i>
                                Mode
                            </label>
                            <select name="mode" id="mode">
                                <option value="encode" <?php echo isset($_POST['mode']) && $_POST['mode'] === 'encode' ? 'selected' : ''; ?>>Encode</option>
                                <option value="decode" <?php echo isset($_POST['mode']) && $_POST['mode'] === 'decode' ? 'selected' : ''; ?>>Decode</option>
                            </select>
                        </div>
                    </div>

                    <div id="bbcode_to_html_fields" class="field-group">
                        <div class="form-group">
                            <label for="bbcode_input">
                                <i class="fas fa-code"></i>
                                Enter BBCode to convert to HTML
                            </label>
                            <textarea name="input" id="bbcode_input" placeholder="Enter BBCode to convert to HTML..." rows="6"><?php echo isset($_POST['input']) && $_POST['convert_type'] === 'bbcode_to_html' ? htmlspecialchars($_POST['input']) : ''; ?></textarea>
                        </div>
                    </div>

                    <div id="html_tags_remover_fields" class="field-group">
                        <div class="form-group">
                            <label for="html_tags_input">
                                <i class="fas fa-eraser"></i>
                                Enter text with HTML tags to remove
                            </label>
                            <textarea name="input" id="html_tags_input" placeholder="Enter text with HTML tags to remove..." rows="6"><?php echo isset($_POST['input']) && $_POST['convert_type'] === 'html_tags_remover' ? htmlspecialchars($_POST['input']) : ''; ?></textarea>
                        </div>
                    </div>

                    <div class="form-group">
                        <button type="submit" name="convert">
                            <i class="fas fa-magic"></i>
                            Convert Now
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <?php if (!empty($result) || !empty($message)): ?>
            <div class="result-section">
                <h3>Conversion Result</h3>
                <?php if (!empty($result)): ?>
                    <pre><?php echo htmlspecialchars($result); ?></pre>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize particles
            createParticles();
            
            // Initialize form
            toggleFields();
            
            // Add entrance animations
            animateElements();
        });

        function toggleFields() {
            const convertType = document.getElementById('convert_type').value;
            document.querySelectorAll('.field-group').forEach(group => {
                group.classList.remove('active');
            });
            
            const fieldGroup = document.getElementById(convertType + '_fields');
            if (fieldGroup) {
                fieldGroup.classList.add('active');
            }
        }

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

        function animateElements() {
            const elements = document.querySelectorAll('.content-section, .result-section');
            elements.forEach((el, index) => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    el.style.transition = 'opacity 0.6s cubic-bezier(0.4, 0, 0.2, 1), transform 0.6s cubic-bezier(0.4, 0, 0.2, 1)';
                    el.style.opacity = '1';
                    el.style.transform = 'translateY(0)';
                }, index * 150);
            });
        }

        // Enhanced form submission with loading state
        document.getElementById('converterForm').addEventListener('submit', function(e) {
            const button = this.querySelector('button[type="submit"]');
            const originalText = button.innerHTML;
            
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            button.disabled = true;
            
            // Re-enable button after 5 seconds (in case of server issues)
            setTimeout(() => {
                button.innerHTML = originalText;
                button.disabled = false;
            }, 5000);
        });

        // Add copy to clipboard functionality for results
        if (document.querySelector('.result-section pre')) {
            const pre = document.querySelector('.result-section pre');
            const copyBtn = document.createElement('button');
            copyBtn.innerHTML = '<i class="fas fa-copy"></i> Copy';
            copyBtn.className = 'copy-btn';
            copyBtn.style.cssText = `
                position: absolute;
                top: 1rem;
                right: 1rem;
                padding: 0.5rem 1rem;
                background: var(--primary);
                color: white;
                border: none;
                border-radius: var(--radius-sm);
                cursor: pointer;
                font-size: 0.75rem;
                transition: all 0.3s ease;
            `;
            
            copyBtn.addEventListener('click', () => {
                navigator.clipboard.writeText(pre.textContent).then(() => {
                    copyBtn.innerHTML = '<i class="fas fa-check"></i> Copied!';
                    setTimeout(() => {
                        copyBtn.innerHTML = '<i class="fas fa-copy"></i> Copy';
                    }, 2000);
                });
            });
            
            pre.style.position = 'relative';
            pre.appendChild(copyBtn);
        }
    </script>
</body>
</html>
<?php
if (isset($conn) && $conn) {
    $conn->close();
}
?>