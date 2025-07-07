<?php
ob_start();
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'db_config.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Tạo bảng user_book_favorites cho việc lưu sách yêu thích
$sql_favorites = "CREATE TABLE IF NOT EXISTS user_book_favorites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    book_key VARCHAR(100) NOT NULL,
    book_title VARCHAR(255) NOT NULL,
    book_author TEXT,
    book_cover_id INT,
    first_publish_year INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE KEY unique_user_book (user_id, book_key)
)";
mysqli_query($conn, $sql_favorites) or die("Error creating user_book_favorites: " . mysqli_error($conn));

// Tạo bảng user_book_reading_list cho danh sách đọc
$sql_reading_list = "CREATE TABLE IF NOT EXISTS user_book_reading_list (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    book_key VARCHAR(100) NOT NULL,
    book_title VARCHAR(255) NOT NULL,
    book_author TEXT,
    book_cover_id INT,
    status ENUM('want_to_read', 'currently_reading', 'read') DEFAULT 'want_to_read',
    rating INT DEFAULT NULL CHECK (rating >= 1 AND rating <= 5),
    notes TEXT,
    started_date DATE,
    finished_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE KEY unique_user_book_reading (user_id, book_key)
)";
mysqli_query($conn, $sql_reading_list) or die("Error creating user_book_reading_list: " . mysqli_error($conn));

// Xử lý thêm sách vào yêu thích
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_favorite') {
        $book_key = mysqli_real_escape_string($conn, $_POST['book_key']);
        $book_title = mysqli_real_escape_string($conn, $_POST['book_title']);
        $book_author = mysqli_real_escape_string($conn, $_POST['book_author']);
        $book_cover_id = !empty($_POST['book_cover_id']) ? (int)$_POST['book_cover_id'] : NULL;
        $first_publish_year = !empty($_POST['first_publish_year']) ? (int)$_POST['first_publish_year'] : NULL;
        
        $stmt = $conn->prepare("INSERT INTO user_book_favorites (user_id, book_key, book_title, book_author, book_cover_id, first_publish_year) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssii", $user_id, $book_key, $book_title, $book_author, $book_cover_id, $first_publish_year);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Đã thêm vào yêu thích!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Sách đã có trong danh sách yêu thích!']);
        }
        exit;
    }
    
    if ($_POST['action'] === 'remove_favorite') {
        $book_key = mysqli_real_escape_string($conn, $_POST['book_key']);
        
        $stmt = $conn->prepare("DELETE FROM user_book_favorites WHERE user_id = ? AND book_key = ?");
        $stmt->bind_param("is", $user_id, $book_key);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Đã xóa khỏi yêu thích!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Lỗi khi xóa!']);
        }
        exit;
    }
    
    if ($_POST['action'] === 'add_to_reading_list') {
        $book_key = mysqli_real_escape_string($conn, $_POST['book_key']);
        $book_title = mysqli_real_escape_string($conn, $_POST['book_title']);
        $book_author = mysqli_real_escape_string($conn, $_POST['book_author']);
        $book_cover_id = !empty($_POST['book_cover_id']) ? (int)$_POST['book_cover_id'] : NULL;
        $status = mysqli_real_escape_string($conn, $_POST['status']);
        $rating = !empty($_POST['rating']) ? (int)$_POST['rating'] : NULL;
        $notes = mysqli_real_escape_string($conn, $_POST['notes']);
        
        $stmt = $conn->prepare("INSERT INTO user_book_reading_list (user_id, book_key, book_title, book_author, book_cover_id, status, rating, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status), rating = VALUES(rating), notes = VALUES(notes), updated_at = CURRENT_TIMESTAMP");
        $stmt->bind_param("isssisis", $user_id, $book_key, $book_title, $book_author, $book_cover_id, $status, $rating, $notes);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Đã cập nhật danh sách đọc!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Lỗi khi cập nhật!']);
        }
        exit;
    }
}

// Lấy thông tin user
$user_info = [];
$stmt = $conn->prepare("SELECT username, email FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $user_info = $row;
}

// Lấy danh sách yêu thích
$favorites = [];
$stmt = $conn->prepare("SELECT * FROM user_book_favorites WHERE user_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $favorites[] = $row;
}

