<?php
// Bật chế độ debug trong quá trình phát triển (xóa khi triển khai)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Khởi tạo session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Include file cấu hình cơ sở dữ liệu
require_once 'db_config.php';

// Kiểm tra kết nối cơ sở dữ liệu
if (!isset($conn) || !$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

// Hàm thực thi truy vấn SQL an toàn
function safeQuery($conn, $sql) {
    $result = mysqli_query($conn, $sql);
    if (!$result) {
        error_log("SQL Error: " . mysqli_error($conn) . " in query: " . $sql);
        return false;
    }
    return $result;
}

// Tạo bảng calendar_events nếu chưa tồn tại
$sql_create_table = "CREATE TABLE IF NOT EXISTS calendar_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    start_datetime DATETIME NOT NULL,
    end_datetime DATETIME NOT NULL,
    event_color VARCHAR(20) DEFAULT '#74ebd5',
    is_all_day TINYINT(1) DEFAULT 0,
    is_recurring TINYINT(1) DEFAULT 0,
    recurrence_pattern VARCHAR(50) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
)";
safeQuery($conn, $sql_create_table);

// Khởi tạo biến thông báo
$message = '';
$error = '';

// Xử lý thêm sự kiện mới
if (isset($_POST['add_event'])) {
    $user_id = $_SESSION['user_id'];
    $title = mysqli_real_escape_string($conn, $_POST['title'] ?? '');
    $description = mysqli_real_escape_string($conn, $_POST['description'] ?? '');
    $start_datetime = mysqli_real_escape_string($conn, $_POST['start_datetime'] ?? '');
    $end_datetime = mysqli_real_escape_string($conn, $_POST['end_datetime'] ?? '');
    $event_color = mysqli_real_escape_string($conn, $_POST['event_color'] ?? '#74ebd5');
    $is_all_day = isset($_POST['is_all_day']) ? 1 : 0;
    $is_recurring = isset($_POST['is_recurring']) ? 1 : 0;
    $recurrence_pattern = $is_recurring ? mysqli_real_escape_string($conn, $_POST['recurrence_pattern'] ?? '') : NULL;

    // Kiểm tra định dạng datetime
    try {
        $start_dt = new DateTime($start_datetime);
        $end_dt = new DateTime($end_datetime);
        if ($start_dt >= $end_dt) {
            $error = "End datetime must be after start datetime.";
        } else {
            $sql = "INSERT INTO calendar_events (user_id, title, description, start_datetime, end_datetime, event_color, is_all_day, is_recurring, recurrence_pattern) 
                    VALUES ('$user_id', '$title', '$description', '$start_datetime', '$end_datetime', '$event_color', '$is_all_day', '$is_recurring', '$recurrence_pattern')";
            
            if (safeQuery($conn, $sql)) {
                $message = "Event added successfully!";
            } else {
                $error = "Error adding event: " . mysqli_error($conn);
            }
        }
    } catch (Exception $e) {
        $error = "Invalid date format for start or end datetime.";
    }
}

// Xử lý cập nhật sự kiện
if (isset($_POST['update_event'])) {
    $event_id = mysqli_real_escape_string($conn, $_POST['event_id'] ?? '');
    $title = mysqli_real_escape_string($conn, $_POST['title'] ?? '');
    $description = mysqli_real_escape_string($conn, $_POST['description'] ?? '');
    $start_datetime = mysqli_real_escape_string($conn, $_POST['start_datetime'] ?? '');
    $end_datetime = mysqli_real_escape_string($conn, $_POST['end_datetime'] ?? '');
    $event_color = mysqli_real_escape_string($conn, $_POST['event_color'] ?? '#74ebd5');
    $is_all_day = isset($_POST['is_all_day']) ? 1 : 0;
    $is_recurring = isset($_POST['is_recurring']) ? 1 : 0;
    $recurrence_pattern = $is_recurring ? mysqli_real_escape_string($conn, $_POST['recurrence_pattern'] ?? '') : NULL;

    // Kiểm tra định dạng datetime
    try {
        $start_dt = new DateTime($start_datetime);
        $end_dt = new DateTime($end_datetime);
        if ($start_dt >= $end_dt) {
            $error = "End datetime must be after start datetime.";
        } else {
            $sql = "UPDATE calendar_events 
                    SET title = '$title', description = '$description', start_datetime = '$start_datetime', 
                        end_datetime = '$end_datetime', event_color = '$event_color', is_all_day = '$is_all_day', 
                        is_recurring = '$is_recurring', recurrence_pattern = '$recurrence_pattern' 
                    WHERE id = '$event_id' AND user_id = '{$_SESSION['user_id']}'";
            
            if (safeQuery($conn, $sql)) {
                $message = "Event updated successfully!";
            } else {
                $error = "Error updating event: " . mysqli_error($conn);
            }
        }
    } catch (Exception $e) {
        $error = "Invalid date format for start or end datetime.";
    }
}

// Xử lý xóa sự kiện
if (isset($_GET['delete_event'])) {
    $event_id = mysqli_real_escape_string($conn, $_GET['delete_event']);
    $sql = "DELETE FROM calendar_events WHERE id = '$event_id' AND user_id = '{$_SESSION['user_id']}'";
    
    if (safeQuery($conn, $sql)) {
        $message = "Event deleted successfully!";
    } else {
        $error = "Error deleting event: " . mysqli_error($conn);
    }
}

// Lấy tháng và năm hiện tại
$month = isset($_GET['month']) ? intval($_GET['month']) : intval(date('m'));
$year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));

// Đảm bảo tháng hợp lệ
if ($month < 1) {
    $month = 12;
    $year--;
} elseif ($month > 12) {
    $month = 1;
    $year++;
}

// Tính toán thông tin tháng
$first_day_of_month = mktime(0, 0, 0, $month, 1, $year);
$number_of_days = date('t', $first_day_of_month);
$first_day_of_week = date('N', $first_day_of_month);

// Lấy sự kiện trong tháng hiện tại
$start_date = "$year-$month-01 00:00:00";
$end_date = "$year-$month-$number_of_days 23:59:59";
$sql = "SELECT * FROM calendar_events 
        WHERE user_id = '{$_SESSION['user_id']}' 
        AND ((start_datetime BETWEEN '$start_date' AND '$end_date') 
        OR (end_datetime BETWEEN '$start_date' AND '$end_date')
        OR (start_datetime <= '$start_date' AND end_datetime >= '$end_date'))
        ORDER BY start_datetime ASC";
$result = safeQuery($conn, $sql);
$events = [];
if ($result && mysqli_num_rows($result) > 0) {
    while ($row = mysqli_fetch_assoc($result)) {
        $events[] = $row;
    }
}

// Lấy thông tin sự kiện để chỉnh sửa
$edit_event = null;
if (isset($_GET['edit_event'])) {
    $event_id = mysqli_real_escape_string($conn, $_GET['edit_event']);
    $sql = "SELECT * FROM calendar_events WHERE id = '$event_id' AND user_id = '{$_SESSION['user_id']}'";
    $result = safeQuery($conn, $sql);
    if ($result && mysqli_num_rows($result) > 0) {
        $edit_event = mysqli_fetch_assoc($result);
    }
}

// Hàm kiểm tra sự kiện trong một ngày
function hasEvents($day, $events, $month, $year) {
    $day_events = [];
    $date = sprintf("%04d-%02d-%02d", $year, $month, $day);
    
    foreach ($events as $event) {
        $start_date = substr($event['start_datetime'], 0, 10);
        $end_date = substr($event['end_datetime'], 0, 10);
        
        if (($date >= $start_date && $date <= $end_date) || 
            ($event['is_recurring'] && checkRecurringEvent($date, $event))) {
            $day_events[] = $event;
        }
    }
    return $day_events;
}

// Hàm kiểm tra sự kiện lặp lại
function checkRecurringEvent($date, $event) {
    if (!$event['is_recurring'] || empty($event['recurrence_pattern'])) {
        return false;
    }
    
    try {
        $event_start = new DateTime($event['start_datetime']);
        $check_date = new DateTime($date);
        
        if ($check_date < $event_start) {
            return false;
        }
        
        switch ($event['recurrence_pattern']) {
            case 'daily':
                return true;
            case 'weekly':
                return $event_start->format('N') === $check_date->format('N');
            case 'monthly':
                return $event_start->format('d') === $check_date->format('d');
            case 'yearly':
                return $event_start->format('md') === $check_date->format('md');
            default:
                return false;
        }
    } catch (Exception $e) {
        error_log("Date error in checkRecurringEvent: " . $e->getMessage());
        return false;
    }
}

