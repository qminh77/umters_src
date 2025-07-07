<?php
ob_start();
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'db_config.php';

// Kiểm tra token
if (!isset($_GET['token']) || empty($_GET['token'])) {
    header("Location: index.php");
    exit;
}

$token = mysqli_real_escape_string($conn, $_GET['token']);

// Lấy thông tin chia sẻ
$sql = "SELECT s.*, u.username 
        FROM mood_shares s
        JOIN users u ON s.user_id = u.id
        WHERE s.share_token = ?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "s", $token);
mysqli_stmt_execute($stmt);
$share_result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($share_result) == 0) {
    $error_message = "Liên kết chia sẻ không tồn tại hoặc đã bị xóa.";
} else {
    $share = mysqli_fetch_assoc($share_result);
    
    // Kiểm tra trạng thái và thời gian hết hạn
    $is_expired = false;
    if ($share['expiry_date'] && strtotime($share['expiry_date']) < time()) {
        $is_expired = true;
        $error_message = "Liên kết chia sẻ này đã hết hạn.";
    }
    
    // Kiểm tra quyền truy cập
    $is_private = ($share['is_public'] == 0);
    $has_access = false;
    
    if (isset($_SESSION['user_id'])) {
        if ($_SESSION['user_id'] == $share['user_id']) {
            $has_access = true;
        }
    }
    
    if ($is_private && !$has_access) {
        $error_message = "Chia sẻ này ở chế độ riêng tư. Bạn không có quyền truy cập.";
    }
}

