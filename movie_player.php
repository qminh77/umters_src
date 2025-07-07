<?php
session_start();
include 'db_config.php'; // File cấu hình database hiện có
include 'movie_config.php'; // File cấu hình API phim

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$movie_data = null;
$error_message = '';
$success_message = '';
$search_results = [];
$new_movies = [];
$current_page = isset($_GET['new_movies_page']) ? (int)$_GET['new_movies_page'] : 1;
if ($current_page < 1) $current_page = 1;

// Function to process image URL
function processImageUrl($url, $default = null) {
    global $movie_api_config;
    
    if (empty($url)) {
        return $default ?: $movie_api_config['default_thumb'];
    }
    
    // If URL already has protocol, return as is
    if (preg_match("~^(?:f|ht)tps?://~i", $url)) {
        return $url;
    }
    
    // If URL starts with //, add https:
    if (strpos($url, '//') === 0) {
        return "https:" . $url;
    }
    
    // If URL is relative, add https://
    if (strpos($url, '/') === 0) {
        return "https://phimimg.com" . $url;
    }
    
    return $url;
}

// Xử lý tìm kiếm phim bằng từ khóa qua AJAX hoặc POST thông thường
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search_movie'])) {
    $keyword = trim($_POST['movie_keyword']);
    if (!empty($keyword)) {
        $api_url = "https://phimapi.com/v1/api/tim-kiem?keyword=" . urlencode($keyword) . "&page=1&limit=10";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: Mozilla/5.0 (compatible; MoviePlayer/1.0)',
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            $error_message = "Lỗi cURL: " . curl_error($ch) . " (Mã lỗi: " . curl_errno($ch) . ")";
        } else if ($http_code === 200 && $response) {
            $search_data = json_decode($response, true);
            if ($search_data && isset($search_data['status']) && $search_data['status']) {
                $search_results = $search_data['data']['items'];
                // Process image URLs for search results
                foreach ($search_results as &$result) {
                    $result['thumb_url'] = processImageUrl($result['thumb_url'] ?? '');
                    $result['poster_url'] = processImageUrl($result['poster_url'] ?? '', $movie_api_config['default_poster']);
                }
                $success_message = "Đã tìm thấy " . count($search_results) . " kết quả cho '$keyword'.";
            } else {
                $error_message = "Không tìm thấy phim nào với từ khóa '$keyword'.";
            }
        } else {
            $error_message = "Lỗi khi kết nối API tìm kiếm: HTTP $http_code";
        }
        curl_close($ch);
    } else {
        $error_message = "Vui lòng nhập tên phim để tìm kiếm!";
    }
}

// Xử lý chọn phim từ kết quả tìm kiếm
if (isset($_GET['slug'])) {
    $slug = trim($_GET['slug']);
    $api_url = $movie_api_config['api_base_url'] . urlencode($slug);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: Mozilla/5.0 (compatible; MoviePlayer/1.0)',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false) {
        $error_message = "Lỗi cURL: " . curl_error($ch) . " (Mã lỗi: " . curl_errno($ch) . ")";
    } else if ($http_code === 200 && $response) {
        $movie_data = json_decode($response, true);
        if ($movie_data && isset($movie_data['status']) && $movie_data['status']) {
            // Process image URLs for movie data
            if (isset($movie_data['movie'])) {
                $movie_data['movie']['poster_url'] = processImageUrl($movie_data['movie']['poster_url'] ?? '', $movie_api_config['default_poster']);
                $movie_data['movie']['thumb_url'] = processImageUrl($movie_data['movie']['thumb_url'] ?? '');
            }
            $success_message = "Đã tìm thấy phim: " . htmlspecialchars($movie_data['movie']['name']);
        } else {
            $error_message = "Không tìm thấy phim với slug '$slug'.";
        }
    } else {
        $error_message = "Lỗi khi kết nối API chi tiết: HTTP $http_code";
    }
    curl_close($ch);
}

// Lấy danh sách phim mới cập nhật
function fetchNewMovies($page = 1) {
    global $movie_api_config;
    $api_url = "https://phimapi.com/danh-sach/phim-moi-cap-nhat?page=" . $page;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: Mozilla/5.0 (compatible; MoviePlayer/1.0)',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false) {
        return ['error' => "Lỗi cURL: " . curl_error($ch)];
    } else if ($http_code === 200 && $response) {
        $data = json_decode($response, true);
        if ($data && isset($data['status']) && $data['status']) {
            // Process image URLs for new movies
            if (isset($data['items'])) {
                foreach ($data['items'] as &$movie) {
                    $movie['thumb_url'] = processImageUrl($movie['thumb_url'] ?? '');
                    $movie['poster_url'] = processImageUrl($movie['poster_url'] ?? '', $movie_api_config['default_poster']);
                }
            }
            return $data;
        } else {
            return ['error' => "Không tìm thấy dữ liệu phim mới."];
        }
    } else {
        return ['error' => "Lỗi khi kết nối API: HTTP $http_code"];
    }
    curl_close($ch);
}

// Lấy phim mới với trang hiện tại (chỉ khi không xem phim)
if (!isset($_GET['slug'])) {
    $new_movies_data = fetchNewMovies($current_page);
    if (!isset($new_movies_data['error'])) {
        $new_movies = $new_movies_data['items'];
        $total_pages = $new_movies_data['pagination']['totalPages'];
    } else {
        $total_pages = 1;
    }
}