// Hàm hiển thị chi tiết sự kiện trong ngày
function getEventDetails($day, $events, $month, $year) {
    $day_events = hasEvents($day, $events, $month, $year);
    $html = '';
    
    foreach ($day_events as $event) {
        $start_time = date('H:i', strtotime($event['start_datetime']));
        $end_time = date('H:i', strtotime($event['end_datetime']));
        $event_date = date('Y-m-d', strtotime($event['start_datetime']));
        $is_recurring = $event['is_recurring'] ? '<i class="fas fa-sync-alt"></i> ' : '';
        $is_all_day = $event['is_all_day'] ? 'All Day' : "$start_time - $end_time";
        
        // Enhanced tooltip data
        $tooltip_data = htmlspecialchars(json_encode([
            'title' => $event['title'],
            'description' => $event['description'],
            'start' => $is_all_day ? 'All Day' : date('g:i A', strtotime($event['start_datetime'])),
            'end' => $is_all_day ? '' : date('g:i A', strtotime($event['end_datetime'])),
            'date' => date('l, F j, Y', strtotime($event_date)),
            'recurring' => $event['is_recurring'] ? ucfirst($event['recurrence_pattern']) : '',
            'color' => $event['event_color']
        ]));
        
        $html .= '<div class="event-item" style="background-color: ' . htmlspecialchars($event['event_color']) . ';" 
                      data-event-id="' . $event['id'] . '"
                      data-tooltip="' . $tooltip_data . '">
                      <span class="event-time">' . $start_time . '</span>
                      <span class="event-title">' . htmlspecialchars(substr($event['title'], 0, 15)) . (strlen($event['title']) > 15 ? '...' : '') . '</span>
                  </div>';
    }
    return $html;
}

// Hàm lấy tên tháng
function getMonthName($month) {
    $dateObj = DateTime::createFromFormat('!m', $month);
    return $dateObj->format('F');
}

// Lấy sự kiện cho chế độ xem ngày
$day_view_date = isset($_GET['day']) ? $_GET['day'] : date('Y-m-d');
$sql_day_view = "SELECT * FROM calendar_events 
                WHERE user_id = '{$_SESSION['user_id']}' 
                AND ((DATE(start_datetime) <= '$day_view_date' AND DATE(end_datetime) >= '$day_view_date') 
                    OR (is_recurring = 1))
                ORDER BY start_datetime ASC";
$result_day_view = safeQuery($conn, $sql_day_view);
$day_events = [];
if ($result_day_view && mysqli_num_rows($result_day_view) > 0) {
    while ($row = mysqli_fetch_assoc($result_day_view)) {
        if ($row['is_recurring']) {
            if (checkRecurringEvent($day_view_date, $row)) {
                $day_events[] = $row;
            }
        } else {
            $day_events[] = $row;
        }
    }
}

// Sắp xếp sự kiện theo thời gian bắt đầu
usort($day_events, function($a, $b) {
    return strtotime($a['start_datetime']) - strtotime($b['start_datetime']);
});

// Lấy sự kiện sắp tới cho sidebar
$today = date('Y-m-d');
$next_week = date('Y-m-d', strtotime('+7 days'));
$sql_upcoming = "SELECT * FROM calendar_events 
                WHERE user_id = '{$_SESSION['user_id']}' 
                AND ((DATE(start_datetime) BETWEEN '$today' AND '$next_week') 
                    OR (is_recurring = 1))
                ORDER BY start_datetime ASC
                LIMIT 5";
$result_upcoming = safeQuery($conn, $sql_upcoming);
$upcoming_events = [];
if ($result_upcoming && mysqli_num_rows($result_upcoming) > 0) {
    while ($row = mysqli_fetch_assoc($result_upcoming)) {
        if ($row['is_recurring']) {
            $next_occurrence = getNextOccurrence($row, $today, $next_week);
            if ($next_occurrence) {
                $row['next_occurrence'] = $next_occurrence;
                $upcoming_events[] = $row;
            }
        } else {
            $upcoming_events[] = $row;
        }
    }
}

// Hàm lấy lần xuất hiện tiếp theo của sự kiện lặp lại
function getNextOccurrence($event, $start_date, $end_date) {
    if (empty($event) || empty($start_date) || empty($end_date)) {
        return false;
    }
    
    if (empty($event['recurrence_pattern']) || empty($event['start_datetime'])) {
        return false;
    }
    
    try {
        $pattern = $event['recurrence_pattern'];
        $event_start = new DateTime($event['start_datetime']);
        $check_start = new DateTime($start_date);
        $check_end = new DateTime($end_date);
        
        switch ($pattern) {
            case 'daily':
                if ($check_start <= $check_end) {
                    return $check_start->format('Y-m-d');
                }
                break;
                
            case 'weekly':
                $event_day = $event_start->format('N');
                $check_day = $check_start->format('N');
                
                if ($event_day == $check_day) {
                    return $check_start->format('Y-m-d');
                } else {
                    $days_to_add = ($event_day - $check_day + 7) % 7;
                    if ($days_to_add == 0) $days_to_add = 7;
                    $next_date = clone $check_start;
                    $next_date->modify("+$days_to_add day");
                    if ($next_date <= $check_end) {
                        return $next_date->format('Y-m-d');
                    }
                }
                break;
                
            case 'monthly':
                $event_day = $event_start->format('d');
                $check_day = $check_start->format('d');
                $check_month = $check_start->format('m');
                $check_year = $check_start->format('Y');
                
                if ($event_day >= $check_day) {
                    $next_date = new DateTime("$check_year-$check_month-$event_day");
                } else {
                    $next_month = $check_month + 1;
                    $next_year = $check_year;
                    if ($next_month > 12) {
                        $next_month = 1;
                        $next_year++;
                    }
                    $last_day_of_next_month = date('t', mktime(0, 0, 0, $next_month, 1, $next_year));
                    $event_day = min($event_day, $last_day_of_next_month);
                    $next_date = new DateTime("$next_year-$next_month-$event_day");
                }
                
                if ($next_date <= $check_end) {
                    return $next_date->format('Y-m-d');
                }
                break;
                
            case 'yearly':
                $event_month = $event_start->format('m');
                $event_day = $event_start->format('d');
                $check_month = $check_start->format('m');
                $check_day = $check_start->format('d');
                $check_year = $check_start->format('Y');
                
                if (($event_month > $check_month) || 
                    ($event_month == $check_month && $event_day >= $check_day)) {
                    $next_date = new DateTime("$check_year-$event_month-$event_day");
                } else {
                    $next_date = new DateTime(($check_year + 1) . "-$event_month-$event_day");
                }
                
                if ($next_date <= $check_end) {
                    return $next_date->format('Y-m-d');
                }
                break;
        }
    } catch (Exception $e) {
        error_log("Error in getNextOccurrence: " . $e->getMessage());
        return false;
    }
    return false;
}

