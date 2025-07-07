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

// Tạo bảng habits cho thói quen
$sql_habits = "CREATE TABLE IF NOT EXISTS habits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    target_days INT NOT NULL DEFAULT 1,
    color VARCHAR(7) DEFAULT '#7000FF',
    icon VARCHAR(50) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
)";
mysqli_query($conn, $sql_habits) or die("Error creating habits: " . mysqli_error($conn));

// Tạo bảng habit_logs để lưu trữ dữ liệu hoàn thành
$sql_habit_logs = "CREATE TABLE IF NOT EXISTS habit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    habit_id INT NOT NULL,
    user_id INT NOT NULL,
    date DATE NOT NULL,
    is_completed TINYINT(1) DEFAULT 1,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (habit_id) REFERENCES habits(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE KEY (habit_id, date)
)";
mysqli_query($conn, $sql_habit_logs) or die("Error creating habit_logs: " . mysqli_error($conn));

// Tạo bảng habit_categories để phân loại thói quen
$sql_habit_categories = "CREATE TABLE IF NOT EXISTS habit_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(50) NOT NULL,
    color VARCHAR(7) DEFAULT '#7000FF',
    icon VARCHAR(50) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
)";
mysqli_query($conn, $sql_habit_categories) or die("Error creating habit_categories: " . mysqli_error($conn));

// Thêm trường category_id vào bảng habits nếu chưa có
$check_column = mysqli_query($conn, "SHOW COLUMNS FROM habits LIKE 'category_id'");
if (mysqli_num_rows($check_column) == 0) {
    mysqli_query($conn, "ALTER TABLE habits ADD category_id INT NULL, ADD FOREIGN KEY (category_id) REFERENCES habit_categories(id)");
}

// Lấy tháng và năm hiện tại hoặc từ tham số
$current_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$current_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Xử lý thêm thói quen mới
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_habit') {
    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $description = mysqli_real_escape_string($conn, $_POST['description']);
    $target_days = (int)$_POST['target_days'];
    $color = mysqli_real_escape_string($conn, $_POST['color']);
    $icon = isset($_POST['icon']) ? mysqli_real_escape_string($conn, $_POST['icon']) : null;
    
    // Kiểm tra category_id
    if (isset($_POST['category_id']) && !empty($_POST['category_id'])) {
        $category_id = (int)$_POST['category_id'];
        
        // Kiểm tra xem category_id có tồn tại trong bảng habit_categories không
        $check_category = "SELECT id FROM habit_categories WHERE id = ? AND user_id = ?";
        $check_stmt = mysqli_prepare($conn, $check_category);
        mysqli_stmt_bind_param($check_stmt, "ii", $category_id, $user_id);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);
        
        if (mysqli_num_rows($check_result) == 0) {
            // Nếu category_id không tồn tại, đặt thành NULL
            $category_id = null;
        }
    } else {
        $category_id = null;
    }
    
    // Sử dụng truy vấn SQL phù hợp với giá trị NULL cho category_id
    if ($category_id === null) {
        $sql = "INSERT INTO habits (user_id, name, description, target_days, color, icon, category_id) 
                VALUES (?, ?, ?, ?, ?, ?, NULL)";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ississ", $user_id, $name, $description, $target_days, $color, $icon);
    } else {
        $sql = "INSERT INTO habits (user_id, name, description, target_days, color, icon, category_id) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ississi", $user_id, $name, $description, $target_days, $color, $icon, $category_id);
    }
    
    if (mysqli_stmt_execute($stmt)) {
        $success_message = "Đã thêm thói quen mới!";
    } else {
        $error_message = "Lỗi khi thêm thói quen: " . mysqli_error($conn);
    }
}

// Xử lý chỉnh sửa thói quen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_habit') {
    $habit_id = (int)$_POST['habit_id'];
    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $description = mysqli_real_escape_string($conn, $_POST['description']);
    $target_days = (int)$_POST['target_days'];
    $color = mysqli_real_escape_string($conn, $_POST['color']);
    $icon = isset($_POST['icon']) ? mysqli_real_escape_string($conn, $_POST['icon']) : null;
    
    // Kiểm tra category_id
    if (isset($_POST['category_id']) && !empty($_POST['category_id'])) {
        $category_id = (int)$_POST['category_id'];
        
        // Kiểm tra xem category_id có tồn tại trong bảng habit_categories không
        $check_category = "SELECT id FROM habit_categories WHERE id = ? AND user_id = ?";
        $check_stmt = mysqli_prepare($conn, $check_category);
        mysqli_stmt_bind_param($check_stmt, "ii", $category_id, $user_id);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);
        
        if (mysqli_num_rows($check_result) == 0) {
            // Nếu category_id không tồn tại, đặt thành NULL
            $category_id = null;
        }
    } else {
        $category_id = null;
    }
    
    // Sử dụng truy vấn SQL phù hợp với giá trị NULL cho category_id
    if ($category_id === null) {
        $sql = "UPDATE habits SET name = ?, description = ?, target_days = ?, color = ?, icon = ?, category_id = NULL
                WHERE id = ? AND user_id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ssissii", $name, $description, $target_days, $color, $icon, $habit_id, $user_id);
    } else {
        $sql = "UPDATE habits SET name = ?, description = ?, target_days = ?, color = ?, icon = ?, category_id = ?
                WHERE id = ? AND user_id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ssissiii", $name, $description, $target_days, $color, $icon, $category_id, $habit_id, $user_id);
    }
    
    if (mysqli_stmt_execute($stmt)) {
        $success_message = "Đã cập nhật thói quen!";
    } else {
        $error_message = "Lỗi khi cập nhật thói quen: " . mysqli_error($conn);
    }
}

// Xử lý xóa thói quen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_habit') {
    $habit_id = (int)$_POST['habit_id'];
    
    // Xóa log trước
    $delete_logs = "DELETE FROM habit_logs WHERE habit_id = ? AND user_id = ?";
    $stmt_logs = mysqli_prepare($conn, $delete_logs);
    mysqli_stmt_bind_param($stmt_logs, "ii", $habit_id, $user_id);
    mysqli_stmt_execute($stmt_logs);
    
    // Sau đó xóa thói quen
    $delete_habit = "DELETE FROM habits WHERE id = ? AND user_id = ?";
    $stmt_habit = mysqli_prepare($conn, $delete_habit);
    mysqli_stmt_bind_param($stmt_habit, "ii", $habit_id, $user_id);
    
    if (mysqli_stmt_execute($stmt_habit)) {
        echo json_encode(['success' => true]);
        exit;
    } else {
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
        exit;
    }
}

// Xử lý thêm danh mục thói quen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_category') {
    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $color = mysqli_real_escape_string($conn, $_POST['color']);
    $icon = isset($_POST['icon']) ? mysqli_real_escape_string($conn, $_POST['icon']) : null;
    
    $sql = "INSERT INTO habit_categories (user_id, name, color, icon) VALUES (?, ?, ?, ?)";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "isss", $user_id, $name, $color, $icon);
    
    if (mysqli_stmt_execute($stmt)) {
        $success_message = "Đã thêm danh mục mới!";
    } else {
        $error_message = "Lỗi khi thêm danh mục: " . mysqli_error($conn);
    }
}

// Xử lý đánh dấu hoàn thành thói quen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_habit') {
    $habit_id = (int)$_POST['habit_id'];
    $date = mysqli_real_escape_string($conn, $_POST['date']);
    $completed = (int)$_POST['completed'];
    
    // Kiểm tra xem đã tồn tại bản ghi chưa
    $check_sql = "SELECT id FROM habit_logs WHERE habit_id = ? AND user_id = ? AND date = ?";
    $check_stmt = mysqli_prepare($conn, $check_sql);
    mysqli_stmt_bind_param($check_stmt, "iis", $habit_id, $user_id, $date);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    
    if (mysqli_num_rows($check_result) > 0) {
        if ($completed == 0) {
            // Nếu đánh dấu là chưa hoàn thành, xóa bản ghi
            $delete_sql = "DELETE FROM habit_logs WHERE habit_id = ? AND user_id = ? AND date = ?";
            $stmt = mysqli_prepare($conn, $delete_sql);
            mysqli_stmt_bind_param($stmt, "iis", $habit_id, $user_id, $date);
        } else {
            // Cập nhật bản ghi hiện có
            $update_sql = "UPDATE habit_logs SET is_completed = ? WHERE habit_id = ? AND user_id = ? AND date = ?";
            $stmt = mysqli_prepare($conn, $update_sql);
            mysqli_stmt_bind_param($stmt, "iiis", $completed, $habit_id, $user_id, $date);
        }
    } else if ($completed == 1) {
        // Tạo bản ghi mới nếu chưa tồn tại và đánh dấu là hoàn thành
        $insert_sql = "INSERT INTO habit_logs (habit_id, user_id, date, is_completed) VALUES (?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $insert_sql);
        mysqli_stmt_bind_param($stmt, "iisi", $habit_id, $user_id, $date, $completed);
    }
    
    if (isset($stmt) && mysqli_stmt_execute($stmt)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    }
    exit;
}

