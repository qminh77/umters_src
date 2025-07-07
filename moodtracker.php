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

// Tạo bảng mood_categories cho các loại cảm xúc
$sql_mood_categories = "CREATE TABLE IF NOT EXISTS mood_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(50) NOT NULL,
    color VARCHAR(7) NOT NULL,
    icon VARCHAR(50) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
)";
mysqli_query($conn, $sql_mood_categories) or die("Error creating mood_categories: " . mysqli_error($conn));

// Tạo bảng mood_shares cho các chia sẻ cảm xúc
$sql_mood_shares = "CREATE TABLE IF NOT EXISTS mood_shares (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    share_token VARCHAR(64) NOT NULL,
    title VARCHAR(100) NOT NULL,
    description TEXT,
    is_public TINYINT(1) DEFAULT 1,
    expiry_date DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE KEY (share_token)
)";
mysqli_query($conn, $sql_mood_shares) or die("Error creating mood_shares: " . mysqli_error($conn));

// Tạo bảng mood_entries cho các mục nhập cảm xúc
$sql_mood_entries = "CREATE TABLE IF NOT EXISTS mood_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    mood_category_id INT NOT NULL,
    time_period ENUM('morning', 'noon', 'afternoon', 'evening', 'night') NOT NULL,
    date DATE NOT NULL,
    time TIME NOT NULL,
    notes TEXT,
    activities TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (mood_category_id) REFERENCES mood_categories(id)
)";
mysqli_query($conn, $sql_mood_entries) or die("Error creating mood_entries: " . mysqli_error($conn));

// Lấy danh sách loại cảm xúc
$emotion_types = [];
$sql = "SELECT * FROM emotion_types ORDER BY id ASC";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $emotion_types[] = $row;
    }
}

// Lấy danh sách triggers
$triggers = [];
$sql = "SELECT * FROM triggers ORDER BY id ASC";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $triggers[] = $row;
    }
}

// Lấy danh sách hoạt động
$activities = [];
$sql = "SELECT * FROM mood_activities ORDER BY id ASC";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $activities[] = $row;
    }
}

// Xử lý nhập cảm xúc
$success_message = '';
$error_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_emotion'])) {
    $emotion_type = (int)$_POST['emotion_type'];
    $intensity = (int)$_POST['intensity'];
    $date_time = $_POST['date_time'];
    $notes = trim($_POST['notes']);
    $selected_triggers = isset($_POST['triggers']) ? implode(',', $_POST['triggers']) : '';
    $selected_activities = isset($_POST['activities']) ? implode(',', $_POST['activities']) : '';
    $is_private = isset($_POST['is_private']) ? 1 : 0;

    $stmt = $conn->prepare("INSERT INTO emotions_log (user_id, emotion_type, intensity, date_time, notes, triggers, activities, is_private) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iiissssi", $_user_id, $emotion_type, $intensity, $date_time, $notes, $selected_triggers, $selected_activities, $is_private);
    if ($stmt->execute()) {
        $success_message = "Đã ghi nhận cảm xúc!";
    } else {
        $error_message = "Lỗi khi lưu cảm xúc: " . $stmt->error;
    }
}

// Lấy dữ liệu cảm xúc theo ngày trong tháng hiện tại
$calendar_data = [];
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$start_date = "$year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01 00:00:00";
$end_date = date('Y-m-t 23:59:59', strtotime($start_date));

$stmt = $conn->prepare("SELECT * FROM emotions_log WHERE user_id = ? AND date_time BETWEEN ? AND ? ORDER BY date_time ASC");
if ($stmt) {
    $stmt->bind_param("iss", $user_id, $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $day = date('j', strtotime($row['date_time']));
        if (!isset($calendar_data[$day])) $calendar_data[$day] = [];
        $calendar_data[$day][] = $row;
    }
}

function getEmotionType($id, $emotion_types) {
    foreach ($emotion_types as $e) {
        if ($e['id'] == $id) return $e;
    }
    return null;
}

// Xử lý tạo mới chia sẻ cảm xúc
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_mood_share') {
    $title = mysqli_real_escape_string($conn, $_POST['title']);
    $description = mysqli_real_escape_string($conn, $_POST['description']);
    $is_public = isset($_POST['is_public']) ? 1 : 0;
    $expiry_date = !empty($_POST['expiry_date']) ? mysqli_real_escape_string($conn, $_POST['expiry_date']) : NULL;
    
    // Tạo token ngẫu nhiên
    $share_token = bin2hex(random_bytes(16));
    
    if ($expiry_date) {
        $sql = "INSERT INTO mood_shares (user_id, share_token, title, description, is_public, expiry_date) 
                VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "isssis", $user_id, $share_token, $title, $description, $is_public, $expiry_date);
    } else {
        $sql = "INSERT INTO mood_shares (user_id, share_token, title, description, is_public) 
                VALUES (?, ?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "isssi", $user_id, $share_token, $title, $description, $is_public);
    }
    
    if (mysqli_stmt_execute($stmt)) {
        $share_id = mysqli_insert_id($conn);
        $share_url = "https://".$_SERVER['HTTP_HOST']."/shared_mood?token=".$share_token;
        echo json_encode(['success' => true, 'share_url' => $share_url, 'share_id' => $share_id]);
        exit;
    } else {
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
        exit;
    }
}

// Xử lý cập nhật chia sẻ cảm xúc
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_mood_share') {
    $share_id = (int)$_POST['share_id'];
    $title = mysqli_real_escape_string($conn, $_POST['title']);
    $description = mysqli_real_escape_string($conn, $_POST['description']);
    $is_public = isset($_POST['is_public']) ? 1 : 0;
    $expiry_date = !empty($_POST['expiry_date']) ? mysqli_real_escape_string($conn, $_POST['expiry_date']) : NULL;
    
    if ($expiry_date) {
        $sql = "UPDATE mood_shares 
                SET title = ?, description = ?, is_public = ?, expiry_date = ? 
                WHERE id = ? AND user_id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ssissi", $title, $description, $is_public, $expiry_date, $share_id, $user_id);
    } else {
        $sql = "UPDATE mood_shares 
                SET title = ?, description = ?, is_public = ?, expiry_date = NULL 
                WHERE id = ? AND user_id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ssiii", $title, $description, $is_public, $share_id, $user_id);
    }
    
    if (mysqli_stmt_execute($stmt)) {
        echo json_encode(['success' => true]);
        exit;
    } else {
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
        exit;
    }
}

// Xử lý xóa chia sẻ cảm xúc
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_mood_share') {
    $share_id = (int)$_POST['share_id'];
    
    $sql = "DELETE FROM mood_shares WHERE id = ? AND user_id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ii", $share_id, $user_id);
    
    if (mysqli_stmt_execute($stmt)) {
        echo json_encode(['success' => true]);
        exit;
    } else {
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
        exit;
    }
}

