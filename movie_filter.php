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
$error_message = '';
$success_message = '';
$filter_results = [];
$total_pages = 1;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($current_page < 1) $current_page = 1;

// Lấy danh sách thể loại phim
function fetchCategories() {
    $api_url = "https://phimapi.com/the-loai";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: Mozilla/5.0 (compatible; MoviePlayer/1.0)',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false || $http_code !== 200) {
        return [];
    }

    $data = json_decode($response, true);
    curl_close($ch);
    return $data ?: [];
}

// Lấy danh sách quốc gia
function fetchCountries() {
    $api_url = "https://phimapi.com/quoc-gia";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: Mozilla/5.0 (compatible; MoviePlayer/1.0)',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false || $http_code !== 200) {
        return [];
    }

    $data = json_decode($response, true);
    curl_close($ch);
    return $data ?: [];
}

// Lấy danh sách phim theo bộ lọc
function fetchFilteredMovies($type_list, $page = 1, $sort_field = 'modified.time', $sort_type = 'desc', $sort_lang = '', $country = '', $year = '', $limit = 24) {
    // Xử lý trường sort_field đặc biệt
    $api_sort_field = $sort_field;
    
    $api_url = "https://phimapi.com/v1/api/the-loai/{$type_list}?page={$page}&sort_field={$api_sort_field}&sort_type={$sort_type}";
    
    if (!empty($sort_lang)) {
        $api_url .= "&sort_lang={$sort_lang}";
    }
    
    if (!empty($country)) {
        $api_url .= "&country={$country}";
    }
    
    if (!empty($year)) {
        $api_url .= "&year={$year}";
    }
    
    $api_url .= "&limit={$limit}";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: Mozilla/5.0 (compatible; MoviePlayer/1.0)',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false) {
        return ['error' => "Lỗi cURL: " . curl_error($ch)];
    } else if ($http_code === 200 && $response) {
        $data = json_decode($response, true);
        if ($data && isset($data['status']) && $data['status'] === 'success') {
            return $data;
        } else {
            return ['error' => "Không tìm thấy dữ liệu phim."];
        }
    } else {
        return ['error' => "Lỗi khi kết nối API: HTTP $http_code"];
    }
    curl_close($ch);
}

// Lấy danh sách thể loại và quốc gia
$categories = fetchCategories();
$countries = fetchCountries();

// Xử lý lọc phim
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['filter_movies'])) {
    $type_list = isset($_GET['category']) && !empty($_GET['category']) ? trim($_GET['category']) : 'hanh-dong';
    $sort_field = isset($_GET['sort_field']) && !empty($_GET['sort_field']) ? trim($_GET['sort_field']) : 'modified.time';
    $sort_type = isset($_GET['sort_type']) && in_array(trim($_GET['sort_type']), ['asc', 'desc']) ? trim($_GET['sort_type']) : 'desc';
    $sort_lang = isset($_GET['language']) ? trim($_GET['language']) : '';
    $country = isset($_GET['country']) ? trim($_GET['country']) : '';
    $year = isset($_GET['year']) ? trim($_GET['year']) : '';
    $limit = 24; // Số phim hiển thị trên mỗi trang
    
    $filter_data = fetchFilteredMovies($type_list, $current_page, $sort_field, $sort_type, $sort_lang, $country, $year, $limit);
    
    if (!isset($filter_data['error'])) {
        $filter_results = $filter_data['data']['items'] ?? [];
        $total_pages = $filter_data['data']['params']['pagination']['totalPages'] ?? 1;
        $success_message = "Đã tìm thấy " . count($filter_results) . " kết quả phù hợp.";
    } else {
        $error_message = $filter_data['error'];
    }
}