// Lấy danh sách tất cả thói quen của người dùng
$habits_sql = "SELECT h.*, c.name as category_name, c.color as category_color, 
              (SELECT COUNT(*) FROM habit_logs hl WHERE hl.habit_id = h.id AND MONTH(hl.date) = ? AND YEAR(hl.date) = ?) as completed_days
              FROM habits h 
              LEFT JOIN habit_categories c ON h.category_id = c.id
              WHERE h.user_id = ?
              ORDER BY h.created_at DESC";
$habits_stmt = mysqli_prepare($conn, $habits_sql);
mysqli_stmt_bind_param($habits_stmt, "iii", $current_month, $current_year, $user_id);
mysqli_stmt_execute($habits_stmt);
$habits_result = mysqli_stmt_get_result($habits_stmt);
$habits = [];
while ($habit = mysqli_fetch_assoc($habits_result)) {
    $habits[] = $habit;
}

// Lấy danh sách danh mục
$categories_sql = "SELECT * FROM habit_categories WHERE user_id = ? ORDER BY name ASC";
$categories_stmt = mysqli_prepare($conn, $categories_sql);
mysqli_stmt_bind_param($categories_stmt, "i", $user_id);
mysqli_stmt_execute($categories_stmt);
$categories_result = mysqli_stmt_get_result($categories_stmt);
$categories = [];
while ($category = mysqli_fetch_assoc($categories_result)) {
    $categories[] = $category;
}

// Lấy dữ liệu hoàn thành thói quen trong tháng
$logs_sql = "SELECT hl.habit_id, hl.date, hl.is_completed 
             FROM habit_logs hl
             JOIN habits h ON hl.habit_id = h.id
             WHERE hl.user_id = ? AND h.user_id = ? AND MONTH(hl.date) = ? AND YEAR(hl.date) = ?";
$logs_stmt = mysqli_prepare($conn, $logs_sql);
mysqli_stmt_bind_param($logs_stmt, "iiii", $user_id, $user_id, $current_month, $current_year);
mysqli_stmt_execute($logs_stmt);
$logs_result = mysqli_stmt_get_result($logs_stmt);
$habit_logs = [];
while ($log = mysqli_fetch_assoc($logs_result)) {
    if (!isset($habit_logs[$log['habit_id']])) {
        $habit_logs[$log['habit_id']] = [];
    }
    $habit_logs[$log['habit_id']][date('j', strtotime($log['date']))] = $log['is_completed'];
}

// Tính toán số ngày trong tháng
$days_in_month = cal_days_in_month(CAL_GREGORIAN, $current_month, $current_year);

// Lấy ngày đầu tiên của tháng
$first_day_of_month = mktime(0, 0, 0, $current_month, 1, $current_year);
$first_day_of_week = date('N', $first_day_of_month); // 1 (Thứ 2) đến 7 (Chủ nhật)

// Mảng chứa tên các ngày trong tuần
$weekdays = ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'];

// Tính tổng số hoàn thành và tổng mục tiêu
$total_completed = 0;
$total_target = 0;

foreach ($habits as $habit) {
    $completions = isset($habit_logs[$habit['id']]) ? count($habit_logs[$habit['id']]) : 0;
    $total_completed += $completions;
    $total_target += $habit['target_days'];
}

// Lấy top thói quen hoàn thành nhiều nhất
$top_habits_sql = "SELECT h.id, h.name, COUNT(hl.id) as completion_count
                  FROM habits h
                  LEFT JOIN habit_logs hl ON h.id = hl.habit_id
                  WHERE h.user_id = ? AND MONTH(hl.date) = ? AND YEAR(hl.date) = ?
                  GROUP BY h.id
                  ORDER BY completion_count DESC
                  LIMIT 5";