// Chỉ lấy dữ liệu cảm xúc nếu không có lỗi
if (!isset($error_message)) {
    $user_id = $share['user_id'];

    // Lấy danh mục cảm xúc của người dùng
    $sql = "SELECT * FROM mood_categories WHERE user_id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $mood_categories_result = mysqli_stmt_get_result($stmt);
    $mood_categories = [];
    while ($category = mysqli_fetch_assoc($mood_categories_result)) {
        $mood_categories[$category['id']] = $category;
    }

    // Lấy dữ liệu cảm xúc trong 30 ngày gần đây
    $end_date = date('Y-m-d');
    $start_date = date('Y-m-d', strtotime('-30 days'));
    
    $sql = "SELECT me.*, mc.name as mood_name, mc.color, mc.icon 
            FROM mood_entries me 
            JOIN mood_categories mc ON me.mood_category_id = mc.id 
            WHERE me.user_id = ? AND me.date BETWEEN ? AND ?
            ORDER BY me.date, me.time";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "iss", $user_id, $start_date, $end_date);
    mysqli_stmt_execute($stmt);
    $mood_entries_result = mysqli_stmt_get_result($stmt);
    $mood_entries = [];
    while ($entry = mysqli_fetch_assoc($mood_entries_result)) {
        $mood_entries[] = $entry;
    }

    // Organize entries by date
    $entries_by_date = [];
    foreach ($mood_entries as $entry) {
        $date = $entry['date'];
        if (!isset($entries_by_date[$date])) {
            $entries_by_date[$date] = [];
        }
        $entries_by_date[$date][] = $entry;
    }

    // Get statistics for the mood chart
    $month_start = date('Y-m-01');
    $month_end = date('Y-m-t');

    // Monthly statistics
    $sql = "SELECT 
              mc.name as mood_name, 
              mc.color,
              COUNT(*) as count 
            FROM 
              mood_entries me 
              JOIN mood_categories mc ON me.mood_category_id = mc.id 
            WHERE 
              me.user_id = ? 
              AND me.date BETWEEN ? AND ? 
            GROUP BY 
              mc.id 
            ORDER BY 
              count DESC";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "iss", $user_id, $month_start, $month_end);
    mysqli_stmt_execute($stmt);
    $monthly_stats_result = mysqli_stmt_get_result($stmt);
    $monthly_stats = [];
    while ($row = mysqli_fetch_assoc($monthly_stats_result)) {
        $monthly_stats[] = $row;
    }

    // Get mood entry counts by time period for the current month
    $sql = "SELECT 
              time_period,
              COUNT(*) as count 
            FROM 
              mood_entries 
            WHERE 
              user_id = ? 
              AND date BETWEEN ? AND ? 
            GROUP BY 
              time_period 
            ORDER BY 
              FIELD(time_period, 'morning', 'noon', 'afternoon', 'evening', 'night')";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "iss", $user_id, $month_start, $month_end);
    mysqli_stmt_execute($stmt);
    $time_period_stats_result = mysqli_stmt_get_result($stmt);
    $time_period_stats = [];
    while ($row = mysqli_fetch_assoc($time_period_stats_result)) {
        $time_period_labels = [
            'morning' => 'Buổi sáng',
            'noon' => 'Buổi trưa',
            'afternoon' => 'Buổi chiều',
            'evening' => 'Buổi tối',
            'night' => 'Đêm khuya'
        ];
        $row['label'] = $time_period_labels[$row['time_period']];
        $time_period_stats[] = $row;
    }

    // Get mood trends by day for the current month
    $sql = "SELECT 
              me.date,
              GROUP_CONCAT(mc.name ORDER BY me.time) as moods
            FROM 
              mood_entries me 
              JOIN mood_categories mc ON me.mood_category_id = mc.id 
            WHERE 
              me.user_id = ? 
              AND me.date BETWEEN ? AND ? 
            GROUP BY 
              me.date 
            ORDER BY 
              me.date";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "iss", $user_id, $month_start, $month_end);
    mysqli_stmt_execute($stmt);
    $mood_trends_result = mysqli_stmt_get_result($stmt);
    $mood_trends = [];
    while ($row = mysqli_fetch_assoc($mood_trends_result)) {
        $mood_trends[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($share) ? htmlspecialchars($share['title']) : 'Theo dõi cảm xúc'; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.10.1/main.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.css" rel="stylesheet">
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

        /* Main layout */
        .mood-container {
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
            background: linear-gradient(to right, var(--primary-light), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .page-subtitle {
            color: var(--foreground-muted);
            font-size: 1rem;
            margin-top: 0.5rem;
        }

        .share-info {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 1.5rem 2rem;
            margin-bottom: 2rem;
        }

        .share-info-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--foreground);
        }

        .share-info-description {
            color: var(--foreground-muted);
            margin-bottom: 1rem;
        }

        .share-info-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            color: var(--foreground-muted);
            font-size: 0.9rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border);
        }

        .share-info-meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .share-info-meta-item i {
            color: var(--primary-light);
        }

        .error-container {
            background: rgba(255, 61, 87, 0.1);
            border: 1px solid var(--danger-dark);
            border-radius: var(--radius-lg);
            padding: 2rem;
            text-align: center;
            margin: 5rem auto;
            max-width: 600px;
        }

        .error-icon {
            font-size: 3rem;
            color: var(--danger);
            margin-bottom: 1rem;
        }

        .error-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--foreground);
        }

        .error-message {
            color: var(--foreground-muted);
            margin-bottom: 1.5rem;
        }

        .return-btn {
            padding: 0.75rem 1.5rem;
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            color: white;
            border: none;
            border-radius: var(--radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .return-btn:hover {
            background: linear-gradient(90deg, var(--primary-light), var(--primary));
            transform: translateY(-2px);
            box-shadow: var(--glow);
            color: white;
        }

        /* Calendar */
        .calendar-container {
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

        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .calendar-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--foreground);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .calendar-title i {
            color: var(--primary-light);
        }

        /* FullCalendar customization */
        .fc {
            background: transparent;
            font-family: 'Outfit', sans-serif;
        }

        .fc .fc-toolbar {
            margin-bottom: 1.5rem;
        }

        .fc .fc-toolbar-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--foreground);
        }

        .fc .fc-button {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid var(--border);
            color: var(--foreground);
            font-weight: 500;
            padding: 0.5rem 1rem;
            border-radius: var(--radius);
            transition: all 0.3s ease;
        }

        .fc .fc-button:hover {
            background: rgba(255, 255, 255, 0.15);
            border-color: var(--primary-light);
            transform: translateY(-2px);
        }

        .fc .fc-button-primary:not(:disabled).fc-button-active {
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            border-color: var(--primary-light);
            box-shadow: var(--glow);
        }

        .fc .fc-daygrid-day {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--border);
        }

        .fc .fc-daygrid-day:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        .fc .fc-daygrid-day.fc-day-today {
            background: rgba(112, 0, 255, 0.1);
        }

        .fc .fc-daygrid-day-number {
            color: var(--foreground);
            padding: 0.5rem;
        }

        .fc .fc-event {
            border: none;
            border-radius: var(--radius-sm);
            padding: 0.25rem 0.5rem;
            margin: 0.25rem 0;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .fc .fc-event:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        /* Chart container */
        .chart-container {
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

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .chart-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--foreground);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .chart-title i {
            color: var(--primary-light);
        }

        .chart-tabs {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .chart-tab {
            padding: 0.5rem 1rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            color: var(--foreground-muted);
            border-radius: var(--radius);
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .chart-tab:hover {
            background: rgba(255, 255, 255, 0.08);
            color: var(--foreground);
        }

        .chart-tab.active {
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            color: white;
            border-color: var(--primary-light);
            box-shadow: var(--glow);
        }

        .chart-content {
            display: none;
            animation: fadeIn 0.5s ease;
        }

        .chart-content.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Mood Detail Popover */
        .mood-popover {
            position: fixed;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            width: 350px;
            max-width: 90vw;
            box-shadow: var(--shadow-lg);
            z-index: 1050;
            opacity: 0;
            visibility: hidden;
            transform: translateY(10px);
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
        }

        .mood-popover.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .popover-header {
            background: none;
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border);
            
        }

        .popover-icon {
            width: 50px;
            height: 50px;
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .popover-title {
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 0.25rem;
        }

        .popover-time {
            color: var(--foreground-muted);
            font-size: 0.9rem;
        }

        .popover-content {
            margin-bottom: 1rem;
        }

        .popover-section {
            margin-bottom: 1rem;
        }

        .popover-section-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .popover-section-title i {
            color: var(--primary-light);
        }

        .popover-section-content {
            color: var(--foreground-muted);
            font-size: 0.95rem;
        }

        .popover-close {
            position: absolute;
            top: 1rem;
            right: 1rem;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            color: var(--foreground-muted);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .popover-close:hover {
            background: rgba(255, 255, 255, 0.2);
            color: var(--foreground);
        }

        /* Categories list */
        .categories-container {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .categories-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--foreground);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }

        .categories-title i {
            color: var(--primary-light);
        }

        .categories-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .category-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: var(--radius-full);
            padding: 0.5rem 1rem;
            transition: all 0.3s ease;
        }

        .category-icon {
            width: 30px;
            height: 30px;
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .category-name {
            font-weight: 500;
            font-size: 0.9rem;
        }

        /* Responsive styles */
        @media (max-width: 992px) {
            .mood-container {
                padding: 0 1rem;
            }

            .page-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
                padding: 1.25rem;
            }

            .chart-tabs {
                flex-wrap: wrap;
            }
        }

        @media (max-width: 768px) {
            .chart-tabs {
                overflow-x: auto;
                padding-bottom: 0.5rem;
                -webkit-overflow-scrolling: touch;
                scroll-snap-type: x mandatory;
            }

            .chart-tab {
                scroll-snap-align: start;
                flex: 0 0 auto;
            }
        }

        @media (max-width: 576px) {
            .page-title {
                font-size: 1.5rem;
            }

            .fc .fc-toolbar {
                flex-direction: column;
                gap: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="mood-container">
        <?php if (isset($error_message)): ?>
            <div class="error-container">
                <div class="error-icon">
                    <i class="fas fa-exclamation-circle"></i>
                </div>
                <h2 class="error-title">Không thể hiển thị chia sẻ</h2>
                <p class="error-message"><?php echo $error_message; ?></p>
                <a href="index.php" class="return-btn">
                    <i class="fas fa-home"></i>
                    Trở về trang chủ
                </a>
            </div>
        <?php else: ?>
            <div class="page-header">
                <div>
                    <h1 class="page-title">
                        <i class="fas fa-heart"></i>
                        <?php echo htmlspecialchars($share['title']); ?>
                    </h1>
                    <?php if (!empty($share['description'])): ?>
                        <p class="page-subtitle"><?php echo nl2br(htmlspecialchars($share['description'])); ?></p>
                    <?php endif; ?>
                </div>

                <div class="share-info-meta">
                    <div class="share-info-meta-item">
                        <i class="fas fa-user"></i>
                        <span><?php echo htmlspecialchars($share['username']); ?></span>
                    </div>
                    <div class="share-info-meta-item">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Tạo ngày <?php echo date('d/m/Y', strtotime($share['created_at'])); ?></span>
                    </div>
                    <?php if ($share['expiry_date']): ?>
                        <div class="share-info-meta-item">
                            <i class="fas fa-clock"></i>
                            <span>Hết hạn: <?php echo date('d/m/Y H:i', strtotime($share['expiry_date'])); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Categories Container -->
            <div class="categories-container">
                <h2 class="categories-title">
                    <i class="fas fa-tags"></i>
                    Danh mục cảm xúc
                </h2>
                <div class="categories-list">
                    <?php foreach ($mood_categories as $category): ?>
                        <div class="category-item">
                            <div class="category-icon" style="background: <?php echo $category['color']; ?>20; color: <?php echo $category['color']; ?>">
                                <?php if ($category['icon']): ?>
                                    <i class="<?php echo $category['icon']; ?>"></i>
                                <?php endif; ?>
                            </div>
                            <div class="category-name"><?php echo $category['name']; ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="row">
                <!-- Main Content -->
                <div class="col-lg-8">
                    <div class="calendar-container">
                        <div class="calendar-header">
                            <h2 class="calendar-title">
                                <i class="fas fa-calendar-alt"></i>
                                Lịch cảm xúc
                            </h2>
                        </div>
                        <div id="calendar"></div>
                    </div>
                </div>

                <!-- Sidebar -->
                <div class="col-lg-4">
                    <!-- Charts Container -->
                    <div class="chart-container">
                        <div class="chart-header">
                            <h2 class="chart-title">
                                <i class="fas fa-chart-pie"></i>
                                Thống kê cảm xúc
                            </h2>
                        </div>
                        
                        <div class="chart-tabs">
                            <div class="chart-tab active" data-target="monthlyChart">Tháng này</div>
                            <div class="chart-tab" data-target="timePeriodsChart">Thời điểm</div>
                            <div class="chart-tab" data-target="trendChart">Xu hướng</div>
                        </div>
                        
                        <div class="chart-content active" id="monthlyChart">
                            <canvas id="monthlyChartCanvas" height="300"></canvas>
                        </div>
                        
                        <div class="chart-content" id="timePeriodsChart">
                            <canvas id="timePeriodsChartCanvas" height="300"></canvas>
                        </div>
                        
                        <div class="chart-content" id="trendChart">
                            <canvas id="trendChartCanvas" height="300"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Mood Detail Popover -->
    <div class="mood-popover" id="moodPopover">
        <div class="popover-close" id="closePopover">
            <i class="fas fa-times"></i>
        </div>
        <div class="popover-header">
            <div class="popover-icon" id="popoverIcon"></div>
            <div>
                <div class="popover-title" id="popoverTitle"></div>
                <div class="popover-time" id="popoverTime"></div>
            </div>
        </div>
        <div class="popover-content">
            <div class="popover-section">
                <div class="popover-section-title">
                    <i class="fas fa-sticky-note"></i>
                    Ghi chú
                </div>
                <div class="popover-section-content" id="popoverNotes"></div>
            </div>
            <div class="popover-section">
                <div class="popover-section-title">
                    <i class="fas fa-tasks"></i>
                    Hoạt động cải thiện
                </div>
                <div class="popover-section-content" id="popoverActivities"></div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.10.1/main.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        <?php if (!isset($error_message)): ?>
        // Khởi tạo calendar
        initializeCalendar();
        
        // Khởi tạo biểu đồ
        initializeCharts();
        
        // Xử lý tab trong chart container
        document.querySelectorAll('.chart-tab').forEach(tab => {
            tab.addEventListener('click', function() {
                document.querySelectorAll('.chart-tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.chart-content').forEach(c => c.classList.remove('active'));
                
                this.classList.add('active');
                document.getElementById(this.getAttribute('data-target')).classList.add('active');
            });
        });
        
        // Xử lý đóng popover
        document.getElementById('closePopover').addEventListener('click', function() {
            document.getElementById('moodPopover').classList.remove('show');
        });
        
        // Hàm khởi tạo calendar
        function initializeCalendar() {
            const calendarEl = document.getElementById('calendar');
            calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek'
                },
                events: [
                    <?php foreach ($entries_by_date as $date => $entries): ?>
                    <?php foreach ($entries as $entry): ?>
                    {
                        id: '<?php echo $entry['id']; ?>',
                        title: '<?php echo $entry['mood_name']; ?>',
                        start: '<?php echo $date . 'T' . $entry['time']; ?>',
                        backgroundColor: '<?php echo $entry['color']; ?>',
                        borderColor: '<?php echo $entry['color']; ?>',
                        extendedProps: {
                            mood_category_id: '<?php echo $entry['mood_category_id']; ?>',
                            time_period: '<?php echo $entry['time_period']; ?>',
                            date: '<?php echo $date; ?>',
                            time: '<?php echo $entry['time']; ?>',
                            notes: '<?php echo addslashes($entry['notes']); ?>',
                            activities: '<?php echo addslashes($entry['activities']); ?>',
                            icon: '<?php echo $entry['icon']; ?>'
                        }
                    },
                    <?php endforeach; ?>
                    <?php endforeach; ?>
                ],
                eventClick: function(info) {
                    showMoodPopover(info.event);
                }
            });
            calendar.render();
        }
        
        // Hàm hiển thị popover chi tiết cảm xúc
        function showMoodPopover(event) {
            const popover = document.getElementById('moodPopover');
            
            document.getElementById('popoverTitle').textContent = event.title;
            
            const timePeriodLabels = {
                'morning': 'Buổi sáng',
                'noon': 'Buổi trưa',
                'afternoon': 'Buổi chiều',
                'evening': 'Buổi tối',
                'night': 'Đêm khuya'
            };
            
            const dateObj = new Date(event.start);
            const formattedDate = dateObj.toLocaleDateString('vi-VN', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            
            const timeStr = dateObj.toLocaleTimeString('vi-VN', {
                hour: '2-digit',
                minute: '2-digit'
            });
            
            document.getElementById('popoverTime').textContent = 
                `${timePeriodLabels[event.extendedProps.time_period]} - ${formattedDate} (${timeStr})`;
            
            const iconEl = document.getElementById('popoverIcon');
            iconEl.innerHTML = `<i class="${event.extendedProps.icon}"></i>`;
            iconEl.style.backgroundColor = `${event.backgroundColor}20`;
            iconEl.style.color = event.backgroundColor;
            
            document.getElementById('popoverNotes').textContent = 
                event.extendedProps.notes || 'Không có ghi chú';
            document.getElementById('popoverActivities').textContent = 
                event.extendedProps.activities || 'Không có hoạt động';
            
            try {
                let rect;
                if (event.el && typeof event.el.getBoundingClientRect === 'function') {
                    rect = event.el.getBoundingClientRect();
                } else {
                    const mousePosition = getCurrentMousePosition();
                    rect = {
                        left: mousePosition.x,
                        right: mousePosition.x,
                        top: mousePosition.y,
                        bottom: mousePosition.y,
                        width: 0,
                        height: 0
                    };
                }
                
                const windowWidth = window.innerWidth;
                const windowHeight = window.innerHeight;
                const popoverWidth = 350;
                const popoverHeight = 400;
                
                let left, top;
                
                if (rect.right + popoverWidth + 20 < windowWidth) {
                    left = rect.right + 10;
                } else if (rect.left - popoverWidth - 20 > 0) {
                    left = rect.left - popoverWidth - 10;
                } else {
                    left = (windowWidth - popoverWidth) / 2;
                }
                
                const eventMiddle = rect.top + rect.height / 2;
                top = eventMiddle - popoverHeight / 2;
                
                if (top < 10) top = 10;
                if (top + popoverHeight > windowHeight - 10) {
                    top = windowHeight - popoverHeight - 10;
                }
                
                popover.style.left = `${left}px`;
                popover.style.top = `${top}px`;
            } catch (e) {
                console.error("Error positioning popover:", e);
                // Fallback to center positioning
                const windowWidth = window.innerWidth;
                const windowHeight = window.innerHeight;
                popover.style.left = `${windowWidth / 2 - 175}px`;
                popover.style.top = `${windowHeight / 2 - 200}px`;
            }
            
            popover.classList.add('show');
        }
        
        // Hàm lấy vị trí chuột hiện tại
        let mouseX = 0, mouseY = 0;
        document.addEventListener('mousemove', function(e) {
            mouseX = e.clientX;
            mouseY = e.clientY;
        });
        
        function getCurrentMousePosition() {
            return {
                x: mouseX,
                y: mouseY
            };
        }
        
        // Biểu đồ cảm xúc trong tháng
        function initializeCharts() {
            initMonthlyChart();
            initTimePeriodsChart();
            initTrendChart();
        }
        
        function initMonthlyChart() {
            const ctx = document.getElementById('monthlyChartCanvas').getContext('2d');
            
            const data = {
                labels: [
                    <?php foreach ($monthly_stats as $stat): ?>
                    '<?php echo $stat['mood_name']; ?>',
                    <?php endforeach; ?>
                ],
                datasets: [{
                    data: [
                        <?php foreach ($monthly_stats as $stat): ?>
                        <?php echo $stat['count']; ?>,
                        <?php endforeach; ?>
                    ],
                    backgroundColor: [
                        <?php foreach ($monthly_stats as $stat): ?>
                        '<?php echo $stat['color']; ?>',
                        <?php endforeach; ?>
                    ],
                    borderWidth: 0,
                    hoverOffset: 15
                }]
            };
            
            const config = {
                type: 'pie',
                data: data,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                color: 'rgba(255, 255, 255, 0.7)',
                                font: {
                                    family: 'Outfit',
                                    size: 12
                                },
                                padding: 15
                            }
                        },
                        title: {
                            display: true,
                            text: 'Cảm xúc trong tháng này',
                            color: 'rgba(255, 255, 255, 0.9)',
                            font: {
                                family: 'Outfit',
                                size: 16,
                                weight: 'bold'
                            },
                            padding: {
                                bottom: 15
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.formattedValue;
                                    const total = context.dataset.data.reduce((acc, val) => acc + val, 0);
                                    const percentage = Math.round((context.raw / total) * 100);
                                    return `${label}: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    },
                    animation: {
                        animateScale: true,
                        animateRotate: true
                    }
                }
            };
            
            new Chart(ctx, config);
        }
        
        // Biểu đồ thời điểm cảm xúc
        function initTimePeriodsChart() {
            const ctx = document.getElementById('timePeriodsChartCanvas').getContext('2d');
            
            const gradientBar1 = ctx.createLinearGradient(0, 0, 0, 400);
            gradientBar1.addColorStop(0, 'rgba(112, 0, 255, 0.8)');
            gradientBar1.addColorStop(1, 'rgba(112, 0, 255, 0.2)');
            
            const data = {
                labels: [
                    <?php foreach ($time_period_stats as $stat): ?>
                    '<?php echo $stat['label']; ?>',
                    <?php endforeach; ?>
                ],
                datasets: [{
                    label: 'Số lượng',
                    data: [
                        <?php foreach ($time_period_stats as $stat): ?>
                        <?php echo $stat['count']; ?>,
                        <?php endforeach; ?>
                    ],
                    backgroundColor: gradientBar1,
                    borderRadius: 8,
                    borderSkipped: false
                }]
            };
            
            const config = {
                type: 'bar',
                data: data,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            grid: {
                                display: false,
                                drawBorder: false
                            },
                            ticks: {
                                color: 'rgba(255, 255, 255, 0.7)'
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(255, 255, 255, 0.1)',
                                drawBorder: false
                            },
                            ticks: {
                                color: 'rgba(255, 255, 255, 0.7)',
                                precision: 0
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        title: {
                            display: true,
                            text: 'Cảm xúc theo thời điểm trong ngày',
                            color: 'rgba(255, 255, 255, 0.9)',
                            font: {
                                family: 'Outfit',
                                size: 16,
                                weight: 'bold'
                            },
                            padding: {
                                bottom: 15
                            }
                        }
                    },
                    animation: {
                        duration: 2000
                    }
                }
            };
            
            new Chart(ctx, config);
        }
        
        // Biểu đồ xu hướng cảm xúc
        function initTrendChart() {
            const ctx = document.getElementById('trendChartCanvas').getContext('2d');
            
            const gradient = ctx.createLinearGradient(0, 0, 0, 400);
            gradient.addColorStop(0, 'rgba(0, 224, 255, 0.5)');
            gradient.addColorStop(1, 'rgba(0, 224, 255, 0)');
            
            <?php 
            $daily_counts = [];
            foreach ($mood_entries as $entry) {
                $date = $entry['date'];
                if (!isset($daily_counts[$date])) {
                    $daily_counts[$date] = 0;
                }
                $daily_counts[$date]++;
            }
            ksort($daily_counts);
            ?>
            
            const dailyData = {
                labels: [
                    <?php foreach ($daily_counts as $date => $count): ?>
                    '<?php echo date("d/m", strtotime($date)); ?>',
                    <?php endforeach; ?>
                ],
                datasets: [{
                    label: 'Số lượng cảm xúc',
                    data: [
                        <?php foreach ($daily_counts as $count): ?>
                        <?php echo $count; ?>,
                        <?php endforeach; ?>
                    ],
                    borderColor: 'rgba(0, 224, 255, 1)',
                    backgroundColor: gradient,
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: 'rgba(0, 224, 255, 1)',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            };
            
            const config = {
                type: 'line',
                data: dailyData,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            grid: {
                                display: false,
                                drawBorder: false
                            },
                            ticks: {
                                color: 'rgba(255, 255, 255, 0.7)'
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(255, 255, 255, 0.1)',
                                drawBorder: false
                            },
                            ticks: {
                                color: 'rgba(255, 255, 255, 0.7)',
                                precision: 0
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        title: {
                            display: true,
                            text: 'Xu hướng cảm xúc trong tháng',
                            color: 'rgba(255, 255, 255, 0.9)',
                            font: {
                                family: 'Outfit',
                                size: 16,
                                weight: 'bold'
                            },
                            padding: {
                                bottom: 15
                            }
                        }
                    },
                    animation: {
                        duration: 2000
                    }
                }
            };
            
            new Chart(ctx, config);
        }
        <?php endif; ?>
    });
</script>
</body>
</html>