// Tạo danh sách năm từ 1970 đến hiện tại
$current_year = date('Y');
$years = range($current_year, 1970);
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VinPhim - Lọc Phim</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/aos@next/dist/aos.css" />
    <style>
        :root {
            /* Màu chính */
            --primary: #7c3aed;
            --primary-light: #a78bfa;
            --primary-dark: #6d28d9;
            --secondary: #10b981;
            --secondary-light: #34d399;
            --secondary-dark: #059669;
            
            /* Màu nền */
            --bg-dark: #0f172a;
            --bg-card: #1e293b;
            --bg-card-hover: #334155;
            --bg-input: #1e293b;
            --bg-button: #7c3aed;
            --bg-button-hover: #6d28d9;
            
            /* Màu chữ */
            --text-light: #f8fafc;
            --text-muted: #94a3b8;
            --text-dark: #0f172a;
            
            /* Màu trạng thái */
            --success: #10b981;
            --error: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            
            /* Hiệu ứng và bo góc */
            --shadow-sm: 0 2px 4px rgba(0, 0, 0, 0.3);
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
            --shadow-lg: 0 10px 15px rgba(0, 0, 0, 0.3);
            --shadow-glow: 0 0 15px rgba(124, 58, 237, 0.5);
            --radius-sm: 0.375rem;
            --radius: 0.5rem;
            --radius-lg: 0.75rem;
            --radius-xl: 1rem;
            --radius-full: 9999px;
            
            /* Hiệu ứng */
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Montserrat', sans-serif;
            background-color: var(--bg-dark);
            color: var(--text-light);
            line-height: 1.6;
            overflow-x: hidden;
        }

        .container {
            width: 100%;
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 1rem;
        }

        /* Header */
        .header {
            background-color: rgba(15, 23, 42, 0.9);
            backdrop-filter: blur(10px);
            position: sticky;
            top: 0;
            z-index: 100;
            padding: 1rem 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-light);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .logo i {
            color: var(--primary);
        }

        .logo span {
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .nav-links {
            display: flex;
            gap: 1.5rem;
        }

        .nav-link {
            color: var(--text-light);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .nav-link:hover {
            color: var(--primary-light);
        }

        .nav-link.active {
            color: var(--primary);
            font-weight: 600;
        }

        /* Main Content */
        .main {
            padding: 2rem 0;
        }

        .section-title {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            position: relative;
            display: inline-block;
            padding-bottom: 0.5rem;
        }

        .section-title::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 50%;
            height: 3px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            border-radius: var(--radius-full);
        }

        /* Filter Form */
        .filter-form {
            background-color: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
        }

        .filter-group {
            margin-bottom: 1rem;
        }

        .filter-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--text-light);
        }

        .filter-select {
            width: 100%;
            padding: 0.75rem 1rem;
            background-color: var(--bg-input);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: var(--radius);
            color: var(--text-light);
            font-size: 0.9rem;
            transition: var(--transition);
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23a78bfa' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 1rem;
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: var(--shadow-glow);
        }

        .filter-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background-color: var(--bg-button);
            color: var(--text-light);
            border: none;
            border-radius: var(--radius-full);
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            margin-top: 1rem;
        }

        .filter-button:hover {
            background-color: var(--bg-button-hover);
            transform: translateY(-3px);
            box-shadow: var(--shadow-glow);
        }

        .filter-reset {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background-color: var(--bg-card-hover);
            color: var(--text-light);
            border: none;
            border-radius: var(--radius-full);
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            margin-top: 1rem;
            margin-left: 0.5rem;
        }

        .filter-reset:hover {
            background-color: var(--error);
            transform: translateY(-3px);
        }

        /* Messages */
        .message {
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            position: relative;
            padding-left: 3rem;
            animation: slideInDown 0.4s ease-out;
        }

        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .message-success {
            background-color: rgba(16, 185, 129, 0.2);
            border-left: 4px solid var(--success);
        }

        .message-error {
            background-color: rgba(239, 68, 68, 0.2);
            border-left: 4px solid var(--error);
        }

        .message::before {
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
        }

        .message-success::before {
            content: "\f00c";
            color: var(--success);
        }

        .message-error::before {
            content: "\f071";
            color: var(--error);
        }

        /* Movie Grid */
        .movie-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .movie-card {
            background-color: var(--bg-card);
            border-radius: var(--radius);
            overflow: hidden;
            transition: var(--transition);
            position: relative;
            box-shadow: var(--shadow);
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .movie-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--shadow-lg);
            background-color: var(--bg-card-hover);
        }

        .movie-poster {
            position: relative;
            overflow: hidden;
            aspect-ratio: 2/3;
        }

        .movie-poster img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: var(--transition);
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
            background: linear-gradient(to top, rgba(0, 0, 0, 0.8) 0%, rgba(0, 0, 0, 0) 60%);
            opacity: 0;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .movie-card:hover .movie-overlay {
            opacity: 1;
        }

        .play-button {
            background-color: rgba(124, 58, 237, 0.8);
            color: white;
            width: 3.5rem;
            height: 3.5rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transform: scale(0);
            transition: var(--transition);
        }

        .movie-card:hover .play-button {
            transform: scale(1);
        }

        .movie-info {
            padding: 1rem;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
        }

        .movie-title {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            line-height: 1.4;
            height: 2.8rem;
            color: aliceblue;
        }

        .movie-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: auto;
        }

        .movie-year, .movie-lang, .movie-date {
            font-size: 0.8rem;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .movie-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            background-color: var(--primary);
            color: white;
            border-radius: var(--radius-full);
            font-size: 0.7rem;
            font-weight: 600;
            position: absolute;
            top: 0.75rem;
            right: 0.75rem;
            z-index: 10;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin: 2rem 0;
            flex-wrap: wrap;
        }

        .pagination-item {
            min-width: 2.5rem;
            height: 2.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: var(--bg-card);
            color: var(--text-light);
            border-radius: var(--radius);
            text-decoration: none;
            transition: var(--transition);
        }

        .pagination-item:hover, .pagination-item.active {
            background-color: var(--primary);
            transform: translateY(-3px);
            box-shadow: var(--shadow-glow);
        }

        /* Back Button */
        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background-color: var(--bg-card);
            color: var(--text-light);
            border-radius: var(--radius-full);
            text-decoration: none;
            transition: var(--transition);
            font-weight: 500;
            margin-top: 2rem;
        }

        .back-button:hover {
            background-color: var(--primary);
            transform: translateY(-3px);
            box-shadow: var(--shadow-glow);
        }

        /* Footer */
        .footer {
            background-color: rgba(15, 23, 42, 0.9);
            padding: 2rem 0;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            margin-top: 3rem;
        }

        .footer-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .footer-logo {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-light);
            text-decoration: none;
        }

        .footer-links {
            display: flex;
            gap: 1.5rem;
        }

        .footer-link {
            color: var(--text-muted);
            text-decoration: none;
            transition: var(--transition);
        }

        .footer-link:hover {
            color: var(--primary-light);
        }

        .footer-copyright {
            width: 100%;
            text-align: center;
            margin-top: 1.5rem;
            color: var(--text-muted);
            font-size: 0.9rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .filter-grid {
                grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            }
            
            .movie-grid {
                grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
                gap: 1rem;
            }
        }

        @media (max-width: 576px) {
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .movie-grid {
                grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
                gap: 0.75rem;
            }
            
            .section-title {
                font-size: 1.5rem;
            }
        }

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideUp {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .fade-in {
            animation: fadeIn 0.5s ease-out;
        }

        .slide-up {
            animation: slideUp 0.5s ease-out;
        }

        /* Loading Skeleton */
        .skeleton {
            background: linear-gradient(90deg, var(--bg-card) 25%, var(--bg-card-hover) 50%, var(--bg-card) 75%);
            background-size: 200% 100%;
            animation: loading 1.5s infinite;
            border-radius: var(--radius);
        }

        @keyframes loading {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        /* Scroll to top button */
        .scroll-top {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            width: 3rem;
            height: 3rem;
            background-color: var(--primary);
            color: var(--text-light);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--shadow-lg);
            cursor: pointer;
            transition: var(--transition);
            opacity: 0;
            visibility: hidden;
            z-index: 99;
        }

        .scroll-top.visible {
            opacity: 1;
            visibility: visible;
        }

        .scroll-top:hover {
            background-color: var(--primary-dark);
            transform: translateY(-5px);
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="container">
            <div class="header-content">
                <a href="/movie_player" class="logo">
                    <i class="fas fa-film"></i>
                    <span>VinPhim</span>
                </a>
                <div class="nav-links">
                    <a href="movie_player" class="nav-link">
                        <i class="fas fa-home"></i> Trang chủ
                    </a>
                    <a href="movie_filter.php" class="nav-link active">
                        <i class="fas fa-filter"></i> Lọc phim
                    </a>
                    <a href="dashboard.php" class="nav-link">
                        <i class="fas fa-user"></i> Tài khoản
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="main">
        <div class="container">
            <h1 class="section-title" data-aos="fade-down">Lọc Phim</h1>
            
            <!-- Filter Form -->
            <div class="filter-form" data-aos="fade-up">
                <form method="GET" action="movie_filter.php">
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label for="category" class="filter-label">Thể loại</label>
                            <select name="category" id="category" class="filter-select">
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo htmlspecialchars($category['slug']); ?>" <?php echo (isset($_GET['category']) && $_GET['category'] === $category['slug']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(html_entity_decode($category['name'])); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="country" class="filter-label">Quốc gia</label>
                            <select name="country" id="country" class="filter-select">
                                <option value="">Tất cả quốc gia</option>
                                <?php foreach ($countries as $country): ?>
                                    <option value="<?php echo htmlspecialchars($country['slug']); ?>" <?php echo (isset($_GET['country']) && $_GET['country'] === $country['slug']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(html_entity_decode($country['name'])); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="year" class="filter-label">Năm phát hành</label>
                            <select name="year" id="year" class="filter-select">
                                <option value="">Tất cả các năm</option>
                                <?php foreach ($years as $year): ?>
                                    <option value="<?php echo $year; ?>" <?php echo (isset($_GET['year']) && $_GET['year'] == $year) ? 'selected' : ''; ?>>
                                        <?php echo $year; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="language" class="filter-label">Ngôn ngữ</label>
                            <select name="language" id="language" class="filter-select">
                                <option value="">Tất cả ngôn ngữ</option>
                                <option value="vietsub" <?php echo (isset($_GET['language']) && $_GET['language'] === 'vietsub') ? 'selected' : ''; ?>>Vietsub</option>
                                <option value="thuyet-minh" <?php echo (isset($_GET['language']) && $_GET['language'] === 'thuyet-minh') ? 'selected' : ''; ?>>Thuyết minh</option>
                                <option value="long-tieng" <?php echo (isset($_GET['language']) && $_GET['language'] === 'long-tieng') ? 'selected' : ''; ?>>Lồng tiếng</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="sort_field" class="filter-label">Sắp xếp theo</label>
                            <select name="sort_field" id="sort_field" class="filter-select">
                                <option value="modified.time" <?php echo (!isset($_GET['sort_field']) || $_GET['sort_field'] === 'modified.time') ? 'selected' : ''; ?>>Thời gian cập nhật</option>
                                <option value="_id" <?php echo (isset($_GET['sort_field']) && $_GET['sort_field'] === '_id') ? 'selected' : ''; ?>>ID phim</option>
                                <option value="year" <?php echo (isset($_GET['sort_field']) && $_GET['sort_field'] === 'year') ? 'selected' : ''; ?>>Năm phát hành</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="sort_type" class="filter-label">Thứ tự</label>
                            <select name="sort_type" id="sort_type" class="filter-select">
                                <option value="desc" <?php echo (!isset($_GET['sort_type']) || $_GET['sort_type'] === 'desc') ? 'selected' : ''; ?>>Giảm dần</option>
                                <option value="asc" <?php echo (isset($_GET['sort_type']) && $_GET['sort_type'] === 'asc') ? 'selected' : ''; ?>>Tăng dần</option>
                            </select>
                        </div>
                    </div>
                    
                    <button type="submit" name="filter_movies" value="1" class="filter-button">
                        <i class="fas fa-filter"></i> Lọc phim
                    </button>
                    
                    <a href="movie_filter.php" class="filter-reset">
                        <i class="fas fa-undo"></i> Đặt lại
                    </a>
                </form>
            </div>

            <!-- Messages -->
            <div id="message-container">
                <?php if ($error_message): ?>
                    <div class="message message-error" data-aos="fade-in"><?php echo $error_message; ?></div>
                <?php endif; ?>
                <?php if ($success_message): ?>
                    <div class="message message-success" data-aos="fade-in"><?php echo $success_message; ?></div>
                <?php endif; ?>
            </div>

            <!-- Filter Results -->
            <?php if (!empty($filter_results)): ?>
                <section class="filter-results" data-aos="fade-up">
                    <h2 class="section-title">Kết quả lọc phim</h2>
                    <div class="movie-grid">
                        <?php foreach ($filter_results as $index => $movie): ?>
                            <?php
                            $thumb_url = $movie['thumb_url'] ?? $movie_api_config['default_thumb'];
                            if (!empty($thumb_url) && !preg_match("~^(?:f|ht)tps?://~i", $thumb_url)) {
                                $thumb_url = "https://phimimg.com" . $thumb_url;
                            }
                            $lang_display = isset($movie['lang']) ? htmlspecialchars($movie['lang']) : 'Không rõ';
                            $lang_display = str_replace(['vietsub', 'thuyet-minh', 'long-tieng'], ['Vietsub', 'Thuyết Minh', 'Lồng Tiếng'], strtolower($lang_display));
                            ?>
                            <div class="movie-card" data-aos="zoom-in" data-aos-delay="<?php echo $index * 50; ?>">
                                <div class="movie-badge"><?php echo $lang_display; ?></div>
                                <a href="movie_player?slug=<?php echo htmlspecialchars($movie['slug']); ?>">
                                    <div class="movie-poster">
                                        <img src="<?php echo htmlspecialchars($thumb_url); ?>" 
                                            alt="<?php echo htmlspecialchars($movie['name']); ?>" 
                                            onerror="this.src='<?php echo $movie_api_config['default_thumb']; ?>';">
                                        <div class="movie-overlay">
                                            <div class="play-button">
                                                <i class="fas fa-play"></i>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="movie-info">
                                        <h3 class="movie-title"><?php echo htmlspecialchars($movie['name']); ?></h3>
                                        <div class="movie-meta">
                                            <div class="movie-year">
                                                <i class="fas fa-calendar-alt"></i>
                                                <?php echo $movie['year']; ?>
                                            </div>
                                            <?php if (isset($movie['time'])): ?>
                                            <div class="movie-date">
                                                <i class="fas fa-clock"></i>
                                                <?php echo htmlspecialchars($movie['time']); ?>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination" data-aos="fade-up">
                            <?php
                            $start_page = max(1, $current_page - 2);
                            $end_page = min($total_pages, $current_page + 2);
                            
                            // Tạo URL với các tham số lọc hiện tại
                            $filter_params = $_GET;
                            if(isset($filter_params['page'])) {
                                unset($filter_params['page']); // Xóa tham số page hiện tại
                            }
                            $filter_url = http_build_query($filter_params);
                            
                            if ($current_page > 1): ?>
                                <a href="?<?php echo $filter_url; ?>&page=<?php echo $current_page - 1; ?>" class="pagination-item">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            <?php endif; ?>
                            
                            <?php if ($start_page > 1): ?>
                                <a href="?<?php echo $filter_url; ?>&page=1" class="pagination-item">1</a>
                                <?php if ($start_page > 2): ?>
                                    <span class="pagination-item">...</span>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                <a href="?<?php echo $filter_url; ?>&page=<?php echo $i; ?>" class="pagination-item <?php echo $i === $current_page ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($end_page < $total_pages): ?>
                                <?php if ($end_page < $total_pages - 1): ?>
                                    <span class="pagination-item">...</span>
                                <?php endif; ?>
                                <a href="?<?php echo $filter_url; ?>&page=<?php echo $total_pages; ?>" class="pagination-item"><?php echo $total_pages; ?></a>
                            <?php endif; ?>
                            
                            <?php if ($current_page < $total_pages): ?>
                                <a href="?<?php echo $filter_url; ?>&page=<?php echo $current_page + 1; ?>" class="pagination-item">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php elseif (isset($_GET['filter_movies'])): ?>
                <div class="message message-error" data-aos="fade-in">Không tìm thấy phim nào phù hợp với bộ lọc. Vui lòng thử lại với các tiêu chí khác.</div>
            <?php endif; ?>

            <!-- Back Button -->
            <a href="movie_player" class="back-button" data-aos="fade-up">
                <i class="fas fa-arrow-left"></i> Quay lại trang chủ
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
    <script src="https://unpkg.com/aos@next/dist/aos.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Khởi tạo AOS (Animate On Scroll)
            AOS.init({
                duration: 800,
                easing: 'ease-out',
                once: true
            });

            // Hiệu ứng tự động ẩn thông báo sau 5 giây
            setTimeout(() => {
                const messages = document.querySelectorAll('.message');
                messages.forEach(message => {
                    message.style.opacity = '0';
                    message.style.transform = 'translateY(-10px)';
                    message.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    
                    setTimeout(() => {
                        message.style.display = 'none';
                    }, 500);
                });
            }, 5000);

            // Scroll to top button
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
        });
    </script>
</body>
</html>
<?php
if (isset($conn) && $conn) {
    mysqli_close($conn);
}
?>