// Lấy danh sách đọc
$reading_list = [];
$stmt = $conn->prepare("SELECT * FROM user_book_reading_list WHERE user_id = ? ORDER BY updated_at DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $reading_list[] = $row;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Search - Tìm kiếm sách</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            /* Màu chính */
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
            --success: #00FF85;
            --success-light: #4DFFAA;
            --success-dark: #00CC6A;
            
            /* Màu nền và text */
            --background: #0A0A1A;
            --surface: #12122A;
            --surface-light: #1A1A3A;
            --foreground: #FFFFFF;
            --foreground-muted: rgba(255, 255, 255, 0.7);
            --foreground-subtle: rgba(255, 255, 255, 0.5);
            
            /* Màu card */
            --card: rgba(30, 30, 60, 0.6);
            --card-hover: rgba(40, 40, 80, 0.8);
            --card-active: rgba(50, 50, 100, 0.9);
            
            /* Border và shadow */
            --border: rgba(255, 255, 255, 0.1);
            --border-light: rgba(255, 255, 255, 0.05);
            --shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            --shadow-sm: 0 4px 16px rgba(0, 0, 0, 0.2);
            --shadow-lg: 0 16px 48px rgba(0, 0, 0, 0.4);
            --glow: 0 0 20px rgba(112, 0, 255, 0.5);
            --glow-accent: 0 0 20px rgba(255, 61, 255, 0.5);
            --glow-secondary: 0 0 20px rgba(0, 224, 255, 0.5);
            --glow-danger: 0 0 20px rgba(255, 61, 87, 0.5);
            --glow-success: 0 0 20px rgba(0, 255, 133, 0.5);
            
            /* Border radius */
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

        /* Scrollbar styling */
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

        /* Particles */
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

        @keyframes float {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
            100% { transform: translateY(0px); }
        }

        @keyframes gradientBorder {
            0% { background-position: 0% 0%; }
            100% { background-position: 300% 0%; }
        }

        @keyframes slideIn {
            to { transform: translateX(0); opacity: 1; }
        }

        @keyframes fadeOut {
            to { opacity: 0; visibility: hidden; }
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* Container */
        .book-container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 1.5rem;
            position: relative;
            z-index: 1;
        }

        /* Header */
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
            background: linear-gradient(to right, var(--primary-light), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-welcome {
            color: var(--foreground-muted);
            font-size: 0.9rem;
        }

        .logout-btn {
            padding: 0.5rem 1rem;
            background: rgba(255, 61, 87, 0.1);
            color: var(--danger);
            border: 1px solid var(--danger-dark);
            border-radius: var(--radius);
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .logout-btn:hover {
            background: rgba(255, 61, 87, 0.2);
            color: var(--danger-light);
            transform: translateY(-2px);
            box-shadow: var(--glow-danger);
        }

        /* Navigation Tabs */
        .nav-tabs-container {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .nav-tabs {
            display: flex;
            gap: 1rem;
            margin-bottom: 0;
            border: none;
        }

        .nav-tab {
            padding: 0.75rem 1.5rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            color: var(--foreground-muted);
            border-radius: var(--radius);
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .nav-tab:hover {
            background: rgba(255, 255, 255, 0.08);
            color: var(--foreground);
        }

        .nav-tab.active {
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            color: white;
            border-color: var(--primary-light);
            box-shadow: var(--glow);
        }

        .tab-content {
            display: none;
            animation: fadeIn 0.5s ease;
        }

        .tab-content.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Search Section */
        .search-section {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .search-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--foreground);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .search-title i {
            color: var(--primary-light);
        }

        .search-form {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            align-items: end;
        }

        .search-input-group {
            flex: 1;
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

        .form-control, .form-select {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            color: var(--foreground);
            padding: 0.75rem 1rem;
            transition: all 0.3s ease;
        }

        .form-control:focus, .form-select:focus {
            background: rgba(255, 255, 255, 0.08);
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(112, 0, 255, 0.2);
            color: var(--foreground);
            outline: none;
        }

        .form-control::placeholder {
            color: var(--foreground-muted);
        }

        .search-btn {
            padding: 0.75rem 1.5rem;
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
            white-space: nowrap;
        }

        .search-btn:hover {
            background: linear-gradient(90deg, var(--primary-light), var(--primary));
            transform: translateY(-2px);
            box-shadow: var(--glow);
        }

        .search-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .search-filters {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        /* Loading */
        .loading {
            text-align: center;
            padding: 3rem;
            color: var(--foreground-muted);
        }

        .loading i {
            font-size: 2rem;
            color: var(--primary-light);
            animation: spin 1s linear infinite;
        }

        /* Results Section */
        .results-section {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .results-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--foreground);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .results-title i {
            color: var(--primary-light);
        }

        .results-count {
            color: var(--foreground-muted);
            font-size: 0.9rem;
        }

        /* Book Grid */
        .books-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .book-card {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .book-card:hover {
            background: rgba(255, 255, 255, 0.08);
            transform: translateY(-8px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary-light);
        }

        .book-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 2px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }

        .book-card:hover::before {
            transform: scaleX(1);
        }

        .book-header {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .book-cover {
            width: 80px;
            height: 120px;
            border-radius: var(--radius);
            background: var(--surface);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
            border: 1px solid var(--border);
        }

        .book-cover img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .book-cover i {
            font-size: 2rem;
            color: var(--foreground-muted);
        }

        .book-info {
            flex: 1;
            min-width: 0;
        }

        .book-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--foreground);
            margin-bottom: 0.5rem;
            line-height: 1.3;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .book-author {
            color: var(--secondary);
            font-size: 0.9rem;
            font-weight: 500;
            margin-bottom: 0.5rem;
        }

        .book-year {
            color: var(--foreground-muted);
            font-size: 0.8rem;
        }

        .book-meta {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .book-editions {
            background: rgba(112, 0, 255, 0.1);
            color: var(--primary-light);
            padding: 0.25rem 0.5rem;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            font-weight: 500;
        }

        .book-availability {
            display: flex;
            gap: 0.5rem;
        }

        .availability-badge {
            padding: 0.25rem 0.5rem;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            font-weight: 500;
        }

        .badge-readable {
            background: rgba(0, 255, 133, 0.1);
            color: var(--success);
            border: 1px solid var(--success-dark);
        }

        .badge-ia {
            background: rgba(255, 171, 61, 0.1);
            color: #FFAB3D;
            border: 1px solid rgba(255, 171, 61, 0.3);
        }

        /* Book Actions */
        .book-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
            justify-content: center;
        }

        .book-action-btn {
            padding: 0.5rem 1rem;
            border-radius: var(--radius);
            font-size: 0.75rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.25rem;
            border: none;
        }

        .btn-favorite {
            background: rgba(255, 61, 255, 0.1);
            color: var(--accent);
            border: 1px solid var(--accent-dark);
        }

        .btn-favorite:hover, .btn-favorite.active {
            background: rgba(255, 61, 255, 0.2);
            transform: translateY(-2px);
            box-shadow: var(--glow-accent);
        }

        .btn-reading {
            background: rgba(0, 224, 255, 0.1);
            color: var(--secondary);
            border: 1px solid var(--secondary-dark);
        }

        .btn-reading:hover {
            background: rgba(0, 224, 255, 0.2);
            transform: translateY(-2px);
            box-shadow: var(--glow-secondary);
        }

        /* Favorites and Reading List */
        .my-books-section {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .my-books-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--foreground);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .my-books-title i {
            color: var(--primary-light);
        }

        .book-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1rem;
        }

        .book-list-item {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1rem;
            transition: all 0.3s ease;
        }

        .book-list-item:hover {
            background: rgba(255, 255, 255, 0.08);
            transform: translateY(-4px);
            box-shadow: var(--shadow-sm);
        }

        .book-list-header {
            display: flex;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
        }

        .book-list-cover {
            width: 50px;
            height: 75px;
            border-radius: var(--radius-sm);
            background: var(--surface);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
            border: 1px solid var(--border);
        }

        .book-list-cover img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .book-list-cover i {
            font-size: 1.25rem;
            color: var(--foreground-muted);
        }

        .book-list-info {
            flex: 1;
            min-width: 0;
        }

        .book-list-title {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--foreground);
            margin-bottom: 0.25rem;
            line-height: 1.3;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .book-list-author {
            color: var(--secondary);
            font-size: 0.8rem;
            font-weight: 500;
        }

        .book-list-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: center;
        }

        .remove-btn {
            background: rgba(255, 61, 87, 0.1);
            color: var(--danger);
            border: 1px solid var(--danger-dark);
        }

        .remove-btn:hover {
            background: rgba(255, 61, 87, 0.2);
            transform: translateY(-2px);
            box-shadow: var(--glow-danger);
        }

        /* Pagination */
        .pagination-container {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 1rem;
            margin-top: 2rem;
        }

        .pagination-btn {
            padding: 0.75rem 1rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            color: var(--foreground);
            border-radius: var(--radius);
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .pagination-btn:hover:not(:disabled) {
            background: rgba(255, 255, 255, 0.1);
            border-color: var(--primary-light);
            transform: translateY(-2px);
        }

        .pagination-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        .pagination-info {
            color: var(--foreground-muted);
            font-size: 0.9rem;
        }

        /* Modal */
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

        .btn-close {
            filter: invert(1) grayscale(100%) brightness(200%);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--foreground-muted);
        }

        .empty-state i {
            font-size: 4rem;
            color: var(--primary-light);
            margin-bottom: 1rem;
        }

        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            color: var(--foreground);
        }

        /* Alert Messages */
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

        .alert-success {
            background: rgba(0, 255, 133, 0.1);
            border: 1px solid var(--success-dark);
            color: var(--success);
        }

        .alert-error {
            background: rgba(255, 61, 87, 0.1);
            border: 1px solid rgba(255, 61, 87, 0.3);
            color: #FF5D77;
        }

        /* Responsive */
        @media (max-width: 992px) {
            .book-container {
                padding: 0 1rem;
            }

            .page-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
                padding: 1.25rem;
            }

            .search-form {
                flex-direction: column;
            }

            .search-filters {
                flex-direction: column;
            }

            .books-grid {
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
                gap: 1rem;
            }

            .nav-tabs {
                flex-wrap: wrap;
            }
        }

        @media (max-width: 768px) {
            .page-title {
                font-size: 1.5rem;
            }

            .books-grid {
                grid-template-columns: 1fr;
            }

            .pagination-container {
                flex-direction: column;
                gap: 0.5rem;
            }

            .book-list {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 576px) {
            .book-header {
                flex-direction: column;
                align-items: center;
                text-align: center;
            }

            .book-cover {
                width: 100px;
                height: 150px;
            }

            .nav-tabs {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="particles" id="particles"></div>

    <!-- Alert Messages Container -->
    <div class="alert-container" id="alertContainer"></div>

    <div class="book-container">
        <!-- Header -->
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-book"></i>
                Book Search - Tìm kiếm sách
            </h1>
            <div class="user-info">
                <div class="user-welcome">
                    Xin chào, <strong><?php echo htmlspecialchars($user_info['username']); ?></strong>
                </div>
                <a href="logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
                    Đăng xuất
                </a>
            </div>
        </div>

        <!-- Navigation Tabs -->
        <div class="nav-tabs-container">
            <div class="nav-tabs">
                <div class="nav-tab active" data-target="searchTab">
                    <i class="fas fa-search"></i>
                    Tìm kiếm sách
                </div>
                <div class="nav-tab" data-target="favoritesTab">
                    <i class="fas fa-heart"></i>
                    Yêu thích (<?php echo count($favorites); ?>)
                </div>
                <div class="nav-tab" data-target="readingListTab">
                    <i class="fas fa-list"></i>
                    Danh sách đọc (<?php echo count($reading_list); ?>)
                </div>
            </div>
        </div>

        <!-- Search Tab -->
        <div class="tab-content active" id="searchTab">
            <!-- Search Section -->
            <div class="search-section">
                <h2 class="search-title">
                    <i class="fas fa-search"></i>
                    Tìm kiếm sách
                </h2>
                
                <form class="search-form" id="searchForm">
                    <div class="search-input-group">
                        <label class="form-label">
                            <i class="fas fa-book-open"></i>
                            Từ khóa tìm kiếm
                        </label>
                        <input type="text" class="form-control" id="searchQuery" placeholder="Nhập tên sách, tác giả hoặc từ khóa...">
                    </div>
                    <div>
                        <label class="form-label">
                            <i class="fas fa-sort"></i>
                            Sắp xếp
                        </label>
                        <select class="form-select" id="sortBy">
                            <option value="">Liên quan nhất</option>
                            <option value="new">Mới nhất</option>
                            <option value="old">Cũ nhất</option>
                            <option value="title">Tên sách</option>
                            <option value="random">Ngẫu nhiên</option>
                        </select>
                    </div>
                    <div>
                        <button type="submit" class="search-btn" id="searchBtn">
                            <i class="fas fa-search"></i>
                            Tìm kiếm
                        </button>
                    </div>
                </form>

                <div class="search-filters">
                    <div>
                        <label class="form-label">
                            <i class="fas fa-user"></i>
                            Tác giả
                        </label>
                        <input type="text" class="form-control" id="authorFilter" placeholder="Tên tác giả">
                    </div>
                    <div>
                        <label class="form-label">
                            <i class="fas fa-tag"></i>
                            Chủ đề
                        </label>
                        <input type="text" class="form-control" id="subjectFilter" placeholder="Chủ đề">
                    </div>
                    <div>
                        <label class="form-label">
                            <i class="fas fa-language"></i>
                            Ngôn ngữ
                        </label>
                        <select class="form-select" id="languageFilter">
                            <option value="">Tất cả</option>
                            <option value="en">English</option>
                            <option value="vi">Tiếng Việt</option>
                            <option value="fr">Français</option>
                            <option value="de">Deutsch</option>
                            <option value="es">Español</option>
                            <option value="ja">日本語</option>
                            <option value="zh">中文</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Loading -->
            <div class="loading" id="loading" style="display: none;">
                <i class="fas fa-spinner"></i>
                <p>Đang tìm kiếm sách...</p>
            </div>

            <!-- Results Section -->
            <div class="results-section" id="resultsSection" style="display: none;">
                <div class="results-header">
                    <h2 class="results-title">
                        <i class="fas fa-list"></i>
                        Kết quả tìm kiếm
                    </h2>
                    <div class="results-count" id="resultsCount"></div>
                </div>
                
                <div class="books-grid" id="booksGrid">
                    <!-- Books will be populated here -->
                </div>

                <!-- Pagination -->
                <div class="pagination-container" id="pagination" style="display: none;">
                    <button class="pagination-btn" id="prevBtn">
                        <i class="fas fa-chevron-left"></i>
                        Trước
                    </button>
                    <div class="pagination-info" id="paginationInfo"></div>
                    <button class="pagination-btn" id="nextBtn">
                        Sau
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            </div>

            <!-- Empty State -->
            <div class="empty-state" id="emptyState" style="display: none;">
                <i class="fas fa-search"></i>
                <h3>Không tìm thấy kết quả</h3>
                <p>Hãy thử tìm kiếm với từ khóa khác hoặc điều chỉnh bộ lọc</p>
            </div>
        </div>

        <!-- Favorites Tab -->
        <div class="tab-content" id="favoritesTab">
            <div class="my-books-section">
                <h2 class="my-books-title">
                    <i class="fas fa-heart"></i>
                    Sách yêu thích
                </h2>
                
                <?php if (count($favorites) > 0): ?>
                    <div class="book-list">
                        <?php foreach ($favorites as $favorite): ?>
                        <div class="book-list-item">
                            <div class="book-list-header">
                                <div class="book-list-cover">
                                    <?php if ($favorite['book_cover_id']): ?>
                                        <img src="https://covers.openlibrary.org/b/id/<?php echo $favorite['book_cover_id']; ?>-S.jpg" alt="Book cover" loading="lazy" onerror="this.parentElement.innerHTML='<i class=\'fas fa-book\'></i>'">
                                    <?php else: ?>
                                        <i class="fas fa-book"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="book-list-info">
                                    <div class="book-list-title"><?php echo htmlspecialchars($favorite['book_title']); ?></div>
                                    <div class="book-list-author"><?php echo htmlspecialchars($favorite['book_author']); ?></div>
                                </div>
                            </div>
                            <div class="book-list-actions">
                                <button class="book-action-btn remove-btn" onclick="removeFavorite('<?php echo htmlspecialchars($favorite['book_key']); ?>')">
                                    <i class="fas fa-trash-alt"></i>
                                    Xóa
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-heart"></i>
                        <h3>Chưa có sách yêu thích</h3>
                        <p>Hãy tìm kiếm và thêm những cuốn sách bạn yêu thích</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Reading List Tab -->
        <div class="tab-content" id="readingListTab">
            <div class="my-books-section">
                <h2 class="my-books-title">
                    <i class="fas fa-list"></i>
                    Danh sách đọc
                </h2>
                
                <?php if (count($reading_list) > 0): ?>
                    <div class="book-list">
                        <?php foreach ($reading_list as $book): ?>
                        <div class="book-list-item">
                            <div class="book-list-header">
                                <div class="book-list-cover">
                                    <?php if ($book['book_cover_id']): ?>
                                        <img src="https://covers.openlibrary.org/b/id/<?php echo $book['book_cover_id']; ?>-S.jpg" alt="Book cover" loading="lazy" onerror="this.parentElement.innerHTML='<i class=\'fas fa-book\'></i>'">
                                    <?php else: ?>
                                        <i class="fas fa-book"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="book-list-info">
                                    <div class="book-list-title"><?php echo htmlspecialchars($book['book_title']); ?></div>
                                    <div class="book-list-author"><?php echo htmlspecialchars($book['book_author']); ?></div>
                                    <div style="margin-top: 0.5rem;">
                                        <?php
                                        $status_labels = [
                                            'want_to_read' => 'Muốn đọc',
                                            'currently_reading' => 'Đang đọc',
                                            'read' => 'Đã đọc'
                                        ];
                                        $status_colors = [
                                            'want_to_read' => 'var(--accent)',
                                            'currently_reading' => 'var(--secondary)',
                                            'read' => 'var(--success)'
                                        ];
                                        ?>
                                        <span style="padding: 0.25rem 0.5rem; background: rgba(112, 0, 255, 0.1); color: <?php echo $status_colors[$book['status']]; ?>; border-radius: var(--radius-sm); font-size: 0.75rem;">
                                            <?php echo $status_labels[$book['status']]; ?>
                                        </span>
                                        <?php if ($book['rating']): ?>
                                        <span style="margin-left: 0.5rem; color: var(--foreground-muted); font-size: 0.75rem;">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <i class="fas fa-star" style="color: <?php echo $i <= $book['rating'] ? '#FFD700' : 'var(--foreground-muted)'; ?>"></i>
                                            <?php endfor; ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php if ($book['notes']): ?>
                            <div style="margin-top: 0.75rem; padding: 0.5rem; background: rgba(255, 255, 255, 0.03); border-radius: var(--radius-sm); font-size: 0.85rem; color: var(--foreground-muted);">
                                <?php echo nl2br(htmlspecialchars($book['notes'])); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-list"></i>
                        <h3>Danh sách đọc trống</h3>
                        <p>Hãy thêm sách vào danh sách đọc của bạn</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Book Detail Modal -->
    <div class="modal fade" id="bookModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-book"></i>
                        Chi tiết sách
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="bookModalBody">
                    <!-- Book details will be populated here -->
                </div>
            </div>
        </div>
    </div>

    <!-- Add to Reading List Modal -->
    <div class="modal fade" id="readingListModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-list"></i>
                        Thêm vào danh sách đọc
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="readingListForm">
                        <input type="hidden" id="readingBookKey">
                        <input type="hidden" id="readingBookTitle">
                        <input type="hidden" id="readingBookAuthor">
                        <input type="hidden" id="readingBookCoverId">
                        
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-bookmark"></i>
                                Trạng thái
                            </label>
                            <select class="form-select" id="readingStatus" required>
                                <option value="want_to_read">Muốn đọc</option>
                                <option value="currently_reading">Đang đọc</option>
                                <option value="read">Đã đọc</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-star"></i>
                                Đánh giá (tùy chọn)
                            </label>
                            <select class="form-select" id="readingRating">
                                <option value="">Chưa đánh giá</option>
                                <option value="1">1 sao</option>
                                <option value="2">2 sao</option>
                                <option value="3">3 sao</option>
                                <option value="4">4 sao</option>
                                <option value="5">5 sao</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-sticky-note"></i>
                                Ghi chú (tùy chọn)
                            </label>
                            <textarea class="form-control" id="readingNotes" rows="3" placeholder="Cảm nhận về cuốn sách..."></textarea>
                        </div>
                        
                        <button type="submit" class="search-btn w-100">
                            <i class="fas fa-plus"></i>
                            Thêm vào danh sách
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        class BookSearchApp {
            constructor() {
                this.currentPage = 1;
                this.limit = 20;
                this.totalResults = 0;
                this.currentQuery = '';
                this.isLoading = false;
                this.favoriteKeys = new Set(<?php echo json_encode(array_column($favorites, 'book_key')); ?>);
                
                this.initializeElements();
                this.attachEventListeners();
                this.createParticles();
                this.animateElements();
            }

            initializeElements() {
                this.searchForm = document.getElementById('searchForm');
                this.searchQuery = document.getElementById('searchQuery');
                this.sortBy = document.getElementById('sortBy');
                this.authorFilter = document.getElementById('authorFilter');
                this.subjectFilter = document.getElementById('subjectFilter');
                this.languageFilter = document.getElementById('languageFilter');
                this.searchBtn = document.getElementById('searchBtn');
                this.loading = document.getElementById('loading');
                this.resultsSection = document.getElementById('resultsSection');
                this.resultsCount = document.getElementById('resultsCount');
                this.booksGrid = document.getElementById('booksGrid');
                this.pagination = document.getElementById('pagination');
                this.paginationInfo = document.getElementById('paginationInfo');
                this.prevBtn = document.getElementById('prevBtn');
                this.nextBtn = document.getElementById('nextBtn');
                this.emptyState = document.getElementById('emptyState');
                this.bookModal = new bootstrap.Modal(document.getElementById('bookModal'));
                this.bookModalBody = document.getElementById('bookModalBody');
                this.readingListModal = new bootstrap.Modal(document.getElementById('readingListModal'));
                this.readingListForm = document.getElementById('readingListForm');
            }

            attachEventListeners() {
                // Navigation tabs
                document.querySelectorAll('.nav-tab').forEach(tab => {
                    tab.addEventListener('click', () => {
                        document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('active'));
                        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                        
                        tab.classList.add('active');
                        document.getElementById(tab.getAttribute('data-target')).classList.add('active');
                    });
                });

                // Search functionality
                this.searchForm.addEventListener('submit', (e) => {
                    e.preventDefault();
                    this.performSearch();
                });

                this.prevBtn.addEventListener('click', () => {
                    if (this.currentPage > 1) {
                        this.currentPage--;
                        this.performSearch();
                    }
                });

                this.nextBtn.addEventListener('click', () => {
                    const maxPage = Math.ceil(this.totalResults / this.limit);
                    if (this.currentPage < maxPage) {
                        this.currentPage++;
                        this.performSearch();
                    }
                });

                // Auto-search on filter change
                [this.authorFilter, this.subjectFilter, this.languageFilter, this.sortBy].forEach(element => {
                    element.addEventListener('change', () => {
                        if (this.currentQuery) {
                            this.currentPage = 1;
                            this.performSearch();
                        }
                    });
                });

                // Reading list form
                this.readingListForm.addEventListener('submit', (e) => {
                    e.preventDefault();
                    this.addToReadingList();
                });
            }

            async performSearch() {
                const query = this.searchQuery.value.trim();
                if (!query) {
                    this.showAlert('Vui lòng nhập từ khóa tìm kiếm', 'error');
                    return;
                }

                this.currentQuery = query;
                this.showLoading();
                
                try {
                    const searchParams = this.buildSearchParams(query);
                    const response = await fetch(`https://openlibrary.org/search.json?${searchParams}`);
                    
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    
                    const data = await response.json();
                    this.displayResults(data);
                } catch (error) {
                    console.error('Search error:', error);
                    this.showAlert('Có lỗi xảy ra khi tìm kiếm. Vui lòng thử lại!', 'error');
                    this.hideLoading();
                }
            }

            buildSearchParams(query) {
                const params = new URLSearchParams();
                
                let searchQuery = query;
                
                if (this.authorFilter.value) {
                    searchQuery += ` author:"${this.authorFilter.value}"`;
                }
                
                if (this.subjectFilter.value) {
                    searchQuery += ` subject:"${this.subjectFilter.value}"`;
                }
                
                params.set('q', searchQuery);
                params.set('fields', 'key,title,author_name,first_publish_year,cover_i,edition_count,ia,has_fulltext,public_scan_b,language');
                params.set('limit', this.limit);
                params.set('offset', (this.currentPage - 1) * this.limit);
                
                if (this.sortBy.value) {
                    params.set('sort', this.sortBy.value);
                }
                
                if (this.languageFilter.value) {
                    params.set('lang', this.languageFilter.value);
                }
                
                return params.toString();
            }

            displayResults(data) {
                this.hideLoading();
                this.totalResults = data.numFound || 0;
                
                if (this.totalResults === 0) {
                    this.showEmptyState();
                    return;
                }
                
                this.resultsCount.textContent = `Tìm thấy ${this.totalResults.toLocaleString()} kết quả`;
                this.booksGrid.innerHTML = '';
                
                data.docs.forEach(book => {
                    const bookCard = this.createBookCard(book);
                    this.booksGrid.appendChild(bookCard);
                });
                
                this.updatePagination();
                this.showResults();
                
                this.resultsSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }

            createBookCard(book) {
                const card = document.createElement('div');
                card.className = 'book-card';
                
                const coverUrl = book.cover_i 
                    ? `https://covers.openlibrary.org/b/id/${book.cover_i}-M.jpg`
                    : null;
                
                const authors = book.author_name ? book.author_name.join(', ') : 'Không rõ tác giả';
                const year = book.first_publish_year || 'Không rõ năm';
                const editions = book.edition_count || 0;
                const isFavorite = this.favoriteKeys.has(book.key);
                
                card.innerHTML = `
                    <div class="book-header" onclick="bookSearchApp.showBookDetails(${JSON.stringify(book).replace(/"/g, '&quot;')})">
                        <div class="book-cover">
                            ${coverUrl 
                                ? `<img src="${coverUrl}" alt="Book cover" loading="lazy" onerror="this.parentElement.innerHTML='<i class=\\'fas fa-book\\'></i>'">`
                                : '<i class="fas fa-book"></i>'
                            }
                        </div>
                        <div class="book-info">
                            <div class="book-title">${this.escapeHtml(book.title)}</div>
                            <div class="book-author">${this.escapeHtml(authors)}</div>
                            <div class="book-year">Năm xuất bản: ${year}</div>
                        </div>
                    </div>
                    <div class="book-meta">
                        <div class="book-editions">${editions} phiên bản</div>
                        <div class="book-availability">
                            ${book.has_fulltext ? '<span class="availability-badge badge-readable">Có thể đọc</span>' : ''}
                            ${book.ia && book.ia.length > 0 ? '<span class="availability-badge badge-ia">Internet Archive</span>' : ''}
                        </div>
                    </div>
                    <div class="book-actions">
                        <button class="book-action-btn btn-favorite ${isFavorite ? 'active' : ''}" onclick="bookSearchApp.toggleFavorite('${book.key}', '${this.escapeHtml(book.title)}', '${this.escapeHtml(authors)}', '${book.cover_i || ''}', '${book.first_publish_year || ''}', this)">
                            <i class="fas fa-heart"></i>
                            ${isFavorite ? 'Đã thích' : 'Yêu thích'}
                        </button>
                        <button class="book-action-btn btn-reading" onclick="bookSearchApp.showReadingListModal('${book.key}', '${this.escapeHtml(book.title)}', '${this.escapeHtml(authors)}', '${book.cover_i || ''}')">
                            <i class="fas fa-list"></i>
                            Thêm vào DS
                        </button>
                    </div>
                `;
                
                return card;
            }

            async showBookDetails(book) {
                try {
                    const workResponse = await fetch(`https://openlibrary.org${book.key}.json`);
                    const workData = await workResponse.json();
                    
                    const coverUrl = book.cover_i 
                        ? `https://covers.openlibrary.org/b/id/${book.cover_i}-L.jpg`
                        : null;
                    
                    const authors = book.author_name ? book.author_name.join(', ') : 'Không rõ tác giả';
                    const description = workData.description 
                        ? (typeof workData.description === 'string' ? workData.description : workData.description.value)
                        : 'Chưa có mô tả';
                    
                    const subjects = workData.subjects ? workData.subjects.slice(0, 10).join(', ') : 'Không có';
                    
                    this.bookModalBody.innerHTML = `
                        <div class="row">
                            <div class="col-md-4 text-center mb-3">
                                ${coverUrl 
                                    ? `<img src="${coverUrl}" alt="Book cover" class="img-fluid" style="max-height: 300px; border-radius: var(--radius);">`
                                    : '<div style="height: 300px; display: flex; align-items: center; justify-content: center; background: var(--surface); border-radius: var(--radius);"><i class="fas fa-book" style="font-size: 3rem; color: var(--foreground-muted);"></i></div>'
                                }
                            </div>
                            <div class="col-md-8">
                                <h4 style="color: var(--foreground); margin-bottom: 1rem;">${this.escapeHtml(book.title)}</h4>
                                <p><strong style="color: var(--primary-light);">Tác giả:</strong> ${this.escapeHtml(authors)}</p>
                                <p><strong style="color: var(--primary-light);">Năm xuất bản:</strong> ${book.first_publish_year || 'Không rõ'}</p>
                                <p><strong style="color: var(--primary-light);">Số phiên bản:</strong> ${book.edition_count || 0}</p>
                                <p><strong style="color: var(--primary-light);">Chủ đề:</strong> ${this.escapeHtml(subjects)}</p>
                                
                                <div style="margin: 1rem 0;">
                                    ${book.has_fulltext ? '<span class="availability-badge badge-readable">Có thể đọc</span>' : ''}
                                    ${book.ia && book.ia.length > 0 ? '<span class="availability-badge badge-ia">Internet Archive</span>' : ''}
                                </div>
                                
                                <h5 style="color: var(--secondary); margin-top: 1.5rem; margin-bottom: 0.5rem;">Mô tả:</h5>
                                <div style="color: var(--foreground-muted); line-height: 1.6; max-height: 200px; overflow-y: auto;">
                                    ${this.escapeHtml(description)}
                                </div>
                                
                                ${book.ia && book.ia.length > 0 ? `
                                    <div style="margin-top: 1.5rem;">
                                        <a href="https://archive.org/details/${book.ia[0]}" target="_blank" class="search-btn">
                                            <i class="fas fa-external-link-alt"></i>
                                            Xem trên Internet Archive
                                        </a>
                                    </div>
                                ` : ''}
                            </div>
                        </div>
                    `;
                    
                    this.bookModal.show();
                } catch (error) {
                    console.error('Error fetching book details:', error);
                    this.showAlert('Không thể tải chi tiết sách', 'error');
                }
            }

            async toggleFavorite(bookKey, title, author, coverId, year, buttonElement) {
                const isFavorite = this.favoriteKeys.has(bookKey);
                const action = isFavorite ? 'remove_favorite' : 'add_favorite';
                
                try {
                    const formData = new FormData();
                    formData.append('action', action);
                    formData.append('book_key', bookKey);
                    formData.append('book_title', title);
                    formData.append('book_author', author);
                    formData.append('book_cover_id', coverId);
                    formData.append('first_publish_year', year);
                    
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        if (isFavorite) {
                            this.favoriteKeys.delete(bookKey);
                            buttonElement.classList.remove('active');
                            buttonElement.innerHTML = '<i class="fas fa-heart"></i> Yêu thích';
                        } else {
                            this.favoriteKeys.add(bookKey);
                            buttonElement.classList.add('active');
                            buttonElement.innerHTML = '<i class="fas fa-heart"></i> Đã thích';
                        }
                        this.showAlert(result.message, 'success');
                    } else {
                        this.showAlert(result.message, 'error');
                    }
                } catch (error) {
                    console.error('Error toggling favorite:', error);
                    this.showAlert('Có lỗi xảy ra', 'error');
                }
            }

            showReadingListModal(bookKey, title, author, coverId) {
                document.getElementById('readingBookKey').value = bookKey;
                document.getElementById('readingBookTitle').value = title;
                document.getElementById('readingBookAuthor').value = author;
                document.getElementById('readingBookCoverId').value = coverId;
                
                this.readingListModal.show();
            }

            async addToReadingList() {
                try {
                    const formData = new FormData();
                    formData.append('action', 'add_to_reading_list');
                    formData.append('book_key', document.getElementById('readingBookKey').value);
                    formData.append('book_title', document.getElementById('readingBookTitle').value);
                    formData.append('book_author', document.getElementById('readingBookAuthor').value);
                    formData.append('book_cover_id', document.getElementById('readingBookCoverId').value);
                    formData.append('status', document.getElementById('readingStatus').value);
                    formData.append('rating', document.getElementById('readingRating').value);
                    formData.append('notes', document.getElementById('readingNotes').value);
                    
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        this.readingListModal.hide();
                        this.showAlert(result.message, 'success');
                        // Reset form
                        this.readingListForm.reset();
                    } else {
                        this.showAlert(result.message, 'error');
                    }
                } catch (error) {
                    console.error('Error adding to reading list:', error);
                    this.showAlert('Có lỗi xảy ra', 'error');
                }
            }

            updatePagination() {
                const maxPage = Math.ceil(this.totalResults / this.limit);
                
                this.prevBtn.disabled = this.currentPage <= 1;
                this.nextBtn.disabled = this.currentPage >= maxPage;
                
                this.paginationInfo.textContent = `Trang ${this.currentPage} / ${maxPage}`;
                
                if (maxPage > 1) {
                    this.pagination.style.display = 'flex';
                } else {
                    this.pagination.style.display = 'none';
                }
            }

            showLoading() {
                this.isLoading = true;
                this.searchBtn.disabled = true;
                this.searchBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Đang tìm kiếm...';
                this.loading.style.display = 'block';
                this.resultsSection.style.display = 'none';
                this.emptyState.style.display = 'none';
            }

            hideLoading() {
                this.isLoading = false;
                this.searchBtn.disabled = false;
                this.searchBtn.innerHTML = '<i class="fas fa-search"></i> Tìm kiếm';
                this.loading.style.display = 'none';
            }

            showResults() {
                this.resultsSection.style.display = 'block';
                this.emptyState.style.display = 'none';
            }

            showEmptyState() {
                this.resultsSection.style.display = 'none';
                this.emptyState.style.display = 'block';
            }

            showAlert(message, type = 'success') {
                const alertContainer = document.getElementById('alertContainer');
                const alertDiv = document.createElement('div');
                alertDiv.className = `alert-message alert-${type}`;
                alertDiv.innerHTML = `
                    <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
                    ${message}
                `;
                alertContainer.appendChild(alertDiv);
                
                setTimeout(() => {
                    alertDiv.remove();
                }, 3500);
            }

            escapeHtml(text) {
                if (!text) return '';
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            createParticles() {
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

            animateElements() {
                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            entry.target.style.opacity = '1';
                            entry.target.style.transform = 'translateY(0)';
                            observer.unobserve(entry.target);
                        }
                    });
                });

                document.querySelectorAll('.search-section, .book-card, .my-books-section').forEach(el => {
                    el.style.opacity = '0';
                    el.style.transform = 'translateY(20px)';
                    el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    observer.observe(el);
                });
            }
        }

        // Global functions for onclick handlers
        let bookSearchApp;

        async function removeFavorite(bookKey) {
            if (!confirm('Bạn có chắc muốn xóa sách này khỏi danh sách yêu thích?')) {
                return;
            }

            try {
                const formData = new FormData();
                formData.append('action', 'remove_favorite');
                formData.append('book_key', bookKey);
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    bookSearchApp.showAlert(result.message, 'success');
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    bookSearchApp.showAlert(result.message, 'error');
                }
            } catch (error) {
                console.error('Error removing favorite:', error);
                bookSearchApp.showAlert('Có lỗi xảy ra', 'error');
            }
        }

        // Initialize the app when the DOM is loaded
        document.addEventListener('DOMContentLoaded', () => {
            bookSearchApp = new BookSearchApp();
        });
    </script>

    <?php if ($success_message): ?>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            bookSearchApp.showAlert('<?php echo addslashes($success_message); ?>', 'success');
        });
    </script>
    <?php endif; ?>

    <?php if ($error_message): ?>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            bookSearchApp.showAlert('<?php echo addslashes($error_message); ?>', 'error');
        });
    </script>
    <?php endif; ?>
</body>
</html>