// Xác định chế độ xem hiện tại
$current_view = isset($_GET['view']) ? $_GET['view'] : 'month';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendar App</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Định nghĩa biến CSS */
        :root {
            --primary-gradient-start: #74ebd5;
            --primary-gradient-end: #acb6e5;
            --secondary-gradient-start: #acb6e5;
            --secondary-gradient-end: #74ebd5;
            --text-color: #333;
            --text-secondary: #666;
            --link-color: #007bff;
            --link-hover-color: #0056b3;
            --card-bg: #fff;
            --taskbar-bg: #f1f3f4;
            --taskbar-active-bg: #e0e0e0;
            --delete-color: #ff4444;
            --delete-hover-color: #cc0000;
            --border-radius: 10px;
            --small-radius: 5px;
            --shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            --shadow-hover: 0 8px 20px rgba(0, 0, 0, 0.1);
            --tooltip-bg: rgba(0, 0, 0, 0.8);
            --tooltip-text: #fff;
            --context-menu-bg: #fff;
            --context-menu-hover: #f5f5f5;
            --context-menu-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        body {
            font-family: Arial, sans-serif;
            background: #f4f7f6;
            color: var(--text-color);
            margin: 0;
            padding: 20px;
        }

        .dashboard-container {
            max-width: 1200px;
            margin: 0 auto;
        }

        h1 {
            text-align: center;
            margin-bottom: 30px;
            font-size: 2rem;
            color: var(--text-color);
            position: relative;
            display: inline-block;
            left: 50%;
            transform: translateX(-50%);
        }

        h1::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 0;
            width: 100%;
            height: 3px;
            background: linear-gradient(90deg, var(--primary-gradient-start), var(--primary-gradient-end));
            border-radius: 3px;
            transform: scaleX(0.7);
            transition: transform 0.3s ease;
        }

        h1:hover::after {
            transform: scaleX(1);
        }

        /* Taskbar styles */
        .taskbar {
            background: var(--taskbar-bg);
            padding: 10px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            box-shadow: var(--shadow);
            transition: box-shadow 0.3s ease;
        }

        .taskbar:hover {
            box-shadow: var(--shadow-hover);
        }

        .taskbar-items {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .taskbar-item, .logout-btn {
            padding: 10px 20px;
            border-radius: 20px;
            text-decoration: none;
            color: var(--text-color);
            font-weight: 500;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .taskbar-item::before, .logout-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s ease;
        }

        .taskbar-item:hover::before, .logout-btn:hover::before {
            left: 100%;
        }

        .taskbar-item:hover, .logout-btn:hover {
            background: var(--taskbar-active-bg);
            transform: translateY(-3px);
        }

        .taskbar-item.active {
            background: linear-gradient(135deg, var(--primary-gradient-start), var(--primary-gradient-end));
            color: #fff;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        .menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1rem;
            cursor: pointer;
            color: var(--text-color);
            transition: transform 0.3s ease;
        }

        .menu-toggle:hover {
            transform: scale(1.1);
        }

        /* Calendar container */
        .calendar-container {
            position: relative;
        }

        /* Calendar header */
        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding: 15px;
            background: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            transition: box-shadow 0.3s ease, transform 0.3s ease;
        }

        .calendar-header:hover {
            box-shadow: var(--shadow-hover);
            transform: translateY(-3px);
        }

        .calendar-nav {
            display: flex;
            gap: 15px;
            align-items: center;
        }

        .calendar-nav a {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--primary-gradient-start), var(--primary-gradient-end));
            color: #fff;
            border-radius: 50%;
            text-decoration: none;
            transition: all 0.3s ease;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        .calendar-nav a:hover {
            transform: translateY(-3px) scale(1.1);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15);
            background: linear-gradient(135deg, var(--secondary-gradient-start), var(--secondary-gradient-end));
        }

        .calendar-title {
            font-size: 1.5rem;
            font-weight: bold;
            padding: 0 15px;
            color: var(--text-color);
            position: relative;
            transition: transform 0.3s ease;
        }

        .calendar-title:hover {
            transform: scale(1.05);
        }

        .calendar-view-toggle {
            display: flex;
            gap: 5px;
            background: var(--taskbar-bg);
            padding: 5px;
            border-radius: 30px;
            transition: transform 0.3s ease;
        }

        .calendar-view-toggle:hover {
            transform: translateY(-2px);
        }

        .calendar-view-toggle a {
            padding: 8px 15px;
            color: var(--text-color);
            border-radius: 20px;
            text-decoration: none;
            transition: all 0.3s ease;
            font-weight: 500;
            font-size: 0.9rem;
            position: relative;
            overflow: hidden;
        }

        .calendar-view-toggle a::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s ease;
        }

        .calendar-view-toggle a:hover::before {
            left: 100%;
        }

        .calendar-view-toggle a.active {
            background: linear-gradient(135deg, var(--primary-gradient-start), var(--primary-gradient-end));
            color: #fff;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        .calendar-view-toggle a:hover:not(.active) {
            background: rgba(116, 235, 213, 0.1);
            transform: translateY(-2px);
        }

        /* Calendar grid */
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 8px;
            margin-bottom: 20px;
        }

        .calendar-day-header {
            text-align: center;
            font-weight: bold;
            padding: 10px;
            background: var(--card-bg);
            border-radius: var(--small-radius);
            box-shadow: var(--shadow);
            color: var(--text-color);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .calendar-day-header:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-hover);
        }

        .calendar-day {
            min-height: 120px;
            background: var(--card-bg);
            border-radius: var(--small-radius);
            padding: 8px;
            position: relative;
            transition: all 0.3s ease;
            box-shadow: var(--shadow);
            display: flex;
            flex-direction: column;
            cursor: pointer;
        }

        .calendar-day:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-hover);
        }

        .calendar-day.today {
            background: linear-gradient(135deg, rgba(116, 235, 213, 0.1), rgba(172, 182, 229, 0.1));
            border: 2px solid var(--primary-gradient-start);
            position: relative;
            z-index: 1;
        }

        .calendar-day.today::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(116, 235, 213, 0.05), rgba(172, 182, 229, 0.05));
            border-radius: var(--small-radius);
            z-index: -1;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { opacity: 0.5; }
            50% { opacity: 1; }
            100% { opacity: 0.5; }
        }

        .calendar-day.other-month {
            opacity: 0.5;
            min-height: 100px;
        }

        .day-number {
            font-weight: bold;
            margin-bottom: 8px;
            color: var(--text-color);
            display: inline-block;
            width: 25px;
            height: 25px;
            text-align: center;
            line-height: 25px;
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .calendar-day:hover .day-number {
            transform: scale(1.1);
        }

        .calendar-day.today .day-number {
            background: linear-gradient(135deg, var(--primary-gradient-start), var(--primary-gradient-end));
            color: #fff;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .event-container {
            flex: 1;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 5px;
            margin-top: 5px;
            max-height: 80px;
            scrollbar-width: thin;
            scrollbar-color: var(--primary-gradient-start) transparent;
        }

        .event-container::-webkit-scrollbar {
            width: 4px;
        }

        .event-container::-webkit-scrollbar-thumb {
            background-color: var(--primary-gradient-start);
            border-radius: 4px;
        }

        .event-item {
            padding: 4px 6px;
            border-radius: 4px;
            font-size: 0.75rem;
            color: #fff;
            cursor: pointer;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s ease;
            position: relative;
            z-index: 10;
        }

        .event-item:hover {
            transform: translateX(3px) scale(1.02);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .event-time {
            font-weight: bold;
            font-size: 0.7rem;
        }

        .event-title {
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Event tooltip */
        .event-tooltip {
            position: absolute;
            background: var(--tooltip-bg);
            color: var(--tooltip-text);
            padding: 10px 15px;
            border-radius: var(--small-radius);
            z-index: 1000;
            width: 250px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.3s ease, transform 0.3s ease;
            transform: translateY(10px);
        }

        .event-tooltip.show {
            opacity: 1;
            transform: translateY(0);
        }

        .tooltip-title {
            font-weight: bold;
            font-size: 1rem;
            margin-bottom: 5px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            padding-bottom: 5px;
        }

        .tooltip-time {
            font-size: 0.8rem;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .tooltip-description {
            font-size: 0.85rem;
            margin-top: 5px;
            max-height: 100px;
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: rgba(255, 255, 255, 0.3) transparent;
        }

        .tooltip-description::-webkit-scrollbar {
            width: 3px;
        }

        .tooltip-description::-webkit-scrollbar-thumb {
            background-color: rgba(255, 255, 255, 0.3);
            border-radius: 3px;
        }

        .tooltip-recurring {
            font-size: 0.8rem;
            margin-top: 5px;
            font-style: italic;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        /* Context menu */
        .context-menu {
            position: absolute;
            background: var(--context-menu-bg);
            border-radius: var(--small-radius);
            box-shadow: var(--context-menu-shadow);
            padding: 5px 0;
            z-index: 1000;
            min-width: 180px;
            display: none;
            animation: fadeIn 0.2s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .context-menu-item {
            padding: 8px 15px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .context-menu-item:hover {
            background: var(--context-menu-hover);
        }

        .context-menu-item i {
            width: 20px;
            text-align: center;
        }

        .context-menu-divider {
            height: 1px;
            background: rgba(0, 0, 0, 0.1);
            margin: 5px 0;
        }

        .view-day-link {
            font-size: 0.75rem;
            color: var(--link-color);
            text-decoration: none;
            text-align: center;
            margin-top: 5px;
            padding: 3px;
            border-radius: 4px;
            background: rgba(116, 235, 213, 0.1);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .view-day-link::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.5), transparent);
            transition: left 0.5s ease;
        }

        .view-day-link:hover::before {
            left: 100%;
        }

        .view-day-link:hover {
            background: rgba(116, 235, 213, 0.2);
            color: var(--link-hover-color);
            transform: translateY(-2px);
        }

        /* Day view */
        .day-view {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 20px;
            margin-top: 20px;
            box-shadow: var(--shadow);
            transition: box-shadow 0.3s ease, transform 0.3s ease;
        }

        .day-view:hover {
            box-shadow: var(--shadow-hover);
            transform: translateY(-3px);
        }

        .day-view-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        .day-view-header a {
            width: 35px;
            height: 35px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--primary-gradient-start), var(--primary-gradient-end));
            color: #fff;
            border-radius: 50%;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .day-view-header a:hover {
            transform: translateY(-2px) scale(1.1);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            background: linear-gradient(135deg, var(--secondary-gradient-start), var(--secondary-gradient-end));
        }

        .day-view-title {
            font-size: 1.3rem;
            font-weight: bold;
            color: var(--text-color);
            position: relative;
            transition: transform 0.3s ease;
        }

        .day-view-title:hover {
            transform: scale(1.05);
        }

        .day-view-events {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .day-event {
            padding: 15px;
            border-radius: var(--small-radius);
            position: relative;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            border-left: 5px solid;
            overflow: hidden;
        }

        .day-event::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), transparent);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .day-event:hover::before {
            opacity: 1;
        }

        .day-event:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1);
        }

        .day-event-time {
            font-weight: bold;
            margin-bottom: 8px;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .recurring-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 20px;
            height: 20px;
            background: rgba(116, 235, 213, 0.2);
            border-radius: 50%;
            color: var(--primary-gradient-start);
            transition: transform 0.3s ease;
        }

        .recurring-badge:hover {
            transform: rotate(180deg);
        }

        .day-event-title {
            font-size: 1.2rem;
            margin-bottom: 8px;
            color: var(--text-color);
            transition: transform 0.3s ease;
        }

        .day-event:hover .day-event-title {
            transform: translateX(5px);
        }

        .day-event-description {
            color: var(--text-secondary);
            font-size: 0.95rem;
            line-height: 1.5;
        }

        .day-event-actions {
            margin-top: 15px;
            display: flex;
            gap: 10px;
        }

        .day-event-actions a {
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s ease;
        }

        .day-event-actions .edit-btn {
            background: linear-gradient(135deg, var(--primary-gradient-start), var(--primary-gradient-end));
            color: #fff;
            position: relative;
            overflow: hidden;
        }

        .day-event-actions .edit-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s ease;
        }

        .day-event-actions .edit-btn:hover::before {
            left: 100%;
        }

        .day-event-actions .edit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        .day-event-actions .delete-btn {
            background: var(--delete-color);
            color: #fff;
            position: relative;
            overflow: hidden;
        }

        .day-event-actions .delete-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s ease;
        }

        .day-event-actions .delete-btn:hover::before {
            left: 100%;
        }

        .day-event-actions .delete-btn:hover {
            background: var(--delete-hover-color);
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        /* Event form */
        .event-form {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 25px;
            margin-top: 20px;
            box-shadow: var(--shadow);
            animation: fadeIn 0.3s ease;
            transition: box-shadow 0.3s ease;
        }

        .event-form:hover {
            box-shadow: var(--shadow-hover);
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .event-form h3 {
            margin-bottom: 25px;
            color: var(--text-color);
            font-size: 1.5rem;
            text-align: center;
            position: relative;
            padding-bottom: 10px;
        }

        .event-form h3::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 50px;
            height: 3px;
            background: linear-gradient(90deg, var(--primary-gradient-start), var(--primary-gradient-end));
            border-radius: 3px;
            transition: width 0.3s ease;
        }

        .event-form:hover h3::after {
            width: 100px;
        }

        .form-group {
            margin-bottom: 20px;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-color);
            font-size: 0.95rem;
            transition: transform 0.3s ease, color 0.3s ease;
        }

        .form-group:focus-within label {
            color: var(--primary-gradient-start);
            transform: translateX(5px);
        }

        .form-group input[type="text"],
        .form-group input[type="datetime-local"],
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid rgba(116, 235, 213, 0.3);
            border-radius: var(--small-radius);
            background: var(--card-bg);
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-group input[type="text"]:focus,
        .form-group input[type="datetime-local"]:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            border-color: var(--primary-gradient-start);
            box-shadow: 0 0 0 3px rgba(116, 235, 213, 0.2);
            outline: none;
            transform: translateY(-2px);
        }

        .form-group textarea {
            min-height: 120px;
            resize: vertical;
            line-height: 1.5;
        }

        .form-group input[type="color"] {
            width: 60px;
            height: 60px;
            padding: 5px;
            border: none;
            border-radius: var(--small-radius);
            cursor: pointer;
            transition: transform 0.3s ease;
        }

        .form-group input[type="color"]:hover {
            transform: scale(1.1);
        }

        .color-preview {
            display: inline-block;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            margin-left: 10px;
            vertical-align: middle;
            border: 2px solid rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .color-preview:hover {
            transform: scale(1.2);
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.2);
        }

        .form-check {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            cursor: pointer;
            transition: transform 0.3s ease;
        }

        .form-check:hover {
            transform: translateX(5px);
        }

        .form-check input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
            accent-color: var(--primary-gradient-start);
            transition: transform 0.3s ease;
        }

        .form-check input[type="checkbox"]:hover {
            transform: scale(1.2);
        }

        .form-check label {
            font-weight: 500;
            color: var(--text-color);
            cursor: pointer;
        }

        .form-buttons {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
        }

        .form-buttons a {
            padding: 12px 25px;
            border-radius: 25px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .form-buttons a::before, .form-buttons button::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s ease;
        }

        .form-buttons a:hover::before, .form-buttons button:hover::before {
            left: 100%;
        }

        .form-buttons .btn {
            background: var(--taskbar-bg);
            color: var(--text-color);
        }

        .form-buttons .btn:hover {
            background: var(--taskbar-active-bg);
            color: #fff;
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
        }

        .form-buttons button {
            padding: 12px 30px;
            border-radius: 25px;
            font-weight: 600;
            background: linear-gradient(135deg, var(--primary-gradient-start), var(--primary-gradient-end));
            color: #fff;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .form-buttons button:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
            background: linear-gradient(135deg, var(--secondary-gradient-start), var(--secondary-gradient-end));
        }

        /* Success and error messages */
        .success-message, .error-message {
            padding: 15px;
            border-radius: var(--small-radius);
            margin-bottom: 20px;
            animation: slideIn 0.3s ease;
            text-align: center;
            font-weight: 500;
            position: relative;
            overflow: hidden;
        }

        .success-message::after, .error-message::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            animation: pulse 2s infinite;
        }

        .success-message::after {
            background: #2a9d8f;
        }

        .error-message::after {
            background: #ff4444;
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .success-message {
            background: rgba(116, 235, 213, 0.2);
            color: #2a9d8f;
            border-left: 4px solid #2a9d8f;
        }

        .error-message {
            background: rgba(255, 68, 68, 0.1);
            color: #ff4444;
            border-left: 4px solid #ff4444;
        }

        /* Sidebar for upcoming events */
        .calendar-layout {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .upcoming-events {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: var(--shadow);
            margin-top: 20px;
            transition: box-shadow 0.3s ease, transform 0.3s ease;
        }

        .upcoming-events:hover {
            box-shadow: var(--shadow-hover);
            transform: translateY(-3px);
        }

        .upcoming-events h3 {
            margin-bottom: 15px;
            color: var(--text-color);
            font-size: 1.2rem;
            position: relative;
            padding-bottom: 10px;
        }

        .upcoming-events h3::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 40px;
            height: 3px;
            background: linear-gradient(90deg, var(--primary-gradient-start), var(--primary-gradient-end));
            border-radius: 3px;
            transition: width 0.3s ease;
        }

        .upcoming-events:hover h3::after {
            width: 80px;
        }

        .upcoming-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .upcoming-event {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            border-radius: var(--small-radius);
            background: rgba(116, 235, 213, 0.05);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .upcoming-event::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s ease;
        }

        .upcoming-event:hover::before {
            left: 100%;
        }

        .upcoming-event:hover {
            transform: translateX(5px);
            background: rgba(116, 235, 213, 0.1);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        .upcoming-event-color {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            flex-shrink: 0;
            transition: transform 0.3s ease;
        }

        .upcoming-event:hover .upcoming-event-color {
            transform: scale(1.3);
        }

        .upcoming-event-details {
            flex: 1;
        }

        .upcoming-event-date {
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-bottom: 3px;
        }

        .upcoming-event-title {
            font-weight: 500;
            color: var(--text-color);
            font-size: 0.95rem;
            transition: transform 0.3s ease;
        }

        .upcoming-event:hover .upcoming-event-title {
            transform: translateX(3px);
        }

        /* Week view */
        .week-view {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 20px;
            margin-top: 20px;
            box-shadow: var(--shadow);
            transition: box-shadow 0.3s ease, transform 0.3s ease;
        }

        .week-view:hover {
            box-shadow: var(--shadow-hover);
            transform: translateY(-3px);
        }

        .week-header {
            display: flex;
            margin-bottom: 15px;
        }

        .week-day-header {
            flex: 1;
            text-align: center;
            font-weight: bold;
            padding: 10px;
            color: var(--text-color);
            transition: transform 0.3s ease;
        }

        .week-day-header:hover {
            transform: translateY(-2px);
        }

        .week-grid {
            display: flex;
            min-height: 500px;
        }

        .week-day {
            flex: 1;
            border-right: 1px solid rgba(0, 0, 0, 0.05);
            padding: 5px;
            position: relative;
            transition: background-color 0.3s ease;
            cursor: pointer;
        }

        .week-day:hover {
            background-color: rgba(116, 235, 213, 0.05);
        }

        .week-day:last-child {
            border-right: none;
        }

        .week-day.today {
            background: rgba(116, 235, 213, 0.05);
            position: relative;
        }

        .week-day.today::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: linear-gradient(90deg, var(--primary-gradient-start), var(--primary-gradient-end));
        }

        .week-day-number {
            text-align: center;
            font-weight: bold;
            margin-bottom: 10px;
            padding: 5px;
            color: var(--text-color);
            transition: transform 0.3s ease;
        }

        .week-day:hover .week-day-number {
            transform: scale(1.1);
        }

        .week-day.today .week-day-number {
            background: linear-gradient(135deg, var(--primary-gradient-start), var(--primary-gradient-end));
            color: #fff;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        /* Agenda view */
        .agenda-view {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            padding: 20px;
            margin-top: 20px;
            box-shadow: var(--shadow);
            transition: box-shadow 0.3s ease, transform 0.3s ease;
        }

        .agenda-view:hover {
            box-shadow: var(--shadow-hover);
            transform: translateY(-3px);
        }

        .agenda-date {
            font-weight: bold;
            margin: 20px 0 10px;
            padding-bottom: 5px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            color: var(--text-color);
            transition: transform 0.3s ease;
            position: relative;
        }

        .agenda-date::after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 0;
            width: 50px;
            height: 2px;
            background: linear-gradient(90deg, var(--primary-gradient-start), var(--primary-gradient-end));
            transition: width 0.3s ease;
        }

        .agenda-date:hover::after {
            width: 100px;
        }

        .agenda-date:hover {
            transform: translateX(5px);
        }

        .agenda-date:first-child {
            margin-top: 0;
        }

        .agenda-events {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        /* Add event button */
        .add-event-btn {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-gradient-start), var(--primary-gradient-end));
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            cursor: pointer;
            transition: all 0.3s ease;
            z-index: 100;
            text-decoration: none;
        }

        .add-event-btn:hover {
            transform: translateY(-5px) rotate(90deg);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.25);
            background: linear-gradient(135deg, var(--secondary-gradient-start), var(--secondary-gradient-end));
        }

        /* Important day marker */
        .important-marker {
            position: absolute;
            top: 5px;
            right: 5px;
            z-index: 5;
            animation: pulse 2s infinite;
        }

        /* Responsive styles */
        @media (min-width: 992px) {
            .calendar-layout {
                flex-direction: row;
            }

            .calendar-main {
                flex: 3;
            }

            .calendar-sidebar {
                flex: 1;
                position: sticky;
                top: 20px;
                height: fit-content;
            }
        }

        @media (max-width: 991px) {
            .calendar-sidebar {
                order: -1;
            }
        }

        @media (max-width: 768px) {
            .calendar-header {
                flex-direction: column;
                gap: 15px;
                padding: 15px 10px;
            }

            .calendar-nav, .calendar-view-toggle {
                width: 100%;
                justify-content: center;
            }

            .calendar-grid {
                gap: 5px;
            }

            .calendar-day {
                min-height: 80px;
                padding: 5px;
            }

            .event-container {
                max-height: 40px;
            }

            .day-number {
                font-size: 0.9rem;
                width: 22px;
                height: 22px;
                line-height: 22px;
            }

            .event-item {
                padding: 3px 5px;
                font-size: 0.7rem;
            }

            .event-time {
                font-size: 0.65rem;
            }

            .day-view-title {
                font-size: 1.1rem;
            }

            .day-event-title {
                font-size: 1.1rem;
            }

            .form-buttons {
                flex-direction: column;
            }

            .form-buttons a, .form-buttons button {
                width: 100%;
            }

            .add-event-btn {
                width: 50px;
                height: 50px;
                bottom: 20px;
                right: 20px;
                font-size: 1.2rem;
            }

            .taskbar-items {
                display: none;
                flex-direction: column;
                gap: 10px;
            }

            .taskbar-items.show {
                display: flex;
            }

            .menu-toggle {
                display: block;
                margin-bottom: 10px;
            }
            
            .event-tooltip {
                width: 200px;
            }
        }

        @media (max-width: 576px) {
            .calendar-day-header {
                padding: 5px;
                font-size: 0.8rem;
            }

            .calendar-title {
                font-size: 1.2rem;
            }

            .calendar-view-toggle {
                flex-wrap: wrap;
            }

            .calendar-view-toggle a {
                font-size: 0.8rem;
                padding: 6px 10px;
            }

            .calendar-day {
                min-height: 60px;
                padding: 3px;
            }

            .day-number {
                font-size: 0.8rem;
                width: 20px;
                height: 20px;
                line-height: 20px;
                margin-bottom: 3px;
            }

            .event-container {
                max-height: 30px;
                margin-top: 2px;
            }

            .event-item {
                padding: 2px 4px;
                font-size: 0.65rem;
                border-radius: 3px;
            }

            .event-time {
                display: none;
            }

            .day-view-header a {
                width: 30px;
                height: 30px;
            }

            .day-view-title {
                font-size: 1rem;
            }

            .day-event {
                padding: 10px;
            }

            .day-event-title {
                font-size: 1rem;
            }

            .day-event-description {
                font-size: 0.85rem;
            }

            .day-event-actions a {
                padding: 6px 12px;
                font-size: 0.8rem;
            }

            .event-form {
                padding: 15px;
            }

            .event-form h3 {
                font-size: 1.2rem;
            }

            .form-group label {
                font-size: 0.9rem;
            }

            .form-group input[type="text"],
            .form-group input[type="datetime-local"],
            .form-group textarea,
            .form-group select {
                padding: 10px;
                font-size: 0.9rem;
            }

            .form-buttons a, .form-buttons button {
                padding: 10px 20px;
                font-size: 0.9rem;
            }
            
            .context-menu {
                min-width: 150px;
            }
        }

        @media (hover: none) {
            .calendar-day {
                min-height: 70px;
            }

            .event-item {
                padding: 5px 8px;
            }

            .day-event-actions a {
                padding: 10px 15px;
            }

            .form-check {
                margin-bottom: 15px;
            }

            .form-check input[type="checkbox"] {
                width: 24px;
                height: 24px;
            }
        }
        
        /* Animation keyframes */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes pulse {
            0% {
                opacity: 0.6;
                transform: scale(1);
            }
            50% {
                opacity: 1;
                transform: scale(1.05);
            }
            100% {
                opacity: 0.6;
                transform: scale(1);
            }
        }
        
        @keyframes shimmer {
            0% {
                background-position: -200% 0;
            }
            100% {
                background-position: 200% 0;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container calendar-container">
        <h1><i class="fas fa-calendar-alt"></i> Calendar App</h1>

        <!-- Taskbar -->
        <div class="taskbar">
            <button class="menu-toggle">Menu <i class="fas fa-bars"></i></button>
            <div class="taskbar-items">
                <a href="index.php" class="taskbar-item"><i class="fas fa-home"></i> Home</a>
                <a href="dashboard.php" class="taskbar-item"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="calendar.php" class="taskbar-item active"><i class="fas fa-calendar-alt"></i> Calendar</a>
                <a href="profile.php" class="taskbar-item"><i class="fas fa-user"></i> Profile</a>
                <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>

        <!-- Hiển thị thông báo -->
        <?php if (!empty($message)): ?>
            <div class="success-message"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="error-message"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- Calendar Header -->
        <div class="calendar-header">
            <div class="calendar-nav">
                <a href="?month=<?php echo $month-1; ?>&year=<?php echo $year; ?>&view=<?php echo htmlspecialchars($current_view); ?>"><i class="fas fa-chevron-left"></i></a>
                <div class="calendar-title"><?php echo getMonthName($month) . ' ' . $year; ?></div>
                <a href="?month=<?php echo $month+1; ?>&year=<?php echo $year; ?>&view=<?php echo htmlspecialchars($current_view); ?>"><i class="fas fa-chevron-right"></i></a>
            </div>

            <div class="calendar-view-toggle">
                <a href="?view=month" class="<?php echo $current_view == 'month' ? 'active' : ''; ?>"><i class="fas fa-th"></i> Month</a>
                <a href="?view=week" class="<?php echo $current_view == 'week' ? 'active' : ''; ?>"><i class="fas fa-columns"></i> Week</a>
                <a href="?view=day&day=<?php echo date('Y-m-d'); ?>" class="<?php echo $current_view == 'day' ? 'active' : ''; ?>"><i class="fas fa-calendar-day"></i> Day</a>
                <a href="?view=agenda" class="<?php echo $current_view == 'agenda' ? 'active' : ''; ?>"><i class="fas fa-list"></i> Agenda</a>
            </div>
        </div>

        <div class="calendar-layout">
            <div class="calendar-main">
                <?php if ($current_view == 'day'): ?>
                    <!-- Day View -->
                    <div class="day-view">
                        <div class="day-view-header">
                            <a href="?view=day&day=<?php echo date('Y-m-d', strtotime($day_view_date . ' -1 day')); ?>"><i class="fas fa-chevron-left"></i></a>
                            <div class="day-view-title"><?php echo date('l, F j, Y', strtotime($day_view_date)); ?></div>
                            <a href="?view=day&day=<?php echo date('Y-m-d', strtotime($day_view_date . ' +1 day')); ?>"><i class="fas fa-chevron-right"></i></a>
                        </div>

                        <div class="day-view-events">
                            <?php if (empty($day_events)): ?>
                                <div style="text-align: center; padding: 30px; color: var(--text-secondary);">
                                    <i class="fas fa-calendar-day" style="font-size: 3rem; margin-bottom: 15px; opacity: 0.3;"></i>
                                    <p>No events scheduled for this day.</p>
                                    <a href="?add_event=1&start_date=<?php echo htmlspecialchars($day_view_date); ?>" style="display: inline-block; margin-top: 15px; color: var(--link-color);">
                                        <i class="fas fa-plus-circle"></i> Add an event
                                    </a>
                                </div>
                            <?php else: ?>
                                <?php foreach ($day_events as $event): ?>
                                    <div class="day-event" style="border-left-color: <?php echo htmlspecialchars($event['event_color']); ?>; background-color: <?php echo htmlspecialchars($event['event_color']); ?>10;">
                                        <div class="day-event-time">
                                            <i class="far fa-clock" style="color: <?php echo htmlspecialchars($event['event_color']); ?>;"></i>
                                            <?php if ($event['is_all_day']): ?>
                                                All Day
                                            <?php else: ?>
                                                <?php echo date('g:i A', strtotime($event['start_datetime'])); ?> - 
                                                <?php echo date('g:i A', strtotime($event['end_datetime'])); ?>
                                            <?php endif; ?>
                                            <?php if ($event['is_recurring']): ?>
                                                <span class="recurring-badge" title="<?php echo ucfirst($event['recurrence_pattern']); ?> recurring event">
                                                    <i class="fas fa-sync-alt"></i>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="day-event-title"><?php echo htmlspecialchars($event['title']); ?></div>
                                        <div class="day-event-description"><?php echo nl2br(htmlspecialchars($event['description'])); ?></div>
                                        <div class="day-event-actions">
                                            <a href="?edit_event=<?php echo $event['id']; ?>" class="edit-btn"><i class="fas fa-edit"></i> Edit</a>
                                            <a href="?delete_event=<?php echo $event['id']; ?>" class="delete-btn" onclick="return confirm('Are you sure you want to delete this event?');"><i class="fas fa-trash"></i> Delete</a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php elseif ($current_view == 'week'): ?>
                    <!-- Week View -->
                    <div class="week-view">
                        <div class="week-header">
                            <?php
                            $today = new DateTime();
                            $weekday = $today->format('N');
                            $monday = clone $today;
                            $monday->modify('-' . ($weekday - 1) . ' days');
                            
                            for ($i = 0; $i < 7; $i++) {
                                $day = clone $monday;
                                $day->modify('+' . $i . ' days');
                                echo '<div class="week-day-header">' . $day->format('D') . '</div>';
                            }
                            ?>
                        </div>

                        <div class="week-grid">
                            <?php
                            for ($i = 0; $i < 7; $i++) {
                                $day = clone $monday;
                                $day->modify('+' . $i . ' days');
                                $is_today = $day->format('Y-m-d') == date('Y-m-d');
                                $day_class = $is_today ? 'week-day today' : 'week-day';
                                $day_date = $day->format('Y-m-d');
                                
                                echo '<div class="' . $day_class . '" data-date="' . $day_date . '">';
                                echo '<div class="week-day-number">' . $day->format('j') . '</div>';
                                
                                $sql_day = "SELECT * FROM calendar_events 
                                            WHERE user_id = '{$_SESSION['user_id']}' 
                                            AND ((DATE(start_datetime) <= '$day_date' AND DATE(end_datetime) >= '$day_date') 
                                                OR (is_recurring = 1))
                                            ORDER BY start_datetime ASC";
                                
                                $result_day = safeQuery($conn, $sql_day);
                                $day_events_list = [];
                                
                                if ($result_day && mysqli_num_rows($result_day) > 0) {
                                    while ($row = mysqli_fetch_assoc($result_day)) {
                                        if ($row['is_recurring'] && !checkRecurringEvent($day_date, $row)) {
                                            continue;
                                        }
                                        $day_events_list[] = $row;
                                    }
                                }
                                
                                if (!empty($day_events_list)) {
                                    echo '<div class="event-container">';
                                    foreach ($day_events_list as $event) {
                                        $start_time = date('H:i', strtotime($event['start_datetime']));
                                        $end_time = date('H:i', strtotime($event['end_datetime']));
                                        $is_recurring = $event['is_recurring'] ? '<i class="fas fa-sync-alt"></i> ' : '';
                                        $is_all_day = $event['is_all_day'] ? 'All Day' : "$start_time - $end_time";
                                        
                                        // Enhanced tooltip data
                                        $tooltip_data = htmlspecialchars(json_encode([
                                            'title' => $event['title'],
                                            'description' => $event['description'],
                                            'start' => $is_all_day ? 'All Day' : date('g:i A', strtotime($event['start_datetime'])),
                                            'end' => $is_all_day ? '' : date('g:i A', strtotime($event['end_datetime'])),
                                            'date' => date('l, F j, Y', strtotime($day_date)),
                                            'recurring' => $event['is_recurring'] ? ucfirst($event['recurrence_pattern']) : '',
                                            'color' => $event['event_color']
                                        ]));
                                        
                                        echo '<div class="event-item" style="background-color: ' . htmlspecialchars($event['event_color']) . ';" 
                                                  data-event-id="' . $event['id'] . '"
                                                  data-tooltip="' . $tooltip_data . '">
                                                  <span class="event-time">' . $start_time . '</span>
                                                  <span class="event-title">' . htmlspecialchars(substr($event['title'], 0, 15)) . (strlen($event['title']) > 15 ? '...' : '') . '</span>
                                              </div>';
                                    }
                                    echo '</div>';
                                    echo '<a href="?view=day&day=' . htmlspecialchars($day_date) . '" class="view-day-link">View All</a>';
                                }
                                echo '</div>';
                            }
                            ?>
                        </div>
                    </div>

                <?php elseif ($current_view == 'agenda'): ?>
                    <!-- Agenda View -->
                    <div class="agenda-view">
                        <?php
                        $today = date('Y-m-d');
                        $thirty_days_later = date('Y-m-d', strtotime('+30 days'));
                        
                        $sql_agenda = "SELECT * FROM calendar_events 
                                      WHERE user_id = '{$_SESSION['user_id']}' 
                                      AND ((DATE(start_datetime) BETWEEN '$today' AND '$thirty_days_later') 
                                          OR (is_recurring = 1))
                                      ORDER BY start_datetime ASC
                                      LIMIT 100";
                        
                        $result_agenda = safeQuery($conn, $sql_agenda);
                        $agenda_events = [];
                        
                        if ($result_agenda && mysqli_num_rows($result_agenda) > 0) {
                            while ($row = mysqli_fetch_assoc($result_agenda)) {
                                $agenda_events[] = $row;
                            }
                        }
                        
                        if (empty($agenda_events)) {
                            echo '<div style="text-align: center; padding: 30px; color: var(--text-secondary);">
                                    <i class="fas fa-calendar" style="font-size: 3rem; margin-bottom: 15px; opacity: 0.3;"></i>
                                    <p>No upcoming events in the next 30 days.</p>
                                    <a href="?add_event=1" style="display: inline-block; margin-top: 15px; color: var(--link-color);">
                                        <i class="fas fa-plus-circle"></i> Add an event
                                    </a>
                                  </div>';
                        } else {
                            $grouped_events = [];
                            
                            foreach ($agenda_events as $event) {
                                $event_date = date('Y-m-d', strtotime($event['start_datetime']));
                                
                                if ($event['is_recurring']) {
                                    $check_dates = [];
                                    for ($i = 0; $i < 30; $i++) {
                                        $check_date = date('Y-m-d', strtotime("$today +$i days"));
                                        if (checkRecurringEvent($check_date, $event)) {
                                            $check_dates[] = $check_date;
                                        }
                                    }
                                    foreach ($check_dates as $check_date) {
                                        if (!isset($grouped_events[$check_date])) {
                                            $grouped_events[$check_date] = [];
                                        }
                                        $event_copy = $event;
                                        $event_copy['start_datetime'] = date('Y-m-d H:i:s', strtotime("$check_date " . date('H:i:s', strtotime($event['start_datetime']))));
                                        $event_copy['end_datetime'] = date('Y-m-d H:i:s', strtotime("$check_date " . date('H:i:s', strtotime($event['end_datetime']))));
                                        $grouped_events[$check_date][] = $event_copy;
                                    }
                                } else {
                                    if (!isset($grouped_events[$event_date])) {
                                        $grouped_events[$event_date] = [];
                                    }
                                    $grouped_events[$event_date][] = $event;
                                }
                            }
                            
                            ksort($grouped_events);
                            
                            foreach ($grouped_events as $date => $date_events) {
                                echo '<div class="agenda-date">' . date('l, F j, Y', strtotime($date)) . '</div>';
                                echo '<div class="agenda-events">';
                                
                                foreach ($date_events as $event) {
                                    echo '<div class="day-event" style="border-left-color: ' . htmlspecialchars($event['event_color']) . '; background-color: ' . htmlspecialchars($event['event_color']) . '10;">
                                            <div class="day-event-time">
                                                <i class="far fa-clock" style="color: ' . htmlspecialchars($event['event_color']) . ';"></i>
                                                ' . ($event['is_all_day'] ? 'All Day' : date('g:i A', strtotime($event['start_datetime'])) . ' - ' . date('g:i A', strtotime($event['end_datetime']))) . '
                                                ' . ($event['is_recurring'] ? '<span class="recurring-badge" title="' . ucfirst($event['recurrence_pattern']) . ' recurring event"><i class="fas fa-sync-alt"></i></span>' : '') . '
                                            </div>
                                            <div class="day-event-title">' . htmlspecialchars($event['title']) . '</div>
                                            <div class="day-event-description">' . nl2br(htmlspecialchars($event['description'])) . '</div>
                                            <div class="day-event-actions">
                                                <a href="?edit_event=' . $event['id'] . '" class="edit-btn"><i class="fas fa-edit"></i> Edit</a>
                                                <a href="?delete_event=' . $event['id'] . '" class="delete-btn" onclick="return confirm(\'Are you sure you want to delete this event?\');"><i class="fas fa-trash"></i> Delete</a>
                                            </div>
                                        </div>';
                                }
                                echo '</div>';
                            }
                        }
                        ?>
                    </div>

                <?php elseif (isset($_GET['add_event']) || isset($_GET['edit_event'])): ?>
                    <!-- Event Form -->
                    <div class="event-form">
                        <h3><?php echo isset($_GET['edit_event']) ? 'Edit Event' : 'Add New Event'; ?></h3>
                        <form method="post" action="">
                            <?php if (isset($_GET['edit_event'])): ?>
                                <input type="hidden" name="event_id" value="<?php echo htmlspecialchars($edit_event['id']); ?>">
                            <?php endif; ?>

                            <div class="form-group">
                                <label for="title">Event Title</label>
                                <input type="text" id="title" name="title" required value="<?php echo isset($edit_event) ? htmlspecialchars($edit_event['title']) : ''; ?>" placeholder="Enter event title">
                            </div>

                            <div class="form-group">
                                <label for="description">Description</label>
                                <textarea id="description" name="description" placeholder="Enter event description"><?php echo isset($edit_event) ? htmlspecialchars($edit_event['description']) : ''; ?></textarea>
                            </div>

                            <div class="form-check">
                                <input type="checkbox" id="is_all_day" name="is_all_day" <?php echo (isset($edit_event) && $edit_event['is_all_day']) ? 'checked' : ''; ?>>
                                <label for="is_all_day">All Day Event</label>
                            </div>

                            <div class="form-group">
                                <label for="start_datetime">Start Date & Time</label>
                                <input type="datetime-local" id="start_datetime" name="start_datetime" required 
                                       value="<?php echo isset($edit_event) ? date('Y-m-d\TH:i', strtotime($edit_event['start_datetime'])) : (isset($_GET['start_date']) ? date('Y-m-d\TH:i', strtotime($_GET['start_date'] . ' 09:00:00')) : ''); ?>">
                            </div>

                            <div class="form-group">
                                <label for="end_datetime">End Date & Time</label>
                                <input type="datetime-local" id="end_datetime" name="end_datetime" required 
                                       value="<?php echo isset($edit_event) ? date('Y-m-d\TH:i', strtotime($edit_event['end_datetime'])) : (isset($_GET['start_date']) ? date('Y-m-d\TH:i', strtotime($_GET['start_date'] . ' 10:00:00')) : ''); ?>">
                            </div>

                            <div class="form-group">
                                <label for="event_color">Event Color</label>
                                <div style="display: flex; align-items: center;">
                                    <input type="color" id="event_color" name="event_color" 
                                           value="<?php echo isset($edit_event) ? htmlspecialchars($edit_event['event_color']) : '#74ebd5'; ?>">
                                    <div class="color-preview" id="color_preview" style="background-color: <?php echo isset($edit_event) ? htmlspecialchars($edit_event['event_color']) : '#74ebd5'; ?>;"></div>
                                </div>
                            </div>

                            <div class="form-check">
                                <input type="checkbox" id="is_recurring" name="is_recurring" <?php echo (isset($edit_event) && $edit_event['is_recurring']) ? 'checked' : ''; ?>>
                                <label for="is_recurring">Recurring Event</label>
                            </div>

                            <div class="form-group" id="recurrence_group" style="<?php echo (isset($edit_event) && $edit_event['is_recurring']) ? '' : 'display: none;'; ?>">
                                <label for="recurrence_pattern">Recurrence Pattern</label>
                                <select id="recurrence_pattern" name="recurrence_pattern">
                                    <option value="daily" <?php echo (isset($edit_event) && $edit_event['recurrence_pattern'] == 'daily') ? 'selected' : ''; ?>>Daily</option>
                                    <option value="weekly" <?php echo (isset($edit_event) && $edit_event['recurrence_pattern'] == 'weekly') ? 'selected' : ''; ?>>Weekly</option>
                                    <option value="monthly" <?php echo (isset($edit_event) && $edit_event['recurrence_pattern'] == 'monthly') ? 'selected' : ''; ?>>Monthly</option>
                                    <option value="yearly" <?php echo (isset($edit_event) && $edit_event['recurrence_pattern'] == 'yearly') ? 'selected' : ''; ?>>Yearly</option>
                                </select>
                            </div>

                            <div class="form-buttons">
                                <a href="calendar.php" class="btn">Cancel</a>
                                <button type="submit" name="<?php echo isset($_GET['edit_event']) ? 'update_event' : 'add_event'; ?>">
                                    <?php echo isset($_GET['edit_event']) ? 'Update Event' : 'Add Event'; ?>
                                </button>
                            </div>
                        </form>
                    </div>

                <?php else: ?>
                    <!-- Month View -->
                    <div class="calendar-grid">
                        <!-- Day headers -->
                        <div class="calendar-day-header">Mon</div>
                        <div class="calendar-day-header">Tue</div>
                        <div class="calendar-day-header">Wed</div>
                        <div class="calendar-day-header">Thu</div>
                        <div class="calendar-day-header">Fri</div>
                        <div class="calendar-day-header">Sat</div>
                        <div class="calendar-day-header">Sun</div>

                        <!-- Calendar days -->
                        <?php
                        // Previous month days
                        $prev_month_days = $first_day_of_week - 1;
                        $prev_month = $month - 1;
                        $prev_year = $year;
                        
                        if ($prev_month < 1) {
                            $prev_month = 12;
                            $prev_year--;
                        }
                        
                        $prev_month_last_day = date('t', mktime(0, 0, 0, $prev_month, 1, $prev_year));
                        
                        for ($i = $prev_month_last_day - $prev_month_days + 1; $i <= $prev_month_last_day; $i++) {
                            echo '<div class="calendar-day other-month">';
                            echo '<div class="day-number">' . $i . '</div>';
                            echo '</div>';
                        }
                        
                        // Current month days
                        $today_day = date('j');
                        $today_month = date('n');
                        $today_year = date('Y');
                        
                        for ($day = 1; $day <= $number_of_days; $day++) {
                            $is_today = ($day == $today_day && $month == $today_month && $year == $today_year);
                            $day_class = $is_today ? 'calendar-day today' : 'calendar-day';
                            $day_date = sprintf("%04d-%02d-%02d", $year, $month, $day);
                            
                            echo '<div class="' . $day_class . '" data-date="' . $day_date . '">';
                            echo '<div class="day-number">' . $day . '</div>';
                            
                            $day_events = hasEvents($day, $events, $month, $year);
                            if (!empty($day_events)) {
                                echo '<div class="event-container">';
                                echo getEventDetails($day, $events, $month, $year);
                                echo '</div>';
                                echo '<a href="?view=day&day=' . $day_date . '" class="view-day-link">View All</a>';
                            }
                            echo '</div>';
                        }
                        
                        // Next month days
                        $total_days_shown = $prev_month_days + $number_of_days;
                        $next_month_days = 42 - $total_days_shown;
                        
                        for ($i = 1; $i <= $next_month_days; $i++) {
                            echo '<div class="calendar-day other-month">';
                            echo '<div class="day-number">' . $i . '</div>';
                            echo '</div>';
                        }
                        ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Upcoming Events Sidebar -->
            <div class="calendar-sidebar">
                <div class="upcoming-events">
                    <h3>Upcoming Events</h3>
                    <div class="upcoming-list">
                        <?php if (empty($upcoming_events)): ?>
                            <p style="color: var(--text-secondary); text-align: center; padding: 15px 0;">
                                No upcoming events
                            </p>
                        <?php else: ?>
                            <?php foreach ($upcoming_events as $event): ?>
                                <a href="?view=day&day=<?php echo isset($event['next_occurrence']) ? htmlspecialchars($event['next_occurrence']) : date('Y-m-d', strtotime($event['start_datetime'])); ?>" class="upcoming-event">
                                    <div class="upcoming-event-color" style="background-color: <?php echo htmlspecialchars($event['event_color']); ?>;"></div>
                                    <div class="upcoming-event-details">
                                        <div class="upcoming-event-date">
                                            <?php 
                                            if (isset($event['next_occurrence'])) {
                                                echo date('D, M j', strtotime($event['next_occurrence']));
                                            } else {
                                                echo date('D, M j', strtotime($event['start_datetime']));
                                            }
                                            if (!$event['is_all_day']) {
                                                echo ' · ' . date('g:i A', strtotime($event['start_datetime']));
                                            }
                                            if ($event['is_recurring']) {
                                                echo ' <i class="fas fa-sync-alt" style="font-size: 0.7rem;" title="' . ucfirst($event['recurrence_pattern']) . ' recurring event"></i>';
                                            }
                                            ?>
                                        </div>
                                        <div class="upcoming-event-title"><?php echo htmlspecialchars($event['title']); ?></div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add Event Button -->
        <a href="?add_event=1" class="add-event-btn" title="Add New Event">
            <i class="fas fa-plus"></i>
        </a>

        <!-- Event Tooltip -->
        <div class="event-tooltip" id="eventTooltip">
            <div class="tooltip-title"></div>
            <div class="tooltip-time"></div>
            <div class="tooltip-description"></div>
            <div class="tooltip-recurring"></div>
        </div>

        <!-- Context Menu -->
        <div class="context-menu" id="dayContextMenu">
            <div class="context-menu-item" id="viewDayMenuItem">
                <i class="fas fa-eye"></i> View Day
            </div>
            <div class="context-menu-item" id="addEventMenuItem">
                <i class="fas fa-plus"></i> Add Event
            </div>
            <div class="context-menu-item" id="addTaskMenuItem">
                <i class="fas fa-tasks"></i> Add Task
            </div>
            <div class="context-menu-divider"></div>
            <div class="context-menu-item" id="markImportantMenuItem">
                <i class="fas fa-star"></i> Mark as Important
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Toggle menu for mobile
            const menuToggle = document.querySelector('.menu-toggle');
            const taskbarItems = document.querySelector('.taskbar-items');
            
            if (menuToggle) {
                menuToggle.addEventListener('click', function() {
                    taskbarItems.classList.toggle('show');
                });
            }
            
            // Toggle recurrence options
            const isRecurringCheckbox = document.getElementById('is_recurring');
            const recurrenceGroup = document.getElementById('recurrence_group');
            
            if (isRecurringCheckbox && recurrenceGroup) {
                isRecurringCheckbox.addEventListener('change', function() {
                    recurrenceGroup.style.display = this.checked ? 'block' : 'none';
                });
            }
            
            // Event dots click to view day
            const eventItems = document.querySelectorAll('.event-item');
            eventItems.forEach(item => {
                item.addEventListener('click', function(e) {
                    const eventId = this.getAttribute('data-event-id');
                    window.location.href = '?edit_event=' + eventId;
                    e.stopPropagation();
                });
            });
            
            // Update color preview
            const colorInput = document.getElementById('event_color');
            const colorPreview = document.getElementById('color_preview');
            
            if (colorInput && colorPreview) {
                colorInput.addEventListener('input', function() {
                    colorPreview.style.backgroundColor = this.value;
                });
            }
            
            // Auto-hide messages after 5 seconds
            const messages = document.querySelectorAll('.success-message, .error-message');
            if (messages.length > 0) {
                setTimeout(function() {
                    messages.forEach(msg => {
                        msg.style.opacity = '0';
                        msg.style.transition = 'opacity 0.5s ease';
                        setTimeout(function() {
                            msg.style.display = 'none';
                        }, 500);
                    });
                }, 5000);
            }
            
            // Smooth scrolling for mobile
            if (window.innerWidth <= 768) {
                const eventContainers = document.querySelectorAll('.event-container');
                eventContainers.forEach(container => {
                    container.style.scrollBehavior = 'smooth';
                });
            }
            
            // Event tooltip functionality
            const eventTooltip = document.getElementById('eventTooltip');
            const tooltipTitle = eventTooltip.querySelector('.tooltip-title');
            const tooltipTime = eventTooltip.querySelector('.tooltip-time');
            const tooltipDescription = eventTooltip.querySelector('.tooltip-description');
            const tooltipRecurring = eventTooltip.querySelector('.tooltip-recurring');
            
            document.querySelectorAll('.event-item').forEach(item => {
                item.addEventListener('mouseenter', function(e) {
                    const tooltipData = JSON.parse(this.getAttribute('data-tooltip'));
                    
                    tooltipTitle.textContent = tooltipData.title;
                    
                    let timeText = tooltipData.date;
                    if (tooltipData.start === 'All Day') {
                        timeText += ' (All Day)';
                    } else {
                        timeText += ' • ' + tooltipData.start;
                        if (tooltipData.end) {
                            timeText += ' - ' + tooltipData.end;
                        }
                    }
                    tooltipTime.innerHTML = '<i class="far fa-clock"></i> ' + timeText;
                    
                    tooltipDescription.textContent = tooltipData.description || 'No description';
                    
                    if (tooltipData.recurring) {
                        tooltipRecurring.innerHTML = '<i class="fas fa-sync-alt"></i> ' + tooltipData.recurring + ' recurring event';
                        tooltipRecurring.style.display = 'flex';
                    } else {
                        tooltipRecurring.style.display = 'none';
                    }
                    
                    // Position the tooltip
                    const rect = this.getBoundingClientRect();
                    const tooltipWidth = 250;
                    const windowWidth = window.innerWidth;
                    
                    // Check if tooltip would go off the right edge
                    if (rect.right + tooltipWidth > windowWidth) {
                        eventTooltip.style.left = (rect.left - tooltipWidth) + 'px';
                    } else {
                        eventTooltip.style.left = rect.right + 'px';
                    }
                    
                    eventTooltip.style.top = rect.top + 'px';
                    eventTooltip.classList.add('show');
                });
                
                item.addEventListener('mouseleave', function() {
                    eventTooltip.classList.remove('show');
                });
            });
            
            // Context menu for calendar days
            const dayContextMenu = document.getElementById('dayContextMenu');
            const calendarDays = document.querySelectorAll('.calendar-day, .week-day');
            
            // Context menu items
            const viewDayMenuItem = document.getElementById('viewDayMenuItem');
            const addEventMenuItem = document.getElementById('addEventMenuItem');
            const addTaskMenuItem = document.getElementById('addTaskMenuItem');
            const markImportantMenuItem = document.getElementById('markImportantMenuItem');
            
            let activeDate = null;
            
            // Show context menu on right click
            calendarDays.forEach(day => {
                day.addEventListener('contextmenu', function(e) {
                    e.preventDefault();
                    
                    // Get the date from the day element
                    activeDate = this.getAttribute('data-date');
                    
                    // Position the context menu
                    dayContextMenu.style.left = e.pageX + 'px';
                    dayContextMenu.style.top = e.pageY + 'px';
                    dayContextMenu.style.display = 'block';
                });
            });
            
            // Hide context menu when clicking elsewhere
            document.addEventListener('click', function() {
                dayContextMenu.style.display = 'none';
            });
            
            // Context menu item actions
            viewDayMenuItem.addEventListener('click', function() {
                if (activeDate) {
                    window.location.href = '?view=day&day=' + activeDate;
                }
            });
            
            addEventMenuItem.addEventListener('click', function() {
                if (activeDate) {
                    window.location.href = '?add_event=1&start_date=' + activeDate;
                }
            });
            
            addTaskMenuItem.addEventListener('click', function() {
                if (activeDate) {
                    // You can implement task functionality or show a message
                    alert('Task feature will be available soon!');
                }
            });
            
            markImportantMenuItem.addEventListener('click', function() {
                if (activeDate) {
                    // You can implement marking days as important
                    alert('This day has been marked as important!');
                    
                    // Visual feedback - add a star to the day
                    calendarDays.forEach(day => {
                        if (day.getAttribute('data-date') === activeDate) {
                            // Check if star already exists
                            if (!day.querySelector('.important-marker')) {
                                const marker = document.createElement('div');
                                marker.className = 'important-marker';
                                marker.innerHTML = '<i class="fas fa-star" style="color: gold; position: absolute; top: 5px; right: 5px; font-size: 12px;"></i>';
                                day.appendChild(marker);
                            }
                        }
                    });
                }
            });
            
            // Add animations to elements
            const animateElements = document.querySelectorAll('.calendar-header, .day-view, .week-view, .agenda-view, .event-form, .upcoming-events');
            animateElements.forEach((element, index) => {
                element.style.animation = `fadeInUp 0.3s ease forwards ${index * 0.1}s`;
                element.style.opacity = '0';
            });
            
            // Add hover effect to calendar days
            calendarDays.forEach(day => {
                day.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-5px)';
                    this.style.boxShadow = '0 8px 15px rgba(0, 0, 0, 0.1)';
                    
                    // Highlight the day number
                    const dayNumber = this.querySelector('.day-number');
                    if (dayNumber) {
                        dayNumber.style.transform = 'scale(1.2)';
                    }
                });
                
                day.addEventListener('mouseleave', function() {
                    this.style.transform = '';
                    this.style.boxShadow = '';
                    
                    // Reset the day number
                    const dayNumber = this.querySelector('.day-number');
                    if (dayNumber) {
                        dayNumber.style.transform = '';
                    }
                });
                
                // Make days clickable to view day
                day.addEventListener('click', function() {
                    const date = this.getAttribute('data-date');
                    if (date) {
                        window.location.href = '?view=day&day=' + date;
                    }
                });
            });
        });
    </script>
</body>
</html>