// Xử lý phát phim
$selected_episode = isset($_GET['episode']) ? (int)$_GET['episode'] : 0;
$selected_server = isset($_GET['server']) ? (int)$_GET['server'] : 0;
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VinPhim - Tìm Kiếm & Phát Phim</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.plyr.io/3.7.8/plyr.css" rel="stylesheet" />
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
            --warning: #FFB800;
            --warning-light: #FFD155;
            --warning-dark: #E6A600;
            
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
            line-height: 1.6;
            overflow-x: hidden;
            background-image: 
                radial-gradient(circle at 20% 30%, rgba(112, 0, 255, 0.15) 0%, transparent 40%),
                radial-gradient(circle at 80% 70%, rgba(0, 224, 255, 0.15) 0%, transparent 40%),
                radial-gradient(circle at 50% 50%, rgba(255, 61, 255, 0.1) 0%, transparent 60%);
            background-attachment: fixed;
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

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        @keyframes slideUp {
            from { transform: translateY(30px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        @keyframes zoomIn {
            from { transform: scale(0.9); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
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

        /* Container */
        .container {
            width: 100%;
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 1.5rem;
            position: relative;
            z-index: 1;
        }

        /* Header */
        .header {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            position: sticky;
            top: 0;
            z-index: 100;
            padding: 1rem 0;
            border-bottom: 1px solid var(--border);
            border-radius: 0 0 var(--radius-lg) var(--radius-lg);
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
        }

        .header::before {
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

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--foreground);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            transition: all 0.3s ease;
        }

        .logo:hover {
            transform: translateY(-2px);
        }

        .logo i {
            font-size: 2rem;
            background: linear-gradient(45deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            animation: pulse 2s infinite;
        }

        .logo span {
            background: linear-gradient(90deg, var(--secondary), var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .nav-links {
            display: flex;
            gap: 2rem;
        }

        .nav-link {
            color: var(--foreground-muted);
            text-decoration: none;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 500;
            padding: 0.5rem 1rem;
            border-radius: var(--radius-full);
        }

        .nav-link:hover, .nav-link.active {
            color: var(--foreground);
            background: rgba(112, 0, 255, 0.1);
            transform: translateY(-2px);
            box-shadow: var(--glow);
        }

        .nav-link.active {
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            color: var(--foreground);
        }

        /* Main Content */
        .main {
            min-height: calc(100vh - 200px);
        }

        .section-title {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 2rem;
            position: relative;
            display: inline-block;
            background: linear-gradient(90deg, var(--foreground), var(--primary-light));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .section-title::after {
            content: '';
            position: absolute;
            bottom: -0.5rem;
            left: 0;
            width: 60%;
            height: 3px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            border-radius: var(--radius-full);
        }

        /* Search Form */
        .search-form {
            margin-bottom: 3rem;
            position: relative;
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 2rem;
            animation: slideUp 0.6s ease-out;
        }

        .search-input {
            width: 100%;
            padding: 1.25rem 1.5rem;
            padding-right: 4rem;
            background: rgba(255, 255, 255, 0.05);
            border: 2px solid var(--border);
            border-radius: var(--radius);
            color: var(--foreground);
            font-size: 1.1rem;
            transition: all 0.3s ease;
            font-family: 'Outfit', sans-serif;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(112, 0, 255, 0.2);
            background: rgba(255, 255, 255, 0.08);
        }

        .search-input::placeholder {
            color: var(--foreground-muted);
        }

        .search-button {
            position: absolute;
            right: 2.75rem;
            top: 50%;
            transform: translateY(-50%);
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            color: var(--foreground);
            border: none;
            border-radius: var(--radius-full);
            width: 3rem;
            height: 3rem;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            box-shadow: var(--shadow-sm);
        }

        .search-button:hover {
            background: linear-gradient(90deg, var(--primary-light), var(--primary));
            transform: translateY(-50%) scale(1.1);
            box-shadow: var(--glow);
        }

        .search-button:active {
            transform: translateY(-50%) scale(0.95);
        }

        /* Messages */
        .message {
            padding: 1.25rem 1.75rem;
            border-radius: var(--radius);
            margin-bottom: 2rem;
            position: relative;
            padding-left: 3.5rem;
            animation: slideUp 0.5s ease-out;
            border: 1px solid transparent;
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }

        .message-success {
            background: rgba(0, 255, 133, 0.1);
            border-color: var(--success-dark);
            color: var(--success);
            box-shadow: 0 0 20px rgba(0, 255, 133, 0.2);
        }

        .message-error {
            background: rgba(255, 61, 87, 0.1);
            border-color: var(--danger-dark);
            color: var(--danger);
            box-shadow: 0 0 20px rgba(255, 61, 87, 0.2);
        }

        .message-info {
            background: rgba(0, 224, 255, 0.1);
            border-color: var(--secondary-dark);
            color: var(--secondary);
            box-shadow: 0 0 20px rgba(0, 224, 255, 0.2);
        }

        .message::before {
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            left: 1.25rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1.25rem;
        }

        .message-success::before {
            content: "\f00c";
            color: var(--success);
        }

        .message-error::before {
            content: "\f071";
            color: var(--danger);
        }

        .message-info::before {
            content: "\f05a";
            color: var(--secondary);
        }

        /* Movie Grid */
        .movie-section {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 2rem;
            margin-bottom: 2rem;
            animation: slideUp 0.8s ease-out;
        }

        .movie-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .movie-card {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.22, 1, 0.36, 1);
            position: relative;
            box-shadow: var(--shadow-sm);
            animation: zoomIn 0.6s ease-out;
            animation-fill-mode: both;
        }

        .movie-card:hover {
            transform: translateY(-12px) scale(1.02);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary-light);
        }

        .movie-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 2px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            transform: scaleX(0);
            transition: transform 0.4s ease;
        }

        .movie-card:hover::before {
            transform: scaleX(1);
        }

        .movie-poster {
            position: relative;
            overflow: hidden;
            aspect-ratio: 2/3;
            background: var(--surface);
        }

        .movie-poster img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: all 0.4s ease;
        }

        .movie-card:hover .movie-poster img {
            transform: scale(1.1);
        }

        .movie-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(
                to bottom,
                rgba(0, 0, 0, 0) 0%,
                rgba(0, 0, 0, 0.3) 50%,
                rgba(0, 0, 0, 0.8) 100%
            );
            opacity: 0;
            transition: all 0.4s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .movie-card:hover .movie-overlay {
            opacity: 1;
        }

        .play-button {
            background: linear-gradient(90deg, var(--primary), var(--accent));
            color: white;
            width: 4rem;
            height: 4rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transform: scale(0);
            transition: all 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55);
            box-shadow: var(--shadow);
            font-size: 1.5rem;
        }

        .movie-card:hover .play-button {
            transform: scale(1);
        }

        .play-button:hover {
            transform: scale(1.1);
            box-shadow: var(--glow);
        }

        .movie-info {
            padding: 1.5rem;
            background: rgba(0, 0, 0, 0.3);
        }

        .movie-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            line-height: 1.4;
            color: var(--foreground);
            transition: color 0.3s ease;
        }

        .movie-card:hover .movie-title {
            color: var(--primary-light);
        }

        .movie-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-top: auto;
        }

        .movie-year, .movie-lang, .movie-date {
            font-size: 0.85rem;
            color: var(--foreground-muted);
            display: flex;
            align-items: center;
            gap: 0.375rem;
        }

        .movie-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            padding: 0.375rem 0.75rem;
            background: linear-gradient(90deg, var(--accent), var(--accent-dark));
            color: white;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
            z-index: 10;
            box-shadow: var(--shadow-sm);
            animation: pulse 2s infinite;
        }

        /* Video Player Container */
        .video-player-container {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow-lg);
            padding: 2rem;
            margin: 2rem 0;
            animation: zoomIn 0.8s ease-out;
        }

        .video-player {
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow-lg);
            background: #000;
            position: relative;
        }

        /* Custom Plyr Styling */
        .plyr {
            --plyr-color-main: var(--primary);
            --plyr-video-control-color: var(--foreground);
            --plyr-video-control-color-hover: var(--primary-light);
            --plyr-video-control-background-hover: rgba(112, 0, 255, 0.2);
            --plyr-audio-control-background-hover: rgba(112, 0, 255, 0.2);
            --plyr-video-progress-buffered-background: rgba(255, 255, 255, 0.25);
            --plyr-range-thumb-background: var(--primary);
            --plyr-range-track-background: rgba(255, 255, 255, 0.2);
            --plyr-range-fill-background: var(--primary);
        }

        .plyr__control {
            transition: all 0.3s ease;
        }

        .plyr__control:hover {
            background: var(--plyr-video-control-background-hover) !important;
            transform: scale(1.1);
        }

        .plyr__control--pressed {
            background: var(--primary) !important;
        }

        .plyr--full-ui input[type=range] {
            background: transparent;
        }

        .plyr__progress input[type=range]::-webkit-slider-thumb {
            background: var(--primary);
            box-shadow: 0 0 10px rgba(112, 0, 255, 0.5);
        }

        .plyr__progress input[type=range]::-moz-range-thumb {
            background: var(--primary);
            box-shadow: 0 0 10px rgba(112, 0, 255, 0.5);
        }

        /* Movie Detail */
        .movie-detail-container {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 2rem;
            margin-bottom: 2rem;
            animation: slideUp 0.8s ease-out;
        }

        .movie-detail {
            display: flex;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .movie-detail-poster {
            flex: 0 0 300px;
            position: relative;
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow-lg);
            transition: all 0.4s ease;
        }

        .movie-detail-poster:hover {
            transform: scale(1.02);
            box-shadow: var(--shadow-lg), var(--glow);
        }

        .movie-detail-poster img {
            width: 100%;
            height: auto;
            display: block;
            transition: all 0.4s ease;
        }

        .movie-detail-info {
            flex: 1;
            min-width: 300px;
        }

        .movie-detail-title {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            background: linear-gradient(90deg, var(--foreground), var(--primary-light), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            line-height: 1.2;
        }

        .movie-detail-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .meta-item {
            background: rgba(255, 255, 255, 0.05);
            padding: 1rem;
            border-radius: var(--radius);
            border: 1px solid var(--border);
            transition: all 0.3s ease;
        }

        .meta-item:hover {
            background: rgba(255, 255, 255, 0.08);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .meta-label {
            font-size: 0.9rem;
            color: var(--foreground-muted);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 500;
        }

        .meta-label i {
            color: var(--primary-light);
            font-size: 1rem;
        }

        .meta-value {
            font-size: 1rem;
            font-weight: 500;
            color: var(--foreground);
        }

        .movie-detail-plot {
            margin-bottom: 2rem;
            line-height: 1.8;
            background: rgba(255, 255, 255, 0.05);
            padding: 1.5rem;
            border-radius: var(--radius);
            border: 1px solid var(--border);
        }

        .movie-detail-plot-label {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--primary-light);
        }

        .movie-detail-plot-label i {
            color: var(--secondary);
        }

        /* Episodes */
        .episodes-container {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 2rem;
            margin: 2rem 0;
            animation: slideUp 1s ease-out;
        }

        .server-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .server-tab {
            padding: 1rem 1.5rem;
            background: rgba(255, 255, 255, 0.05);
            color: var(--foreground);
            border-radius: var(--radius-full);
            text-decoration: none;
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            border: 1px solid var(--border);
        }

        .server-tab i {
            color: var(--primary-light);
            transition: color 0.3s ease;
        }

        .server-tab:hover {
            background: rgba(112, 0, 255, 0.1);
            transform: translateY(-3px);
            box-shadow: var(--shadow-sm);
            color: var(--foreground);
        }

        .server-tab.active {
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            transform: translateY(-3px);
            box-shadow: var(--glow);
            color: var(--foreground);
        }

        .server-tab.active i {
            color: var(--foreground);
        }

        .episodes-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .episode-button {
            min-width: 3rem;
            height: 3rem;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.05);
            color: var(--foreground);
            border-radius: var(--radius);
            text-decoration: none;
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            font-weight: 500;
            border: 1px solid var(--border);
            padding: 0 1rem;
        }

        .episode-button:hover {
            background: rgba(112, 0, 255, 0.1);
            transform: translateY(-3px);
            box-shadow: var(--shadow-sm);
            color: var(--foreground);
        }

        .episode-button.active {
            background: linear-gradient(90deg, var(--accent), var(--accent-dark));
            transform: translateY(-3px);
            box-shadow: var(--glow-accent);
            color: var(--foreground);
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.75rem;
            margin: 2rem 0;
            flex-wrap: wrap;
        }

        .pagination-item {
            min-width: 3rem;
            height: 3rem;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.05);
            color: var(--foreground);
            border-radius: var(--radius);
            text-decoration: none;
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            font-weight: 500;
            border: 1px solid var(--border);
        }

        .pagination-item:hover {
            background: rgba(112, 0, 255, 0.1);
            transform: translateY(-3px);
            box-shadow: var(--shadow-sm);
            color: var(--foreground);
        }

        .pagination-item.active {
            background: linear-gradient(90deg, var(--secondary), var(--secondary-dark));
            transform: translateY(-3px);
            box-shadow: var(--glow-secondary);
            color: var(--foreground);
        }

        /* Back Button */
        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem 2rem;
            background: linear-gradient(90deg, var(--surface), var(--surface-light));
            color: var(--foreground);
            border-radius: var(--radius-full);
            text-decoration: none;
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            font-weight: 500;
            margin-top: 2rem;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-sm);
        }

        .back-button:hover {
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            transform: translateY(-3px);
            box-shadow: var(--glow);
            color: var(--foreground);
        }

        /* Footer */
        .footer {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            padding: 2rem 0;
            border-top: 1px solid var(--border);
            margin-top: 4rem;
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
        }

        .footer-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1.5rem;
        }

        .footer-logo {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--foreground);
            text-decoration: none;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .footer-links {
            display: flex;
            gap: 2rem;
        }

        .footer-link {
            color: var(--foreground-muted);
            text-decoration: none;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .footer-link:hover {
            color: var(--primary-light);
            transform: translateY(-2px);
        }

        .footer-copyright {
            width: 100%;
            text-align: center;
            margin-top: 1.5rem;
            color: var(--foreground-muted);
            font-size: 0.9rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border);
        }

        /* Scroll to top button */
        .scroll-top {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            width: 3.5rem;
            height: 3.5rem;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            color: var(--foreground);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--shadow-lg);
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            opacity: 0;
            visibility: hidden;
            z-index: 99;
            border: none;
        }

        .scroll-top.visible {
            opacity: 1;
            visibility: visible;
        }

        .scroll-top:hover {
            transform: translateY(-5px) scale(1.1);
            box-shadow: var(--glow);
        }

        /* Loading States */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(10, 10, 26, 0.8);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .loading-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .loading-spinner {
            width: 60px;
            height: 60px;
            border: 3px solid var(--border);
            border-top: 3px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 1rem;
        }

        .loading-content {
            text-align: center;
            color: var(--foreground);
        }

        .loading-text {
            font-size: 1.1rem;
            font-weight: 500;
            margin-top: 1rem;
            color: var(--foreground-muted);
        }

        /* Card Loading State */
        .movie-card.loading {
            pointer-events: none;
            opacity: 0.7;
            transform: scale(0.98);
        }

        .movie-card.loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 30px;
            height: 30px;
            border: 2px solid transparent;
            border-top: 2px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            transform: translate(-50%, -50%);
            z-index: 10;
        }

        /* Skeleton Loading */
        .skeleton {
            background: linear-gradient(90deg, var(--card) 25%, var(--card-hover) 50%, var(--card) 75%);
            background-size: 200% 100%;
            animation: loading 1.5s infinite;
            border-radius: var(--radius);
        }

        @keyframes loading {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        .skeleton-movie-detail {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 2rem;
            margin-bottom: 2rem;
            animation: slideUp 0.6s ease-out;
        }

        .skeleton-detail-layout {
            display: flex;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .skeleton-poster {
            flex: 0 0 300px;
            height: 450px;
            background: var(--card);
            border-radius: var(--radius);
            animation: loading 1.5s infinite;
        }

        .skeleton-info {
            flex: 1;
            min-width: 300px;
        }

        .skeleton-title {
            height: 60px;
            width: 80%;
            background: var(--card);
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            animation: loading 1.5s infinite;
        }

        .skeleton-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .skeleton-meta-item {
            height: 80px;
            background: var(--card);
            border-radius: var(--radius);
            animation: loading 1.5s infinite;
        }

        .skeleton-plot {
            height: 120px;
            background: var(--card);
            border-radius: var(--radius);
            animation: loading 1.5s infinite;
        }

        .skeleton-player {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow-lg);
            padding: 2rem;
            margin: 2rem 0;
        }

        .skeleton-video {
            width: 100%;
            height: 400px;
            background: var(--card);
            border-radius: var(--radius);
            animation: loading 1.5s infinite;
        }

        .skeleton-episodes {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 2rem;
            margin: 2rem 0;
        }

        .skeleton-episodes-title {
            height: 40px;
            width: 300px;
            background: var(--card);
            border-radius: var(--radius);
            margin-bottom: 2rem;
            animation: loading 1.5s infinite;
        }

        .skeleton-server-tabs {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .skeleton-server-tab {
            width: 120px;
            height: 45px;
            background: var(--card);
            border-radius: var(--radius-full);
            animation: loading 1.5s infinite;
        }

        .skeleton-episodes-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .skeleton-episode {
            width: 50px;
            height: 45px;
            background: var(--card);
            border-radius: var(--radius);
            animation: loading 1.5s infinite;
        }

        /* Progress Bar */
        .progress-bar {
            position: fixed;
            top: 0;
            left: 0;
            width: 0%;
            height: 3px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            z-index: 1001;
            transition: width 0.3s ease;
        }

        /* Smooth fade transitions */
        .fade-out {
            opacity: 0;
            transform: translateY(20px);
            transition: all 0.4s ease;
        }

        .fade-in {
            opacity: 1;
            transform: translateY(0);
            transition: all 0.4s ease;
        }

        /* Mobile Video Player Optimizations */
        @media (max-width: 768px) {
            .video-player {
                margin: 0 -1rem;
                border-radius: 0;
            }

            .video-player-container {
                padding: 1rem;
                margin: 1rem 0;
            }

            /* Mobile fullscreen optimizations */
            .plyr--fullscreen {
                position: fixed !important;
                top: 0 !important;
                left: 0 !important;
                width: 100vw !important;
                height: 100vh !important;
                z-index: 9999 !important;
                background: #000 !important;
                transform: none !important;
                margin: 0 !important;
            }

            .plyr--fullscreen video {
                width: 100% !important;
                height: 100% !important;
                object-fit: contain !important;
                position: absolute !important;
                top: 0 !important;
                left: 0 !important;
            }

            .plyr--fullscreen .plyr__video-wrapper {
                width: 100% !important;
                height: 100% !important;
            }

            /* Hide mobile browser UI in fullscreen */
            .plyr--fullscreen .plyr__controls {
                background: linear-gradient(transparent, rgba(0, 0, 0, 0.8)) !important;
            }

            /* Landscape mode enhancements */
            @media (orientation: landscape) {
                .plyr--fullscreen {
                    width: 100vh !important;
                    height: 100vw !important;
                }
            }
        }

        /* iOS specific fixes */
        @supports (-webkit-appearance: none) {
            .plyr--fullscreen {
                -webkit-transform: none !important;
            }
            
            .plyr--fullscreen video {
                -webkit-transform: none !important;
            }
        }

        /* Responsive Design */
        @media (max-width: 992px) {
            .container {
                padding: 0 1rem;
            }

            .header-content {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }

            .nav-links {
                gap: 1rem;
            }

            .movie-grid {
                grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
                gap: 1.5rem;
            }

            .movie-detail {
                flex-direction: column;
            }

            .movie-detail-poster {
                max-width: 300px;
                margin: 0 auto;
            }

            .movie-detail-title {
                font-size: 2rem;
            }

            .section-title {
                font-size: 1.75rem;
            }
        }

        @media (max-width: 768px) {
            .movie-grid {
                grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
                gap: 1rem;
            }

            .movie-detail-title {
                font-size: 1.75rem;
            }

            .movie-detail-meta {
                grid-template-columns: 1fr;
            }

            .server-tabs, .episodes-grid {
                gap: 0.75rem;
            }

            .pagination {
                gap: 0.5rem;
            }

            .pagination-item, .episode-button, .server-tab {
                min-width: 2.5rem;
                height: 2.5rem;
                font-size: 0.9rem;
            }
        }

        @media (max-width: 576px) {
            .section-title {
                font-size: 1.5rem;
            }

            .movie-detail-title {
                font-size: 1.5rem;
            }

            .logo {
                font-size: 1.5rem;
            }

            .nav-links {
                flex-direction: column;
                width: 100%;
            }

            .footer-content {
                flex-direction: column;
                text-align: center;
            }
        }

        /* Animation delays for staggered effects */
        .movie-card:nth-child(1) { animation-delay: 0.1s; }
        .movie-card:nth-child(2) { animation-delay: 0.2s; }
        .movie-card:nth-child(3) { animation-delay: 0.3s; }
        .movie-card:nth-child(4) { animation-delay: 0.4s; }
        .movie-card:nth-child(5) { animation-delay: 0.5s; }
        .movie-card:nth-child(6) { animation-delay: 0.6s; }
        .movie-card:nth-child(7) { animation-delay: 0.7s; }
        .movie-card:nth-child(8) { animation-delay: 0.8s; }
        .movie-card:nth-child(n+9) { animation-delay: 0.9s; }
    </style>
</head>
<body>
    <div class="particles" id="particles"></div>

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-content">
            <div class="loading-spinner"></div>
            <div class="loading-text" id="loadingText">Đang tải phim...</div>
        </div>
    </div>

    <!-- Progress Bar -->
    <div class="progress-bar" id="progressBar"></div>

    <!-- Header -->
    <header class="header">
        <div class="container">
            <div class="header-content">
                <a href="/movie_player" class="logo">
                    <i class="fas fa-film"></i>
                    <span>VinPhim</span>
                </a>
                <div class="nav-links">
                    <a href="/movie_player" class="nav-link active">
                        <i class="fas fa-home"></i> Trang chủ
                    </a>
                    <a href="/movie_filter" class="nav-link">
                        <i class="fas fa-filter"></i> Lọc phim
                    </a>
                    <a href="/dashboard" class="nav-link">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="main">
        <div class="container">
            <!-- Search Form -->
            <div class="search-form">
                <form id="search-form" method="POST">
                    <input type="text" name="movie_keyword" class="search-input" placeholder="Nhập tên phim (ví dụ: Bố già, Avengers, Game of Thrones...)" required>
                    <button type="submit" name="search_movie" class="search-button">
                        <i class="fas fa-search"></i>
                    </button>
                </form>
            </div>

            <!-- Messages -->
            <div id="message-container">
                <?php if ($error_message): ?>
                    <div class="message message-error"><?php echo $error_message; ?></div>
                <?php endif; ?>
                <?php if ($success_message): ?>
                    <div class="message message-success"><?php echo $success_message; ?></div>
                <?php endif; ?>
            </div>

            <?php if (!isset($_GET['slug'])): ?>
                <!-- Search Results -->
                <?php if (!empty($search_results)): ?>
                    <section class="movie-section search-results">
                        <h2 class="section-title">Kết quả tìm kiếm</h2>
                        <div class="movie-grid">
                            <?php foreach ($search_results as $i => $result): ?>
                                <?php
                                $thumb_url = $result['thumb_url'];
                                $lang_display = isset($result['lang']) ? htmlspecialchars($result['lang']) : 'Không rõ';
                                $lang_display = str_replace(['vietsub', 'thuyet-minh', 'long-tieng'], ['Vietsub', 'Thuyết Minh', 'Lồng Tiếng'], strtolower($lang_display));
                                ?>
                                <div class="movie-card" data-slug="<?php echo htmlspecialchars($result['slug']); ?>">
                                    <div class="movie-badge"><?php echo $lang_display; ?></div>
                                    <div class="movie-link" data-slug="<?php echo htmlspecialchars($result['slug']); ?>">
                                        <div class="movie-poster">
                                            <img src="<?php echo htmlspecialchars($thumb_url); ?>" 
                                                alt="<?php echo htmlspecialchars($result['name']); ?>" 
                                                onerror="this.src='<?php echo htmlspecialchars($movie_api_config['default_thumb']); ?>';">
                                            <div class="movie-overlay">
                                                <div class="play-button">
                                                    <i class="fas fa-play"></i>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="movie-info">
                                            <h3 class="movie-title"><?php echo htmlspecialchars($result['name']); ?></h3>
                                            <div class="movie-meta">
                                                <div class="movie-year">
                                                    <i class="fas fa-calendar-alt"></i>
                                                    <?php echo $result['year'] ?? 'N/A'; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <!-- New Movies -->
                <section class="movie-section new-movies">
                    <h2 class="section-title">Phim Mới Cập Nhật</h2>
                    <?php if (!empty($new_movies)): ?>
                        <div class="movie-grid">
                            <?php foreach ($new_movies as $index => $movie): ?>
                                <?php $thumb_url = $movie['thumb_url']; ?>
                                <div class="movie-card" data-slug="<?php echo htmlspecialchars($movie['slug']); ?>">
                                    <div class="movie-link" data-slug="<?php echo htmlspecialchars($movie['slug']); ?>">
                                        <div class="movie-poster">
                                            <img src="<?php echo htmlspecialchars($thumb_url); ?>" 
                                                alt="<?php echo htmlspecialchars($movie['name']); ?>" 
                                                onerror="this.src='<?php echo htmlspecialchars($movie_api_config['default_thumb']); ?>';">
                                            <div class="movie-overlay">
                                                <div class="play-button">
                                                    <i class="fas fa-play"></i>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="movie-info">
                                            <h3 class="movie-title"><?php echo htmlspecialchars($movie['name']); ?> (<?php echo $movie['year'] ?? 'N/A'; ?>)</h3>
                                            <div class="movie-meta">
                                                <div class="movie-date">
                                                    <i class="fas fa-clock"></i>
                                                    <?php echo htmlspecialchars($movie['modified']['time'] ?? 'N/A'); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Pagination -->
                        <div class="pagination">
                            <?php
                            $start_page = max(1, $current_page - 2);
                            $end_page = min($total_pages, $current_page + 2);
                            if ($current_page > 1): ?>
                                <a href="?new_movies_page=<?php echo $current_page - 1; ?>" class="pagination-item">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            <?php endif; ?>
                            
                            <?php if ($start_page > 1): ?>
                                <a href="?new_movies_page=1" class="pagination-item">1</a>
                                <?php if ($start_page > 2): ?>
                                    <span class="pagination-item">...</span>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                <a href="?new_movies_page=<?php echo $i; ?>" class="pagination-item <?php echo $i === $current_page ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($end_page < $total_pages): ?>
                                <?php if ($end_page < $total_pages - 1): ?>
                                    <span class="pagination-item">...</span>
                                <?php endif; ?>
                                <a href="?new_movies_page=<?php echo $total_pages; ?>" class="pagination-item"><?php echo $total_pages; ?></a>
                            <?php endif; ?>
                            
                            <?php if ($current_page < $total_pages): ?>
                                <a href="?new_movies_page=<?php echo $current_page + 1; ?>" class="pagination-item">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="message message-error"><?php echo $new_movies_data['error'] ?? 'Không có phim mới nào được tìm thấy.'; ?></div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <!-- Movie Detail Content -->
            <div id="movieDetailContent">
                <?php if ($movie_data): ?>
                    <section class="movie-detail-container">
                        <div class="movie-detail">
                            <div class="movie-detail-poster">
                                <?php
                                $poster_url = $movie_data['movie']['poster_url'] ?? $movie_api_config['default_poster'];
                                if (!empty($poster_url) && !preg_match("~^(?:f|ht)tps?://~i", $poster_url)) {
                                    $poster_url = "https:" . $poster_url;
                                }
                                ?>
                                <img src="<?php echo htmlspecialchars($poster_url); ?>" 
                                    alt="<?php echo htmlspecialchars($movie_data['movie']['name']); ?>" 
                                    onerror="this.src='<?php echo $movie_api_config['default_poster']; ?>';">
                            </div>
                            <div class="movie-detail-info">
                                <h1 class="movie-detail-title"><?php echo htmlspecialchars($movie_data['movie']['name']); ?></h1>
                                
                                <div class="movie-detail-meta">
                                    <div class="meta-item">
                                        <div class="meta-label"><i class="fas fa-signature"></i> Tên gốc</div>
                                        <div class="meta-value"><?php echo htmlspecialchars($movie_data['movie']['origin_name']); ?></div>
                                    </div>
                                    
                                    <div class="meta-item">
                                        <div class="meta-label"><i class="fas fa-film"></i> Loại</div>
                                        <div class="meta-value"><?php echo htmlspecialchars($movie_data['movie']['type']); ?></div>
                                    </div>
                                    
                                    <div class="meta-item">
                                        <div class="meta-label"><i class="fas fa-info-circle"></i> Trạng thái</div>
                                        <div class="meta-value"><?php echo htmlspecialchars($movie_data['movie']['episode_current']); ?></div>
                                    </div>
                                    
                                    <div class="meta-item">
                                        <div class="meta-label"><i class="fas fa-list-ol"></i> Tổng tập</div>
                                        <div class="meta-value"><?php echo htmlspecialchars($movie_data['movie']['episode_total']); ?></div>
                                    </div>
                                    
                                    <div class="meta-item">
                                        <div class="meta-label"><i class="fas fa-video"></i> Chất lượng</div>
                                        <div class="meta-value"><?php echo htmlspecialchars($movie_data['movie']['quality']); ?></div>
                                    </div>
                                    
                                    <?php
                                    $lang_display = isset($movie_data['movie']['lang']) ? htmlspecialchars($movie_data['movie']['lang']) : 'Không rõ';
                                    $lang_display = str_replace(['vietsub', 'thuyet-minh', 'long-tieng'], ['Vietsub', 'Thuyết Minh', 'Lồng Tiếng'], strtolower($lang_display));
                                    ?>
                                    <div class="meta-item">
                                        <div class="meta-label"><i class="fas fa-language"></i> Ngôn ngữ</div>
                                        <div class="meta-value"><?php echo $lang_display; ?></div>
                                    </div>
                                    
                                    <div class="meta-item">
                                        <div class="meta-label"><i class="fas fa-calendar-alt"></i> Năm</div>
                                        <div class="meta-value"><?php echo htmlspecialchars($movie_data['movie']['year']); ?></div>
                                    </div>
                                    
                                    <div class="meta-item">
                                        <div class="meta-label"><i class="fas fa-user-friends"></i> Diễn viên</div>
                                        <div class="meta-value"><?php echo implode(', ', $movie_data['movie']['actor']); ?></div>
                                    </div>
                                    
                                    <div class="meta-item">
                                        <div class="meta-label"><i class="fas fa-user-tie"></i> Đạo diễn</div>
                                        <div class="meta-value"><?php echo implode(', ', $movie_data['movie']['director']); ?></div>
                                    </div>
                                    
                                    <div class="meta-item">
                                        <div class="meta-label"><i class="fas fa-tags"></i> Thể loại</div>
                                        <div class="meta-value"><?php echo implode(', ', array_map(function($cat) { return $cat['name']; }, $movie_data['movie']['category'])); ?></div>
                                    </div>
                                    
                                    <div class="meta-item">
                                        <div class="meta-label"><i class="fas fa-globe-asia"></i> Quốc gia</div>
                                        <div class="meta-value"><?php echo implode(', ', array_map(function($country) { return $country['name']; }, $movie_data['movie']['country'])); ?></div>
                                    </div>
                                </div>
                                
                                <div class="movie-detail-plot">
                                    <div class="movie-detail-plot-label"><i class="fas fa-align-left"></i> Nội dung</div>
                                    <p><?php echo htmlspecialchars($movie_data['movie']['content']); ?></p>
                                </div>
                                
                                <?php if ($movie_data['movie']['trailer_url']): ?>
                                    <a href="<?php echo htmlspecialchars($movie_data['movie']['trailer_url']); ?>" target="_blank" class="server-tab">
                                        <i class="fas fa-play-circle"></i> Xem Trailer
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </section>
                        
                    <!-- Video Player -->
                    <?php if (isset($movie_data['episodes'][$selected_server]['server_data'][$selected_episode])): ?>
                        <div class="video-player-container">
                            <div class="video-player">
                                <video id="movie-player" controls crossorigin playsinline>
                                    <source src="<?php echo htmlspecialchars($movie_data['episodes'][$selected_server]['server_data'][$selected_episode]['link_m3u8']); ?>" 
                                            type="application/x-mpegURL">
                                </video>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Episodes -->
                    <?php if (!empty($movie_data['episodes'])): ?>
                        <div class="episodes-container">
                            <h2 class="section-title">Danh Sách Tập</h2>
                            
                            <div class="server-tabs">
                                <?php foreach ($movie_data['episodes'] as $server_index => $server): ?>
                                    <a href="?slug=<?php echo htmlspecialchars($slug); ?>&server=<?php echo $server_index; ?>&episode=0" 
                                       class="server-tab <?php echo $selected_server === $server_index ? 'active' : ''; ?>">
                                        <i class="fas fa-server"></i> <?php echo htmlspecialchars($server['server_name']); ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                            
                            <div class="episodes-grid">
                                <?php foreach ($movie_data['episodes'][$selected_server]['server_data'] as $index => $episode): ?>
                                    <a href="?slug=<?php echo htmlspecialchars($slug); ?>&server=<?php echo $selected_server; ?>&episode=<?php echo $index; ?>" 
                                       class="episode-button <?php echo $selected_episode === $index ? 'active' : ''; ?>">
                                        <?php echo htmlspecialchars($episode['name']); ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="message message-error">Không có tập phim nào được tìm thấy.</div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Back Button -->
            <a href="<?php echo isset($_GET['slug']) ? 'movie_player.php' : 'dashboard.php'; ?>" class="back-button">
                <i class="fas fa-arrow-left"></i> 
                <?php echo isset($_GET['slug']) ? 'Quay lại danh sách phim' : 'Quay lại Dashboard'; ?>
            </a>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-content">
                <a href="dashboard.php" class="footer-logo">VinPhim</a>
                <div class="footer-links">
                    <a href="#" class="footer-link">Điều khoản sử dụng</a>
                    <a href="#" class="footer-link">Chính sách bảo mật</a>
                    <a href="#" class="footer-link">Liên hệ</a>
                </div>
                <div class="footer-copyright">
                    &copy; <?php echo date('Y'); ?> VinPhim. Tất cả quyền được bảo lưu.
                </div>
            </div>
        </div>
    </footer>

    <!-- Scroll to top button -->
    <button id="scroll-top" class="scroll-top">
        <i class="fas fa-arrow-up"></i>
    </button>

    <!-- Scripts -->
    <script src="https://cdn.plyr.io/3.7.8/plyr.polyfilled.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Create particles background
            createParticles();
            
            // Initialize video player with advanced features
            initializeVideoPlayer();
            
            // Handle search functionality
            handleSearchForm();
            
            // Handle image errors
            handleImageErrors();
            
            // Auto-hide messages
            autoHideMessages();
            
            // Scroll to top functionality
            handleScrollToTop();
            
            // Mobile orientation handling
            handleMobileOrientation();
            
            // Handle movie card clicks with loading states
            handleMovieCardClicks();
        });

        // Global variables
        let loadingOverlay, progressBar, isLoading = false;

        function initializeLoadingElements() {
            loadingOverlay = document.getElementById('loadingOverlay');
            progressBar = document.getElementById('progressBar');
        }

        function showLoading(text = 'Đang tải phim...') {
            if (!loadingOverlay) initializeLoadingElements();
            
            isLoading = true;
            document.getElementById('loadingText').textContent = text;
            loadingOverlay.classList.add('active');
            
            // Animate progress bar
            progressBar.style.width = '0%';
            setTimeout(() => progressBar.style.width = '30%', 100);
            setTimeout(() => progressBar.style.width = '60%', 500);
            setTimeout(() => progressBar.style.width = '90%', 1000);
        }

        function hideLoading() {
            if (!loadingOverlay) return;
            
            isLoading = false;
            progressBar.style.width = '100%';
            
            setTimeout(() => {
                loadingOverlay.classList.remove('active');
                progressBar.style.width = '0%';
            }, 200);
        }

        function showSkeletonLoader() {
            const mainContent = document.querySelector('.container');
            
            // Hide existing content
            const existingContent = document.getElementById('movieDetailContent');
            if (existingContent) {
                existingContent.classList.add('fade-out');
                setTimeout(() => existingContent.style.display = 'none', 400);
            }

            // Create skeleton
            const skeletonHTML = `
                <div id="skeletonLoader" class="fade-in">
                    <!-- Movie Detail Skeleton -->
                    <div class="skeleton-movie-detail">
                        <div class="skeleton-detail-layout">
                            <div class="skeleton-poster"></div>
                            <div class="skeleton-info">
                                <div class="skeleton-title"></div>
                                <div class="skeleton-meta">
                                    <div class="skeleton-meta-item"></div>
                                    <div class="skeleton-meta-item"></div>
                                    <div class="skeleton-meta-item"></div>
                                    <div class="skeleton-meta-item"></div>
                                    <div class="skeleton-meta-item"></div>
                                    <div class="skeleton-meta-item"></div>
                                </div>
                                <div class="skeleton-plot"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Video Player Skeleton -->
                    <div class="skeleton-player">
                        <div class="skeleton-video"></div>
                    </div>

                    <!-- Episodes Skeleton -->
                    <div class="skeleton-episodes">
                        <div class="skeleton-episodes-title"></div>
                        <div class="skeleton-server-tabs">
                            <div class="skeleton-server-tab"></div>
                            <div class="skeleton-server-tab"></div>
                            <div class="skeleton-server-tab"></div>
                        </div>
                        <div class="skeleton-episodes-grid">
                            ${Array(20).fill().map(() => '<div class="skeleton-episode"></div>').join('')}
                        </div>
                    </div>
                </div>
            `;

            // Insert skeleton after search results
            const searchResults = document.querySelector('.search-results') || document.querySelector('.new-movies');
            if (searchResults) {
                searchResults.insertAdjacentHTML('afterend', skeletonHTML);
            }
        }

        function hideSkeletonLoader() {
            const skeleton = document.getElementById('skeletonLoader');
            if (skeleton) {
                skeleton.classList.add('fade-out');
                setTimeout(() => skeleton.remove(), 400);
            }
        }

        function handleMovieCardClicks() {
            // Add click handlers to movie links
            document.addEventListener('click', function(e) {
                const movieLink = e.target.closest('.movie-link');
                if (movieLink && !isLoading) {
                    e.preventDefault();
                    
                    const movieCard = movieLink.closest('.movie-card');
                    const slug = movieLink.getAttribute('data-slug');
                    
                    if (slug) {
                        loadMovieDetail(slug, movieCard);
                    }
                }
            });

            // Add hover preload effect
            document.addEventListener('mouseenter', function(e) {
                const movieLink = e.target.closest('.movie-link');
                if (movieLink && !isLoading) {
                    const movieCard = movieLink.closest('.movie-card');
                    movieCard.style.transform = 'translateY(-8px) scale(1.02)';
                    
                    // Preload hint
                    setTimeout(() => {
                        if (movieCard.matches(':hover')) {
                            movieCard.style.boxShadow = 'var(--shadow-lg), 0 0 30px rgba(112, 0, 255, 0.3)';
                        }
                    }, 200);
                }
            }, true);

            document.addEventListener('mouseleave', function(e) {
                const movieLink = e.target.closest('.movie-link');
                if (movieLink) {
                    const movieCard = movieLink.closest('.movie-card');
                    movieCard.style.transform = '';
                    movieCard.style.boxShadow = '';
                }
            }, true);
        }

        async function loadMovieDetail(slug, movieCard) {
            try {
                // Add loading state to clicked card
                movieCard.classList.add('loading');
                
                // Show loading overlay and skeleton
                showLoading('Đang tải thông tin phim...');
                setTimeout(() => showSkeletonLoader(), 300);

                // Fetch movie data
                const response = await fetch(`?slug=${encodeURIComponent(slug)}`, {
                    method: 'GET',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const html = await response.text();
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                
                // Extract movie detail content
                const movieDetailContent = doc.getElementById('movieDetailContent');
                
                if (movieDetailContent) {
                    // Update URL without reload
                    window.history.pushState({slug: slug}, '', `?slug=${slug}`);
                    
                    // Hide skeleton and show content
                    hideSkeletonLoader();
                    
                    setTimeout(() => {
                        // Replace content
                        const currentContent = document.getElementById('movieDetailContent');
                        if (currentContent) {
                            currentContent.innerHTML = movieDetailContent.innerHTML;
                            currentContent.style.display = 'block';
                            currentContent.classList.remove('fade-out');
                            currentContent.classList.add('fade-in');
                        }

                        // Hide search results and new movies
                        const searchResults = document.querySelector('.search-results');
                        const newMovies = document.querySelector('.new-movies');
                        
                        if (searchResults) {
                            searchResults.style.display = 'none';
                        }
                        if (newMovies) {
                            newMovies.style.display = 'none';
                        }

                        // Re-initialize video player
                        setTimeout(() => {
                            initializeVideoPlayer();
                            handleImageErrors();
                        }, 100);

                        // Scroll to top smoothly
                        window.scrollTo({
                            top: 0,
                            behavior: 'smooth'
                        });

                        hideLoading();
                    }, 500);
                } else {
                    throw new Error('Không thể tải thông tin phim');
                }

            } catch (error) {
                console.error('Error loading movie:', error);
                
                hideSkeletonLoader();
                hideLoading();
                
                // Show error message
                const messageContainer = document.getElementById('message-container');
                messageContainer.innerHTML = `
                    <div class="message message-error">
                        <i class="fas fa-exclamation-triangle"></i>
                        Không thể tải thông tin phim. Vui lòng thử lại!
                    </div>
                `;
                
                // Fallback to normal page load
                setTimeout(() => {
                    window.location.href = `?slug=${slug}`;
                }, 2000);
            } finally {
                // Remove loading state from card
                movieCard.classList.remove('loading');
            }
        }

        // Handle browser back/forward
        window.addEventListener('popstate', function(e) {
            if (e.state && e.state.slug) {
                loadMovieDetail(e.state.slug, document.querySelector(`[data-slug="${e.state.slug}"]`));
            } else {
                // Back to home
                location.reload();
            }
        });

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

        function initializeVideoPlayer() {
            const video = document.getElementById('movie-player');
            if (!video) return;

            let player, hls;

            // Enhanced mobile detection
            const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
            const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent);
            
            // Mobile-optimized Plyr configuration
            const plyrConfig = {
                controls: [
                    'play-large', 'play', 'progress', 'current-time', 'duration',
                    'mute', 'volume', 'settings', 'fullscreen'
                ],
                settings: ['quality', 'speed'],
                speed: { selected: 1, options: [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2] },
                keyboard: { focused: true, global: !isMobile },
                tooltips: { controls: !isMobile, seek: !isMobile },
                captions: { active: false, language: 'auto', update: false },
                fullscreen: { 
                    enabled: true, 
                    fallback: true,
                    iosNative: isIOS
                },
                // Mobile optimizations
                clickToPlay: !isMobile,
                disableContextMenu: isMobile,
                resetOnEnd: false,
                hideControls: isMobile
            };

            // Check for HLS support
            if (video.canPlayType('application/x-mpegURL') || video.canPlayType('application/vnd.apple.mpegurl')) {
                // Native HLS support (mainly Safari/iOS)
                player = new Plyr(video, plyrConfig);
                console.log('Using native HLS support');
            } else if (Hls.isSupported()) {
                // Use HLS.js for browsers without native support
                hls = new Hls({
                    maxLoadingDelay: 4,
                    maxLatency: 30,
                    liveSyncDurationCount: 3,
                    liveMaxLatencyDurationCount: Infinity,
                    // Mobile optimizations
                    enableWorker: !isMobile,
                    lowLatencyMode: false,
                    backBufferLength: isMobile ? 30 : 60
                });
                
                const source = video.querySelector('source');
                hls.loadSource(source.src);
                hls.attachMedia(video);
                
                hls.on(Hls.Events.MANIFEST_PARSED, function() {
                    player = new Plyr(video, plyrConfig);
                    console.log('HLS.js loaded successfully');

                    // Expose HLS to Plyr for quality control
                    player.hls = hls;
                    
                    // Mobile-specific event listeners
                    if (isMobile) {
                        setupMobileVideoHandlers(player, video);
                    }
                });

                hls.on(Hls.Events.ERROR, function(event, data) {
                    console.error('HLS Error:', data);
                    if (data.fatal) {
                        switch(data.type) {
                            case Hls.ErrorTypes.NETWORK_ERROR:
                                console.log('Fatal network error encountered, try to recover');
                                hls.startLoad();
                                break;
                            case Hls.ErrorTypes.MEDIA_ERROR:
                                console.log('Fatal media error encountered, try to recover');
                                hls.recoverMediaError();
                                break;
                            default:
                                console.log('Fatal error, cannot recover');
                                handlePlayerError(player, video);
                                break;
                        }
                    }
                });
            } else {
                console.warn('HLS not supported, falling back to native video');
                player = new Plyr(video, plyrConfig);
            }

            // Common player event handlers
            if (player) {
                setupCommonPlayerHandlers(player, video, isMobile);
            }

            // Store player globally for debugging
            window.moviePlayer = player;
            window.movieHls = hls;
        }

        function setupMobileVideoHandlers(player, video) {
            console.log('Setting up mobile video handlers');
            
            // Handle fullscreen changes specifically for mobile
            player.on('enterfullscreen', function() {
                console.log('Entering fullscreen on mobile');
                
                // Force landscape orientation if possible
                if (screen.orientation && screen.orientation.lock) {
                    screen.orientation.lock('landscape').catch(function(error) {
                        console.log('Screen orientation lock failed:', error);
                    });
                }
                
                // Ensure video element is properly sized
                setTimeout(() => {
                    const videoEl = player.media;
                    if (videoEl) {
                        videoEl.style.width = '100%';
                        videoEl.style.height = '100%';
                        videoEl.style.objectFit = 'contain';
                    }
                }, 100);
                
                // Hide mobile browser chrome
                if (document.documentElement.requestFullscreen) {
                    document.documentElement.requestFullscreen();
                } else if (document.documentElement.webkitRequestFullscreen) {
                    document.documentElement.webkitRequestFullscreen();
                }
            });

            player.on('exitfullscreen', function() {
                console.log('Exiting fullscreen on mobile');
                
                // Unlock orientation
                if (screen.orientation && screen.orientation.unlock) {
                    screen.orientation.unlock();
                }
                
                // Exit browser fullscreen
                if (document.exitFullscreen) {
                    document.exitFullscreen();
                } else if (document.webkitExitFullscreen) {
                    document.webkitExitFullscreen();
                }
            });

            // Handle orientation changes
            window.addEventListener('orientationchange', function() {
                setTimeout(() => {
                    if (player.fullscreen.active) {
                        const videoEl = player.media;
                        if (videoEl) {
                            videoEl.style.width = '100%';
                            videoEl.style.height = '100%';
                        }
                    }
                }, 500);
            });

            // Prevent video from disappearing on iOS
            video.addEventListener('webkitbeginfullscreen', function() {
                console.log('iOS native fullscreen started');
            });

            video.addEventListener('webkitendfullscreen', function() {
                console.log('iOS native fullscreen ended');
            });

            // Touch gestures for mobile
            let touchStartTime, touchStartX, touchStartY;
            let lastTap = 0;

            video.addEventListener('touchstart', function(e) {
                touchStartTime = Date.now();
                touchStartX = e.touches[0].clientX;
                touchStartY = e.touches[0].clientY;
            });

            video.addEventListener('touchend', function(e) {
                const touchEndTime = Date.now();
                const touchEndX = e.changedTouches[0].clientX;
                const touchEndY = e.changedTouches[0].clientY;
                
                const timeDiff = touchEndTime - touchStartTime;
                const distanceX = Math.abs(touchEndX - touchStartX);
                const distanceY = Math.abs(touchEndY - touchStartY);
                
                // Double tap detection
                const currentTime = new Date().getTime();
                const tapLength = currentTime - lastTap;
                
                if (tapLength < 500 && tapLength > 0 && distanceX < 30 && distanceY < 30 && timeDiff < 200) {
                    e.preventDefault();
                    player.togglePlay();
                } else if (timeDiff < 200 && distanceX < 30 && distanceY < 30) {
                    // Single tap - show/hide controls
                    if (player.fullscreen.active) {
                        const controls = document.querySelector('.plyr__controls');
                        if (controls) {
                            controls.style.opacity = controls.style.opacity === '0' ? '1' : '0';
                        }
                    }
                }
                
                lastTap = currentTime;
            });
        }

        function setupCommonPlayerHandlers(player, video, isMobile) {
            // Keyboard shortcuts (disabled on mobile)
            if (!isMobile) {
                document.addEventListener('keydown', function(e) {
                    if (e.target.tagName.toLowerCase() === 'input') return;
                    
                    switch(e.code) {
                        case 'Space':
                            e.preventDefault();
                            player.togglePlay();
                            break;
                        case 'ArrowLeft':
                            e.preventDefault();
                            player.currentTime -= 10;
                            break;
                        case 'ArrowRight':
                            e.preventDefault();
                            player.currentTime += 10;
                            break;
                        case 'ArrowUp':
                            e.preventDefault();
                            player.volume = Math.min(1, player.volume + 0.1);
                            break;
                        case 'ArrowDown':
                            e.preventDefault();
                            player.volume = Math.max(0, player.volume - 0.1);
                            break;
                        case 'KeyM':
                            e.preventDefault();
                            player.muted = !player.muted;
                            break;
                        case 'KeyF':
                            e.preventDefault();
                            player.fullscreen.toggle();
                            break;
                    }
                });
            }

            // Save playback position
            player.on('timeupdate', function() {
                if (player.duration > 0) {
                    localStorage.setItem('plyr_position_' + window.location.href, player.currentTime);
                }
            });

            // Restore playback position
            player.on('loadedmetadata', function() {
                const savedPosition = localStorage.getItem('plyr_position_' + window.location.href);
                if (savedPosition && parseFloat(savedPosition) > 0) {
                    player.currentTime = parseFloat(savedPosition);
                }
            });

            // Clear position when video ends
            player.on('ended', function() {
                localStorage.removeItem('plyr_position_' + window.location.href);
            });

            // Handle player errors
            player.on('error', function(event) {
                console.error('Player error:', event);
                handlePlayerError(player, video);
            });

            // Quality change handler for mobile
            if (isMobile) {
                player.on('qualitychange', function() {
                    console.log('Quality changed on mobile');
                });
            }
        }

        function handlePlayerError(player, video) {
            const errorMessage = `
                <div class="video-error" style="
                    position: absolute;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                    background: rgba(0, 0, 0, 0.8);
                    color: white;
                    padding: 2rem;
                    border-radius: 1rem;
                    text-align: center;
                    z-index: 1000;
                ">
                    <i class="fas fa-exclamation-triangle" style="font-size: 2rem; margin-bottom: 1rem; color: var(--warning);"></i>
                    <h3>Không thể phát video</h3>
                    <p>Vui lòng thử lại hoặc chọn server khác</p>
                    <button onclick="location.reload()" style="
                        margin-top: 1rem;
                        padding: 0.5rem 1rem;
                        background: var(--primary);
                        color: white;
                        border: none;
                        border-radius: 0.5rem;
                        cursor: pointer;
                    ">Tải lại</button>
                </div>
            `;
            
            const playerContainer = video.closest('.video-player');
            if (playerContainer) {
                playerContainer.style.position = 'relative';
                playerContainer.insertAdjacentHTML('beforeend', errorMessage);
            }
        }

        function handleMobileOrientation() {
            const video = document.getElementById('movie-player');
            if (!video) return;

            // Enhanced mobile detection
            const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
            const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent);
            
            if (isMobile) {
                console.log('Setting up mobile orientation handling');
                
                // Handle device orientation changes
                window.addEventListener('orientationchange', function() {
                    console.log('Orientation changed');
                    
                    // Delay to ensure orientation change is complete
                    setTimeout(() => {
                        const player = window.moviePlayer;
                        if (player && player.fullscreen.active) {
                            console.log('Adjusting video in fullscreen after orientation change');
                            
                            const videoEl = player.media;
                            if (videoEl) {
                                // Force video to fit container
                                videoEl.style.width = '100%';
                                videoEl.style.height = '100%';
                                videoEl.style.objectFit = 'contain';
                                
                                // Trigger resize event
                                if (player.hls) {
                                    player.hls.trigger('hlsManifestParsed');
                                }
                            }
                        }
                    }, 500);
                });

                // Handle screen size changes (for mobile browsers)
                window.addEventListener('resize', function() {
                    const player = window.moviePlayer;
                    if (player && player.fullscreen.active) {
                        setTimeout(() => {
                            const videoEl = player.media;
                            if (videoEl) {
                                videoEl.style.width = '100%';
                                videoEl.style.height = '100%';
                            }
                        }, 100);
                    }
                });

                // iOS specific handling
                if (isIOS) {
                    // Handle iOS native fullscreen
                    document.addEventListener('webkitfullscreenchange', function() {
                        const player = window.moviePlayer;
                        if (document.webkitFullscreenElement) {
                            console.log('iOS entering fullscreen');
                            // iOS is entering fullscreen
                            if (screen.orientation && screen.orientation.lock) {
                                screen.orientation.lock('landscape').catch(console.log);
                            }
                        } else {
                            console.log('iOS exiting fullscreen');
                            // iOS is exiting fullscreen
                            if (screen.orientation && screen.orientation.unlock) {
                                screen.orientation.unlock();
                            }
                        }
                    });
                }

                // Android specific handling
                if (/Android/i.test(navigator.userAgent)) {
                    // Handle Android fullscreen
                    document.addEventListener('fullscreenchange', function() {
                        const player = window.moviePlayer;
                        if (document.fullscreenElement) {
                            console.log('Android entering fullscreen');
                            if (screen.orientation && screen.orientation.lock) {
                                screen.orientation.lock('landscape').catch(console.log);
                            }
                        } else {
                            console.log('Android exiting fullscreen');
                            if (screen.orientation && screen.orientation.unlock) {
                                screen.orientation.unlock();
                            }
                        }
                    });
                }

                // Prevent mobile browser zoom during video playback
                document.addEventListener('touchmove', function(e) {
                    if (e.touches.length > 1) {
                        const player = window.moviePlayer;
                        if (player && player.fullscreen.active) {
                            e.preventDefault();
                        }
                    }
                }, { passive: false });
            }
        }

        function handleSearchForm() {
            const searchForm = document.getElementById('search-form');
            const messageContainer = document.getElementById('message-container');

            if (searchForm) {
                searchForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const formData = new FormData(this);
                    formData.append('search_movie', '1');

                    // Show loading message
                    messageContainer.innerHTML = '<div class="message message-info"><i class="fas fa-spinner fa-spin"></i> Đang tìm kiếm phim...</div>';

                    fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.text())
                    .then(data => {
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(data, 'text/html');
                        const newMessage = doc.getElementById('message-container')?.innerHTML;
                        
                        // Get search results from response
                        const searchResultsSection = doc.querySelector('.search-results');
                        
                        if (searchResultsSection) {
                            // Remove existing search results
                            const existingResults = document.querySelector('.search-results');
                            if (existingResults) {
                                existingResults.remove();
                            }
                            
                            // Add new search results
                            const container = document.querySelector('.container');
                            const movieDetailSection = document.querySelector('.movie-detail-container');
                            const newMoviesSection = document.querySelector('.new-movies');
                            
                            if (movieDetailSection) {
                                movieDetailSection.before(searchResultsSection);
                            } else if (newMoviesSection) {
                                newMoviesSection.before(searchResultsSection);
                            } else {
                                messageContainer.after(searchResultsSection);
                            }
                            
                            // Update message
                            if (newMessage) {
                                messageContainer.innerHTML = newMessage;
                            }
                        } else {
                            if (newMessage) {
                                messageContainer.innerHTML = newMessage;
                            } else {
                                messageContainer.innerHTML = '<div class="message message-error">Không tìm thấy kết quả nào.</div>';
                            }
                        }
                        
                        // Handle image errors for new content
                        handleImageErrors();
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        messageContainer.innerHTML = '<div class="message message-error">Có lỗi xảy ra khi tìm kiếm. Vui lòng thử lại!</div>';
                    });
                });
            }
        }

        function handleImageErrors() {
            document.querySelectorAll('.movie-poster img, .movie-detail-poster img').forEach(img => {
                img.addEventListener('error', function() {
                    console.log('Failed to load image:', this.src);
                    this.src = '<?php echo $movie_api_config['default_thumb'] ?? '/placeholder-image.jpg'; ?>';
                    this.classList.add('error');
                });
            });
        }

        function autoHideMessages() {
            setTimeout(() => {
                const messages = document.querySelectorAll('.message');
                messages.forEach(message => {
                    message.style.opacity = '0';
                    message.style.transform = 'translateY(-20px)';
                    message.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    
                    setTimeout(() => {
                        if (message.parentNode) {
                            message.remove();
                        }
                    }, 500);
                });
            }, 5000);
        }

        function handleScrollToTop() {
            const scrollTopButton = document.getElementById('scroll-top');
            
            window.addEventListener('scroll', () => {
                if (window.pageYOffset > 300) {
                    scrollTopButton.classList.add('visible');
                } else {
                    scrollTopButton.classList.remove('visible');
                }
            });
            
            scrollTopButton.addEventListener('click', () => {
                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            });
        }
    </script>
</body>
</html>
<?php
if (isset($conn) && $conn) {
    mysqli_close($conn);
}
?>