// Handle mood category and entry operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'create_mood_category') {
        $name = mysqli_real_escape_string($conn, $_POST['name']);
        $color = mysqli_real_escape_string($conn, $_POST['color']);
        $icon = mysqli_real_escape_string($conn, $_POST['icon']);
        
        $sql = "INSERT INTO mood_categories (user_id, name, color, icon) VALUES (?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "isss", $user_id, $name, $color, $icon);
        
        if (mysqli_stmt_execute($stmt)) {
            $success_message = "Đã thêm danh mục cảm xúc mới!";
        } else {
            $error_message = "Lỗi khi thêm danh mục cảm xúc: " . mysqli_error($conn);
        }
    }
    
    if ($_POST['action'] === 'edit_mood_category') {
        $category_id = (int)$_POST['category_id'];
        $name = mysqli_real_escape_string($conn, $_POST['name']);
        $color = mysqli_real_escape_string($conn, $_POST['color']);
        $icon = mysqli_real_escape_string($conn, $_POST['icon']);
        
        $sql = "UPDATE mood_categories SET name = ?, color = ?, icon = ? WHERE id = ? AND user_id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "sssii", $name, $color, $icon, $category_id, $user_id);
        
        if (mysqli_stmt_execute($stmt)) {
            $success_message = "Đã cập nhật danh mục cảm xúc!";
        } else {
            $error_message = "Lỗi khi cập nhật danh mục cảm xúc: " . mysqli_error($conn);
        }
    }
    
    if ($_POST['action'] === 'delete_mood_category') {
        $category_id = (int)$_POST['category_id'];
        
        // Check if category has associated entries
        $check_sql = "SELECT COUNT(*) as count FROM mood_entries WHERE mood_category_id = ?";
        $check_stmt = mysqli_prepare($conn, $check_sql);
        mysqli_stmt_bind_param($check_stmt, "i", $category_id);
        mysqli_stmt_execute($check_stmt);
        $count_result = mysqli_stmt_get_result($check_stmt);
        $count = mysqli_fetch_assoc($count_result)['count'];
        
        if ($count > 0) {
            echo json_encode(['success' => false, 'error' => 'Không thể xóa danh mục vì có mục nhập liên quan']);
            exit;
        }
        
        $sql = "DELETE FROM mood_categories WHERE id = ? AND user_id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ii", $category_id, $user_id);
        
        if (mysqli_stmt_execute($stmt)) {
            echo json_encode(['success' => true]);
            exit;
        } else {
            echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
            exit;
        }
    }
    
    if ($_POST['action'] === 'add_mood_entry') {
        $mood_category_id = mysqli_real_escape_string($conn, $_POST['mood_category_id']);
        $time_period = mysqli_real_escape_string($conn, $_POST['time_period']);
        $date = mysqli_real_escape_string($conn, $_POST['date']);
        $time = isset($_POST['time']) ? mysqli_real_escape_string($conn, $_POST['time']) : date('H:i:s');
        $notes = mysqli_real_escape_string($conn, $_POST['notes']);
        $activities = mysqli_real_escape_string($conn, $_POST['activities']);
        
        $sql = "INSERT INTO mood_entries (user_id, mood_category_id, time_period, date, time, notes, activities) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "iisssss", $user_id, $mood_category_id, $time_period, $date, $time, $notes, $activities);
        
        if (mysqli_stmt_execute($stmt)) {
            $success_message = "Đã thêm cảm xúc mới!";
        } else {
            $error_message = "Lỗi khi thêm cảm xúc: " . mysqli_error($conn);
        }
    }
    
    if ($_POST['action'] === 'edit_mood_entry') {
        $entry_id = (int)$_POST['entry_id'];
        $mood_category_id = mysqli_real_escape_string($conn, $_POST['mood_category_id']);
        $time_period = mysqli_real_escape_string($conn, $_POST['time_period']);
        $date = mysqli_real_escape_string($conn, $_POST['date']);
        $time = mysqli_real_escape_string($conn, $_POST['time']);
        $notes = mysqli_real_escape_string($conn, $_POST['notes']);
        $activities = mysqli_real_escape_string($conn, $_POST['activities']);
        
        $sql = "UPDATE mood_entries SET mood_category_id = ?, time_period = ?, date = ?, time = ?, notes = ?, activities = ? 
                WHERE id = ? AND user_id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "isssssii", $mood_category_id, $time_period, $date, $time, $notes, $activities, $entry_id, $user_id);
        
        if (mysqli_stmt_execute($stmt)) {
            $success_message = "Đã cập nhật cảm xúc!";
        } else {
            $error_message = "Lỗi khi cập nhật cảm xúc: " . mysqli_error($conn);
        }
    }
    
    if ($_POST['action'] === 'delete_mood_entry' && isset($_POST['entry_id'])) {
        $entry_id = (int)$_POST['entry_id'];
        
        $sql = "DELETE FROM mood_entries WHERE id = ? AND user_id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ii", $entry_id, $user_id);
        
        if (mysqli_stmt_execute($stmt)) {
            echo json_encode(['success' => true]);
            exit;
        } else {
            echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
            exit;
        }
    }
}

// Get user's mood categories
$sql = "SELECT * FROM mood_categories WHERE user_id = ?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$mood_categories_result = mysqli_stmt_get_result($stmt);
$mood_categories = [];
while ($category = mysqli_fetch_assoc($mood_categories_result)) {
    $mood_categories[] = $category;
}

// Get current month's mood entries
$current_month = date('Y-m');
$sql = "SELECT me.*, mc.name as mood_name, mc.color, mc.icon 
        FROM mood_entries me 
        JOIN mood_categories mc ON me.mood_category_id = mc.id 
        WHERE me.user_id = ? AND DATE_FORMAT(me.date, '%Y-%m') = ?
        ORDER BY me.date, me.time";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "is", $user_id, $current_month);
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
$week_start = date('Y-m-d', strtotime('monday this week'));
$week_end = date('Y-m-d', strtotime('sunday this week'));

// Weekly statistics
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
mysqli_stmt_bind_param($stmt, "iss", $user_id, $week_start, $week_end);
mysqli_stmt_execute($stmt);
$weekly_stats_result = mysqli_stmt_get_result($stmt);
$weekly_stats = [];
while ($row = mysqli_fetch_assoc($weekly_stats_result)) {
    $weekly_stats[] = $row;
}

// Monthly statistics
$month_start = date('Y-m-01');
$month_end = date('Y-m-t');

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

// Lấy danh sách các chia sẻ cảm xúc
$sql = "SELECT * FROM mood_shares WHERE user_id = ? ORDER BY created_at DESC";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$shares_result = mysqli_stmt_get_result($stmt);
$mood_shares = [];
while ($share = mysqli_fetch_assoc($shares_result)) {
    $share['share_url'] = "https://".$_SERVER['HTTP_HOST']."/shared_mood?token=".$share['share_token'];
    $mood_shares[] = $share;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Theo dõi cảm xúc</title>
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
        
        /* Mood Share Styles */
.shares-container {
    background: rgba(30, 30, 60, 0.5);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border-radius: var(--radius-lg);
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}

.shares-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
}

.shares-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--foreground);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.shares-title i {
    color: var(--primary-light);
}

.shares-list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.share-item {
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 1rem;
    transition: all 0.3s ease;
    position: relative;
}

.share-item:hover {
    background: rgba(255, 255, 255, 0.08);
    transform: translateY(-2px);
    box-shadow: var(--shadow-sm);
    border-color: var(--secondary-light);
}

.share-item-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 0.75rem;
}