$top_habits_stmt = mysqli_prepare($conn, $top_habits_sql);
mysqli_stmt_bind_param($top_habits_stmt, "iii", $user_id, $current_month, $current_year);
mysqli_stmt_execute($top_habits_stmt);
$top_habits_result = mysqli_stmt_get_result($top_habits_stmt);
$top_habits = [];
while ($habit = mysqli_fetch_assoc($top_habits_result)) {
    $top_habits[] = $habit;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý thói quen</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
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
            --success: #4ADE80;
            --success-light: #86EFAC;
            --success-dark: #22C55E;
            
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
            --glow-success: 0 0 20px rgba(74, 222, 128, 0.5);
            
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
        .habit-container {
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

        .add-habit-btn {
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

        .add-habit-btn:hover {
            background: linear-gradient(90deg, var(--primary-light), var(--primary));
            transform: translateY(-2px);
            box-shadow: var(--glow);
            color: white;
        }

        /* Progress Circle */
        .progress-circle-container {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            text-align: center;
            margin-bottom: 1.5rem;
        }

        .progress-circle-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .progress-circle {
            width: 150px;
            height: 150px;
            position: relative;
            margin: 0 auto;
        }

        .progress-circle svg {
            transform: rotate(-90deg);
        }

        .progress-circle-bg {
            fill: none;
            stroke: rgba(255, 255, 255, 0.1);
            stroke-width: 8;
        }

        .progress-circle-progress {
            fill: none;
            stroke: var(--primary);
            stroke-width: 8;
            stroke-linecap: round;
            stroke-dasharray: 440;
            stroke-dashoffset: 440;
            transition: stroke-dashoffset 1s ease;
        }

        .progress-circle-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 1.5rem;
            font-weight: 700;
        }

        /* Habit Table */
        .habit-table-container {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            overflow-x: auto;
            margin-bottom: 1.5rem;
        }

        .habit-table-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .habit-table-title i {
            color: var(--primary-light);
        }

        .habit-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .habit-table th,
        .habit-table td {
            padding: 0.75rem;
            text-align: center;
            border-bottom: 1px solid var(--border);
        }

        .habit-table thead th {
            background: var(--surface);
            color: var(--foreground);
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
            padding: 1rem 0.75rem;
            text-align: center;
        }

        .habit-table th:first-child,
        .habit-table td:first-child {
            text-align: left;
            padding-left: 1rem;
            position: sticky;
            left: 0;
            background: var(--surface);
            z-index: 5;
            min-width: 250px;
            border-right: 1px solid var(--border);
        }

        .habit-table thead th:first-child {
            z-index: 15;
        }

        .habit-weekday {
            min-width: 40px;
        }

        .habit-table .habit-current-day {
            background-color: rgba(112, 0, 255, 0.1);
            font-weight: 700;
        }

        .habit-cell {
            position: relative;
        }

        .habit-checkbox {
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            width: 24px;
            height: 24px;
            background: var(--surface-light);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            cursor: pointer;
            display: inline-block;
            position: relative;
            transition: all 0.3s ease;
        }

        .habit-checkbox:checked {
            background: var(--primary);
            border-color: var(--primary-light);
        }

        .habit-checkbox:checked::after {
            content: '✓';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-size: 0.85rem;
        }

        .habit-checkbox:hover {
            background: var(--surface);
            transform: scale(1.1);
        }

        .habit-checkbox:checked:hover {
            background: var(--primary-light);
        }

        .habit-name {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .habit-icon {
            width: 32px;
            height: 32px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .habit-details {
            flex: 1;
        }

        .habit-title {
            font-weight: 500;
            margin-bottom: 0.25rem;
        }

        .habit-meta {
            font-size: 0.75rem;
            color: var(--foreground-muted);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .habit-category {
            padding: 0.25rem 0.5rem;
            border-radius: var(--radius-sm);
            font-size: 0.7rem;
            background: rgba(255, 255, 255, 0.1);
        }

        .habit-actions {
            display: none;
            position: absolute;
            right: 0.5rem;
            top: 50%;
            transform: translateY(-50%);
            gap: 0.5rem;
        }

        .habit-row:hover .habit-actions {
            display: flex;
        }

        .habit-action {
            width: 28px;
            height: 28px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.85rem;
        }

        .habit-action.edit {
            background: rgba(0, 224, 255, 0.1);
            color: var(--secondary);
        }

        .habit-action.edit:hover {
            background: rgba(0, 224, 255, 0.2);
            transform: translateY(-2px);
        }

        .habit-action.delete {
            background: rgba(255, 61, 87, 0.1);
            color: var(--danger);
        }

        .habit-action.delete:hover {
            background: rgba(255, 61, 87, 0.2);
            transform: translateY(-2px);
        }

        .habit-complete-count {
            font-weight: 600;
            min-width: 60px;
        }

        /* Week headers */
        .week-header {
            background: linear-gradient(to right, rgba(112, 0, 255, 0.2), rgba(112, 0, 255, 0.1));
            color: var(--foreground);
            text-align: center;
            padding: 0.5rem;
            font-weight: 700;
            border-bottom: 2px solid var(--primary-light);
            border-left: 1px solid var(--border);
            border-right: 1px solid var(--border);
        }

        .week-header-cell {
            min-width: 40px;
        }
        
        /* Tuần phân cách */
        .week-separator {
            border-left: 2px solid var(--primary-light);
        }

        /* Statistics */
        .stats-container {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .stats-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .stats-title i {
            color: var(--primary-light);
        }

        .stat-cards {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .stat-card {
            background: var(--surface);
            border-radius: var(--radius);
            padding: 1.25rem;
            flex: 1;
            min-width: 200px;
            box-shadow: var(--shadow-sm);
        }

        .stat-card-title {
            font-size: 0.9rem;
            color: var(--foreground-muted);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .stat-card-title i {
            color: var(--primary-light);
        }

        .stat-card-value {
            font-size: 2rem;
            font-weight: 700;
            line-height: 1;
        }

        .stat-progress {
            height: 6px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: var(--radius-full);
            margin-top: 0.75rem;
            overflow: hidden;
        }

        .stat-progress-bar {
            height: 100%;
            border-radius: var(--radius-full);
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            transition: width 1s ease;
        }

        /* Top Habits */
        .top-habits {
            margin-top: 1.5rem;
        }

        .top-habit-item {
            display: flex;
            align-items: center;
            padding: 0.75rem;
            margin-bottom: 0.5rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: var(--radius);
            transition: all 0.3s ease;
        }

        .top-habit-item:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
        }

        .top-habit-rank {
            width: 30px;
            height: 30px;
            background: var(--primary);
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            margin-right: 1rem;
        }

        .top-habit-name {
            flex: 1;
        }

        .top-habit-count {
            font-weight: 600;
            color: var(--primary-light);
            padding: 0.25rem 0.75rem;
            background: rgba(112, 0, 255, 0.1);
            border-radius: var(--radius-sm);
        }

        /* Month Navigation */
        .month-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            padding: 1rem 1.5rem;
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius);
            border: 1px solid var(--border);
        }

        .month-title {
            font-size: 1.25rem;
            font-weight: 600;
        }

        .month-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .month-btn {
            width: 40px;
            height: 40px;
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid var(--border);
            color: var(--foreground);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .month-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }

        .current-month-btn {
            background: var(--primary);
            color: white;
        }

        .current-month-btn:hover {
            background: var(--primary-light);
        }

        /* Modal styles */
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
        }

        .form-control::placeholder {
            color: var(--foreground-muted);
        }

        .btn-close {
            filter: invert(1) grayscale(100%) brightness(200%);
        }

        /* Icon Grid */
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

        /* Success/Error Messages */
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
            background: rgba(74, 222, 128, 0.1);
            border: 1px solid rgba(74, 222, 128, 0.3);
            color: #4ADE80;
        }

        .alert-error {
            background: rgba(255, 61, 87, 0.1);
            border: 1px solid rgba(255, 61, 87, 0.3);
            color: #FF5D77;
        }

        /* Totals row */
        .totals-row td {
            font-weight: 700;
            background: var(--surface);
            border-top: 2px solid var(--border);
        }

        /* Responsive styles */
        @media (max-width: 992px) {
            .habit-container {
                padding: 0 1rem;
            }

            .page-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
                padding: 1.25rem;
            }

            .stat-cards {
                flex-direction: column;
            }
        }

        @media (max-width: 768px) {
            .habit-table-container {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            
            .habit-table th:first-child,
            .habit-table td:first-child {
                min-width: 150px;
            }
        }

        @media (max-width: 576px) {
            .page-title {
                font-size: 1.5rem;
            }

            .add-habit-btn {
                padding: 0.5rem 1rem;
            }
        }
    </style>
</head>
<body>
    <!-- Alert Messages Container -->
    <div class="alert-container" id="alertContainer"></div>

    <div class="habit-container">
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-check-circle"></i>
                Quản lý thói quen
            </h1>
            <a href="dashboard.php" class="add-habit-btn">
                <i class="fas fa-arrow-left"></i>
                Quay lại Dashboard
            </a>
        </div>

        <div class="row">
            <div class="col-lg-8">
                <!-- Month Navigation -->
                <div class="month-nav">
                    <div class="month-title">
                        <?php
                        setlocale(LC_TIME, 'vi_VN');
                        echo date('m/Y', mktime(0, 0, 0, $current_month, 1, $current_year));
                        ?>
                    </div>
                    <div class="month-buttons">
                        <?php
                        // Tháng trước
                        $prev_month = $current_month - 1;
                        $prev_year = $current_year;
                        if ($prev_month < 1) {
                            $prev_month = 12;
                            $prev_year--;
                        }
                        
                        // Tháng sau
                        $next_month = $current_month + 1;
                        $next_year = $current_year;
                        if ($next_month > 12) {
                            $next_month = 1;
                            $next_year++;
                        }
                        ?>
                        <a href="?month=<?php echo $prev_month; ?>&year=<?php echo $prev_year; ?>" class="month-btn">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                        <a href="?month=<?php echo date('m'); ?>&year=<?php echo date('Y'); ?>" class="month-btn <?php echo ($current_month == date('m') && $current_year == date('Y')) ? 'current-month-btn' : ''; ?>">
                            <i class="fas fa-calendar-day"></i>
                        </a>
                        <a href="?month=<?php echo $next_month; ?>&year=<?php echo $next_year; ?>" class="month-btn">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </div>
                </div>

                <!-- Habit Table -->
                <div class="habit-table-container">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2 class="habit-table-title">
                            <i class="fas fa-list-check"></i>
                            Thói quen hằng ngày
                        </h2>
                        <button class="add-habit-btn" data-bs-toggle="modal" data-bs-target="#addHabitModal">
                            <i class="fas fa-plus"></i>
                            Thêm thói quen
                        </button>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="habit-table">
                            <thead>
                                <tr>
                                    <th>Ngày hôm nay<br><?php echo date('d/m/Y'); ?></th>
                                    
                                    <?php 
                                    // Tính tuần của tháng
                                    $weeks = ceil(($first_day_of_week - 1 + $days_in_month) / 7);
                                    
                                    // Hiển thị header cho tuần
                                    for ($w = 1; $w <= $weeks; $w++) {
                                        echo '<th colspan="7" class="week-header">TUẦN ' . $w . '</th>';
                                    }
                                    ?>
                                    <th rowspan="2" style="background: rgba(112, 0, 255, 0.1); color: var(--primary-light); font-size: 1.1rem;">
                                        <i class="fas fa-bullseye"></i><br>
                                        MỤC TIÊU
                                    </th>
                                </tr>
                                <tr>
                                    <th></th>
                                    <?php
                                    // Duyệt qua mỗi tuần để hiển thị header cho ngày
                                    for ($w = 1; $w <= $weeks; $w++) {
                                        foreach ($weekdays as $day) {
                                            echo '<th class="habit-weekday">' . $day . '</th>';
                                        }
                                    }
                                    ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $current_day = date('j'); // Ngày hiện tại
                                
                                // Hiển thị các ngày trong tháng
                                echo '<tr>';
                                echo '<td>' . $current_day . '/' . $current_month . '</td>';
                                
                                $day_count = 1;
                                
                                // Duyệt qua mỗi tuần
                                for ($w = 1; $w <= $weeks; $w++) {
                                    // Duyệt qua mỗi ngày trong tuần
                                    for ($d = 1; $d <= 7; $d++) {
                                        // Bỏ qua những ngày trước ngày 1 của tháng
                                        if ($w == 1 && $d < $first_day_of_week) {
                                            echo '<td></td>';
                                            continue;
                                        }
                                        
                                        // Dừng nếu đã vượt quá số ngày trong tháng
                                        if ($day_count > $days_in_month) {
                                            echo '<td></td>';
                                            continue;
                                        }
                                        
                                        // Kiểm tra xem có phải ngày hiện tại không
                                        $is_current_day = ($day_count == $current_day && $current_month == date('m') && $current_year == date('Y'));
                                        $class = $is_current_day ? 'habit-current-day' : '';
                                        
                                        echo '<td class="' . $class . '">' . $day_count . '</td>';
                                        $day_count++;
                                    }
                                }
                                
                                echo '<td></td>'; // Cột mục tiêu
                                echo '</tr>';
                                
                                // Hiển thị thói quen và trạng thái hoàn thành
                                foreach ($habits as $habit) {
                                    echo '<tr class="habit-row" data-habit-id="' . $habit['id'] . '">';
                                    
                                    // Tên thói quen
                                    echo '<td>';
                                    echo '<div class="habit-name">';
                                    echo '<div class="habit-icon" style="background: ' . $habit['color'] . '20; color: ' . $habit['color'] . '">';
                                    echo $habit['icon'] ? '<i class="' . $habit['icon'] . '"></i>' : '';
                                    echo '</div>';
                                    echo '<div class="habit-details">';
                                    echo '<div class="habit-title">' . $habit['name'] . '</div>';
                                    echo '<div class="habit-meta">';
                                    if ($habit['category_name']) {
                                        echo '<span class="habit-category" style="background: ' . $habit['category_color'] . '20; color: ' . $habit['category_color'] . '">' . $habit['category_name'] . '</span>';
                                    }
                                    echo '</div>';
                                    echo '</div>';
                                    
                                    // Action buttons
                                    echo '<div class="habit-actions">';
                                    echo '<div class="habit-action edit" data-bs-toggle="modal" data-bs-target="#editHabitModal" data-id="' . $habit['id'] . '" data-name="' . htmlspecialchars($habit['name']) . '" data-description="' . htmlspecialchars($habit['description']) . '" data-target="' . $habit['target_days'] . '" data-color="' . $habit['color'] . '" data-icon="' . $habit['icon'] . '" data-category="' . $habit['category_id'] . '">';
                                    echo '<i class="fas fa-edit"></i>';
                                    echo '</div>';
                                    echo '<div class="habit-action delete" data-id="' . $habit['id'] . '">';
                                    echo '<i class="fas fa-trash-alt"></i>';
                                    echo '</div>';
                                    echo '</div>';
                                    
                                    echo '</div>';
                                    echo '</td>';
                                    
                                    // Duyệt qua mỗi ngày trong tháng
                                    $day_count = 1;
                                    
                                    // Duyệt qua mỗi tuần
                                    for ($w = 1; $w <= $weeks; $w++) {
                                        // Duyệt qua mỗi ngày trong tuần
                                        for ($d = 1; $d <= 7; $d++) {
                                            // Thêm class week-separator cho ngày đầu tiên của mỗi tuần (trừ tuần đầu tiên)
                                            $separator_class = ($w > 1 && $d == 1) ? 'week-separator' : '';
                                            
                                            // Bỏ qua những ngày trước ngày 1 của tháng
                                            if ($w == 1 && $d < $first_day_of_week) {
                                                echo '<td class="' . $separator_class . '"></td>';
                                                continue;
                                            }
                                            
                                            // Dừng nếu đã vượt quá số ngày trong tháng
                                            if ($day_count > $days_in_month) {
                                                echo '<td class="' . $separator_class . '"></td>';
                                                continue;
                                            }
                                            
                                            // Kiểm tra xem thói quen có được hoàn thành vào ngày này không
                                            $completed = isset($habit_logs[$habit['id']][$day_count]) ? 1 : 0;
                                            
                                            // Tạo checkbox cho ngày 
                                            $date_str = $current_year . '-' . str_pad($current_month, 2, '0', STR_PAD_LEFT) . '-' . str_pad($day_count, 2, '0', STR_PAD_LEFT);
                                            echo '<td class="habit-cell ' . $separator_class . '">';
                                            echo '<input type="checkbox" class="habit-checkbox" data-habit-id="' . $habit['id'] . '" data-date="' . $date_str . '" ' . ($completed ? 'checked' : '') . '>';
                                            echo '</td>';
                                            
                                            $day_count++;
                                        }
                                    }
                                    
                                    // Hiển thị số ngày hoàn thành / mục tiêu
                                    $completion_count = isset($habit['completed_days']) ? $habit['completed_days'] : 0;
                                    $target_days = (int)$habit['target_days'];
                                    $percentage = $target_days > 0 ? ($completion_count / $target_days) * 100 : 0;
                                    
                                    // Xác định loại badge
                                    $badge_class = 'badge-danger';
                                    $status_text = 'Chưa đạt';
                                    
                                    if ($percentage >= 100) {
                                        $badge_class = 'badge-success';
                                        $status_text = 'Đã đạt';
                                    } elseif ($percentage >= 70) {
                                        $badge_class = 'badge-warning';
                                        $status_text = 'Gần đạt';
                                    }
                                    
                                    echo '<td class="habit-complete-count">';
                                    echo '<div class="habit-complete-text">';
                                    echo '<span>' . $completion_count . ' / ' . $target_days . '</span>';
                                    echo '<span class="habit-complete-badge ' . $badge_class . '">' . $status_text . '</span>';
                                    echo '</div>';
                                    echo '<div class="habit-progress-bar">';
                                    echo '<div class="habit-progress-fill" style="width: ' . min(100, $percentage) . '%"></div>';
                                    echo '</div>';
                                    echo '</td>';
                                    echo '</tr>';
                                }
                                
                                // Hiển thị tổng
                                echo '<tr class="totals-row">';
                                echo '<td>HOÀN THÀNH<br>'. $total_completed . ' / ' . $total_target . '</td>';
                                
                                // Ô trống cho các ngày
                                $total_cells = $weeks * 7;
                                for ($i = 0; $i < $total_cells; $i++) {
                                    echo '<td></td>';
                                }
                                
                                // Tính tỷ lệ phần trăm
                                $total_percentage = $total_target > 0 ? ($total_completed / $total_target) * 100 : 0;
                                $total_badge_class = 'badge-danger';
                                $total_status_text = 'Chưa đạt';
                                
                                if ($total_percentage >= 100) {
                                    $total_badge_class = 'badge-success';
                                    $total_status_text = 'Đã đạt';
                                } elseif ($total_percentage >= 70) {
                                    $total_badge_class = 'badge-warning';
                                    $total_status_text = 'Gần đạt';
                                }
                                
                                echo '<td class="habit-complete-count">';
                                echo '<div class="habit-complete-text">';
                                echo '<span>' . $total_completed . ' / ' . $total_target . '</span>';
                                echo '<span class="habit-complete-badge ' . $total_badge_class . '">' . $total_status_text . '</span>';
                                echo '</div>';
                                echo '<div class="habit-progress-bar">';
                                echo '<div class="habit-progress-fill" style="width: ' . min(100, $total_percentage) . '%"></div>';
                                echo '</div>';
                                echo '</td>';
                                echo '</tr>';
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4">
                <!-- Progress Circle -->
                <div class="progress-circle-container">
                    <h2 class="progress-circle-title">Cơ cấu hoàn thành</h2>
                    <div class="progress-circle">
                        <svg width="150" height="150" viewBox="0 0 150 150">
                            <circle class="progress-circle-bg" cx="75" cy="75" r="70"></circle>
                            <circle class="progress-circle-progress" cx="75" cy="75" r="70" id="progressCircle"></circle>
                        </svg>
                        <div class="progress-circle-text">
                            <span id="progressPercent">0</span>%
                        </div>
                    </div>
                    <div class="mt-3">
                        <span id="progressText">0 / 20</span>
                    </div>
                </div>
                
                <!-- Statistics -->
                <div class="stats-container">
                    <h2 class="stats-title">
                        <i class="fas fa-chart-line"></i>
                        Tiến độ hoàn thành
                    </h2>
                    
                    <div class="stat-cards">
                        <div class="stat-card">
                            <div class="stat-card-title">
                                <i class="fas fa-calendar-check"></i>
                                Hoàn thành hôm nay
                            </div>
                            <div class="stat-card-value">
                                <?php
                                // Tính số thói quen hoàn thành hôm nay
                                $today = date('Y-m-d');
                                $today_completed = 0;
                                $today_total = count($habits);
                                
                                foreach ($habits as $habit) {
                                    if (isset($habit_logs[$habit['id']][date('j')])) {
                                        $today_completed++;
                                    }
                                }
                                
                                echo $today_completed . '/' . $today_total;
                                
                                $today_percent = $today_total > 0 ? ($today_completed / $today_total) * 100 : 0;
                                ?>
                            </div>
                            <div class="stat-progress">
                                <div class="stat-progress-bar" style="width: <?php echo $today_percent; ?>%"></div>
                            </div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-card-title">
                                <i class="fas fa-calendar-week"></i>
                                Hoàn thành tuần này
                            </div>
                            <div class="stat-card-value">
                                <?php
                                // Tính số thói quen hoàn thành trong tuần
                                $week_start = date('Y-m-d', strtotime('monday this week'));
                                $week_end = date('Y-m-d', strtotime('sunday this week'));
                                
                                $week_completed_sql = "SELECT COUNT(*) as count FROM habit_logs hl 
                                                    JOIN habits h ON hl.habit_id = h.id 
                                                    WHERE hl.user_id = ? AND h.user_id = ? 
                                                    AND hl.date BETWEEN ? AND ?";
                                $week_stmt = mysqli_prepare($conn, $week_completed_sql);
                                mysqli_stmt_bind_param($week_stmt, "iiss", $user_id, $user_id, $week_start, $week_end);
                                mysqli_stmt_execute($week_stmt);
                                $week_result = mysqli_stmt_get_result($week_stmt);
                                $week_completed = mysqli_fetch_assoc($week_result)['count'];
                                
                                $week_total = count($habits) * 7; // Tổng số thói quen * 7 ngày
                                
                                echo $week_completed . '/' . $week_total;
                                
                                $week_percent = $week_total > 0 ? ($week_completed / $week_total) * 100 : 0;
                                ?>
                            </div>
                            <div class="stat-progress">
                                <div class="stat-progress-bar" style="width: <?php echo $week_percent; ?>%"></div>
                            </div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-card-title">
                                <i class="fas fa-calendar-alt"></i>
                                Hoàn thành tháng này
                            </div>
                            <div class="stat-card-value">
                                <?php
                                echo $total_completed . '/' . $total_target;
                                
                                $month_percent = $total_target > 0 ? ($total_completed / $total_target) * 100 : 0;
                                ?>
                            </div>
                            <div class="stat-progress">
                                <div class="stat-progress-bar" style="width: <?php echo $month_percent; ?>%"></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Top Habits -->
                    <div class="top-habits">
                        <h3 class="stats-title">
                            <i class="fas fa-trophy"></i>
                            TOP thói quen
                        </h3>
                        
                        <?php
                        if (count($top_habits) > 0) {
                            $rank = 1;
                            foreach ($top_habits as $habit) {
                                echo '<div class="top-habit-item">';
                                echo '<div class="top-habit-rank">' . $rank . '</div>';
                                echo '<div class="top-habit-name">' . $habit['name'] . '</div>';
                                echo '<div class="top-habit-count">' . $habit['completion_count'] . '</div>';
                                echo '</div>';
                                $rank++;
                            }
                        } else {
                            echo '<div class="text-center text-muted p-3">Chưa có dữ liệu</div>';
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Habit Modal -->
    <div class="modal fade" id="addHabitModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus-circle"></i>
                        Thêm thói quen mới
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="addHabitForm">
                        <input type="hidden" name="action" value="add_habit">
                        
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-check-circle"></i>
                                Tên thói quen
                            </label>
                            <input type="text" class="form-control" name="name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-align-left"></i>
                                Mô tả (tùy chọn)
                            </label>
                            <textarea class="form-control" name="description" rows="2"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-bullseye"></i>
                                Mục tiêu (số ngày/tháng)
                            </label>
                            <input type="number" class="form-control" name="target_days" min="1" max="31" value="20">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-tags"></i>
                                Danh mục
                            </label>
                            <select class="form-select" name="category_id">
                                <option value="">-- Không có danh mục --</option>
                                <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>"><?php echo $category['name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-palette"></i>
                                Màu sắc
                            </label>
                            <input type="color" class="form-control form-control-color" name="color" value="#7000FF">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-icons"></i>
                                Icon
                            </label>
                            <div class="input-group">
                                <input type="text" class="form-control" name="icon" id="selectedIcon" placeholder="fas fa-check" readonly>
                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#iconPickerModal">
                                    <i class="fas fa-icons"></i> Chọn Icon
                                </button>
                            </div>
                            <div id="iconPreview" class="mt-2 text-center" style="font-size: 2rem;"></div>
                        </div>
                        
                        <button type="submit" class="add-habit-btn w-100">
                            <i class="fas fa-plus"></i>
                            Thêm thói quen
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Habit Modal -->
    <div class="modal fade" id="editHabitModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit"></i>
                        Chỉnh sửa thói quen
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="editHabitForm">
                        <input type="hidden" name="action" value="edit_habit">
                        <input type="hidden" name="habit_id" id="editHabitId">
                        
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-check-circle"></i>
                                Tên thói quen
                            </label>
                            <input type="text" class="form-control" name="name" id="editHabitName" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-align-left"></i>
                                Mô tả (tùy chọn)
                            </label>
                            <textarea class="form-control" name="description" id="editHabitDescription" rows="2"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-bullseye"></i>
                                Mục tiêu (số ngày/tháng)
                            </label>
                            <input type="number" class="form-control" name="target_days" id="editHabitTarget" min="1" max="31">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-tags"></i>
                                Danh mục
                            </label>
                            <select class="form-select" name="category_id" id="editHabitCategory">
                                <option value="">-- Không có danh mục --</option>
                                <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>"><?php echo $category['name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-palette"></i>
                                Màu sắc
                            </label>
                            <input type="color" class="form-control form-control-color" name="color" id="editHabitColor">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-icons"></i>
                                Icon
                            </label>
                            <div class="input-group">
                                <input type="text" class="form-control" name="icon" id="editSelectedIcon" readonly>
                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#editIconPickerModal">
                                    <i class="fas fa-icons"></i> Chọn Icon
                                </button>
                            </div>
                            <div id="editIconPreview" class="mt-2 text-center" style="font-size: 2rem;"></div>
                        </div>
                        
                        <button type="submit" class="add-habit-btn w-100">
                            <i class="fas fa-save"></i>
                            Lưu thay đổi
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Icon Picker Modal -->
    <div class="modal fade" id="iconPickerModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-icons"></i>
                        Chọn Icon
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <input type="text" class="form-control" id="iconSearch" placeholder="Tìm kiếm icon...">
                    </div>
                    <div class="icon-grid" id="iconGrid">
                        <!-- Icons will be populated here -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Icon Picker Modal -->
    <div class="modal fade" id="editIconPickerModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-icons"></i>
                        Chọn Icon
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <input type="text" class="form-control" id="editIconSearch" placeholder="Tìm kiếm icon...">
                    </div>
                    <div class="icon-grid" id="editIconGrid">
                        <!-- Icons will be populated here -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Category Modal -->
    <div class="modal fade" id="addCategoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-folder-plus"></i>
                        Thêm danh mục mới
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="addCategoryForm">
                        <input type="hidden" name="action" value="add_category">
                        
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-tag"></i>
                                Tên danh mục
                            </label>
                            <input type="text" class="form-control" name="name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-palette"></i>
                                Màu sắc
                            </label>
                            <input type="color" class="form-control form-control-color" name="color" value="#7000FF">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-icons"></i>
                                Icon
                            </label>
                            <div class="input-group">
                                <input type="text" class="form-control" name="icon" id="categorySelectedIcon" readonly>
                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#categoryIconPickerModal">
                                    <i class="fas fa-icons"></i> Chọn Icon
                                </button>
                            </div>
                            <div id="categoryIconPreview" class="mt-2 text-center" style="font-size: 2rem;"></div>
                        </div>
                        
                        <button type="submit" class="add-habit-btn w-100">
                            <i class="fas fa-plus"></i>
                            Thêm danh mục
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Category Icon Picker Modal -->
    <div class="modal fade" id="categoryIconPickerModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-icons"></i>
                        Chọn Icon
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <input type="text" class="form-control" id="categoryIconSearch" placeholder="Tìm kiếm icon...">
                    </div>
                    <div class="icon-grid" id="categoryIconGrid">
                        <!-- Icons will be populated here -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Hiển thị thông báo
            <?php if (isset($success_message)): ?>
                showAlert('<?php echo $success_message; ?>', 'success');
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                showAlert('<?php echo $error_message; ?>', 'error');
            <?php endif; ?>
            
            // Cập nhật vòng tròn tiến độ
            updateProgressCircle(<?php echo $total_completed; ?>, <?php echo $total_target; ?>);
            
            // Xử lý checkbox đánh dấu hoàn thành
            document.querySelectorAll('.habit-checkbox').forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    const habitId = this.getAttribute('data-habit-id');
                    const date = this.getAttribute('data-date');
                    const completed = this.checked ? 1 : 0;
                    
                    // Thêm hiệu ứng loading cho checkbox
                    this.disabled = true;
                    this.style.opacity = '0.5';
                    
                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', window.location.href, true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    // Xử lý JSON response
                    xhr.onload = function() {
                        if (xhr.status === 200) {
                            try {
                                const response = JSON.parse(xhr.responseText);
                                if (response.success) {
                                    showAlert('Đã cập nhật trạng thái!', 'success');
                                    
                                    // Cập nhật UI mà không cần tải lại trang
                                    updateHabitCompletion(habitId, completed, date);
                                } else {
                                    showAlert('Lỗi khi cập nhật: ' + response.error, 'error');
                                    // Khôi phục trạng thái checkbox
                                    checkbox.checked = !completed;
                                }
                            } catch (e) {
                                console.error("Error parsing response:", e, "Response:", xhr.responseText);
                                showAlert('Lỗi hệ thống, vui lòng thử lại!', 'error');
                                // Khôi phục trạng thái checkbox
                                checkbox.checked = !completed;
                            }
                            
                            // Xóa hiệu ứng loading
                            checkbox.disabled = false;
                            checkbox.style.opacity = '1';
                        }
                    };
                    xhr.send(`action=toggle_habit&habit_id=${habitId}&date=${date}&completed=${completed}`);
                });
            });
            
            // Xử lý xóa thói quen
            document.querySelectorAll('.habit-action.delete').forEach(btn => {
                btn.addEventListener('click', function() {
                    if (confirm('Bạn có chắc muốn xóa thói quen này? Tất cả dữ liệu liên quan cũng sẽ bị xóa.')) {
                        const habitId = this.getAttribute('data-id');
                        const habitRow = this.closest('.habit-row');
                        
                        // Thêm hiệu ứng loading
                        habitRow.style.opacity = '0.5';
                        
                        const xhr = new XMLHttpRequest();
                        xhr.open('POST', window.location.href, true);
                        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                        xhr.onload = function() {
                            if (xhr.status === 200) {
                                try {
                                    const response = JSON.parse(xhr.responseText);
                                    if (response.success) {
                                        showAlert('Đã xóa thói quen!', 'success');
                                        
                                        // Xóa hàng khỏi bảng mà không cần tải lại trang
                                        habitRow.style.height = habitRow.offsetHeight + 'px';
                                        habitRow.style.transition = 'all 0.3s ease';
                                        setTimeout(() => {
                                            habitRow.style.height = '0';
                                            habitRow.style.opacity = '0';
                                            habitRow.style.overflow = 'hidden';
                                            setTimeout(() => {
                                                habitRow.remove();
                                                updateTotalProgress();
                                            }, 300);
                                        }, 100);
                                    } else {
                                        showAlert('Lỗi khi xóa: ' + response.error, 'error');
                                        habitRow.style.opacity = '1';
                                    }
                                } catch (e) {
                                    console.error(e);
                                    showAlert('Lỗi hệ thống, vui lòng thử lại!', 'error');
                                    habitRow.style.opacity = '1';
                                }
                            }
                        };
                        xhr.send(`action=delete_habit&habit_id=${habitId}`);
                    }
                });
            });
            
            // Xử lý chỉnh sửa thói quen
            document.querySelectorAll('.habit-action.edit').forEach(btn => {
                btn.addEventListener('click', function() {
                    const habitId = this.getAttribute('data-id');
                    const name = this.getAttribute('data-name');
                    const description = this.getAttribute('data-description');
                    const target = this.getAttribute('data-target');
                    const color = this.getAttribute('data-color');
                    const icon = this.getAttribute('data-icon');
                    const category = this.getAttribute('data-category');
                    
                    document.getElementById('editHabitId').value = habitId;
                    document.getElementById('editHabitName').value = name;
                    document.getElementById('editHabitDescription').value = description;
                    document.getElementById('editHabitTarget').value = target;
                    document.getElementById('editHabitColor').value = color;
                    document.getElementById('editSelectedIcon').value = icon;
                    
                    if (category) {
                        document.getElementById('editHabitCategory').value = category;
                    } else {
                        document.getElementById('editHabitCategory').value = '';
                    }
                    
                    if (icon) {
                        document.getElementById('editIconPreview').innerHTML = `<i class="${icon}"></i>`;
                    } else {
                        document.getElementById('editIconPreview').innerHTML = '';
                    }
                });
            });
            
            // Xử lý icon picker
            initIconPicker();
            
            // Form submissions with AJAX
            document.getElementById('addHabitForm').addEventListener('submit', function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                const submitButton = this.querySelector('button[type="submit"]');
                
                // Disable button and show loading
                submitButton.disabled = true;
                submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Đang xử lý...';
                
                const xhr = new XMLHttpRequest();
                xhr.open('POST', window.location.href, true);
                xhr.onload = function() {
                    if (xhr.status === 200) {
                        // Re-enable button
                        submitButton.disabled = false;
                        submitButton.innerHTML = '<i class="fas fa-plus"></i> Thêm thói quen';
                        
                        // Check if response contains success message
                        if (xhr.responseText.includes('Đã thêm thói quen mới')) {
                            showAlert('Đã thêm thói quen mới!', 'success');
                            
                            // Clear form
                            document.getElementById('addHabitForm').reset();
                            document.getElementById('iconPreview').innerHTML = '';
                            
                            // Close modal
                            const modal = bootstrap.Modal.getInstance(document.getElementById('addHabitModal'));
                            modal.hide();
                            
                            // Reload data without full page refresh
                            setTimeout(() => {
                                location.reload(); // Temporary solution, ideally we'd update the UI without reload
                            }, 500);
                        } else {
                            showAlert('Đã có lỗi xảy ra. Vui lòng thử lại.', 'error');
                        }
                    }
                };
                xhr.send(formData);
            });
            
            document.getElementById('editHabitForm').addEventListener('submit', function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                const submitButton = this.querySelector('button[type="submit"]');
                
                // Disable button and show loading
                submitButton.disabled = true;
                submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Đang xử lý...';
                
                const xhr = new XMLHttpRequest();
                xhr.open('POST', window.location.href, true);
                xhr.onload = function() {
                    if (xhr.status === 200) {
                        // Re-enable button
                        submitButton.disabled = false;
                        submitButton.innerHTML = '<i class="fas fa-save"></i> Lưu thay đổi';
                        
                        // Check if response contains success message
                        if (xhr.responseText.includes('Đã cập nhật thói quen')) {
                            showAlert('Đã cập nhật thói quen!', 'success');
                            
                            // Close modal
                            const modal = bootstrap.Modal.getInstance(document.getElementById('editHabitModal'));
                            modal.hide();
                            
                            // Reload data without full page refresh
                            setTimeout(() => {
                                location.reload(); // Temporary solution, ideally we'd update the UI without reload
                            }, 500);
                        } else {
                            showAlert('Đã có lỗi xảy ra. Vui lòng thử lại.', 'error');
                        }
                    }
                };
                xhr.send(formData);
            });
            
            document.getElementById('addCategoryForm').addEventListener('submit', function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                const submitButton = this.querySelector('button[type="submit"]');
                
                // Disable button and show loading
                submitButton.disabled = true;
                submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Đang xử lý...';
                
                const xhr = new XMLHttpRequest();
                xhr.open('POST', window.location.href, true);
                xhr.onload = function() {
                    if (xhr.status === 200) {
                        // Re-enable button
                        submitButton.disabled = false;
                        submitButton.innerHTML = '<i class="fas fa-plus"></i> Thêm danh mục';
                        
                        // Check if response contains success message
                        if (xhr.responseText.includes('Đã thêm danh mục mới')) {
                            showAlert('Đã thêm danh mục mới!', 'success');
                            
                            // Clear form
                            document.getElementById('addCategoryForm').reset();
                            document.getElementById('categoryIconPreview').innerHTML = '';
                            
                            // Close modal
                            const modal = bootstrap.Modal.getInstance(document.getElementById('addCategoryModal'));
                            modal.hide();
                            
                            // Reload data without full page refresh
                            setTimeout(() => {
                                location.reload(); // Temporary solution, ideally we'd update the UI without reload
                            }, 500);
                        } else {
                            showAlert('Đã có lỗi xảy ra. Vui lòng thử lại.', 'error');
                        }
                    }
                };
                xhr.send(formData);
            });
        });
        
        // Hàm cập nhật UI khi đánh dấu hoàn thành thói quen
        function updateHabitCompletion(habitId, completed, date) {
            // Tìm hàng thói quen theo habitId
            const habitRow = document.querySelector(`.habit-row[data-habit-id="${habitId}"]`);
            if (!habitRow) return;
            
            // Cập nhật số ngày hoàn thành và thanh tiến độ
            const countCell = habitRow.querySelector('.habit-complete-count');
            if (countCell) {
                const countText = countCell.querySelector('.habit-complete-text span:first-child');
                if (countText) {
                    const [current, total] = countText.textContent.split('/').map(n => parseInt(n.trim()));
                    const newCount = completed ? current + 1 : current - 1;
                    countText.textContent = `${newCount} / ${total}`;
                    
                    // Cập nhật thanh tiến độ
                    const progressBar = countCell.querySelector('.habit-progress-fill');
                    if (progressBar) {
                        const newPercentage = (newCount / total) * 100;
                        progressBar.style.width = `${Math.min(100, newPercentage)}%`;
                    }
                    
                    // Cập nhật badge
                    const badge = countCell.querySelector('.habit-complete-badge');
                    if (badge) {
                        let badgeClass = 'badge-danger';
                        let statusText = 'Chưa đạt';
                        
                        const newPercentage = (newCount / total) * 100;
                        
                        if (newPercentage >= 100) {
                            badgeClass = 'badge-success';
                            statusText = 'Đã đạt';
                        } else if (newPercentage >= 70) {
                            badgeClass = 'badge-warning';
                            statusText = 'Gần đạt';
                        }
                        
                        badge.className = `habit-complete-badge ${badgeClass}`;
                        badge.textContent = statusText;
                    }
                }
            }
            
            // Cập nhật tổng tiến độ
            updateTotalProgress();
            
            // Cập nhật các biểu đồ
            updateCharts();
        }
        
        // Hàm cập nhật các biểu đồ
        function updateCharts() {
            // Tính toán dữ liệu mới cho biểu đồ
            updateWeeklyChart();
            updateMonthlyChart();
            updateTimePeriodsChart();
            updateTrendChart();
        }
        
        // Cập nhật biểu đồ hàng tuần
        function updateWeeklyChart() {
            // Lấy tất cả thói quen và số lượng hoàn thành của chúng
            const habitData = [];
            const habitColors = [];
            
            document.querySelectorAll('.habit-row').forEach(row => {
                if (!row.classList.contains('totals-row')) {
                    const habitName = row.querySelector('.habit-title').textContent;
                    const countCell = row.querySelector('.habit-complete-count');
                    const completed = parseInt(countCell.querySelector('.habit-complete-text span:first-child').textContent.split('/')[0].trim());
                    const color = row.querySelector('.habit-icon').style.color;
                    
                    habitData.push({ name: habitName, count: completed });
                    habitColors.push(color);
                }
            });
            
            // Tìm biểu đồ hiện tại
            const weeklyChartCanvas = document.getElementById('weeklyChartCanvas');
            if (!weeklyChartCanvas) return;
            
            // Nếu đã có biểu đồ, hủy nó và tạo mới
            if (weeklyChartCanvas.chart) {
                weeklyChartCanvas.chart.destroy();
            }
            
            // Tạo biểu đồ mới với dữ liệu cập nhật
            const ctx = weeklyChartCanvas.getContext('2d');
            
            const data = {
                labels: habitData.map(h => h.name),
                datasets: [{
                    data: habitData.map(h => h.count),
                    backgroundColor: habitColors,
                    borderWidth: 0,
                    hoverOffset: 15
                }]
            };
            
            const config = {
                type: 'doughnut',
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
                            text: 'Cảm xúc trong tuần này',
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
                    cutout: '60%',
                    animation: {
                        animateScale: true,
                        animateRotate: true
                    }
                }
            };
            
            weeklyChartCanvas.chart = new Chart(ctx, config);
        }
        
        // Cập nhật biểu đồ hàng tháng
        function updateMonthlyChart() {
            // Tương tự như updateWeeklyChart
            const habitData = [];
            const habitColors = [];
            
            document.querySelectorAll('.habit-row').forEach(row => {
                if (!row.classList.contains('totals-row')) {
                    const habitName = row.querySelector('.habit-title').textContent;
                    const countCell = row.querySelector('.habit-complete-count');
                    const completed = parseInt(countCell.querySelector('.habit-complete-text span:first-child').textContent.split('/')[0].trim());
                    const color = row.querySelector('.habit-icon').style.color;
                    
                    habitData.push({ name: habitName, count: completed });
                    habitColors.push(color);
                }
            });
            
            const monthlyChartCanvas = document.getElementById('monthlyChartCanvas');
            if (!monthlyChartCanvas) return;
            
            if (monthlyChartCanvas.chart) {
                monthlyChartCanvas.chart.destroy();
            }
            
            const ctx = monthlyChartCanvas.getContext('2d');
            
            const data = {
                labels: habitData.map(h => h.name),
                datasets: [{
                    data: habitData.map(h => h.count),
                    backgroundColor: habitColors,
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
            
            monthlyChartCanvas.chart = new Chart(ctx, config);
        }
        
        // Cập nhật biểu đồ thời gian
        function updateTimePeriodsChart() {
            // Giả lập dữ liệu thời gian (trong thực tế, bạn sẽ lấy từ cơ sở dữ liệu)
            const timePeriodsChartCanvas = document.getElementById('timePeriodsChartCanvas');
            if (!timePeriodsChartCanvas) return;
            
            // Đối với biểu đồ này, chúng ta chỉ làm mới khi tải lại trang
            // vì dữ liệu phân theo thời điểm trong ngày yêu cầu truy vấn cơ sở dữ liệu
        }
        
        // Cập nhật biểu đồ xu hướng
        function updateTrendChart() {
            // Giả lập dữ liệu xu hướng (trong thực tế, bạn sẽ lấy từ cơ sở dữ liệu)
            const trendChartCanvas = document.getElementById('trendChartCanvas');
            if (!trendChartCanvas) return;
            
            // Đối với biểu đồ này, chúng ta chỉ làm mới khi tải lại trang
            // vì dữ liệu xu hướng yêu cầu truy vấn cơ sở dữ liệu
        }
        
        // Hàm cập nhật tổng tiến độ
        function updateTotalProgress() {
            // Tính tổng số hoàn thành và mục tiêu
            let totalCompleted = 0;
            let totalTarget = 0;
            
            document.querySelectorAll('.habit-complete-count').forEach(cell => {
                if (!cell.closest('.totals-row')) { // Bỏ qua hàng tổng
                    const countText = cell.querySelector('.habit-complete-text span:first-child');
                    if (countText) {
                        const [completed, target] = countText.textContent.split('/').map(n => parseInt(n.trim()));
                        totalCompleted += completed;
                        totalTarget += target;
                    }
                }
            });
            
            // Cập nhật hàng tổng
            const totalsRow = document.querySelector('.totals-row');
            if (totalsRow) {
                const totalCell = totalsRow.querySelector('.habit-complete-count');
                if (totalCell) {
                    const countText = totalCell.querySelector('.habit-complete-text span:first-child');
                    if (countText) {
                        countText.textContent = `${totalCompleted} / ${totalTarget}`;
                        
                        // Cập nhật thanh tiến độ
                        const progressBar = totalCell.querySelector('.habit-progress-fill');
                        if (progressBar) {
                            const totalPercentage = totalTarget > 0 ? (totalCompleted / totalTarget) * 100 : 0;
                            progressBar.style.width = `${Math.min(100, totalPercentage)}%`;
                        }
                        
                        // Cập nhật badge
                        const badge = totalCell.querySelector('.habit-complete-badge');
                        if (badge) {
                            let badgeClass = 'badge-danger';
                            let statusText = 'Chưa đạt';
                            
                            const totalPercentage = totalTarget > 0 ? (totalCompleted / totalTarget) * 100 : 0;
                            
                            if (totalPercentage >= 100) {
                                badgeClass = 'badge-success';
                                statusText = 'Đã đạt';
                            } else if (totalPercentage >= 70) {
                                badgeClass = 'badge-warning';
                                statusText = 'Gần đạt';
                            }
                            
                            badge.className = `habit-complete-badge ${badgeClass}`;
                            badge.textContent = statusText;
                        }
                    }
                }
            }
            
            // Cập nhật vòng tròn tiến độ
            updateProgressCircle(totalCompleted, totalTarget);
        }
        
        // Hàm cập nhật vòng tròn tiến độ
        function updateProgressCircle(completed, total) {
            const circle = document.getElementById('progressCircle');
            const percentText = document.getElementById('progressPercent');
            const progressText = document.getElementById('progressText');
            
            const percent = total > 0 ? Math.round((completed / total) * 100) : 0;
            const circumference = 440; // 2 * PI * r, where r = 70
            
            const offset = circumference - (percent / 100) * circumference;
            circle.style.strokeDashoffset = offset;
            
            // Gradient color based on percentage
            if (percent < 30) {
                circle.style.stroke = '#FF3D57'; // Red
            } else if (percent < 70) {
                circle.style.stroke = '#FFAB3D'; // Orange
            } else {
                circle.style.stroke = '#4ADE80'; // Green
            }
            
            // Animation for percentage
            let currentPercent = 0;
            const duration = 1500; // 1.5 seconds
            const increment = percent / (duration / 16); // 60fps
            
            const animation = setInterval(() => {
                currentPercent += increment;
                
                if (currentPercent >= percent) {
                    currentPercent = percent;
                    clearInterval(animation);
                }
                
                percentText.textContent = Math.round(currentPercent);
                progressText.textContent = `${completed} / ${total}`;
            }, 16);
        }
        
        // Hàm hiển thị thông báo
        function showAlert(message, type = 'success') {
            const alertContainer = document.getElementById('alertContainer');
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert-message alert-${type}`;
            alertDiv.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
                ${message}
            `;
            alertContainer.appendChild(alertDiv);
            
            setTimeout(() => {
                alertDiv.style.opacity = '0';
                setTimeout(() => {
                    alertDiv.remove();
                }, 300);
            }, 3000);
        }
        
        // Khởi tạo icon picker
        function initIconPicker() {
            const commonIcons = [
                'fas fa-check', 'fas fa-check-circle', 'fas fa-check-square', 'fas fa-tasks',
                'fas fa-list', 'fas fa-clipboard-list', 'fas fa-clipboard-check', 'fas fa-star',
                'fas fa-award', 'fas fa-medal', 'fas fa-trophy', 'fas fa-crown',
                'fas fa-heart', 'fas fa-smile', 'fas fa-thumbs-up', 'fas fa-fire',
                'fas fa-book', 'fas fa-graduation-cap', 'fas fa-brain', 'fas fa-lightbulb',
                'fas fa-running', 'fas fa-walking', 'fas fa-biking', 'fas fa-hiking',
                'fas fa-swimmer', 'fas fa-dumbbell', 'fas fa-weight', 'fas fa-apple-alt',
                'fas fa-carrot', 'fas fa-seedling', 'fas fa-leaf', 'fas fa-tree',
                'fas fa-water', 'fas fa-shower', 'fas fa-bed', 'fas fa-moon',
                'fas fa-sun', 'fas fa-cloud-sun', 'fas fa-cloud-moon', 'fas fa-clock',
                'fas fa-stopwatch', 'fas fa-hourglass', 'fas fa-calendar', 'fas fa-calendar-check',
                'fas fa-calendar-alt', 'fas fa-calendar-day', 'fas fa-briefcase', 'fas fa-laptop-code',
                'fas fa-code', 'fas fa-keyboard', 'fas fa-desktop', 'fas fa-mobile-alt',
                'fas fa-gamepad', 'fas fa-headphones', 'fas fa-music', 'fas fa-guitar',
                'fas fa-film', 'fas fa-tv', 'fas fa-book-reader', 'fas fa-newspaper',
                'fas fa-pencil-alt', 'fas fa-paint-brush', 'fas fa-palette', 'fas fa-camera',
                'fas fa-image', 'fas fa-comments', 'fas fa-envelope', 'fas fa-phone',
                'fas fa-users', 'fas fa-user-friends', 'fas fa-handshake', 'fas fa-child',
                'fas fa-dog', 'fas fa-cat', 'fas fa-paw', 'fas fa-feather',
                'fas fa-home', 'fas fa-broom', 'fas fa-trash', 'fas fa-recycle',
                'fas fa-pills', 'fas fa-medkit', 'fas fa-notes-medical', 'fas fa-heartbeat',
                'fas fa-car', 'fas fa-bicycle', 'fas fa-bus', 'fas fa-train',
                'fas fa-plane', 'fas fa-ship', 'fas fa-money-bill-wave', 'fas fa-piggy-bank',
                'fas fa-hand-holding-usd', 'fas fa-donate', 'fas fa-chart-line', 'fas fa-chart-pie',
                'fas fa-chart-bar', 'fas fa-chart-area', 'fas fa-gift', 'fas fa-birthday-cake'
            ];
            
            // Populate Icon Grids
            populateIconGrid('iconGrid', commonIcons, selectIcon);
            populateIconGrid('editIconGrid', commonIcons, selectEditIcon);
            populateIconGrid('categoryIconGrid', commonIcons, selectCategoryIcon);
            
            // Search functionality
            document.getElementById('iconSearch').addEventListener('input', function() {
                filterIcons(this.value, 'iconGrid', commonIcons, selectIcon);
            });
            
            document.getElementById('editIconSearch').addEventListener('input', function() {
                filterIcons(this.value, 'editIconGrid', commonIcons, selectEditIcon);
            });
            
            document.getElementById('categoryIconSearch').addEventListener('input', function() {
                filterIcons(this.value, 'categoryIconGrid', commonIcons, selectCategoryIcon);
            });
        }
        
        // Populate icon grid
        function populateIconGrid(containerId, icons, selectCallback) {
            const container = document.getElementById(containerId);
            container.innerHTML = '';
            
            icons.forEach(icon => {
                const div = document.createElement('div');
                div.className = 'icon-item';
                div.innerHTML = `<i class="${icon}"></i>`;
                div.addEventListener('click', () => selectCallback(icon));
                container.appendChild(div);
            });
        }
        
        // Filter icons based on search
        function filterIcons(search, containerId, allIcons, selectCallback) {
            const filteredIcons = allIcons.filter(icon => 
                icon.toLowerCase().includes(search.toLowerCase())
            );
            populateIconGrid(containerId, filteredIcons, selectCallback);
        }
        
        // Select icon functions
        function selectIcon(icon) {
            document.getElementById('selectedIcon').value = icon;
            document.getElementById('iconPreview').innerHTML = `<i class="${icon}"></i>`;
            const modal = bootstrap.Modal.getInstance(document.getElementById('iconPickerModal'));
            modal.hide();
        }
        
        function selectEditIcon(icon) {
            document.getElementById('editSelectedIcon').value = icon;
            document.getElementById('editIconPreview').innerHTML = `<i class="${icon}"></i>`;
            const modal = bootstrap.Modal.getInstance(document.getElementById('editIconPickerModal'));
            modal.hide();
        }
        
        function selectCategoryIcon(icon) {
            document.getElementById('categorySelectedIcon').value = icon;
            document.getElementById('categoryIconPreview').innerHTML = `<i class="${icon}"></i>`;
            const modal = bootstrap.Modal.getInstance(document.getElementById('categoryIconPickerModal'));
            modal.hide();
        }
    </script>
</body>
</html>