.share-item-title {
    font-weight: 600;
    color: var(--foreground);
    margin-bottom: 0.25rem;
}

.share-item-date {
    font-size: 0.8rem;
    color: var(--foreground-muted);
}

.share-item-description {
    color: var(--foreground-muted);
    margin-bottom: 1rem;
    font-size: 0.9rem;
}

.share-item-actions {
    display: flex;
    gap: 0.5rem;
}

.share-item-action {
    padding: 0.5rem 1rem;
    border-radius: var(--radius);
    font-size: 0.85rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.share-url {
    background: rgba(30, 30, 60, 0.8);
    border-radius: var(--radius);
    padding: 0.75rem 1rem;
    font-size: 0.9rem;
    margin-bottom: 1rem;
    word-break: break-all;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border: 1px solid var(--border);
}

.share-url-text {
    color: var(--foreground);
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.copy-btn {
    background: var(--primary);
    color: white;
    border: none;
    border-radius: var(--radius);
    padding: 0.5rem 1rem;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 0.85rem;
    margin-left: 0.5rem;
    flex-shrink: 0;
}

.copy-btn:hover {
    background: var(--primary-light);
    transform: translateY(-2px);
    box-shadow: var(--glow);
}

.share-link {
    background: rgba(0, 224, 255, 0.1);
    color: var(--secondary);
    border: 1px solid var(--secondary-dark);
}

.share-link:hover {
    background: rgba(0, 224, 255, 0.2);
    transform: translateY(-2px);
    box-shadow: var(--glow-secondary);
}

.share-edit {
    background: rgba(112, 0, 255, 0.1);
    color: var(--primary);
    border: 1px solid var(--primary-dark);
}

.share-edit:hover {
    background: rgba(112, 0, 255, 0.2);
    transform: translateY(-2px);
    box-shadow: var(--glow);
}

.share-delete {
    background: rgba(255, 61, 87, 0.1);
    color: var(--danger);
    border: 1px solid var(--danger-dark);
}

.share-delete:hover {
    background: rgba(255, 61, 87, 0.2);
    transform: translateY(-2px);
    box-shadow: var(--glow-danger);
}

.share-status {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: var(--radius-sm);
    font-size: 0.75rem;
    font-weight: 600;
    margin-left: 0.5rem;
}

.share-status.active {
    background: rgba(74, 222, 128, 0.2);
    color: #4ADE80;
    border: 1px solid rgba(74, 222, 128, 0.3);
}

.share-status.expired {
    background: rgba(255, 61, 87, 0.1);
    color: #FF5D77;
    border: 1px solid rgba(255, 61, 87, 0.3);
}

.share-status.private {
    background: rgba(255, 171, 61, 0.1);
    color: #FFAB3D;
    border: 1px solid rgba(255, 171, 61, 0.3);
}

.qr-code-container {
    text-align: center;
    margin-top: 1rem;
    margin-bottom: 1rem;
}

.qr-code-img {
    max-width: 200px;
    background: white;
    padding: 0.75rem;
    border-radius: var(--radius);
}

.no-shares-message {
    background: rgba(255, 255, 255, 0.05);
    border-radius: var(--radius);
    padding: 1.5rem;
    text-align: center;
    color: var(--foreground-muted);
}

.no-shares-message i {
    font-size: 2rem;
    margin-bottom: 1rem;
    color: var(--primary-light);
}

/* Social Sharing Buttons */
.social-share-buttons {
    display: flex;
    gap: 0.5rem;
    margin-top: 1rem;
    flex-wrap: wrap;
}

.social-share-button {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    border-radius: var(--radius);
    color: white;
    font-size: 1.25rem;
    cursor: pointer;
    transition: all 0.3s ease;
}

.social-share-button:hover {
    transform: translateY(-3px);
    box-shadow: var(--shadow-sm);
}

.social-facebook {
    background: #1877F2;
}

.social-twitter {
    background: #1DA1F2;
}

.social-whatsapp {
    background: #25D366;
}

.social-telegram {
    background: #0088cc;
}

.social-email {
    background: #B23121;
}

.social-linkedin {
    background: #0077B5;
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

        /* Sidebar */
        .mood-sidebar {
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

        .add-mood-btn {
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

        .add-mood-btn:hover {
            background: linear-gradient(90deg, var(--primary-light), var(--primary));
            transform: translateY(-2px);
            box-shadow: var(--glow);
            color: white;
        }

        .mood-categories {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .mood-category {
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

        .mood-category:hover {
            background: rgba(255, 255, 255, 0.08);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
            border-color: var(--primary-light);
        }

        .mood-category-actions {
            display: flex;
            gap: 0.5rem;
            position: absolute;
            right: 1rem;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .mood-category:hover .mood-category-actions {
            opacity: 1;
        }

        .mood-category-action {
            padding: 0.25rem 0.5rem;
            border-radius: var(--radius-sm);
            cursor: pointer;
            color: var(--foreground-muted);
            transition: all 0.3s ease;
        }

        .mood-category-action.edit {
            background: rgba(0, 224, 255, 0.1);
        }

        .mood-category-action.edit:hover {
            background: rgba(0, 224, 255, 0.2);
            color: var(--secondary);
        }

        .mood-category-action.delete {
            background: rgba(255, 61, 87, 0.1);
        }

        .mood-category-action.delete:hover {
            background: rgba(255, 61, 87, 0.2);
            color: var(--danger);
        }

        .mood-category-icon {
            width: 40px;
            height: 40px;
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .mood-category-info {
            flex: 1;
        }

        .mood-category-name {
            font-weight: 500;
            color: var(--foreground);
            margin-bottom: 0.25rem;
        }

        .mood-category-count {
            font-size: 0.75rem;
            color: var(--foreground-muted);
        }

        /* Calendar */
        .calendar-container {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter:-Literature Review blur(20px);
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

        /* Particle background */
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

        .popover-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border);
        }

        .popover-action {
            padding: 0.5rem 1rem;
            border-radius: var(--radius);
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .popover-edit {
            background: rgba(0, 224, 255, 0.1);
            color: var(--secondary);
            border: 1px solid var(--secondary-dark);
        }

        .popover-edit:hover {
            background: rgba(0, 224, 255, 0.2);
            transform: translateY(-2px);
            box-shadow: var(--glow-secondary);
        }

        .popover-delete {
            background: rgba(255, 61, 87, 0.1);
            color: var(--danger);
            border: 1px solid var(--danger-dark);
        }

        .popover-delete:hover {
            background: rgba(255, 61, 87, 0.2);
            transform: translateY(-2px);
            box-shadow: var(--glow-danger);
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
            background: rgba(0, 224, 0, 0.1);
            border: 1px solid rgba(0, 224, 0, 0.3);
            color: #4ADE80;
        }

        .alert-error {
            background: rgba(255, 61, 87, 0.1);
            border: 1px solid rgba(255, 61, 87, 0.3);
            color: #FF5D77;
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

            .add-mood-btn {
                padding: 0.5rem 1rem;
            }

            .fc .fc-toolbar {
                flex-direction: column;
                gap: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="particles" id="particles"></div>

    <!-- Alert Messages Container -->
    <div class="alert-container" id="alertContainer"></div>

    <div class="mood-container">
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-heart"></i>
                Theo dõi cảm xúc
            </h1>
            <a href="dashboard.php" class="add-mood-btn">
                <i class="fas fa-arrow-left"></i>
                Quay lại Dashboard
            </a>
        </div>
        <div class="shares-container">
    <div class="shares-header">
        <h2 class="shares-title">
            <i class="fas fa-share-alt"></i>
            Chia sẻ cảm xúc
        </h2>
        <button class="add-mood-btn" data-bs-toggle="modal" data-bs-target="#createShareModal">
            <i class="fas fa-plus"></i>
            Tạo chia sẻ mới
        </button>
    </div>
    
    <div class="shares-list">
        <?php if (count($mood_shares) > 0): ?>
            <?php foreach ($mood_shares as $share): ?>
                <?php 
                $status_class = 'active';
                $status_text = 'Hoạt động';
                
                if ($share['is_public'] == 0) {
                    $status_class = 'private';
                    $status_text = 'Riêng tư';
                } elseif ($share['expiry_date'] && strtotime($share['expiry_date']) < time()) {
                    $status_class = 'expired';
                    $status_text = 'Hết hạn';
                }
                ?>
                <div class="share-item" data-share-id="<?php echo $share['id']; ?>">
                    <div class="share-item-header">
                        <div>
                            <div class="share-item-title">
                                <?php echo htmlspecialchars($share['title']); ?>
                                <span class="share-status <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                            </div>
                            <div class="share-item-date">
                                Tạo: <?php echo date('d/m/Y H:i', strtotime($share['created_at'])); ?>
                                <?php if ($share['expiry_date']): ?>
                                • Hết hạn: <?php echo date('d/m/Y H:i', strtotime($share['expiry_date'])); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (!empty($share['description'])): ?>
                    <div class="share-item-description">
                        <?php echo nl2br(htmlspecialchars($share['description'])); ?>
                    </div>
                    <?php endif; ?>
                    
                    <div class="share-url">
                        <div class="share-url-text"><?php echo $share['share_url']; ?></div>
                        <button class="copy-btn" data-url="<?php echo $share['share_url']; ?>">
                            <i class="fas fa-copy"></i> Sao chép
                        </button>
                    </div>
                    
                    <div class="share-item-actions">
                        <a href="<?php echo $share['share_url']; ?>" target="_blank" class="share-item-action share-link">
                            <i class="fas fa-external-link-alt"></i> Xem
                        </a>
                        <button class="share-item-action share-edit" data-bs-toggle="modal" data-bs-target="#editShareModal" data-id="<?php echo $share['id']; ?>" data-title="<?php echo htmlspecialchars($share['title']); ?>" data-description="<?php echo htmlspecialchars($share['description']); ?>" data-public="<?php echo $share['is_public']; ?>" data-expiry="<?php echo $share['expiry_date']; ?>">
                            <i class="fas fa-edit"></i> Sửa
                        </button>
                        <button class="share-item-action share-delete" data-id="<?php echo $share['id']; ?>">
                            <i class="fas fa-trash-alt"></i> Xóa
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="no-shares-message">
                <i class="fas fa-share-alt"></i>
                <p>Bạn chưa có chia sẻ nào. Hãy tạo chia sẻ đầu tiên!</p>
            </div>
        <?php endif; ?>
    </div>
</div>
        <div class="row">
            <!-- Sidebar -->
            <div class="col-lg-4 col-md-6">
                <div class="mood-sidebar">
                    <div class="sidebar-header">
                        <h2 class="sidebar-title">
                            <i class="fas fa-tags"></i>
                            Danh mục cảm xúc
                        </h2>
                        <button class="add-mood-btn" data-bs-toggle="modal" data-bs-target="#addMoodCategoryModal">
                            <i class="fas fa-plus"></i>
                            Thêm mới
                        </button>
                    </div>
                    
                    <div class="mood-categories">
                        <?php foreach ($mood_categories as $category): ?>
                        <div class="mood-category" style="border-left: 4px solid <?php echo $category['color']; ?>" data-category-id="<?php echo $category['id']; ?>">
                            <div class="mood-category-icon" style="background: <?php echo $category['color']; ?>20;">
                                <?php if ($category['icon']): ?>
                                    <i class="<?php echo $category['icon']; ?>" style="color: <?php echo $category['color']; ?>"></i>
                                <?php endif; ?>
                            </div>
                            <div class="mood-category-info">
                                <div class="mood-category-name"><?php echo $category['name']; ?></div>
                                <div class="mood-category-count">
                                    <?php
                                    $count_sql = "SELECT COUNT(*) as count FROM mood_entries WHERE mood_category_id = ?";
                                    $count_stmt = mysqli_prepare($conn, $count_sql);
                                    mysqli_stmt_bind_param($count_stmt, "i", $category['id']);
                                    mysqli_stmt_execute($count_stmt);
                                    $count_result = mysqli_stmt_get_result($count_stmt);
                                    $count = mysqli_fetch_assoc($count_result)['count'];
                                    echo $count . " mục nhập";
                                    ?>
                                </div>
                            </div>
                            <div class="mood-category-actions">
                                <i class="fas fa-edit mood-category-action edit" data-bs-toggle="modal" data-bs-target="#editMoodCategoryModal" data-id="<?php echo $category['id']; ?>" data-name="<?php echo $category['name']; ?>" data-color="<?php echo $category['color']; ?>" data-icon="<?php echo $category['icon']; ?>"></i>
                                <i class="fas fa-trash-alt mood-category-action delete" data-id="<?php echo $category['id']; ?>"></i>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Charts Container -->
                <div class="chart-container">
                    <div class="chart-header">
                        <h2 class="chart-title">
                            <i class="fas fa-chart-pie"></i>
                            Thống kê cảm xúc
                        </h2>
                    </div>
                    
                    <div class="chart-tabs">
                        <div class="chart-tab active" data-target="weeklyChart">Tuần này</div>
                        <div class="chart-tab" data-target="monthlyChart">Tháng này</div>
                        <div class="chart-tab" data-target="timePeriodsChart">Thời điểm</div>
                        <div class="chart-tab" data-target="trendChart">Xu hướng</div>
                    </div>
                    
                    <div class="chart-content active" id="weeklyChart">
                        <canvas id="weeklyChartCanvas" height="300"></canvas>
                    </div>
                    
                    <div class="chart-content" id="monthlyChart">
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

            <!-- Main Content -->
            <div class="col-lg-8 col-md-6">
                <div class="calendar-container">
                    <div class="calendar-header">
                        <h2 class="calendar-title">
                            <i class="fas fa-calendar-alt"></i>
                            Lịch cảm xúc
                        </h2>
                        <button class="add-mood-btn" data-bs-toggle="modal" data-bs-target="#addMoodEntryModal">
                            <i class="fas fa-plus"></i>
                            Thêm cảm xúc
                        </button>
                    </div>
                    <div id="calendar"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Mood Category Modal -->
    <div class="modal fade" id="addMoodCategoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus-circle"></i>
                        Thêm cảm xúc mới
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="addCategoryForm">
                        <input type="hidden" name="action" value="create_mood_category">
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-tag"></i>
                                Tên cảm xúc
                            </label>
                            <input type="text" class="form-control" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-palette"></i>
                                Màu sắc
                            </label>
                            <input type="color" class="form-control form-control-color" name="color" value="#7000FF" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-icons"></i>
                                Icon
                            </label>
                            <div class="input-group">
                                <input type="text" class="form-control" name="icon" id="selectedIcon" placeholder="fas fa-smile" readonly>
                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#iconPickerModal">
                                    <i class="fas fa-icons"></i> Chọn Icon
                                </button>
                            </div>
                            <div id="iconPreview" class="mt-2 text-center" style="font-size: 2rem;"></div>
                        </div>
                        <button type="submit" class="add-mood-btn w-100">
                            <i class="fas fa-plus"></i>
                            Thêm cảm xúc
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Mood Category Modal -->
    <div class="modal fade" id="editMoodCategoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit"></i>
                        Sửa danh mục cảm xúc
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="editCategoryForm">
                        <input type="hidden" name="action" value="edit_mood_category">
                        <input type="hidden" name="category_id" id="editCategoryId">
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-tag"></i>
                                Tên cảm xúc
                            </label>
                            <input type="text" class="form-control" name="name" id="editCategoryName" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-palette"></i>
                                Màu sắc
                            </label>
                            <input type="color" class="form-control form-control-color" name="color" id="editCategoryColor" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-icons"></i>
                                Icon
                            </label>
                            <div class="input-group">
                                <input type="text" class="form-control" name="icon" id="editSelectedIcon" readonly>
                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#iconPickerModalEdit">
                                    <i class="fas fa-icons"></i> Chọn Icon
                                </button>
                            </div>
                            <div id="editIconPreview" class="mt-2 text-center" style="font-size: 2rem;"></div>
                        </div>
                        <button type="submit" class="add-mood-btn w-100">
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
    <!-- Icon Picker Modal for Edit -->
    <div class="modal fade" id="iconPickerModalEdit" tabindex="-1">
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
                        <input type="text" class="form-control" id="iconSearchEdit" placeholder="Tìm kiếm icon...">
                    </div>
                    <div class="icon-grid" id="iconGridEdit">
                        <!-- Icons will be populated here -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Mood Entry Modal -->
    <div class="modal fade" id="addMoodEntryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus-circle"></i>
                        Thêm cảm xúc mới
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="addMoodForm">
                        <input type="hidden" name="action" value="add_mood_entry">
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-heart"></i>
                                Cảm xúc
                            </label>
                            <select class="form-select" name="mood_category_id" required>
                                <?php foreach ($mood_categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>">
                                    <?php echo $category['name']; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-clock"></i>
                                Thời gian
                            </label>
                            <select class="form-select" name="time_period" required>
                                <option value="morning">Buổi sáng</option>
                                <option value="noon">Buổi trưa</option>
                                <option value="afternoon">Buổi chiều</option>
                                <option value="evening">Buổi tối</option>
                                <option value="night">Đêm khuya</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-calendar"></i>
                                Ngày
                            </label>
                            <input type="date" class="form-control" name="date" id="mood_date" required value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-hourglass"></i>
                                Giờ
                            </label>
                            <input type="time" class="form-control" name="time" required value="<?php echo date('H:i'); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-sticky-note"></i>
                                Ghi chú
                            </label>
                            <textarea class="form-control" name="notes" rows="3" placeholder="Mô tả cảm xúc của bạn..."></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-tasks"></i>
                                Hoạt động cải thiện
                            </label>
                            <textarea class="form-control" name="activities" rows="3" placeholder="Những hoạt động giúp cải thiện cảm xúc..."></textarea>
                        </div>
                        <button type="submit" class="add-mood-btn w-100">
                            <i class="fas fa-plus"></i>
                            Thêm cảm xúc
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Mood Entry Modal -->
    <div class="modal fade" id="editMoodEntryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit"></i>
                        Sửa cảm xúc
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="editMoodForm">
                        <input type="hidden" name="action" value="edit_mood_entry">
                        <input type="hidden" name="entry_id" id="editMoodEntryId">
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-heart"></i>
                                Cảm xúc
                            </label>
                            <select class="form-select" name="mood_category_id" id="editMoodCategoryId" required>
                                <?php foreach ($mood_categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>">
                                    <?php echo $category['name']; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-clock"></i>
                                Thời gian
                            </label>
                            <select class="form-select" name="time_period" id="editTimePeriod" required>
                                <option value="morning">Buổi sáng</option>
                                <option value="noon">Buổi trưa</option>
                                <option value="afternoon">Buổi chiều</option>
                                <option value="evening">Buổi tối</option>
                                <option value="night">Đêm khuya</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-calendar"></i>
                                Ngày
                            </label>
                            <input type="date" class="form-control" name="date" id="editMoodDate" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-hourglass"></i>
                                Giờ
                            </label>
                            <input type="time" class="form-control" name="time" id="editMoodTime" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-sticky-note"></i>
                                Ghi chú
                            </label>
                            <textarea class="form-control" name="notes" id="editMoodNotes" rows="3" placeholder="Mô tả cảm xúc của bạn..."></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-tasks"></i>
                                Hoạt động cải thiện
                            </label>
                            <textarea class="form-control" name="activities" id="editMoodActivities" rows="3" placeholder="Những hoạt động giúp cải thiện cảm xúc..."></textarea>
                        </div>
                        <button type="submit" class="add-mood-btn w-100">
                            <i class="fas fa-save"></i>
                            Lưu thay đổi
                        </button>
                    </form>
                </div>
            </div>
        </div>
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
        <div class="popover-actions">
            <button class="popover-action popover-edit" id="editMoodBtn">
                <i class="fas fa-edit"></i>
                Chỉnh sửa
            </button>
            <button class="popover-action popover-delete" id="deleteMoodBtn">
                <i class="fas fa-trash-alt"></i>
                Xóa
            </button>
        </div>
    </div>
<!-- Create Share Modal -->
<div class="modal fade" id="createShareModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-share-alt"></i>
                    Tạo chia sẻ mới
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="createShareForm">
                    <div class="mb-3">
                        <label class="form-label">
                            <i class="fas fa-heading"></i>
                            Tiêu đề
                        </label>
                        <input type="text" class="form-control" name="title" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">
                            <i class="fas fa-align-left"></i>
                            Mô tả (tùy chọn)
                        </label>
                        <textarea class="form-control" name="description" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">
                            <i class="fas fa-clock"></i>
                            Thời gian hết hạn (tùy chọn)
                        </label>
                        <input type="datetime-local" class="form-control" name="expiry_date">
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" name="is_public" id="isPublic" checked>
                        <label class="form-check-label" for="isPublic">
                            <i class="fas fa-globe"></i>
                            Công khai (ai có link đều xem được)
                        </label>
                    </div>
                    <button type="submit" class="add-mood-btn w-100">
                        <i class="fas fa-share-alt"></i>
                        Tạo chia sẻ
                    </button>
                </form>

                <div id="shareSuccess" style="display: none;">
                    <div class="alert alert-success mb-3">
                        <i class="fas fa-check-circle"></i>
                        Đã tạo chia sẻ thành công!
                    </div>
                    
                    <div class="share-url mb-3">
                        <div class="share-url-text" id="newShareUrl"></div>
                        <button class="copy-btn" id="copyNewShareUrl">
                            <i class="fas fa-copy"></i> Sao chép
                        </button>
                    </div>
                    
                    <div class="qr-code-container">
                        <div id="qrcode"></div>
                    </div>
                    
                    <div class="social-share-buttons">
                        <a href="#" target="_blank" class="social-share-button social-facebook" id="shareFacebook">
                            <i class="fab fa-facebook-f"></i>
                        </a>
                        <a href="#" target="_blank" class="social-share-button social-twitter" id="shareTwitter">
                            <i class="fab fa-twitter"></i>
                        </a>
                        <a href="#" target="_blank" class="social-share-button social-whatsapp" id="shareWhatsapp">
                            <i class="fab fa-whatsapp"></i>
                        </a>
                        <a href="#" target="_blank" class="social-share-button social-telegram" id="shareTelegram">
                            <i class="fab fa-telegram-plane"></i>
                        </a>
                        <a href="#" target="_blank" class="social-share-button social-email" id="shareEmail">
                            <i class="fas fa-envelope"></i>
                        </a>
                        <a href="#" target="_blank" class="social-share-button social-linkedin" id="shareLinkedin">
                            <i class="fab fa-linkedin-in"></i>
                        </a>
                    </div>
                    
                    <button type="button" class="add-mood-btn w-100 mt-3" data-bs-dismiss="modal">
                        <i class="fas fa-check"></i>
                        Hoàn tất
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Share Modal -->
<div class="modal fade" id="editShareModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-edit"></i>
                    Chỉnh sửa chia sẻ
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="editShareForm">
                    <input type="hidden" name="share_id" id="editShareId">
                    <div class="mb-3">
                        <label class="form-label">
                            <i class="fas fa-heading"></i>
                            Tiêu đề
                        </label>
                        <input type="text" class="form-control" name="title" id="editShareTitle" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">
                            <i class="fas fa-align-left"></i>
                            Mô tả (tùy chọn)
                        </label>
                        <textarea class="form-control" name="description" id="editShareDescription" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">
                            <i class="fas fa-clock"></i>
                            Thời gian hết hạn (tùy chọn)
                        </label>
                        <input type="datetime-local" class="form-control" name="expiry_date" id="editShareExpiry">
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" name="is_public" id="editSharePublic">
                        <label class="form-check-label" for="editSharePublic">
                            <i class="fas fa-globe"></i>
                            Công khai (ai có link đều xem được)
                        </label>
                    </div>
                    <button type="submit" class="add-mood-btn w-100">
                        <i class="fas fa-save"></i>
                        Lưu thay đổi
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.10.1/main.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Khởi tạo các biến toàn cục
        let calendar;
        let currentMoodEntryId;
        
        // Khởi tạo hiệu ứng particle
        createParticles();
        
        // Hiển thị thông báo thành công/lỗi
        <?php if ($success_message): ?>
            showAlert('<?php echo $success_message; ?>', 'success');
        <?php endif; ?>
        
        <?php if ($error_message): ?>
            showAlert('<?php echo $error_message; ?>', 'error');
        <?php endif; ?>
        
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
        
        // Xử lý click vào danh mục cảm xúc
        document.querySelectorAll('.mood-category').forEach(category => {
            category.addEventListener('click', function(e) {
                if (e.target.classList.contains('mood-category-action')) return;
                const categoryId = this.getAttribute('data-category-id');
                document.getElementById('addMoodForm').querySelector('select[name="mood_category_id"]').value = categoryId;
                const addMoodModal = new bootstrap.Modal(document.getElementById('addMoodEntryModal'));
                addMoodModal.show();
            });
        });
        
        // Xử lý chỉnh sửa danh mục cảm xúc
        document.querySelectorAll('.mood-category-action.edit').forEach(btn => {
            btn.addEventListener('click', function() {
                const categoryId = this.getAttribute('data-id');
                const name = this.getAttribute('data-name');
                const color = this.getAttribute('data-color');
                const icon = this.getAttribute('data-icon');
                
                document.getElementById('editCategoryId').value = categoryId;
                document.getElementById('editCategoryName').value = name;
                document.getElementById('editCategoryColor').value = color;
                document.getElementById('editSelectedIcon').value = icon;
                document.getElementById('editIconPreview').innerHTML = icon ? `<i class="${icon}"></i>` : '';
            });
        });
        
        // Xử lý xóa danh mục cảm xúc
        document.querySelectorAll('.mood-category-action.delete').forEach(btn => {
            btn.addEventListener('click', function() {
                if (confirm('Bạn có chắc muốn xóa danh mục này?')) {
                    const categoryId = this.getAttribute('data-id');
                    
                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', window.location.href, true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onload = function() {
                        if (xhr.status === 200) {
                            try {
                                const response = JSON.parse(xhr.responseText);
                                if (response.success) {
                                    showAlert('Đã xóa danh mục cảm xúc!', 'success');
                                    setTimeout(() => {
                                        window.location.reload();
                                    }, 1000);
                                } else {
                                    showAlert(response.error, 'error');
                                }
                            } catch (e) {
                                showAlert('Có lỗi xảy ra, vui lòng thử lại!', 'error');
                            }
                        }
                    };
                    xhr.send(`action=delete_mood_category&category_id=${categoryId}`);
                }
            });
        });
        
        // Xử lý đóng popover
        document.getElementById('closePopover').addEventListener('click', function() {
            document.getElementById('moodPopover').classList.remove('show');
        });
        
        // Xử lý chỉnh sửa cảm xúc
        document.getElementById('editMoodBtn').addEventListener('click', function() {
            document.getElementById('moodPopover').classList.remove('show');
            
            // Lấy thông tin cảm xúc hiện tại
            const entry = calendar.getEventById(currentMoodEntryId).extendedProps;
            
            // Điền thông tin vào form
            document.getElementById('editMoodEntryId').value = currentMoodEntryId;
            document.getElementById('editMoodCategoryId').value = entry.mood_category_id;
            document.getElementById('editTimePeriod').value = entry.time_period;
            document.getElementById('editMoodDate').value = entry.date;
            document.getElementById('editMoodTime').value = entry.time;
            document.getElementById('editMoodNotes').value = entry.notes || '';
            document.getElementById('editMoodActivities').value = entry.activities || '';
            
            // Hiển thị modal chỉnh sửa
            const editMoodModal = new bootstrap.Modal(document.getElementById('editMoodEntryModal'));
            editMoodModal.show();
        });
        
        // Xử lý xóa cảm xúc
        document.getElementById('deleteMoodBtn').addEventListener('click', function() {
            if (confirm('Bạn có chắc muốn xóa cảm xúc này?')) {
                const xhr = new XMLHttpRequest();
                xhr.open('POST', window.location.href, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() {
                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                calendar.refetchEvents();
                                document.getElementById('moodPopover').classList.remove('show');
                                showAlert('Đã xóa cảm xúc thành công!', 'success');
                                setTimeout(() => {
                                    window.location.reload();
                                }, 1000);
                            } else {
                                showAlert('Lỗi khi xóa cảm xúc: ' + response.error, 'error');
                            }
                        } catch (e) {
                            showAlert('Có lỗi xảy ra, vui lòng thử lại!', 'error');
                        }
                    }
                };
                xhr.send(`action=delete_mood_entry&entry_id=${currentMoodEntryId}`);
            }
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
                },
                dateClick: function(info) {
                    document.getElementById('mood_date').value = info.dateStr;
                    const addMoodModal = new bootstrap.Modal(document.getElementById('addMoodEntryModal'));
                    addMoodModal.show();
                }
            });
            calendar.render();
        }
        
        // Hàm hiển thị popover chi tiết cảm xúc
        function showMoodPopover(event) {
            const popover = document.getElementById('moodPopover');
            
            currentMoodEntryId = event.id;
            
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
            
            let rect;
            try {
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
            } catch (e) {
                console.error("Error getting element position:", e);
                const windowWidth = window.innerWidth;
                const windowHeight = window.innerHeight;
                popover.style.left = `${windowWidth / 2 - 175}px`;
                popover.style.top = `${windowHeight / 2 - 200}px`;
                popover.classList.add('show');
                return;
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
            popover.classList.add('show');
        }
        
        // Hàm lấy vị trí chuột
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
                alertDiv.remove();
            }, 3500);
        }
        
        // Hàm khởi tạo biểu đồ
        function initializeCharts() {
            initWeeklyChart();
            initMonthlyChart();
            initTimePeriodsChart();
            initTrendChart();
        }
        
        // Biểu đồ cảm xúc trong tuần
        function initWeeklyChart() {
            const ctx = document.getElementById('weeklyChartCanvas').getContext('2d');
            
            const data = {
                labels: [
                    <?php foreach ($weekly_stats as $stat): ?>
                    '<?php echo $stat['mood_name']; ?>',
                    <?php endforeach; ?>
                ],
                datasets: [{
                    data: [
                        <?php foreach ($weekly_stats as $stat): ?>
                        <?php echo $stat['count']; ?>,
                        <?php endforeach; ?>
                    ],
                    backgroundColor: [
                        <?php foreach ($weekly_stats as $stat): ?>
                        '<?php echo $stat['color']; ?>',
                        <?php endforeach; ?>
                    ],
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
            
            new Chart(ctx, config);
        }
        
        // Biểu đồ cảm xúc trong tháng
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
        
        // Icon picker functionality
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
        
        // Common Font Awesome icons
        const commonIcons = [
            'fas fa-smile', 'fas fa-laugh', 'fas fa-grin', 'fas fa-grin-beam', 'fas fa-grin-hearts',
            'fas fa-grin-stars', 'fas fa-grin-tears', 'fas fa-meh', 'fas fa-frown', 'fas fa-angry',
            'fas fa-sad-tear', 'fas fa-sad-cry', 'fas fa-tired', 'fas fa-grimace', 'fas fa-dizzy',
            'fas fa-heart', 'fas fa-heart-broken', 'fas fa-heartbeat', 'fas fa-star', 'fas fa-star-half-alt',
            'fas fa-sun', 'fas fa-moon', 'fas fa-cloud', 'fas fa-cloud-sun', 'fas fa-cloud-moon',
            'fas fa-bolt', 'fas fa-wind', 'fas fa-snowflake', 'fas fa-rainbow', 'fas fa-fire',
            'fas fa-thumbs-up', 'fas fa-thumbs-down', 'fas fa-check', 'fas fa-times', 'fas fa-exclamation',
            'fas fa-question', 'fas fa-info', 'fas fa-lightbulb', 'fas fa-bell', 'fas fa-comment',
            'fas fa-comment-dots', 'fas fa-comment-slash', 'fas fa-comment-medical', 'fas fa-microphone',
            'fas fa-music', 'fas fa-headphones', 'fas fa-book', 'fas fa-book-open', 'fas fa-graduation-cap',
            'fas fa-laptop', 'fas fa-mobile-alt', 'fas fa-gamepad', 'fas fa-coffee', 'fas fa-glass-cheers',
            'fas fa-wine-glass-alt', 'fas fa-beer', 'fas fa-pizza-slice', 'fas fa-hamburger', 'fas fa-ice-cream',
            'fas fa-cookie', 'fas fa-carrot', 'fas fa-apple-alt', 'fas fa-fish', 'fas fa-bread-slice',
            'fas fa-bacon', 'fas fa-egg', 'fas fa-pepper-hot', 'fas fa-cheese', 'fas fa-drumstick-bite',
            'fas fa-running', 'fas fa-walking', 'fas fa-hiking', 'fas fa-biking', 'fas fa-swimming-pool',
            'fas fa-dumbbell', 'fas fa-weight', 'fas fa-basketball-ball', 'fas fa-football-ball', 'fas fa-baseball-ball',
            'fas fa-volleyball-ball', 'fas fa-table-tennis', 'fas fa-film', 'fas fa-tv', 'fas fa-camera',
            'fas fa-image', 'fas fa-paint-brush', 'fas fa-palette', 'fas fa-pencil-alt', 'fas fa-pen',
            'fas fa-marker', 'fas fa-highlighter', 'fas fa-code', 'fas fa-terminal', 'fas fa-bug',
            'fas fa-briefcase', 'fas fa-building', 'fas fa-city', 'fas fa-store', 'fas fa-school',
            'fas fa-university', 'fas fa-hospital', 'fas fa-clinic-medical', 'fas fa-medal', 'fas fa-trophy',
            'fas fa-crown', 'fas fa-award', 'fas fa-gift', 'fas fa-birthday-cake', 'fas fa-baby',
            'fas fa-child', 'fas fa-user', 'fas fa-female', 'fas fa-male', 'fas fa-users',
            'fas fa-user-friends', 'fas fa-user-tie', 'fas fa-user-graduate', 'fas fa-user-ninja', 'fas fa-user-astronaut'
        ];
        
        // Populate icon grid
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
        
        // Select icon
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
        
        // Search icons
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
        
        // Initialize icon grids
        populateIconGrid(commonIcons, iconGrid, selectIcon);
        populateIconGrid(commonIcons, iconGridEdit, selectIconEdit);
        
        // Hàm tạo hiệu ứng particle
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
        
        // Thêm hiệu ứng cuộn
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
        
        // Khởi tạo animation
        animateElements('.mood-category', 100);
        animateElements('.chart-container > *', 100);
        animateElements('.calendar-container > *', 100);
        
        // Handle form submissions with AJAX
        document.getElementById('addCategoryForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            const xhr = new XMLHttpRequest();
            xhr.open('POST', window.location.href, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    showAlert('Đã thêm danh mục cảm xúc mới!', 'success');
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    showAlert('Lỗi khi thêm danh mục cảm xúc!', 'error');
                }
            };
            xhr.send(formData);
        });
        
        document.getElementById('editCategoryForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            const xhr = new XMLHttpRequest();
            xhr.open('POST', window.location.href, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    showAlert('Đã cập nhật danh mục cảm xúc!', 'success');
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    showAlert('Lỗi khi cập nhật danh mục cảm xúc!', 'error');
                }
            };
            xhr.send(formData);
        });
        
        document.getElementById('addMoodForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            const xhr = new XMLHttpRequest();
            xhr.open('POST', window.location.href, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    calendar.refetchEvents();
                    bootstrap.Modal.getInstance(document.getElementById('addMoodEntryModal')).hide();
                    showAlert('Đã thêm cảm xúc mới!', 'success');
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    showAlert('Lỗi khi thêm cảm xúc!', 'error');
                }
            };
            xhr.send(formData);
        });
        
        document.getElementById('editMoodForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            const xhr = new XMLHttpRequest();
            xhr.open('POST', window.location.href, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    calendar.refetchEvents();
                    bootstrap.Modal.getInstance(document.getElementById('editMoodEntryModal')).hide();
                    showAlert('Đã cập nhật cảm xúc!', 'success');
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    showAlert('Lỗi khi cập nhật cảm xúc!', 'error');
                }
            };
            xhr.send(formData);
        });
        
        // Thêm animation Float
        document.head.appendChild(document.createElement('style')).textContent = `
            @keyframes float {
                0% { transform: translateY(0px); }
                50% { transform: translateY(-20px); }
                100% { transform: translateY(0px); }
            }
        `;
    });
    
    // Script cho việc chia sẻ cảm xúc
document.addEventListener('DOMContentLoaded', function() {
    // Xử lý form tạo chia sẻ
    document.getElementById('createShareForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        formData.append('action', 'create_mood_share');
        
        const xhr = new XMLHttpRequest();
        xhr.open('POST', window.location.href, true);
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        document.getElementById('createShareForm').style.display = 'none';
                        document.getElementById('shareSuccess').style.display = 'block';
                        
                        // Hiển thị URL
                        document.getElementById('newShareUrl').textContent = response.share_url;
                        
                        // Thiết lập copy button
                        document.getElementById('copyNewShareUrl').setAttribute('data-url', response.share_url);
                        
                        // Tạo QR code
                        document.getElementById('qrcode').innerHTML = '';
                        new QRCode(document.getElementById("qrcode"), {
                            text: response.share_url,
                            width: 200,
                            height: 200,
                            colorDark : "#000000",
                            colorLight : "#ffffff",
                            correctLevel : QRCode.CorrectLevel.H
                        });
                        
                        // Thiết lập các nút chia sẻ xã hội
                        setupSocialSharing(response.share_url);
                        
                        setTimeout(() => {
                            window.location.reload();
                        }, 5000);
                    } else {
                        showAlert('Lỗi khi tạo chia sẻ: ' + response.error, 'error');
                    }
                } catch (e) {
                    showAlert('Có lỗi xảy ra, vui lòng thử lại!', 'error');
                }
            }
        };
        xhr.send(formData);
    });
    
    // Xử lý form chỉnh sửa chia sẻ
    document.getElementById('editShareForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        formData.append('action', 'update_mood_share');
        
        const xhr = new XMLHttpRequest();
        xhr.open('POST', window.location.href, true);
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        bootstrap.Modal.getInstance(document.getElementById('editShareModal')).hide();
                        showAlert('Đã cập nhật chia sẻ thành công!', 'success');
                        setTimeout(() => {
                            window.location.reload();
                        }, 1000);
                    } else {
                        showAlert('Lỗi khi cập nhật chia sẻ: ' + response.error, 'error');
                    }
                } catch (e) {
                    showAlert('Có lỗi xảy ra, vui lòng thử lại!', 'error');
                }
            }
        };
        xhr.send(formData);
    });
    
    // Xử lý nút xóa chia sẻ
    document.querySelectorAll('.share-delete').forEach(btn => {
        btn.addEventListener('click', function() {
            if (confirm('Bạn có chắc muốn xóa chia sẻ này?')) {
                const shareId = this.getAttribute('data-id');
                
                const xhr = new XMLHttpRequest();
                xhr.open('POST', window.location.href, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function() {
                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                showAlert('Đã xóa chia sẻ thành công!', 'success');
                                setTimeout(() => {
                                    window.location.reload();
                                }, 1000);
                            } else {
                                showAlert('Lỗi khi xóa chia sẻ: ' + response.error, 'error');
                            }
                        } catch (e) {
                            showAlert('Có lỗi xảy ra, vui lòng thử lại!', 'error');
                        }
                    }
                };
                xhr.send(`action=delete_mood_share&share_id=${shareId}`);
            }
        });
    });
    
    // Xử lý nút sửa chia sẻ
    document.querySelectorAll('.share-edit').forEach(btn => {
        btn.addEventListener('click', function() {
            const shareId = this.getAttribute('data-id');
            const title = this.getAttribute('data-title');
            const description = this.getAttribute('data-description');
            const isPublic = this.getAttribute('data-public');
            const expiry = this.getAttribute('data-expiry');
            
            document.getElementById('editShareId').value = shareId;
            document.getElementById('editShareTitle').value = title;
            document.getElementById('editShareDescription').value = description;
            document.getElementById('editSharePublic').checked = isPublic === '1';
            
            if (expiry) {
                // Định dạng datetime-local yêu cầu format YYYY-MM-DDThh:mm
                const expiryDate = new Date(expiry);
                const year = expiryDate.getFullYear();
                const month = String(expiryDate.getMonth() + 1).padStart(2, '0');
                const day = String(expiryDate.getDate()).padStart(2, '0');
                const hours = String(expiryDate.getHours()).padStart(2, '0');
                const minutes = String(expiryDate.getMinutes()).padStart(2, '0');
                
                document.getElementById('editShareExpiry').value = `${year}-${month}-${day}T${hours}:${minutes}`;
            } else {
                document.getElementById('editShareExpiry').value = '';
            }
        });
    });
    
    // Xử lý nút sao chép link
    document.querySelectorAll('.copy-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const url = this.getAttribute('data-url');
            
            navigator.clipboard.writeText(url).then(() => {
                // Lưu trạng thái ban đầu
                const originalText = this.innerHTML;
                
                // Thay đổi nút
                this.innerHTML = '<i class="fas fa-check"></i> Đã sao chép';
                
                // Khôi phục sau 2 giây
                setTimeout(() => {
                    this.innerHTML = originalText;
                }, 2000);
            }).catch(err => {
                showAlert('Lỗi khi sao chép: ' + err, 'error');
            });
        });
    });
    
    // Thiết lập chia sẻ xã hội
    function setupSocialSharing(url) {
        const title = encodeURIComponent('Theo dõi cảm xúc của tôi');
        const encodedUrl = encodeURIComponent(url);
        
        // Facebook
        document.getElementById('shareFacebook').href = `https://www.facebook.com/sharer/sharer.php?u=${encodedUrl}`;
        
        // Twitter
        document.getElementById('shareTwitter').href = `https://twitter.com/intent/tweet?text=${title}&url=${encodedUrl}`;
        
        // WhatsApp
        document.getElementById('shareWhatsapp').href = `https://wa.me/?text=${title}%20${encodedUrl}`;
        
        // Telegram
        document.getElementById('shareTelegram').href = `https://t.me/share/url?url=${encodedUrl}&text=${title}`;
        
        // Email
        document.getElementById('shareEmail').href = `mailto:?subject=${title}&body=${encodedUrl}`;
        
        // LinkedIn
        document.getElementById('shareLinkedin').href = `https://www.linkedin.com/shareArticle?mini=true&url=${encodedUrl}&title=${title}`;
    }
});
</script>

</body>
</html>