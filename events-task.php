<?php
ob_start();
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Thiết lập múi giờ Việt Nam
date_default_timezone_set('Asia/Ho_Chi_Minh');

require_once 'db_config.php';

// Simple QR Code generation using QR Server API (more reliable)
function generateQRCode($data, $size = 200) {
    // Try multiple QR code APIs for better reliability
    $apis = [
        "https://api.qrserver.com/v1/create-qr-code/?size={$size}x{$size}&data=" . urlencode($data),
        "https://chart.googleapis.com/chart?chs={$size}x{$size}&cht=qr&chl=" . urlencode($data)
    ];
    
    return $apis;
}

// Function to save QR code image from URLs with fallback
function saveQRCodeImage($urls, $filepath) {
    // Ensure directory exists and is writable
    $dir = dirname($filepath);
    if (!file_exists($dir)) {
        if (!mkdir($dir, 0755, true)) {
            error_log("Failed to create directory: $dir");
            return false;
        }
    }
    
    if (!is_writable($dir)) {
        error_log("Directory not writable: $dir");
        return false;
    }
    
    // Try each URL until one works
    foreach ($urls as $url) {
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'user_agent' => 'Mozilla/5.0 (compatible; QR-Generator/1.0)'
            ]
        ]);
        
        $imageData = @file_get_contents($url, false, $context);
        if ($imageData !== false && strlen($imageData) > 100) { // Basic validation
            $result = file_put_contents($filepath, $imageData);
            if ($result !== false) {
                error_log("QR code generated successfully using: $url");
                return true;
            }
        }
        error_log("Failed to generate QR code using: $url");
    }
    
    // If all APIs fail, create a simple text-based placeholder
    return createTextPlaceholder($filepath, basename($filepath, '.png'));
}

// Create a simple text placeholder if QR generation fails
function createTextPlaceholder($filepath, $text) {
    $width = 200;
    $height = 200;
    
    // Create a simple image with text
    $image = imagecreate($width, $height);
    if (!$image) return false;
    
    $bg_color = imagecolorallocate($image, 255, 255, 255);
    $text_color = imagecolorallocate($image, 0, 0, 0);
    $border_color = imagecolorallocate($image, 200, 200, 200);
    
    // Draw border
    imagerectangle($image, 0, 0, $width-1, $height-1, $border_color);
    
    // Add text
    $font_size = 3;
    $text_width = imagefontwidth($font_size) * strlen($text);
    $text_height = imagefontheight($font_size);
    $x = ($width - $text_width) / 2;
    $y = ($height - $text_height) / 2;
    
    imagestring($image, $font_size, $x, $y, $text, $text_color);
    
    // Add QR label
    $qr_text = "QR CODE";
    $qr_width = imagefontwidth(2) * strlen($qr_text);
    $qr_x = ($width - $qr_width) / 2;
    imagestring($image, 2, $qr_x, $y - 30, $qr_text, $text_color);
    
    $result = imagepng($image, $filepath);
    imagedestroy($image);
    
    if ($result) {
        error_log("Created text placeholder for QR code: $filepath");
        return true;
    }
    
    return false;
}

// Check login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Create tables for check-in system
$sql_events = "CREATE TABLE IF NOT EXISTS events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    event_date DATE NOT NULL,
    start_time TIME,
    end_time TIME,
    location VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_active TINYINT(1) DEFAULT 1,
    FOREIGN KEY (user_id) REFERENCES users(id)
)";
mysqli_query($conn, $sql_events) or die("Error creating events: " . mysqli_error($conn));

$sql_attendees = "CREATE TABLE IF NOT EXISTS attendees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(20),
    student_id VARCHAR(50),
    class VARCHAR(50),
    qr_code VARCHAR(255) UNIQUE,
    qr_path VARCHAR(255),
    status ENUM('pending', 'checked_in', 'checked_out') DEFAULT 'pending',
    checkin_time TIMESTAMP NULL,
    checkout_time TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
)";
mysqli_query($conn, $sql_attendees) or die("Error creating attendees: " . mysqli_error($conn));

$sql_checkin_logs = "CREATE TABLE IF NOT EXISTS checkin_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    attendee_id INT NOT NULL,
    action ENUM('check_in', 'check_out') NOT NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45),
    user_agent VARCHAR(255),
    FOREIGN KEY (attendee_id) REFERENCES attendees(id) ON DELETE CASCADE
)";
mysqli_query($conn, $sql_checkin_logs) or die("Error creating checkin_logs: " . mysqli_error($conn));

// Debug QR code generation
if (isset($_GET['test_qr'])) {
    $test_code = 'TEST_' . uniqid();
    $test_path = 'uploads/qr_codes/test_' . time() . '.png';
    
    echo "<h3>QR Code Generation Test</h3>";
    echo "<p>Testing QR code: $test_code</p>";
    echo "<p>Target path: $test_path</p>";
    
    // Check directory
    $dir = dirname($test_path);
    echo "<p>Directory exists: " . (file_exists($dir) ? 'YES' : 'NO') . "</p>";
    echo "<p>Directory writable: " . (is_writable($dir) ? 'YES' : 'NO') . "</p>";
    echo "<p>Current directory permissions: " . (file_exists($dir) ? substr(sprintf('%o', fileperms($dir)), -4) : 'N/A') . "</p>";
    
    // Test QR generation
    $qr_urls = generateQRCode($test_code);
    echo "<p>QR URLs to try:</p><ul>";
    foreach ($qr_urls as $url) {
        echo "<li><a href='$url' target='_blank'>$url</a></li>";
    }
    echo "</ul>";
    
    $result = saveQRCodeImage($qr_urls, $test_path);
    echo "<p>QR generation result: " . ($result ? 'SUCCESS' : 'FAILED') . "</p>";
    
    if ($result && file_exists($test_path)) {
        echo "<p>File size: " . filesize($test_path) . " bytes</p>";
        echo "<p><img src='$test_path' alt='Test QR Code' style='border: 1px solid #ccc;'></p>";
    }
    
    // Test file_get_contents
    echo "<p>Testing file_get_contents with Google:</p>";
    $test_url = "https://www.google.com";
    $test_content = @file_get_contents($test_url);
    echo "<p>file_get_contents test: " . ($test_content !== false ? 'SUCCESS' : 'FAILED') . "</p>";
    
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    
    // Create event
    if ($_POST['action'] === 'create_event') {
        $name = mysqli_real_escape_string($conn, $_POST['name']);
        $description = mysqli_real_escape_string($conn, $_POST['description']);
        $event_date = mysqli_real_escape_string($conn, $_POST['event_date']);
        $start_time = mysqli_real_escape_string($conn, $_POST['start_time']);
        $end_time = mysqli_real_escape_string($conn, $_POST['end_time']);
        $location = mysqli_real_escape_string($conn, $_POST['location']);
        
        $sql = "INSERT INTO events (user_id, name, description, event_date, start_time, end_time, location) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "issssss", $user_id, $name, $description, $event_date, $start_time, $end_time, $location);
        
        if (mysqli_stmt_execute($stmt)) {
            $event_id = mysqli_insert_id($conn);
            echo json_encode(['success' => true, 'message' => 'Event created successfully!', 'event_id' => $event_id]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error creating event: ' . mysqli_error($conn)]);
        }
        exit;
    }
    
    // Add single attendee
    if ($_POST['action'] === 'add_attendee') {
        $event_id = (int)$_POST['event_id'];
        $name = mysqli_real_escape_string($conn, $_POST['name']);
        $email = mysqli_real_escape_string($conn, $_POST['email']);
        $phone = mysqli_real_escape_string($conn, $_POST['phone']);
        $student_id = mysqli_real_escape_string($conn, $_POST['student_id']);
        $class = mysqli_real_escape_string($conn, $_POST['class']);
        
        // Generate unique QR code
        $qr_code = 'CHK_' . $event_id . '_' . uniqid();
        $qr_path = 'uploads/qr_codes/' . $qr_code . '.png';
        
        // Create QR code directory if not exists with proper permissions
        if (!file_exists('uploads/qr_codes/')) {
            if (!mkdir('uploads/qr_codes/', 0755, true)) {
                echo json_encode(['success' => false, 'message' => 'Failed to create QR code directory']);
                exit;
            }
        }
        
        // Generate QR code using multiple APIs
        $qr_urls = generateQRCode($qr_code);
        
        if (saveQRCodeImage($qr_urls, $qr_path)) {
            $sql = "INSERT INTO attendees (event_id, name, email, phone, student_id, class, qr_code, qr_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "isssssss", $event_id, $name, $email, $phone, $student_id, $class, $qr_code, $qr_path);
            
            if (mysqli_stmt_execute($stmt)) {
                echo json_encode(['success' => true, 'message' => 'Đã thêm người tham gia thành công!']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Lỗi khi thêm người tham gia: ' . mysqli_error($conn)]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Lỗi khi tạo mã QR']);
        }
        exit;
    }
    
    // Edit attendee
    if ($_POST['action'] === 'edit_attendee') {
        $attendee_id = (int)$_POST['attendee_id'];
        $name = mysqli_real_escape_string($conn, $_POST['name']);
        $email = mysqli_real_escape_string($conn, $_POST['email']);
        $phone = mysqli_real_escape_string($conn, $_POST['phone']);
        $student_id = mysqli_real_escape_string($conn, $_POST['student_id']);
        $class = mysqli_real_escape_string($conn, $_POST['class']);
        
        $sql = "UPDATE attendees SET name = ?, email = ?, phone = ?, student_id = ?, class = ? WHERE id = ? AND event_id IN (SELECT id FROM events WHERE user_id = ?)";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "sssssii", $name, $email, $phone, $student_id, $class, $attendee_id, $user_id);
        
        if (mysqli_stmt_execute($stmt)) {
            echo json_encode(['success' => true, 'message' => 'Đã cập nhật thông tin thành công!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Lỗi khi cập nhật: ' . mysqli_error($conn)]);
        }
        exit;
    }
    
    // Delete attendee
    if ($_POST['action'] === 'delete_attendee') {
        $attendee_id = (int)$_POST['attendee_id'];
        
        // Get QR path for deletion
        $sql = "SELECT qr_path FROM attendees WHERE id = ? AND event_id IN (SELECT id FROM events WHERE user_id = ?)";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ii", $attendee_id, $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if ($attendee = mysqli_fetch_assoc($result)) {
            // Delete QR file
            if (file_exists($attendee['qr_path'])) {
                unlink($attendee['qr_path']);
            }
            
            // Delete attendee
            $sql = "DELETE FROM attendees WHERE id = ? AND event_id IN (SELECT id FROM events WHERE user_id = ?)";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "ii", $attendee_id, $user_id);
            
            if (mysqli_stmt_execute($stmt)) {
                echo json_encode(['success' => true, 'message' => 'Đã xóa người tham gia thành công!']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Lỗi khi xóa: ' . mysqli_error($conn)]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Không tìm thấy người tham gia!']);
        }
        exit;
    }
    
    // Get attendee details for editing
    if ($_POST['action'] === 'get_attendee') {
        $attendee_id = (int)$_POST['attendee_id'];
        
        $sql = "SELECT * FROM attendees WHERE id = ? AND event_id IN (SELECT id FROM events WHERE user_id = ?)";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ii", $attendee_id, $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if ($attendee = mysqli_fetch_assoc($result)) {
            echo json_encode(['success' => true, 'attendee' => $attendee]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Không tìm thấy người tham gia!']);
        }
        exit;
    }
    
    if ($_POST['action'] === 'qr_scan') {
        $qr_code = mysqli_real_escape_string($conn, $_POST['qr_code']);
        
        // Find attendee
        $sql = "SELECT a.*, e.name as event_name FROM attendees a JOIN events e ON a.event_id = e.id WHERE a.qr_code = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "s", $qr_code);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if ($attendee = mysqli_fetch_assoc($result)) {
            $attendee_id = $attendee['id'];
            $current_status = $attendee['status'];
            
            if ($current_status === 'pending') {
                // Check in
                $sql = "UPDATE attendees SET status = 'checked_in', checkin_time = CURRENT_TIMESTAMP WHERE id = ?";
                $stmt = mysqli_prepare($conn, $sql);
                mysqli_stmt_bind_param($stmt, "i", $attendee_id);
                mysqli_stmt_execute($stmt);
                
                // Log action
                $sql = "INSERT INTO checkin_logs (attendee_id, action, ip_address, user_agent) VALUES (?, 'check_in', ?, ?)";
                $stmt = mysqli_prepare($conn, $sql);
                $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
                mysqli_stmt_bind_param($stmt, "iss", $attendee_id, $ip, $user_agent);
                mysqli_stmt_execute($stmt);
                
                echo json_encode([
                    'success' => true,
                    'action' => 'check_in',
                    'message' => 'Check-in thành công!',
                    'attendee' => [
                        'id' => $attendee['id'],
                        'name' => $attendee['name'],
                        'email' => $attendee['email'],
                        'student_id' => $attendee['student_id'],
                        'class' => $attendee['class'],
                        'event_name' => $attendee['event_name']
                    ]
                ]);
            } elseif ($current_status === 'checked_in') {
                // Check out
                $sql = "UPDATE attendees SET status = 'checked_out', checkout_time = CURRENT_TIMESTAMP WHERE id = ?";
                $stmt = mysqli_prepare($conn, $sql);
                mysqli_stmt_bind_param($stmt, "i", $attendee_id);
                mysqli_stmt_execute($stmt);
                
                // Log action
                $sql = "INSERT INTO checkin_logs (attendee_id, action, ip_address, user_agent) VALUES (?, 'check_out', ?, ?)";
                $stmt = mysqli_prepare($conn, $sql);
                mysqli_stmt_bind_param($stmt, "iss", $attendee_id, $ip, $user_agent);
                mysqli_stmt_execute($stmt);
                
                echo json_encode([
                    'success' => true,
                    'action' => 'check_out',
                    'message' => 'Check-out thành công!',
                    'attendee' => [
                        'id' => $attendee['id'],
                        'name' => $attendee['name'],
                        'email' => $attendee['email'],
                        'student_id' => $attendee['student_id'],
                        'class' => $attendee['class'],
                        'event_name' => $attendee['event_name']
                    ]
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Người này đã check-out rồi.'
                ]);
            }
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Mã QR không hợp lệ hoặc không tìm thấy người tham gia.'
            ]);
        }
        exit;
    }
    
    // Delete event
    if ($_POST['action'] === 'delete_event') {
        $event_id = (int)$_POST['event_id'];
        
        // Delete QR code files first
        $sql = "SELECT qr_path FROM attendees WHERE event_id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $event_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        while ($row = mysqli_fetch_assoc($result)) {
            if (file_exists($row['qr_path'])) {
                unlink($row['qr_path']);
            }
        }
        
        // Delete event (cascade will handle attendees and logs)
        $sql = "DELETE FROM events WHERE id = ? AND user_id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ii", $event_id, $user_id);
        
        if (mysqli_stmt_execute($stmt)) {
            echo json_encode(['success' => true, 'message' => 'Đã xóa sự kiện thành công!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Lỗi khi xóa sự kiện: ' . mysqli_error($conn)]);
        }
        exit;
    }
}

// Handle Excel upload (traditional POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_excel') {
    $event_id = (int)$_POST['event_id'];
    
    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === UPLOAD_ERR_OK) {
        $upload_path = 'uploads/excel/' . uniqid() . '_' . $_FILES['excel_file']['name'];
        
        if (!file_exists('uploads/excel/')) {
            mkdir('uploads/excel/', 0755, true);
        }
        
        if (move_uploaded_file($_FILES['excel_file']['tmp_name'], $upload_path)) {
            require_once 'vendor/autoload.php';
            
            try {
                $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
                $reader->setReadDataOnly(true);
                $spreadsheet = $reader->load($upload_path);
                $worksheet = $spreadsheet->getActiveSheet();
                $rows = $worksheet->toArray();
                
                $success_count = 0;
                $error_count = 0;
                
                // Skip header row
                for ($i = 1; $i < count($rows); $i++) {
                    $row = $rows[$i];
                    if (!empty($row[0])) { // Check if name is not empty
                        $name = mysqli_real_escape_string($conn, $row[0] ?? '');
                        $email = mysqli_real_escape_string($conn, $row[1] ?? '');
                        $phone = mysqli_real_escape_string($conn, $row[2] ?? '');
                        $student_id = mysqli_real_escape_string($conn, $row[3] ?? '');
                        $class = mysqli_real_escape_string($conn, $row[4] ?? '');
                        
                        // Generate unique QR code
                        $qr_code = 'CHK_' . $event_id . '_' . uniqid();
                        $qr_path = 'uploads/qr_codes/' . $qr_code . '.png';
                        
                        // Create QR code directory if not exists
                        if (!file_exists('uploads/qr_codes/')) {
                            mkdir('uploads/qr_codes/', 0755, true);
                        }
                        
                        // Generate QR code using multiple APIs
                        $qr_urls = generateQRCode($qr_code);
                        
                        if (saveQRCodeImage($qr_urls, $qr_path)) {
                            $sql = "INSERT INTO attendees (event_id, name, email, phone, student_id, class, qr_code, qr_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                            $stmt = mysqli_prepare($conn, $sql);
                            mysqli_stmt_bind_param($stmt, "isssssss", $event_id, $name, $email, $phone, $student_id, $class, $qr_code, $qr_path);
                            
                            if (mysqli_stmt_execute($stmt)) {
                                $success_count++;
                            } else {
                                $error_count++;
                            }
                        } else {
                            $error_count++;
                        }
                    }
                }
                
                // Redirect to prevent form resubmission
                $_SESSION['upload_message'] = "Excel uploaded successfully! Added: $success_count, Errors: $error_count";
                $_SESSION['upload_type'] = 'success';
                unlink($upload_path); // Clean up uploaded file
                header("Location: " . $_SERVER['PHP_SELF'] . "?event_id=" . $event_id);
                exit;
            } catch (Exception $e) {
                $_SESSION['upload_message'] = "Error reading Excel file: " . $e->getMessage();
                $_SESSION['upload_type'] = 'error';
                header("Location: " . $_SERVER['PHP_SELF'] . "?event_id=" . $event_id);
                exit;
            }
        } else {
            $_SESSION['upload_message'] = "Error uploading file.";
            $_SESSION['upload_type'] = 'error';
            header("Location: " . $_SERVER['PHP_SELF'] . "?event_id=" . $event_id);
            exit;
        }
    } else {
        $_SESSION['upload_message'] = "Please select a valid Excel file.";
        $_SESSION['upload_type'] = 'error';
        header("Location: " . $_SERVER['PHP_SELF'] . "?event_id=" . $event_id);
        exit;
    }
}

// Export to Excel
if (isset($_GET['export_excel']) && isset($_GET['event_id'])) {
    $event_id = (int)$_GET['event_id'];
    
    // Verify event belongs to user
    $sql = "SELECT name FROM events WHERE id = ? AND user_id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ii", $event_id, $user_id);
    mysqli_stmt_execute($stmt);
    $event_result = mysqli_stmt_get_result($stmt);
    
    if ($event = mysqli_fetch_assoc($event_result)) {
        require_once 'vendor/autoload.php';
        
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Set headers
        $headers = ['STT', 'Họ Tên', 'Email', 'Số Điện Thoại', 'Mã Học Sinh', 'Lớp', 'Trạng Thái', 'Thời Gian Check-in', 'Thời Gian Check-out', 'Mã QR'];
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . '1', $header);
            $sheet->getStyle($col . '1')->getFont()->setBold(true);
            $sheet->getColumnDimension($col)->setAutoSize(true);
            $col++;
        }
        
        // Get attendees data
        $sql = "SELECT * FROM attendees WHERE event_id = ? ORDER BY created_at ASC";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $event_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        $row = 2;
        $stt = 1;
        while ($attendee = mysqli_fetch_assoc($result)) {
            $status_text = $attendee['status'] == 'checked_in' ? 'Đã Check-in' : 
                          ($attendee['status'] == 'checked_out' ? 'Đã Check-out' : 'Chờ Check-in');
            
            $checkin_time = $attendee['checkin_time'] ? date('d/m/Y H:i:s', strtotime($attendee['checkin_time'])) : '';
            $checkout_time = $attendee['checkout_time'] ? date('d/m/Y H:i:s', strtotime($attendee['checkout_time'])) : '';
            
            $sheet->setCellValue('A' . $row, $stt);
            $sheet->setCellValue('B' . $row, $attendee['name']);
            $sheet->setCellValue('C' . $row, $attendee['email']);
            $sheet->setCellValue('D' . $row, $attendee['phone']);
            $sheet->setCellValue('E' . $row, $attendee['student_id']);
            $sheet->setCellValue('F' . $row, $attendee['class']);
            $sheet->setCellValue('G' . $row, $status_text);
            $sheet->setCellValue('H' . $row, $checkin_time);
            $sheet->setCellValue('I' . $row, $checkout_time);
            $sheet->setCellValue('J' . $row, $attendee['qr_code']);
            
            $row++;
            $stt++;
        }
        
        // Style the table
        $sheet->getStyle('A1:J1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID);
        $sheet->getStyle('A1:J1')->getFill()->getStartColor()->setARGB('FF4CAF50');
        $sheet->getStyle('A1:J1')->getFont()->getColor()->setARGB('FFFFFFFF');
        
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        
        $filename = 'DanhSach_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $event['name']) . '_' . date('dmY_His') . '.xlsx';
        
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        
        $writer->save('php://output');
        exit;
    } else {
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

if (isset($_GET['download_sample'])) {
    require_once 'vendor/autoload.php';
    
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    // Headers
    $sheet->setCellValue('A1', 'Name');
    $sheet->setCellValue('B1', 'Email');
    $sheet->setCellValue('C1', 'Phone');
    $sheet->setCellValue('D1', 'Student ID');
    $sheet->setCellValue('E1', 'Class');
    
    // Sample data
    $sheet->setCellValue('A2', 'Nguyen Van A');
    $sheet->setCellValue('B2', 'nguyenvana@example.com');
    $sheet->setCellValue('C2', '0901234567');
    $sheet->setCellValue('D2', '20210001');
    $sheet->setCellValue('E2', '12A1');
    
    $sheet->setCellValue('A3', 'Tran Thi B');
    $sheet->setCellValue('B3', 'tranthib@example.com');
    $sheet->setCellValue('C3', '0901234568');
    $sheet->setCellValue('D3', '20210002');
    $sheet->setCellValue('E3', '12A2');
    
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="attendee_sample.xlsx"');
    header('Cache-Control: max-age=0');
    
    $writer->save('php://output');
    exit;
}

// Check for upload messages
$success_message = '';
$error_message = '';
if (isset($_SESSION['upload_message'])) {
    if ($_SESSION['upload_type'] === 'success') {
        $success_message = $_SESSION['upload_message'];
    } else {
        $error_message = $_SESSION['upload_message'];
    }
    unset($_SESSION['upload_message']);
    unset($_SESSION['upload_type']);
}

// Get user's events
$sql = "SELECT * FROM events WHERE user_id = ? ORDER BY created_at DESC";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$events_result = mysqli_stmt_get_result($stmt);
$events = [];
while ($event = mysqli_fetch_assoc($events_result)) {
    $events[] = $event;
}

// Get attendees for selected event
$selected_event_id = $_GET['event_id'] ?? ($events[0]['id'] ?? 0);
$attendees = [];
if ($selected_event_id) {
    $sql = "SELECT * FROM attendees WHERE event_id = ? ORDER BY created_at DESC";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $selected_event_id);
    mysqli_stmt_execute($stmt);
    $attendees_result = mysqli_stmt_get_result($stmt);
    while ($attendee = mysqli_fetch_assoc($attendees_result)) {
        $attendees[] = $attendee;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hệ Thống Quản Lý Check-in</title>
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
            --success: #00E040;
            --success-light: #50FF70;
            --success-dark: #00B030;
            --warning: #FFB000;
            --warning-light: #FFCC50;
            --warning-dark: #E09000;
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
            --glow-success: 0 0 20px rgba(0, 224, 64, 0.5);
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

        .checkin-container {
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

        .main-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .action-btn {
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

        .action-btn:hover {
            background: linear-gradient(90deg, var(--primary-light), var(--primary));
            transform: translateY(-2px);
            box-shadow: var(--glow);
            color: white;
        }

        .action-btn.secondary {
            background: linear-gradient(90deg, var(--secondary), var(--secondary-dark));
        }

        .action-btn.secondary:hover {
            background: linear-gradient(90deg, var(--secondary-light), var(--secondary));
            box-shadow: var(--glow-secondary);
        }

        .action-btn.success {
            background: linear-gradient(90deg, var(--success), var(--success-dark));
        }

        .action-btn.success:hover {
            background: linear-gradient(90deg, var(--success-light), var(--success));
            box-shadow: var(--glow-success);
        }

        .action-btn.danger {
            background: linear-gradient(90deg, var(--danger), var(--danger-dark));
        }

        .action-btn-small {
            padding: 0.375rem 0.75rem;
            margin: 0 0.125rem;
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            color: white;
            border: none;
            border-radius: 0.75rem;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .action-btn-small:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }

        .action-btn-small.secondary {
            background: linear-gradient(90deg, var(--secondary), var(--secondary-dark));
        }

        .action-btn-small.secondary:hover {
            background: linear-gradient(90deg, var(--secondary-light), var(--secondary));
            box-shadow: 0 4px 12px rgba(0, 224, 255, 0.4);
        }

        .action-btn-small.danger {
            background: linear-gradient(90deg, var(--danger), var(--danger-dark));
        }

        .action-btn-small.danger:hover {
            background: linear-gradient(90deg, var(--danger-light), var(--danger));
            box-shadow: 0 4px 12px rgba(255, 61, 87, 0.4);
        }

        .action-cell {
            text-align: center;
            white-space: nowrap;
        }

        .action-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        .card-container {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border);
        }

        .card-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--foreground);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-title i {
            color: var(--primary-light);
        }

        .event-selector {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            color: var(--foreground);
            padding: 0.5rem 1rem;
            min-width: 200px;
        }

        .event-selector:focus {
            background: rgba(255, 255, 255, 0.08);
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(112, 0, 255, 0.2);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.5rem;
            text-align: center;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            background: rgba(255, 255, 255, 0.08);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: var(--foreground-muted);
            font-size: 0.875rem;
        }

        .stat-card.total .stat-number { color: var(--secondary); }
        .stat-card.pending .stat-number { color: var(--warning); }
        .stat-card.checkedin .stat-number { color: var(--success); }
        .stat-card.checkedout .stat-number { color: var(--primary-light); }

        /* Search container */
        .search-container {
            margin: 1.5rem 0;
            position: relative;
        }

        .search-input-wrapper {
            position: relative;
            max-width: 500px;
        }

        .search-input {
            width: 100%;
            padding: 1rem 1rem 1rem 3rem;
            background: rgba(255, 255, 255, 0.05);
            border: 2px solid var(--border);
            border-radius: var(--radius);
            color: var(--foreground);
            font-size: 1rem;
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            backdrop-filter: blur(10px);
        }

        .search-input:focus {
            background: rgba(255, 255, 255, 0.08);
            border-color: var(--primary-light);
            box-shadow: 0 0 0 4px rgba(112, 0, 255, 0.15), var(--glow);
            outline: none;
        }

        .search-input::placeholder {
            color: var(--foreground-subtle);
        }

        .search-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--foreground-muted);
            font-size: 1.1rem;
            pointer-events: none;
            transition: all 0.3s ease;
        }

        .search-input:focus + .search-icon {
            color: var(--primary-light);
        }

        .search-stats {
            margin-top: 0.75rem;
            font-size: 0.875rem;
            color: var(--foreground-muted);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .search-result-count {
            color: var(--secondary-light);
            font-weight: 600;
        }

        .search-clear-btn {
            background: none;
            border: none;
            color: var(--danger-light);
            cursor: pointer;
            font-size: 0.875rem;
            padding: 0.25rem 0.5rem;
            border-radius: 0.5rem;
            transition: all 0.3s ease;
        }

        .search-clear-btn:hover {
            background: rgba(255, 61, 87, 0.1);
            color: var(--danger);
        }

        .attendees-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .attendees-table th,
        .attendees-table td {
            padding: 0.3rem;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        .attendees-table th {
            background: rgba(255, 255, 255, 0.05);
            font-weight: 600;
            color: var(--foreground);
        }

        .attendees-table tr {
            transition: all 0.3s ease;
        }

        .attendees-table tr:hover {
            background: rgba(255, 255, 255, 0.03);
        }

        /* Search highlight styles */
        .attendees-table tr.search-hidden {
            display: none;
        }

        .attendees-table tr.search-match {
            background: rgba(0, 224, 255, 0.1);
            border-left: 3px solid var(--secondary);
        }

        .search-highlight {
            background: linear-gradient(90deg, rgba(0, 224, 255, 0.3), rgba(255, 61, 255, 0.3));
            color: var(--foreground);
            padding: 0.125rem 0.25rem;
            border-radius: 0.25rem;
            font-weight: 600;
        }

        .no-results-message {
            text-align: center;
            padding: 3rem 2rem;
            color: var(--foreground-muted);
            background: rgba(255, 255, 255, 0.02);
            border-radius: var(--radius);
            margin: 1rem 0;
            border: 2px dashed var(--border);
        }

        .no-results-message i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--warning);
        }

        .no-results-message h3 {
            margin-bottom: 0.5rem;
            color: var(--foreground);
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-badge.pending {
            background: rgba(255, 176, 0, 0.2);
            color: var(--warning);
            border: 1px solid rgba(255, 176, 0, 0.3);
        }

        .status-badge.checked-in {
            background: rgba(0, 224, 64, 0.2);
            color: var(--success);
            border: 1px solid rgba(0, 224, 64, 0.3);
        }

        .status-badge.checked-out {
            background: rgba(157, 80, 255, 0.2);
            color: var(--primary-light);
            border: 1px solid rgba(157, 80, 255, 0.3);
        }

        .qr-code-cell {
            text-align: center;
        }

        .qr-code-img {
            width: 60px;
            height: 60px;
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .qr-code-img:hover {
            transform: scale(1.1);
            box-shadow: var(--shadow-sm);
        }

        .scanner-container {
            position: relative;
            margin: 1.5rem 0;
            background: linear-gradient(135deg, rgba(0, 0, 0, 0.95) 0%, rgba(20, 20, 40, 0.95) 100%);
            border-radius: 2rem;
            overflow: hidden;
            border: 3px solid;
            border-image: linear-gradient(45deg, var(--primary), var(--secondary), var(--accent)) 1;
            box-shadow: 
                0 20px 40px rgba(0, 0, 0, 0.3),
                0 0 30px rgba(112, 0, 255, 0.3),
                inset 0 1px 0 rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
        }

        #qr-reader {
            width: 100%;
            max-width: 500px;
            height: 400px;
            margin: 0 auto;
            border-radius: 1.5rem;
            overflow: hidden;
            background: #000;
            position: relative;
        }

        #qr-reader video {
            width: 100% !important;
            height: 100% !important;
            object-fit: cover;
            border-radius: 1.5rem;
        }

        /* Enhanced Scanner Overlay */
        .scanner-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            pointer-events: none;
            z-index: 10;
            display: flex;
            align-items: center;
            justify-content: center;
            background: 
                radial-gradient(circle at center, transparent 40%, rgba(0, 0, 0, 0.4) 70%);
        }

        .scanner-frame {
            position: relative;
            width: 280px;
            height: 280px;
            border: 3px solid;
            border-image: linear-gradient(45deg, var(--secondary), var(--accent), var(--secondary)) 1;
            border-radius: 2rem;
            background: 
                linear-gradient(135deg, rgba(0, 224, 255, 0.1) 0%, rgba(255, 61, 255, 0.1) 100%);
            backdrop-filter: blur(5px);
            animation: scannerPulse 3s infinite;
            box-shadow: 
                0 0 30px rgba(0, 224, 255, 0.5),
                inset 0 0 20px rgba(255, 255, 255, 0.1);
        }

        @keyframes scannerPulse {
            0% { 
                box-shadow: 
                    0 0 30px rgba(0, 224, 255, 0.5),
                    0 0 0 0 rgba(0, 224, 255, 0.7),
                    inset 0 0 20px rgba(255, 255, 255, 0.1);
            }
            50% { 
                box-shadow: 
                    0 0 40px rgba(0, 224, 255, 0.8),
                    0 0 0 20px rgba(0, 224, 255, 0),
                    inset 0 0 30px rgba(255, 255, 255, 0.2);
            }
            100% { 
                box-shadow: 
                    0 0 30px rgba(0, 224, 255, 0.5),
                    0 0 0 0 rgba(0, 224, 255, 0),
                    inset 0 0 20px rgba(255, 255, 255, 0.1);
            }
        }

        /* Beautiful Scanner Corners */
        .scanner-corners {
            position: absolute;
            width: 40px;
            height: 40px;
            border: 4px solid;
            border-image: linear-gradient(45deg, var(--secondary), var(--accent)) 1;
            animation: cornerGlow 2s infinite alternate;
        }

        @keyframes cornerGlow {
            0% { 
                opacity: 0.7;
                filter: drop-shadow(0 0 5px var(--secondary));
            }
            100% { 
                opacity: 1;
                filter: drop-shadow(0 0 15px var(--secondary));
            }
        }

        .scanner-corners.top-left {
            top: -6px;
            left: -6px;
            border-right: none;
            border-bottom: none;
            border-top-left-radius: 1.5rem;
        }

        .scanner-corners.top-right {
            top: -6px;
            right: -6px;
            border-left: none;
            border-bottom: none;
            border-top-right-radius: 1.5rem;
        }

        .scanner-corners.bottom-left {
            bottom: -6px;
            left: -6px;
            border-right: none;
            border-top: none;
            border-bottom-left-radius: 1.5rem;
        }

        .scanner-corners.bottom-right {
            bottom: -6px;
            right: -6px;
            border-left: none;
            border-top: none;
            border-bottom-right-radius: 1.5rem;
        }

        /* Animated Scanning Line */
        .scanning-line {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, 
                transparent 0%, 
                rgba(0, 224, 255, 0.5) 20%, 
                var(--secondary) 50%, 
                rgba(255, 61, 255, 0.8) 80%, 
                transparent 100%);
            border-radius: 2px;
            animation: scanLine 2.5s linear infinite;
            filter: drop-shadow(0 0 8px var(--secondary));
        }

        @keyframes scanLine {
            0% { 
                transform: translateY(0); 
                opacity: 1; 
            }
            50% { 
                opacity: 1; 
            }
            100% { 
                transform: translateY(280px); 
                opacity: 0; 
            }
        }

        /* Enhanced Scanner Hint */
        .scanner-hint {
            position: absolute;
            bottom: -60px;
            left: 50%;
            transform: translateX(-50%);
            background: linear-gradient(135deg, rgba(0, 0, 0, 0.9) 0%, rgba(20, 20, 40, 0.9) 100%);
            color: var(--secondary);
            padding: 0.75rem 1.5rem;
            border-radius: 2rem;
            font-size: 0.875rem;
            font-weight: 500;
            border: 2px solid;
            border-image: linear-gradient(45deg, var(--secondary), var(--accent)) 1;
            animation: hintPulse 4s infinite;
            backdrop-filter: blur(10px);
            box-shadow: 
                0 10px 25px rgba(0, 0, 0, 0.3),
                0 0 20px rgba(0, 224, 255, 0.3);
        }

        @keyframes hintPulse {
            0%, 100% { 
                opacity: 0.8; 
                transform: translateX(-50%) scale(1);
            }
            50% { 
                opacity: 1; 
                transform: translateX(-50%) scale(1.05);
            }
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
            color: var(--foreground);
        }

        .form-control::placeholder {
            color: var(--foreground-subtle);
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
            animation: slideIn 0.3s forwards, fadeOut 0.3s 4s forwards;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            min-width: 300px;
        }

        @keyframes slideIn {
            to { transform: translateX(0); opacity: 1; }
        }

        @keyframes fadeOut {
            to { opacity: 0; visibility: hidden; }
        }

        .alert-success {
            background: rgba(0, 224, 64, 0.1);
            border: 1px solid rgba(0, 224, 64, 0.3);
            color: var(--success-light);
        }

        .alert-error {
            background: rgba(255, 61, 87, 0.1);
            border: 1px solid rgba(255, 61, 87, 0.3);
            color: var(--danger-light);
        }

        .alert-info {
            background: rgba(0, 224, 255, 0.1);
            border: 1px solid rgba(0, 224, 255, 0.3);
            color: var(--secondary-light);
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

        .file-upload-area {
            border: 2px dashed var(--border);
            border-radius: var(--radius);
            padding: 2rem;
            text-align: center;
            transition: all 0.3s ease;
        }

        .file-upload-area:hover {
            border-color: var(--primary-light);
            background: rgba(112, 0, 255, 0.05);
        }

        .scan-result {
            border-radius: var(--radius);
            padding: 1rem;
            margin-top: 1rem;
            display: none;
        }

        .scan-result.error {
            background: rgba(255, 61, 87, 0.1);
            border: 1px solid rgba(255, 61, 87, 0.3);
        }

        /* Enhanced Status Container */
        .scan-status-container {
            margin: 1.5rem 0;
            padding: 1rem;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.05) 0%, rgba(255, 255, 255, 0.02) 100%);
            border-radius: 1.5rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(15px);
            box-shadow: 
                0 8px 32px rgba(0, 0, 0, 0.2),
                inset 0 1px 0 rgba(255, 255, 255, 0.1);
        }

        .scan-status {
            font-size: 0.9rem;
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            border-radius: 2rem;
            display: inline-block;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(10px);
            border: 2px solid transparent;
        }

        .scan-status.active {
            background: linear-gradient(135deg, rgba(0, 224, 64, 0.2) 0%, rgba(0, 224, 64, 0.1) 100%);
            color: var(--success-light);
            border-color: rgba(0, 224, 64, 0.4);
            animation: pulse-success 2.5s infinite;
            box-shadow: 
                0 0 20px rgba(0, 224, 64, 0.3),
                inset 0 1px 0 rgba(255, 255, 255, 0.2);
        }

        .scan-status.processing {
            background: linear-gradient(135deg, rgba(0, 224, 255, 0.2) 0%, rgba(255, 61, 255, 0.2) 100%);
            color: var(--secondary-light);
            border-color: rgba(0, 224, 255, 0.4);
            animation: pulse-processing 1.2s infinite;
            box-shadow: 
                0 0 25px rgba(0, 224, 255, 0.4),
                inset 0 1px 0 rgba(255, 255, 255, 0.2);
        }

        .scan-status.success {
            background: linear-gradient(135deg, rgba(0, 224, 64, 0.3) 0%, rgba(0, 224, 64, 0.1) 100%);
            color: var(--success-light);
            border-color: rgba(0, 224, 64, 0.5);
            box-shadow: 
                0 0 30px rgba(0, 224, 64, 0.5),
                inset 0 1px 0 rgba(255, 255, 255, 0.3);
        }

        .scan-status.paused {
            background: linear-gradient(135deg, rgba(255, 176, 0, 0.2) 0%, rgba(255, 176, 0, 0.1) 100%);
            color: var(--warning-light);
            border-color: rgba(255, 176, 0, 0.4);
            animation: pulse-warning 3s infinite;
            box-shadow: 
                0 0 20px rgba(255, 176, 0, 0.3),
                inset 0 1px 0 rgba(255, 255, 255, 0.2);
        }

        .scan-status.stopped {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.1) 0%, rgba(255, 255, 255, 0.05) 100%);
            color: var(--foreground-muted);
            border-color: rgba(255, 255, 255, 0.2);
            box-shadow: 
                0 0 10px rgba(0, 0, 0, 0.2),
                inset 0 1px 0 rgba(255, 255, 255, 0.1);
        }

        .scan-status.error {
            background: linear-gradient(135deg, rgba(255, 61, 87, 0.2) 0%, rgba(255, 61, 87, 0.1) 100%);
            color: var(--danger-light);
            border-color: rgba(255, 61, 87, 0.4);
            animation: pulse-error 2s infinite;
            box-shadow: 
                0 0 25px rgba(255, 61, 87, 0.4),
                inset 0 1px 0 rgba(255, 255, 255, 0.2);
        }

        @keyframes pulse-success {
            0% { box-shadow: 0 0 20px rgba(0, 224, 64, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.2); }
            50% { box-shadow: 0 0 30px rgba(0, 224, 64, 0.6), inset 0 1px 0 rgba(255, 255, 255, 0.3); }
            100% { box-shadow: 0 0 20px rgba(0, 224, 64, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.2); }
        }

        @keyframes pulse-processing {
            0% { box-shadow: 0 0 25px rgba(0, 224, 255, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.2); }
            50% { box-shadow: 0 0 35px rgba(255, 61, 255, 0.6), inset 0 1px 0 rgba(255, 255, 255, 0.3); }
            100% { box-shadow: 0 0 25px rgba(0, 224, 255, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.2); }
        }

        @keyframes pulse-warning {
            0% { box-shadow: 0 0 20px rgba(255, 176, 0, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.2); }
            50% { box-shadow: 0 0 30px rgba(255, 176, 0, 0.5), inset 0 1px 0 rgba(255, 255, 255, 0.3); }
            100% { box-shadow: 0 0 20px rgba(255, 176, 0, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.2); }
        }

        @keyframes pulse-error {
            0% { box-shadow: 0 0 25px rgba(255, 61, 87, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.2); }
            50% { box-shadow: 0 0 35px rgba(255, 61, 87, 0.6), inset 0 1px 0 rgba(255, 255, 255, 0.3); }
            100% { box-shadow: 0 0 25px rgba(255, 61, 87, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.2); }
        }

        /* Enhanced Scanner Controls */
        .scanner-controls {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 1.5rem;
        }

        .scanner-controls .action-btn {
            border-radius: 1.5rem;
            padding: 0.875rem 1.5rem;
            font-weight: 600;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(10px);
            border: 2px solid transparent;
            position: relative;
            overflow: hidden;
        }

        .scanner-controls .action-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(45deg, rgba(255, 255, 255, 0.1), transparent);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .scanner-controls .action-btn:hover::before {
            opacity: 1;
        }

        .scanner-controls .action-btn.success {
            background: linear-gradient(135deg, var(--success) 0%, var(--success-dark) 100%);
            border-color: var(--success-light);
            box-shadow: 
                0 8px 25px rgba(0, 224, 64, 0.3),
                inset 0 1px 0 rgba(255, 255, 255, 0.2);
        }

        .scanner-controls .action-btn.success:hover {
            background: linear-gradient(135deg, var(--success-light) 0%, var(--success) 100%);
            box-shadow: 
                0 12px 35px rgba(0, 224, 64, 0.4),
                inset 0 1px 0 rgba(255, 255, 255, 0.3);
            transform: translateY(-3px);
        }

        .scanner-controls .action-btn.danger {
            background: linear-gradient(135deg, var(--danger) 0%, var(--danger-dark) 100%);
            border-color: var(--danger-light);
            box-shadow: 
                0 8px 25px rgba(255, 61, 87, 0.3),
                inset 0 1px 0 rgba(255, 255, 255, 0.2);
        }

        .scanner-controls .action-btn.danger:hover {
            background: linear-gradient(135deg, var(--danger-light) 0%, var(--danger) 100%);
            box-shadow: 
                0 12px 35px rgba(255, 61, 87, 0.4),
                inset 0 1px 0 rgba(255, 255, 255, 0.3);
            transform: translateY(-3px);
        }

        .scanner-controls .action-btn.secondary {
            background: linear-gradient(135deg, var(--secondary) 0%, var(--secondary-dark) 100%);
            border-color: var(--secondary-light);
            box-shadow: 
                0 8px 25px rgba(0, 224, 255, 0.3),
                inset 0 1px 0 rgba(255, 255, 255, 0.2);
        }

        .scanner-controls .action-btn.secondary:hover {
            background: linear-gradient(135deg, var(--secondary-light) 0%, var(--secondary) 100%);
            box-shadow: 
                0 12px 35px rgba(0, 224, 255, 0.4),
                inset 0 1px 0 rgba(255, 255, 255, 0.3);
            transform: translateY(-3px);
        }

        /* Enhanced Scanner Tips */
        .scanner-tips {
            margin-top: 1.5rem;
            padding: 1rem;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.03) 0%, rgba(255, 255, 255, 0.01) 100%);
            border-radius: 1.5rem;
            border: 1px solid rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(15px);
            box-shadow: 
                0 8px 32px rgba(0, 0, 0, 0.15),
                inset 0 1px 0 rgba(255, 255, 255, 0.1);
        }

        .scanner-features {
            display: flex;
            justify-content: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .feature-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1rem;
            background: linear-gradient(135deg, rgba(0, 224, 255, 0.15) 0%, rgba(255, 61, 255, 0.1) 100%);
            border: 1px solid rgba(0, 224, 255, 0.3);
            border-radius: 1.5rem;
            font-size: 0.8rem;
            font-weight: 500;
            color: var(--secondary-light);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(10px);
            box-shadow: 
                0 4px 15px rgba(0, 224, 255, 0.2),
                inset 0 1px 0 rgba(255, 255, 255, 0.1);
        }

        .feature-item:hover {
            background: linear-gradient(135deg, rgba(0, 224, 255, 0.2) 0%, rgba(255, 61, 255, 0.15) 100%);
            border-color: rgba(0, 224, 255, 0.4);
            transform: translateY(-2px);
            box-shadow: 
                0 8px 25px rgba(0, 224, 255, 0.3),
                inset 0 1px 0 rgba(255, 255, 255, 0.2);
        }

        .feature-item i {
            color: var(--secondary);
            font-size: 0.9rem;
        }

        .scanner-tips .text-muted {
            color: var(--foreground-muted);
            font-size: 0.85rem;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .scanner-tips i.fa-info-circle {
            color: var(--secondary-light);
            font-size: 1rem;
        }

        /* Enhanced Modal for Scanner */
        #scannerModal .modal-dialog {
            max-width: 650px;
            margin: 1.5rem auto;
        }

        #scannerModal .modal-content {
            background: linear-gradient(135deg, 
                var(--surface) 0%, 
                var(--surface-light) 50%, 
                var(--surface) 100%);
            border: 3px solid;
            border-image: linear-gradient(45deg, var(--primary), var(--secondary), var(--accent)) 1;
            border-radius: 2rem;
            box-shadow: 
                0 25px 50px rgba(0, 0, 0, 0.3),
                0 0 40px rgba(112, 0, 255, 0.2),
                inset 0 1px 0 rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(20px);
            overflow: hidden;
        }

        #scannerModal .modal-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 50%, var(--accent) 100%);
            color: white;
            border-bottom: none;
            padding: 1.5rem 2rem;
            border-radius: 2rem 2rem 0 0;
            position: relative;
            overflow: hidden;
        }

        #scannerModal .modal-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(45deg, rgba(255, 255, 255, 0.1), transparent);
            pointer-events: none;
        }

        #scannerModal .modal-header .modal-title {
            font-size: 1.4rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            position: relative;
            z-index: 1;
        }

        #scannerModal .modal-header .modal-title i {
            color: white;
            font-size: 1.5rem;
            filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.3));
        }

        #scannerModal .btn-close {
            filter: brightness(0) invert(1);
            opacity: 0.8;
            transition: all 0.3s ease;
            border-radius: 50%;
            padding: 0.5rem;
            position: relative;
            z-index: 1;
        }

        #scannerModal .btn-close:hover {
            opacity: 1;
            background: rgba(255, 255, 255, 0.1);
            transform: scale(1.1);
        }

        #scannerModal .modal-body {
            padding: 2rem;
            background: rgba(255, 255, 255, 0.01);
        }

        /* Permission Denied Enhanced */
        .scanner-container.permission-denied {
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, rgba(255, 61, 87, 0.1) 0%, rgba(255, 61, 87, 0.05) 100%);
            border: 3px solid;
            border-image: linear-gradient(45deg, var(--danger), var(--danger-light)) 1;
            min-height: 400px;
            border-radius: 2rem;
        }

        .permission-denied-content {
            text-align: center;
            color: var(--danger-light);
            padding: 2rem;
            border-radius: 1.5rem;
            background: rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 61, 87, 0.3);
        }

        .permission-denied-content i {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            color: var(--danger);
            filter: drop-shadow(0 4px 8px rgba(255, 61, 87, 0.3));
        }

        .permission-denied-content h4 {
            margin-bottom: 1rem;
            color: var(--foreground);
            font-size: 1.3rem;
            font-weight: 600;
        }

        .permission-denied-content p {
            color: var(--foreground-muted);
            margin-bottom: 1.5rem;
            font-size: 1rem;
        }

        .permission-denied-content small {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--foreground-subtle);
            font-size: 0.85rem;
        }

        /* Success Result Enhanced */
        .scan-result.success .attendee-info {
            animation: successZoom 0.6s cubic-bezier(0.4, 0, 0.2, 1);
            background: linear-gradient(135deg, rgba(0, 224, 64, 0.1) 0%, rgba(0, 224, 64, 0.05) 100%);
            border-radius: 1.5rem;
            padding: 1rem;
            border: 1px solid rgba(0, 224, 64, 0.3);
        }

        @keyframes successZoom {
            0% { 
                transform: scale(0.9); 
                opacity: 0;
                filter: blur(2px);
            }
            60% { 
                transform: scale(1.05); 
                opacity: 1;
                filter: blur(0);
            }
            100% { 
                transform: scale(1); 
                opacity: 1;
                filter: blur(0);
            }
        }

        /* Responsive scanner */
        @media (max-width: 768px) {
            #qr-reader {
                height: 300px;
                max-width: 100%;
            }

            .scanner-frame {
                width: 200px;
                height: 200px;
            }

            .scanner-hint {
                font-size: 0.75rem;
                bottom: -40px;
            }

            .scanner-features {
                flex-direction: column;
                gap: 0.5rem;
            }

            .feature-item {
                justify-content: center;
                font-size: 0.75rem;
            }

            .scanner-controls {
                flex-direction: column;
                gap: 0.75rem;
            }

            .scanner-controls .action-btn {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            #scannerModal .modal-dialog {
                margin: 0.5rem;
                max-width: none;
                width: calc(100% - 1rem);
            }

            #qr-reader {
                height: 250px;
            }

            .scanner-frame {
                width: 160px;
                height: 160px;
            }

            .scanner-corners {
                width: 25px;
                height: 25px;
                border-width: 3px;
            }
        }

        /* Hide overlay when camera is active for full-frame scanning */
        .scanner-container.full-frame .scanner-overlay {
            display: none;
        }

        /* Camera permission denied state */
        .scanner-container.permission-denied {
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 61, 87, 0.1);
            border-color: var(--danger);
            min-height: 400px;
        }

        .permission-denied-content {
            text-align: center;
            color: var(--danger-light);
        }

        .permission-denied-content i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--danger);
        }

        .permission-denied-content h4 {
            margin-bottom: 0.5rem;
            color: var(--foreground);
        }

        .permission-denied-content p {
            color: var(--foreground-muted);
            margin-bottom: 1rem;
        }

        /* Success animation for scan result */
        .scan-result.success .attendee-info {
            animation: successZoom 0.5s ease;
        }

        @keyframes successZoom {
            0% { transform: scale(0.9); opacity: 0; }
            50% { transform: scale(1.05); opacity: 1; }
            100% { transform: scale(1); opacity: 1; }
        }

        .scan-result.success {
            background: rgba(0, 224, 64, 0.1);
            border: 1px solid rgba(0, 224, 64, 0.3);
            border-radius: var(--radius);
            padding: 1rem;
            margin-top: 1rem;
            animation: slideInUp 0.3s ease;
        }

        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .info-value.success-action {
            color: var(--success-light);
            font-weight: 600;
            font-size: 1.1em;
        }

        .attendees-table tr.highlight {
            background: rgba(0, 224, 64, 0.1) !important;
            animation: highlightRow 2s ease;
        }

        @keyframes highlightRow {
            0% { background: rgba(0, 224, 64, 0.3) !important; }
            100% { background: rgba(0, 224, 64, 0.1) !important; }
        }

        .attendee-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .info-item {
            background: rgba(255, 255, 255, 0.05);
            padding: 0.5rem;
            border-radius: var(--radius-sm);
        }

        .info-label {
            font-size: 0.75rem;
            color: var(--foreground-muted);
            margin-bottom: 0.25rem;
        }

        .info-value {
            font-weight: 500;
        }

        .loading-spinner {
            display: none;
            margin-left: 0.5rem;
        }

        .spinner-border {
            width: 1rem;
            height: 1rem;
        }

        @media (max-width: 992px) {
            .checkin-container {
                padding: 0 1rem;
            }

            .page-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
                padding: 1.25rem;
            }

            .main-actions {
                flex-wrap: wrap;
                width: 100%;
                justify-content: flex-start;
            }

            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            }

            .search-input-wrapper {
                max-width: 100%;
            }
        }

        @media (max-width: 576px) {
            .page-title {
                font-size: 1.5rem;
            }

            .action-btn {
                padding: 0.5rem 1rem;
                font-size: 0.8rem;
            }

            .attendees-table {
                font-size: 0.8rem;
            }

            .attendees-table th,
            .attendees-table td {
                padding: 0.5rem;
            }

            .qr-code-img {
                width: 40px;
                height: 40px;
            }

            .search-stats {
                flex-direction: column;
                gap: 0.5rem;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="particles" id="particles"></div>

    <div class="alert-container" id="alertContainer"></div>

    <div class="checkin-container">
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-qrcode"></i>
                Hệ Thống Quản Lý Check-in
            </h1>
            <div class="main-actions">
                <button class="action-btn success" data-bs-toggle="modal" data-bs-target="#scannerModal">
                    <i class="fas fa-camera"></i>
                    Quét QR
                </button>
                <button class="action-btn secondary" data-bs-toggle="modal" data-bs-target="#addEventModal">
                    <i class="fas fa-plus"></i>
                    Tạo Sự Kiện
                </button>
                <a href="dashboard.php" class="action-btn">
                    <i class="fas fa-arrow-left"></i>
                    Trang Chủ
                </a>
            </div>
        </div>

        <!-- Event Management -->
        <div class="card-container">
            <div class="card-header">
                <h2 class="card-title">
                    <i class="fas fa-calendar-alt"></i>
                    Quản Lý Sự Kiện
                </h2>
                <select class="event-selector" id="eventSelector" onchange="changeEvent(this.value)">
                    <option value="">Chọn Sự Kiện</option>
                    <?php foreach ($events as $event): ?>
                    <option value="<?php echo $event['id']; ?>" <?php echo $event['id'] == $selected_event_id ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($event['name']); ?> - <?php echo date('M d, Y', strtotime($event['event_date'])); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($selected_event_id && !empty($events)): ?>
            <?php 
            $current_event = array_filter($events, function($e) use ($selected_event_id) { return $e['id'] == $selected_event_id; });
            $current_event = reset($current_event);
            
            $total_attendees = count($attendees);
            $pending_count = count(array_filter($attendees, function($a) { return $a['status'] == 'pending'; }));
            $checkedin_count = count(array_filter($attendees, function($a) { return $a['status'] == 'checked_in'; }));
            $checkedout_count = count(array_filter($attendees, function($a) { return $a['status'] == 'checked_out'; }));
            ?>

            <div class="stats-grid">
                <div class="stat-card total">
                    <div class="stat-number"><?php echo $total_attendees; ?></div>
                    <div class="stat-label">Tổng Số Người</div>
                </div>
                <div class="stat-card pending">
                    <div class="stat-number"><?php echo $pending_count; ?></div>
                    <div class="stat-label">Chờ Check-in</div>
                </div>
                <div class="stat-card checkedin">
                    <div class="stat-number"><?php echo $checkedin_count; ?></div>
                    <div class="stat-label">Đã Check-in</div>
                </div>
                <div class="stat-card checkedout">
                    <div class="stat-number"><?php echo $checkedout_count; ?></div>
                    <div class="stat-label">Đã Check-out</div>
                </div>
            </div>

            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-users"></i>
                    Quản Lý Người Tham Gia
                </h3>
                <div class="main-actions">
                    <a href="?export_excel=1&event_id=<?php echo $selected_event_id; ?>" class="action-btn secondary">
                        <i class="fas fa-file-excel"></i>
                        Xuất Excel
                    </a>
                    <button class="action-btn secondary" data-bs-toggle="modal" data-bs-target="#uploadExcelModal">
                        <i class="fas fa-upload"></i>
                        Tải Excel
                    </button>
                    <a href="?download_sample=1" class="action-btn">
                        <i class="fas fa-download"></i>
                        Tải File Mẫu
                    </a>
                    <button class="action-btn" data-bs-toggle="modal" data-bs-target="#addAttendeeModal">
                        <i class="fas fa-user-plus"></i>
                        Thêm Người
                    </button>
                    <a href="?test_qr=1" class="action-btn" style="background: linear-gradient(90deg, var(--warning), var(--warning-dark));" target="_blank">
                        <i class="fas fa-bug"></i>
                        Kiểm Tra QR
                    </a>
                    <button class="action-btn danger" onclick="deleteEvent(<?php echo $selected_event_id; ?>)">
                        <i class="fas fa-trash"></i>
                        Xóa Sự Kiện
                    </button>
                </div>
            </div>

            <!-- Search Container -->
            <div class="search-container">
                <div class="search-input-wrapper">
                    <input type="text" 
                           class="search-input" 
                           id="attendeeSearch" 
                           placeholder="Tìm kiếm theo tên, mã học sinh hoặc email..."
                           autocomplete="off">
                    <i class="fas fa-search search-icon"></i>
                </div>
                <div class="search-stats" id="searchStats">
                    <span class="search-result-count" id="searchResultCount">Hiển thị <?php echo $total_attendees; ?> người tham gia</span>
                    <button class="search-clear-btn" id="searchClearBtn" style="display: none;" onclick="clearSearch()">
                        <i class="fas fa-times"></i>
                        Xóa tìm kiếm
                    </button>
                </div>
            </div>

            <div style="overflow-x: auto;">
                <table class="attendees-table" id="attendeesTable">
                    <thead>
                        <tr>
                            <th>Họ Tên</th>
                            <th>Email</th>
                            <th>Điện Thoại</th>
                            <th>Mã Học Sinh</th>
                            <th>Lớp</th>
                            <th>Mã QR</th>
                            <th>Trạng Thái</th>
                            <th>Thời Gian Check-in</th>
                            <th>Thời Gian Check-out</th>
                            <th>Thao Tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attendees as $attendee): ?>
                        <tr data-attendee-id="<?php echo $attendee['id']; ?>" 
                            data-searchable="<?php echo htmlspecialchars(strtolower($attendee['name'] . ' ' . $attendee['email'] . ' ' . $attendee['student_id'] . ' ' . $attendee['class'])); ?>">
                            <td class="attendee-name"><?php echo htmlspecialchars($attendee['name']); ?></td>
                            <td class="attendee-email"><?php echo htmlspecialchars($attendee['email']); ?></td>
                            <td class="attendee-phone"><?php echo htmlspecialchars($attendee['phone']); ?></td>
                            <td class="attendee-student-id"><?php echo htmlspecialchars($attendee['student_id']); ?></td>
                            <td class="attendee-class"><?php echo htmlspecialchars($attendee['class']); ?></td>
                            <td class="qr-code-cell">
                                <?php if (file_exists($attendee['qr_path'])): ?>
                                <img src="<?php echo htmlspecialchars($attendee['qr_path']); ?>" 
                                     alt="QR Code" 
                                     class="qr-code-img"
                                     onclick="showQRModal('<?php echo htmlspecialchars($attendee['qr_path']); ?>', '<?php echo htmlspecialchars($attendee['name']); ?>')">
                                <?php else: ?>
                                <div style="padding: 10px; background: rgba(255,176,0,0.1); border: 1px solid rgba(255,176,0,0.3); border-radius: 8px; font-size: 0.8rem; color: var(--warning);">
                                    <i class="fas fa-exclamation-triangle"></i><br>
                                    QR Missing<br>
                                    <small><?php echo htmlspecialchars($attendee['qr_code']); ?></small>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td class="status-cell">
                                <span class="status-badge <?php echo $attendee['status'] == 'checked_in' ? 'checked-in' : ($attendee['status'] == 'checked_out' ? 'checked-out' : 'pending'); ?>">
                                    <?php 
                                    echo $attendee['status'] == 'checked_in' ? 'Check-in' : 
                                         ($attendee['status'] == 'checked_out' ? 'Check-out' : 'Chờ Check-in'); 
                                    ?>
                                </span>
                            </td>
                            <td class="checkin-time"><?php echo $attendee['checkin_time'] ? date('d/m/Y H:i', strtotime($attendee['checkin_time'])) : '-'; ?></td>
                            <td class="checkout-time"><?php echo $attendee['checkout_time'] ? date('d/m/Y H:i', strtotime($attendee['checkout_time'])) : '-'; ?></td>
                            <td class="action-cell">
                                <button class="action-btn-small secondary" onclick="editAttendee(<?php echo $attendee['id']; ?>)" title="Chỉnh sửa">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="action-btn-small danger" onclick="deleteAttendee(<?php echo $attendee['id']; ?>)" title="Xóa">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <!-- No Results Message -->
                <div class="no-results-message" id="noResultsMessage" style="display: none;">
                    <i class="fas fa-search-minus"></i>
                    <h3>Không tìm thấy kết quả</h3>
                    <p>Không có người tham gia nào khớp với từ khóa tìm kiếm của bạn.</p>
                    <button class="action-btn secondary" onclick="clearSearch()">
                        <i class="fas fa-times"></i>
                        Xóa tìm kiếm
                    </button>
                </div>
            </div>
            <?php else: ?>
            <div style="text-align: center; padding: 2rem; color: var(--foreground-muted);">
                <i class="fas fa-calendar-plus" style="font-size: 3rem; margin-bottom: 1rem; color: var(--primary-light);"></i>
                <h3>Chưa Chọn Sự Kiện</h3>
                <p>Vui lòng chọn sự kiện từ danh sách trên hoặc tạo sự kiện mới để bắt đầu.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add Event Modal -->
    <div class="modal fade" id="addEventModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-calendar-plus"></i>
                        Tạo Sự Kiện Mới
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="addEventForm">
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">
                                    <i class="fas fa-tag"></i>
                                    Tên Sự Kiện
                                </label>
                                <input type="text" class="form-control" name="name" required placeholder="Nhập tên sự kiện">
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">
                                    <i class="fas fa-align-left"></i>
                                    Mô Tả
                                </label>
                                <textarea class="form-control" name="description" rows="3" placeholder="Nhập mô tả sự kiện"></textarea>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">
                                    <i class="fas fa-calendar"></i>
                                    Ngày Sự Kiện
                                </label>
                                <input type="date" class="form-control" name="event_date" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">
                                    <i class="fas fa-clock"></i>
                                    Giờ Bắt Đầu
                                </label>
                                <input type="time" class="form-control" name="start_time">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">
                                    <i class="fas fa-clock"></i>
                                    Giờ Kết Thúc
                                </label>
                                <input type="time" class="form-control" name="end_time">
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">
                                    <i class="fas fa-map-marker-alt"></i>
                                    Địa Điểm
                                </label>
                                <input type="text" class="form-control" name="location" placeholder="Nhập địa điểm tổ chức">
                            </div>
                        </div>
                        <button type="submit" class="action-btn w-100" id="createEventBtn">
                            <i class="fas fa-plus"></i>
                            Tạo Sự Kiện
                            <div class="loading-spinner">
                                <div class="spinner-border spinner-border-sm text-light" role="status"></div>
                            </div>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Attendee Modal -->
    <div class="modal fade" id="addAttendeeModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-user-plus"></i>
                        Thêm Người Tham Gia
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="addAttendeeForm">
                        <input type="hidden" name="event_id" value="<?php echo $selected_event_id; ?>">
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-user"></i>
                                Họ và Tên
                            </label>
                            <input type="text" class="form-control" name="name" required placeholder="Nhập họ và tên">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-envelope"></i>
                                Email
                            </label>
                            <input type="email" class="form-control" name="email" placeholder="Nhập địa chỉ email">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-phone"></i>
                                Số Điện Thoại
                            </label>
                            <input type="text" class="form-control" name="phone" placeholder="Nhập số điện thoại">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-id-card"></i>
                                Mã Học Sinh
                            </label>
                            <input type="text" class="form-control" name="student_id" placeholder="Nhập mã học sinh">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-graduation-cap"></i>
                                Lớp
                            </label>
                            <input type="text" class="form-control" name="class" placeholder="Nhập lớp">
                        </div>
                        <button type="submit" class="action-btn w-100" id="addAttendeeBtn">
                            <i class="fas fa-plus"></i>
                            Thêm Người Tham Gia
                            <div class="loading-spinner">
                                <div class="spinner-border spinner-border-sm text-light" role="status"></div>
                            </div>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Attendee Modal -->
    <div class="modal fade" id="editAttendeeModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-user-edit"></i>
                        Chỉnh Sửa Thông Tin
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="editAttendeeForm">
                        <input type="hidden" name="attendee_id" id="editAttendeeId">
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-user"></i>
                                Họ và Tên
                            </label>
                            <input type="text" class="form-control" name="name" id="editName" required placeholder="Nhập họ và tên">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-envelope"></i>
                                Email
                            </label>
                            <input type="email" class="form-control" name="email" id="editEmail" placeholder="Nhập địa chỉ email">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-phone"></i>
                                Số Điện Thoại
                            </label>
                            <input type="text" class="form-control" name="phone" id="editPhone" placeholder="Nhập số điện thoại">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-id-card"></i>
                                Mã Học Sinh
                            </label>
                            <input type="text" class="form-control" name="student_id" id="editStudentId" placeholder="Nhập mã học sinh">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-graduation-cap"></i>
                                Lớp
                            </label>
                            <input type="text" class="form-control" name="class" id="editClass" placeholder="Nhập lớp">
                        </div>
                        <button type="submit" class="action-btn w-100" id="updateAttendeeBtn">
                            <i class="fas fa-save"></i>
                            Cập Nhật Thông Tin
                            <div class="loading-spinner">
                                <div class="spinner-border spinner-border-sm text-light" role="status"></div>
                            </div>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Upload Excel Modal -->
    <div class="modal fade" id="uploadExcelModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-upload"></i>
                        Tải File Excel
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" enctype="multipart/form-data" id="uploadExcelForm">
                        <input type="hidden" name="action" value="upload_excel">
                        <input type="hidden" name="event_id" value="<?php echo $selected_event_id; ?>">
                        
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-file-excel"></i>
                                File Excel
                            </label>
                            <div class="file-upload-area" id="fileUploadArea">
                                <i class="fas fa-cloud-upload-alt" style="font-size: 2rem; color: var(--primary-light); margin-bottom: 0.5rem;"></i>
                                <p>Kéo thả file Excel vào đây hoặc nhấp để chọn</p>
                                <input type="file" class="form-control" name="excel_file" accept=".xlsx,.xls" required style="display: none;" id="fileInput">
                                <button type="button" class="action-btn secondary" onclick="document.getElementById('fileInput').click()">
                                    <i class="fas fa-folder-open"></i>
                                    Chọn File
                                </button>
                                <div id="selectedFileName" style="margin-top: 0.5rem; color: var(--success); display: none;">
                                    <i class="fas fa-file-excel"></i>
                                    <span></span>
                                </div>
                            </div>
                            <small class="text-muted">
                                Các cột cần có: Họ Tên, Email, Số Điện Thoại, Mã Học Sinh, Lớp
                            </small>
                        </div>
                        
                        <button type="submit" class="action-btn w-100">
                            <i class="fas fa-upload"></i>
                            Tải Lên Excel
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- QR Scanner Modal -->
    <div class="modal fade" id="scannerModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-qrcode"></i>
                        Máy Quét Mã QR
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="scanner-container">
                        <div id="qr-reader"></div>
                        <div class="scanner-overlay">
                            <div class="scanner-frame">
                                <div class="scanner-corners top-left"></div>
                                <div class="scanner-corners top-right"></div>
                                <div class="scanner-corners bottom-left"></div>
                                <div class="scanner-corners bottom-right"></div>
                                <div class="scanning-line"></div>
                                <div class="scanner-hint">
                                    <i class="fas fa-qrcode"></i>
                                    Hướng camera vào mã QR bất kỳ đâu
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Scanner Status -->
                    <div class="scan-status-container">
                        <div id="scanStatus" class="scan-status">Máy quét chưa khởi động</div>
                    </div>
                    
                    <div class="scan-result" id="scanResult">
                        <h4 id="scanMessage"></h4>
                        <div class="attendee-info" id="attendeeInfo"></div>
                    </div>
                    
                    <div class="mt-3 scanner-controls">
                        <button class="action-btn success" id="startScanBtn">
                            <i class="fas fa-play"></i>
                            Bật Camera
                        </button>
                        <button class="action-btn danger" id="stopScanBtn" style="display: none;">
                            <i class="fas fa-stop"></i>
                            Tắt Camera
                        </button>
                        <button class="action-btn secondary" id="resumeScanBtn" style="display: none;">
                            <i class="fas fa-play-circle"></i>
                            Tiếp Tục Quét
                        </button>
                    </div>
                    
                    <div class="scanner-tips">
                        <div class="scanner-features">
                            <div class="feature-item">
                                <i class="fas fa-expand-arrows-alt"></i>
                                <span>Quét mọi nơi</span>
                            </div>
                            <div class="feature-item">
                                <i class="fas fa-bolt"></i>
                                <span>Phát hiện nhanh</span>
                            </div>
                            <div class="feature-item">
                                <i class="fas fa-pause-circle"></i>
                                <span>Tự động tạm dừng</span>
                            </div>
                        </div>
                        <small class="text-muted">
                            <i class="fas fa-info-circle"></i>
                            Giữ ổn định 1-2 giây. Hoạt động với mọi hướng xoay.
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- QR Code View Modal -->
    <div class="modal fade" id="qrViewModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-qrcode"></i>
                        Mã QR - <span id="qrAttendName"></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <img id="qrCodeImage" src="" alt="QR Code" style="max-width: 100%; height: auto; border-radius: var(--radius);">
                    <div class="mt-3">
                        <button class="action-btn secondary" onclick="downloadQR()">
                            <i class="fas fa-download"></i>
                            Tải Mã QR
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <script>
        // Global variables for search functionality
        let allAttendees = [];
        let searchTimeout = null;
        let originalResultCount = <?php echo $total_attendees; ?>;

        // Debug: Check if library is loaded
        window.addEventListener('load', function() {
            console.log('Page loaded. Html5Qrcode available:', typeof Html5Qrcode !== 'undefined');
            if (typeof Html5Qrcode === 'undefined') {
                console.error('Html5Qrcode library failed to load. Trying to load from CDN...');
                const script = document.createElement('script');
                script.src = 'https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js';
                script.onload = () => console.log('Html5Qrcode loaded from CDN');
                script.onerror = () => console.error('Failed to load Html5Qrcode from CDN');
                document.head.appendChild(script);
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            // Global scanner variables
            let html5QrCode = null;
            let isScanning = false;
            let lastScanTime = 0;
            let scanCooldown = 1000; // Giảm từ 3s xuống 1s để nhanh hơn
            let isProcessing = false;
            let pauseAfterScan = false;

            // Wait for library to load
            function waitForLibrary(callback, attempts = 0) {
                if (typeof Html5Qrcode !== 'undefined') {
                    console.log('Html5Qrcode library ready');
                    callback();
                } else if (attempts < 20) { // Wait up to 10 seconds
                    console.log(`Waiting for Html5Qrcode library... attempt ${attempts + 1}`);
                    setTimeout(() => waitForLibrary(callback, attempts + 1), 500);
                } else {
                    console.error('Html5Qrcode library failed to load after 10 seconds');
                    showAlert('Thư viện quét QR không thể tải. Vui lòng làm mới trang.', 'error');
                }
            }

            // Initialize after library is ready
            waitForLibrary(function() {
                initializeScanner();
            });

            function initializeScanner() {
                // Show alerts
                <?php if ($success_message): ?>
                    showAlert('<?php echo addslashes($success_message); ?>', 'success');
                <?php endif; ?>
                
                <?php if ($error_message): ?>
                    showAlert('<?php echo addslashes($error_message); ?>', 'error');
                <?php endif; ?>

                // Initialize particles
                createParticles();

                // Initialize scanner status and add debug logging
                document.getElementById('scanStatus').textContent = 'Máy quét sẵn sàng khởi động';
                document.getElementById('scanStatus').className = 'scan-status stopped';
                
                // Debug: Check if elements exist
                console.log('Scanner elements check:', {
                    startBtn: !!document.getElementById('startScanBtn'),
                    stopBtn: !!document.getElementById('stopScanBtn'),
                    resumeBtn: !!document.getElementById('resumeScanBtn'),
                    qrReader: !!document.getElementById('qr-reader'),
                    scanStatus: !!document.getElementById('scanStatus')
                });
                
                // Ensure buttons have click listeners
                const startBtn = document.getElementById('startScanBtn');
                const stopBtn = document.getElementById('stopScanBtn'); 
                const resumeBtn = document.getElementById('resumeScanBtn');
                
                if (startBtn) {
                    console.log('Start button found, adding listener');
                    // Remove any existing listeners first
                    startBtn.removeEventListener('click', startScanning);
                    startBtn.addEventListener('click', startScanning);
                    
                    // Visual feedback for button click
                    startBtn.addEventListener('click', function() {
                        this.classList.add('loading');
                        setTimeout(() => this.classList.remove('loading'), 2000);
                    });
                } else {
                    console.error('Start button not found!');
                }
                
                if (stopBtn) {
                    stopBtn.removeEventListener('click', stopScanning);
                    stopBtn.addEventListener('click', stopScanning);
                }
                
                if (resumeBtn) {
                    resumeBtn.removeEventListener('click', resumeScanning);
                    resumeBtn.addEventListener('click', resumeScanning);
                }

                // Initialize other components
                initializeFileUpload();
                initializeForms();
                initializeModalHandlers();
                initializeKeyboardShortcuts();
                initializeSearch(); // Initialize search functionality
            }

            // Initialize search functionality
            function initializeSearch() {
                const searchInput = document.getElementById('attendeeSearch');
                const searchResultCount = document.getElementById('searchResultCount');
                const searchClearBtn = document.getElementById('searchClearBtn');
                const noResultsMessage = document.getElementById('noResultsMessage');
                const attendeesTable = document.getElementById('attendeesTable');

                if (!searchInput || !attendeesTable) return;

                // Get all attendee rows and store their data
                const rows = attendeesTable.querySelectorAll('tbody tr[data-attendee-id]');
                allAttendees = Array.from(rows).map(row => ({
                    element: row,
                    searchData: row.getAttribute('data-searchable') || '',
                    id: row.getAttribute('data-attendee-id')
                }));

                console.log('Initialized search with', allAttendees.length, 'attendees');

                // Real-time search with debouncing
                searchInput.addEventListener('input', function() {
                    const query = this.value.trim();
                    
                    // Clear previous timeout
                    if (searchTimeout) {
                        clearTimeout(searchTimeout);
                    }
                    
                    // Debounce search for better performance
                    searchTimeout = setTimeout(() => {
                        performSearch(query);
                    }, 300);
                });

                // Enter key support
                searchInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        if (searchTimeout) {
                            clearTimeout(searchTimeout);
                        }
                        performSearch(this.value.trim());
                    }
                });

                // Clear search functionality
                if (searchClearBtn) {
                    searchClearBtn.addEventListener('click', clearSearch);
                }
            }

            // Perform search with highlighting
            function performSearch(query) {
                const searchInput = document.getElementById('attendeeSearch');
                const searchResultCount = document.getElementById('searchResultCount');
                const searchClearBtn = document.getElementById('searchClearBtn');
                const noResultsMessage = document.getElementById('noResultsMessage');
                const attendeesTable = document.getElementById('attendeesTable');

                if (!query) {
                    clearSearch();
                    return;
                }

                console.log('Performing search for:', query);

                // Convert query to lowercase for case-insensitive search
                const searchQuery = query.toLowerCase();
                const searchTerms = searchQuery.split(' ').filter(term => term.length > 0);
                
                let visibleCount = 0;
                let matchedAttendees = [];

                allAttendees.forEach(attendee => {
                    const row = attendee.element;
                    const searchData = attendee.searchData;
                    
                    // Check if all search terms are found in the searchable data
                    const matchesAllTerms = searchTerms.every(term => 
                        searchData.includes(term)
                    );

                    if (matchesAllTerms && searchTerms.length > 0) {
                        // Show matching row
                        row.classList.remove('search-hidden');
                        row.classList.add('search-match');
                        
                        // Highlight matching text in visible cells
                        highlightSearchTerms(row, searchTerms);
                        
                        visibleCount++;
                        matchedAttendees.push(attendee);
                    } else {
                        // Hide non-matching row
                        row.classList.add('search-hidden');
                        row.classList.remove('search-match');
                        
                        // Remove any existing highlights
                        removeHighlights(row);
                    }
                });

                // Update search stats
                updateSearchStats(visibleCount, query);

                // Show/hide no results message
                if (visibleCount === 0) {
                    noResultsMessage.style.display = 'block';
                    attendeesTable.style.display = 'none';
                } else {
                    noResultsMessage.style.display = 'none';
                    attendeesTable.style.display = 'table';
                }

                // Show clear button
                if (searchClearBtn) {
                    searchClearBtn.style.display = 'inline-block';
                }

                console.log(`Search completed: ${visibleCount} results found for "${query}"`);
            }

            // Highlight search terms in row
            function highlightSearchTerms(row, searchTerms) {
                // Remove existing highlights first
                removeHighlights(row);

                // Get text cells (exclude QR code and action cells)
                const textCells = [
                    row.querySelector('.attendee-name'),
                    row.querySelector('.attendee-email'),
                    row.querySelector('.attendee-student-id'),
                    row.querySelector('.attendee-class')
                ].filter(cell => cell);

                textCells.forEach(cell => {
                    const originalText = cell.textContent;
                    let highlightedText = originalText;

                    // Highlight each search term
                    searchTerms.forEach(term => {
                        if (term.length > 0) {
                            const regex = new RegExp(`(${escapeRegExp(term)})`, 'gi');
                            highlightedText = highlightedText.replace(regex, '<span class="search-highlight">$1</span>');
                        }
                    });

                    // Only update if highlighting was applied
                    if (highlightedText !== originalText) {
                        cell.innerHTML = highlightedText;
                    }
                });
            }

            // Remove highlights from row
            function removeHighlights(row) {
                const highlightedElements = row.querySelectorAll('.search-highlight');
                highlightedElements.forEach(element => {
                    const parent = element.parentNode;
                    parent.replaceChild(document.createTextNode(element.textContent), element);
                    parent.normalize(); // Merge adjacent text nodes
                });
            }

            // Escape special regex characters
            function escapeRegExp(string) {
                return string.replace(/[.*+?^${}()|[\]\\]/g, '\\        @keyframes highlightRow');
            }

            // Update search statistics
            function updateSearchStats(visibleCount, query) {
                const searchResultCount = document.getElementById('searchResultCount');
                
                if (searchResultCount) {
                    if (visibleCount === 0) {
                        searchResultCount.innerHTML = `<i class="fas fa-exclamation-circle"></i> Không tìm thấy kết quả cho "${query}"`;
                        searchResultCount.style.color = 'var(--danger-light)';
                    } else if (visibleCount === originalResultCount) {
                        searchResultCount.innerHTML = `<i class="fas fa-check-circle"></i> Hiển thị tất cả ${visibleCount} người tham gia`;
                        searchResultCount.style.color = 'var(--success-light)';
                    } else {
                        searchResultCount.innerHTML = `<i class="fas fa-search"></i> Tìm thấy ${visibleCount} trong ${originalResultCount} người tham gia`;
                        searchResultCount.style.color = 'var(--secondary-light)';
                    }
                }
            }

            // Clear search function (global scope)
            window.clearSearch = function() {
                const searchInput = document.getElementById('attendeeSearch');
                const searchResultCount = document.getElementById('searchResultCount');
                const searchClearBtn = document.getElementById('searchClearBtn');
                const noResultsMessage = document.getElementById('noResultsMessage');
                const attendeesTable = document.getElementById('attendeesTable');

                // Clear search input
                if (searchInput) {
                    searchInput.value = '';
                    searchInput.focus();
                }

                // Clear search timeout
                if (searchTimeout) {
                    clearTimeout(searchTimeout);
                    searchTimeout = null;
                }

                // Show all attendees
                allAttendees.forEach(attendee => {
                    const row = attendee.element;
                    row.classList.remove('search-hidden', 'search-match');
                    removeHighlights(row);
                });

                // Reset search stats
                if (searchResultCount) {
                    searchResultCount.innerHTML = `Hiển thị ${originalResultCount} người tham gia`;
                    searchResultCount.style.color = 'var(--foreground-muted)';
                }

                // Hide clear button
                if (searchClearBtn) {
                    searchClearBtn.style.display = 'none';
                }

                // Show table and hide no results message
                if (attendeesTable) {
                    attendeesTable.style.display = 'table';
                }
                if (noResultsMessage) {
                    noResultsMessage.style.display = 'none';
                }

                console.log('Search cleared');
            };

            // File upload functionality
            function initializeFileUpload() {
                const fileUploadArea = document.getElementById('fileUploadArea');
                const fileInput = document.getElementById('fileInput');
                const selectedFileName = document.getElementById('selectedFileName');

                if (!fileUploadArea || !fileInput) return;

                // File selection
                fileInput.addEventListener('change', function() {
                    if (this.files && this.files[0]) {
                        selectedFileName.querySelector('span').textContent = this.files[0].name;
                        selectedFileName.style.display = 'block';
                    }
                });

                // Drag and drop
                ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                    fileUploadArea.addEventListener(eventName, preventDefaults, false);
                });

                function preventDefaults(e) {
                    e.preventDefault();
                    e.stopPropagation();
                }

                ['dragenter', 'dragover'].forEach(eventName => {
                    fileUploadArea.addEventListener(eventName, () => {
                        fileUploadArea.classList.add('dragover');
                    }, false);
                });

                ['dragleave', 'drop'].forEach(eventName => {
                    fileUploadArea.addEventListener(eventName, () => {
                        fileUploadArea.classList.remove('dragover');
                    }, false);
                });

                fileUploadArea.addEventListener('drop', (e) => {
                    const files = e.dataTransfer.files;
                    if (files.length > 0) {
                        fileInput.files = files;
                        selectedFileName.querySelector('span').textContent = files[0].name;
                        selectedFileName.style.display = 'block';
                    }
                }, false);
            }

            // Form handlers
            function initializeForms() {
                const addEventForm = document.getElementById('addEventForm');
                const addAttendeeForm = document.getElementById('addAttendeeForm');
                const editAttendeeForm = document.getElementById('editAttendeeForm');

                if (addEventForm) {
                    addEventForm.addEventListener('submit', function(e) {
                        e.preventDefault();
                        submitForm(this, 'create_event', 'createEventBtn', 'addEventModal');
                    });
                }

                if (addAttendeeForm) {
                    addAttendeeForm.addEventListener('submit', function(e) {
                        e.preventDefault();
                        console.log('Add attendee form submitted');
                        
                        // Kiểm tra event_id
                        const eventIdInput = this.querySelector('input[name="event_id"]');
                        const eventId = eventIdInput ? eventIdInput.value : '<?php echo $selected_event_id; ?>';
                        
                        if (!eventId) {
                            showAlert('Vui lòng chọn sự kiện trước khi thêm người tham gia!', 'error');
                            return;
                        }
                        
                        // Update event_id nếu cần
                        if (eventIdInput) {
                            eventIdInput.value = eventId;
                        }
                        
                        console.log('Event ID:', eventId);
                        submitForm(this, 'add_attendee', 'addAttendeeBtn', 'addAttendeeModal');
                    });
                }

                if (editAttendeeForm) {
                    editAttendeeForm.addEventListener('submit', function(e) {
                        e.preventDefault();
                        console.log('Edit attendee form submitted');
                        submitForm(this, 'edit_attendee', 'updateAttendeeBtn', 'editAttendeeModal');
                    });
                }
            }

            // Modal handlers
            function initializeModalHandlers() {
                // Stop scanning when modal is closed and reset states
                const scannerModal = document.getElementById('scannerModal');
                if (scannerModal) {
                    scannerModal.addEventListener('hidden.bs.modal', function() {
                        stopScanning();
                        
                        // Reset all states
                        isProcessing = false;
                        pauseAfterScan = false;
                        lastScanTime = 0;
                        
                        // Reset scanner container classes
                        const scannerContainer = document.querySelector('.scanner-container');
                        if (scannerContainer) {
                            scannerContainer.classList.remove('full-frame', 'permission-denied');
                        }
                        
                        // Reset UI
                        document.getElementById('scanResult').style.display = 'none';
                        document.getElementById('scanStatus').textContent = 'Máy quét sẵn sàng khởi động';
                        document.getElementById('scanStatus').className = 'scan-status stopped';
                        document.getElementById('startScanBtn').style.display = 'inline-flex';
                        document.getElementById('stopScanBtn').style.display = 'none';
                        document.getElementById('resumeScanBtn').style.display = 'none';
                        
                        // If scanner container was replaced due to permission error, restore it
                        if (!document.getElementById('qr-reader')) {
                            location.reload();
                        }
                    });
                }
            }

            // Keyboard shortcuts
            function initializeKeyboardShortcuts() {
                document.addEventListener('keydown', function(e) {
                    // Press 'S' to open scanner (when not in input field)
                    if (e.key.toLowerCase() === 's' && !e.target.matches('input, textarea, select')) {
                        e.preventDefault();
                        const scannerModal = document.getElementById('scannerModal');
                        if (scannerModal && !document.body.classList.contains('modal-open')) {
                            new bootstrap.Modal(scannerModal).show();
                        }
                    }
                    
                    // Press 'F' to focus search (when not in input field)
                    if (e.key.toLowerCase() === 'f' && !e.target.matches('input, textarea, select') && !e.ctrlKey && !e.metaKey) {
                        e.preventDefault();
                        const searchInput = document.getElementById('attendeeSearch');
                        if (searchInput) {
                            searchInput.focus();
                            searchInput.select();
                        }
                    }
                    
                    // Press 'Escape' to clear search or stop scanning
                    if (e.key === 'Escape') {
                        if (document.getElementById('attendeeSearch').value) {
                            clearSearch();
                        } else if (isScanning) {
                            stopScanning();
                        }
                    }
                });
            }

            function submitForm(form, action, buttonId, modalId) {
                console.log('submitForm called:', { action, buttonId, modalId });
                
                const btn = document.getElementById(buttonId);
                const spinner = btn.querySelector('.loading-spinner');
                const btnText = btn.innerHTML;
                
                // Show loading state
                btn.disabled = true;
                spinner.style.display = 'inline-block';
                
                const formData = new FormData(form);
                formData.append('action', action);
                formData.append('ajax', '1');
                
                // Debug form data
                console.log('Form data:');
                for (let [key, value] of formData.entries()) {
                    console.log(key, value);
                }
                
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    console.log('Response status:', response.status);
                    return response.json();
                })
                .then(data => {
                    console.log('Response data:', data);
                    if (data.success) {
                        showAlert(data.message, 'success');
                        bootstrap.Modal.getInstance(document.getElementById(modalId)).hide();
                        form.reset();
                        
                        // Handle different actions
                        if (action === 'create_event') {
                            setTimeout(() => {
                                window.location.href = `?event_id=${data.event_id}`;
                            }, 1000);
                        } else if (action === 'add_attendee' || action === 'edit_attendee') {
                            // Reload page to show new/updated attendee - real-time add is complex
                            setTimeout(() => {
                                window.location.reload();
                            }, 1000);
                        } else {
                            setTimeout(() => {
                                window.location.reload();
                            }, 1000);
                        }
                    } else {
                        showAlert(data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showAlert('Đã xảy ra lỗi. Vui lòng thử lại.', 'error');
                })
                .finally(() => {
                    // Reset button state
                    btn.disabled = false;
                    spinner.style.display = 'none';
                });
            }

            // QR Scanner functionality with cooldown
            function startScanning() {
                console.log('Start scanning clicked');
                
                // Check if Html5Qrcode is available
                if (typeof Html5Qrcode === 'undefined') {
                    console.error('Html5Qrcode library not loaded!');
                    showAlert('Thư viện quét QR chưa được tải. Vui lòng làm mới trang.', 'error');
                    return;
                }
                
                if (isScanning) {
                    console.log('Already scanning, returning');
                    return;
                }

                // Reset states
                isProcessing = false;
                pauseAfterScan = false;

                // Add visual feedback
                const btn = document.getElementById('startScanBtn');
                btn.textContent = 'Đang khởi động camera...';
                btn.disabled = true;

                try {
                    html5QrCode = new Html5Qrcode("qr-reader");
                    console.log('Html5Qrcode instance created');
                } catch (error) {
                    console.error('Failed to create Html5Qrcode instance:', error);
                    showAlert('Không thể khởi tạo máy quét. Vui lòng làm mới trang.', 'error');
                    btn.textContent = 'Bật Camera';
                    btn.disabled = false;
                    return;
                }
                
                // Cấu hình tối ưu cho quét nhanh hơn
                const config = { 
                    fps: 30, // Tăng FPS để detect nhanh hơn
                    aspectRatio: 1.0,
                    disableFlip: false,
                    experimentalFeatures: {
                        useBarCodeDetectorIfSupported: true
                    },
                    rememberLastUsedCamera: true,
                    supportedScanTypes: [Html5QrcodeScanType.SCAN_TYPE_CAMERA]
                };

                // Try different camera constraints for better compatibility
                const cameraConfig = [
                    { facingMode: "environment" }, // Back camera first
                    { facingMode: "user" }, // Front camera fallback
                    { video: true } // Any camera as last resort
                ];

                function tryStartCamera(configIndex = 0) {
                    console.log(`Trying camera config ${configIndex}:`, cameraConfig[configIndex]);
                    
                    if (configIndex >= cameraConfig.length) {
                        console.error('All camera configs failed');
                        // Show permission denied UI
                        showCameraPermissionDenied();
                        btn.textContent = 'Bật Camera';
                        btn.disabled = false;
                        return;
                    }

                    html5QrCode.start(
                        cameraConfig[configIndex],
                        config,
                        onScanSuccess,
                        onScanFailure
                    ).then(() => {
                        console.log('Camera started successfully');
                        isScanning = true;
                        isProcessing = false;
                        pauseAfterScan = false;
                        
                        // Update UI
                        btn.textContent = 'Start Camera';
                        btn.disabled = false;
                        document.getElementById('startScanBtn').style.display = 'none';
                        document.getElementById('stopScanBtn').style.display = 'inline-flex';
                        document.getElementById('resumeScanBtn').style.display = 'none';
                        document.getElementById('scanStatus').textContent = 'Máy quét đang hoạt động - Hướng camera vào mã QR bất kỳ đâu';
                        document.getElementById('scanStatus').className = 'scan-status active';
                        
                        // Enable full-frame scanning
                        document.querySelector('.scanner-container').classList.add('full-frame');
                        
                        console.log('Camera started successfully with config:', configIndex);
                        showAlert('Đã bật camera! Bạn có thể quét ở bất kỳ đâu trong khung hình.', 'success');
                    }).catch(err => {
                        console.error(`Camera start failed with config ${configIndex}:`, err);
                        // Try next camera configuration
                        setTimeout(() => tryStartCamera(configIndex + 1), 1000);
                    });
                }

                // Add camera permission denied handler
                function showCameraPermissionDenied() {
                    const scannerContainer = document.querySelector('.scanner-container');
                    scannerContainer.classList.add('permission-denied');
                    scannerContainer.innerHTML = `
                        <div class="permission-denied-content">
                            <i class="fas fa-camera-slash"></i>
                            <h4>Cần Quyền Truy Cập Camera</h4>
                            <p>Vui lòng cho phép truy cập camera để quét mã QR.</p>
                            <div class="d-flex flex-column gap-2">
                                <small>• Nhấp vào biểu tượng camera trong thanh địa chỉ trình duyệt</small>
                                <small>• Chọn "Cho phép" để cấp quyền camera</small>
                                <small>• Làm mới trang và thử lại</small>
                            </div>
                            <button class="action-btn secondary mt-3" onclick="location.reload()">
                                <i class="fas fa-refresh"></i>
                                Làm Mới Trang
                            </button>
                        </div>
                    `;
                    
                    document.getElementById('scanStatus').textContent = 'Bị từ chối quyền camera';
                    document.getElementById('scanStatus').className = 'scan-status error';
                    showAlert('Truy cập camera bị từ chối. Vui lòng cấp quyền camera và làm mới trang.', 'error');
                }

                // Start with first camera config
                tryStartCamera(0);
            }

            function stopScanning() {
                if (!isScanning || !html5QrCode) return;

                html5QrCode.stop().then(() => {
                    isScanning = false;
                    isProcessing = false;
                    pauseAfterScan = false;
                    document.getElementById('startScanBtn').style.display = 'inline-flex';
                    document.getElementById('stopScanBtn').style.display = 'none';
                    document.getElementById('resumeScanBtn').style.display = 'none';
                    document.getElementById('scanResult').style.display = 'none';
                    document.getElementById('scanStatus').textContent = 'Máy quét đã dừng';
                    document.getElementById('scanStatus').className = 'scan-status stopped';
                    
                    // Reset scanner container
                    const scannerContainer = document.querySelector('.scanner-container');
                    scannerContainer.classList.remove('full-frame', 'permission-denied');
                    
                    // Restore original scanner HTML if it was replaced
                    if (scannerContainer.classList.contains('permission-denied')) {
                        location.reload(); // Easier to reload than reconstruct
                    }
                }).catch(err => {
                    console.error("Camera stop failed:", err);
                });
            }

            function pauseScanning() {
                if (!isScanning || !html5QrCode) return;

                html5QrCode.pause(true);
                pauseAfterScan = true;
                document.getElementById('stopScanBtn').style.display = 'none';
                document.getElementById('resumeScanBtn').style.display = 'inline-flex';
                document.getElementById('scanStatus').textContent = 'Máy quét tạm dừng - Nhấn Tiếp Tục để quét tiếp';
                document.getElementById('scanStatus').className = 'scan-status paused';
            }

            function resumeScanning() {
                if (!isScanning || !html5QrCode || !pauseAfterScan) return;

                html5QrCode.resume();
                pauseAfterScan = false;
                isProcessing = false;
                document.getElementById('stopScanBtn').style.display = 'inline-flex';
                document.getElementById('resumeScanBtn').style.display = 'none';
                document.getElementById('scanStatus').textContent = 'Máy quét đang hoạt động - Hướng camera vào mã QR bất kỳ đâu';
                document.getElementById('scanStatus').className = 'scan-status active';
                
                // Ensure full-frame scanning
                document.querySelector('.scanner-container').classList.add('full-frame');
                
                // Hide previous result
                setTimeout(() => {
                    document.getElementById('scanResult').style.display = 'none';
                }, 1000);
            }

            function onScanSuccess(decodedText, decodedResult) {
                const currentTime = Date.now();
                
                // Giảm cooldown và loại bỏ điều kiện pause
                if (isProcessing || (currentTime - lastScanTime) < scanCooldown) {
                    return; // Skip this scan
                }
                
                // Mark as processing and update last scan time
                isProcessing = true;
                lastScanTime = currentTime;
                
                // Update status
                document.getElementById('scanStatus').textContent = 'Đang xử lý mã QR...';
                document.getElementById('scanStatus').className = 'scan-status processing';
                
                // Process the QR code immediately
                processQRCode(decodedText);
            }

            function onScanFailure(error) {
                // Handle scan failure (usually just noise, ignore)
            }

            function processQRCode(qrCode) {
                const formData = new FormData();
                formData.append('action', 'qr_scan');
                formData.append('qr_code', qrCode);
                formData.append('ajax', '1');

                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    console.log('QR scan response:', data);
                    displayScanResult(data);
                    
                    // Update table and stats in real-time if successful
                    if (data.success && data.attendee) {
                        console.log('Updating UI with attendee data:', data.attendee);
                        updateAttendeeStatus(data.attendee, data.action);
                        updateStatistics();
                    }
                    
                    // Reset processing state after showing result - không pause nữa
                    setTimeout(() => {
                        isProcessing = false;
                    }, 1000); // Chỉ chờ 1 giây
                })
                .catch(error => {
                    console.error('Error:', error);
                    showAlert('Lỗi khi xử lý quét QR', 'error');
                    isProcessing = false;
                    document.getElementById('scanStatus').textContent = 'Lỗi - Sẵn sàng quét lại';
                    document.getElementById('scanStatus').className = 'scan-status error';
                });
            }

            // Real-time update functions (fixed)
            function updateAttendeeStatus(attendee, action) {
                console.log('Updating attendee status:', attendee.name, action);
                const table = document.getElementById('attendeesTable');
                if (!table) return;
                
                const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
                
                for (let row of rows) {
                    const attendeeId = row.getAttribute('data-attendee-id');
                    console.log('Checking row attendee ID:', attendeeId, 'vs', attendee.id);
                    
                    if (attendeeId && parseInt(attendeeId) === parseInt(attendee.id)) {
                        console.log('Found matching row, updating...');
                        
                        // Update status cell (cột 7, index 6)
                        const statusCell = row.querySelector('.status-cell');
                        if (statusCell) {
                            const newStatus = action === 'check_in' ? 'checked-in' : 'checked-out';
                            const statusText = action === 'check_in' ? 'Check-in' : 'Check-out';
                            
                            statusCell.innerHTML = `<span class="status-badge ${newStatus}">${statusText}</span>`;
                            
                            // Add animation effect
                            statusCell.style.background = 'rgba(0, 224, 64, 0.2)';
                            setTimeout(() => {
                                statusCell.style.background = '';
                            }, 2000);
                        }
                        
                        // Update time cells
                        const now = new Date();
                        const timeString = now.toLocaleDateString('vi-VN', { 
                            day: '2-digit',
                            month: '2-digit', 
                            year: 'numeric'
                        }) + ' ' + now.toLocaleTimeString('vi-VN', {
                            hour: '2-digit',
                            minute: '2-digit'
                        });
                        
                        if (action === 'check_in') {
                            const checkinCell = row.querySelector('.checkin-time');
                            if (checkinCell) {
                                checkinCell.textContent = timeString;
                                checkinCell.style.background = 'rgba(0, 224, 64, 0.2)';
                                setTimeout(() => {
                                    checkinCell.style.background = '';
                                }, 2000);
                            }
                        } else if (action === 'check_out') {
                            const checkoutCell = row.querySelector('.checkout-time');
                            if (checkoutCell) {
                                checkoutCell.textContent = timeString;
                                checkoutCell.style.background = 'rgba(157, 80, 255, 0.2)';
                                setTimeout(() => {
                                    checkoutCell.style.background = '';
                                }, 2000);
                            }
                        }
                        
                        // Add row highlight animation
                        row.classList.add('highlight');
                        setTimeout(() => {
                            row.classList.remove('highlight');
                        }, 3000);
                        
                        console.log('Row updated successfully');
                        break;
                    }
                }
            }

            function updateStatistics() {
                // Recount statistics from current table
                const table = document.getElementById('attendeesTable');
                if (!table) return;
                
                const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
                let total = rows.length;
                let pending = 0, checkedIn = 0, checkedOut = 0;
                
                for (let row of rows) {
                    const statusCell = row.querySelector('.status-cell');
                    if (statusCell) {
                        const statusBadge = statusCell.querySelector('.status-badge');
                        if (statusBadge) {
                            if (statusBadge.classList.contains('pending')) pending++;
                            else if (statusBadge.classList.contains('checked-in')) checkedIn++;
                            else if (statusBadge.classList.contains('checked-out')) checkedOut++;
                        }
                    }
                }
                
                console.log('Updated stats:', { total, pending, checkedIn, checkedOut });
                
                // Update stat cards with animation
                updateStatCard('total', total);
                updateStatCard('pending', pending);
                updateStatCard('checked-in', checkedIn);
                updateStatCard('checked-out', checkedOut);
            }

            function updateStatCard(type, count) {
                const statCards = document.querySelectorAll('.stat-card');
                for (let card of statCards) {
                    let targetClass = type;
                    if (type === 'checked-in') targetClass = 'checkedin';
                    if (type === 'checked-out') targetClass = 'checkedout';
                    
                    if (card.classList.contains(targetClass)) {
                        const numberEl = card.querySelector('.stat-number');
                        if (numberEl) {
                            // Add pulse animation
                            card.style.transform = 'scale(1.05)';
                            card.style.boxShadow = 'var(--glow-success)';
                            numberEl.textContent = count;
                            
                            setTimeout(() => {
                                card.style.transform = '';
                                card.style.boxShadow = '';
                            }, 500);
                        }
                        break;
                    }
                }
            }

            function displayScanResult(response) {
                const scanResult = document.getElementById('scanResult');
                const scanMessage = document.getElementById('scanMessage');
                const attendeeInfo = document.getElementById('attendeeInfo');

                scanResult.style.display = 'block';
                
                if (response.success) {
                    scanResult.className = 'scan-result success';
                    scanMessage.innerHTML = `<i class="fas fa-check-circle"></i> ${response.message}`;
                    
                    // Update scan status
                    document.getElementById('scanStatus').textContent = 'Quét thành công! Máy quét tiếp tục.';
                    document.getElementById('scanStatus').className = 'scan-status success';
                    
                    if (response.attendee) {
                        const attendee = response.attendee;
                        attendeeInfo.innerHTML = `
                            <div class="info-item">
                                <div class="info-label">Họ Tên</div>
                                <div class="info-value">${attendee.name}</div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Email</div>
                                <div class="info-value">${attendee.email || 'Không có'}</div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Mã Học Sinh</div>
                                <div class="info-value">${attendee.student_id || 'Không có'}</div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Lớp</div>
                                <div class="info-value">${attendee.class || 'Không có'}</div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Sự Kiện</div>
                                <div class="info-value">${attendee.event_name}</div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Hành Động</div>
                                <div class="info-value success-action">${response.action === 'check_in' ? '✅ Đã Check-in' : '🏁 Đã Check-out'}</div>
                            </div>
                        `;
                    }

                    showAlert(response.message, 'success');
                    
                    // Tiếp tục quét sau 2 giây thay vì pause
                    setTimeout(() => {
                        isProcessing = false;
                        document.getElementById('scanStatus').textContent = 'Máy quét đang hoạt động - Hướng camera vào mã QR bất kỳ đâu';
                        document.getElementById('scanStatus').className = 'scan-status active';
                        document.getElementById('scanResult').style.display = 'none';
                    }, 2000);
                    
                } else {
                    scanResult.className = 'scan-result error';
                    scanMessage.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${response.message}`;
                    attendeeInfo.innerHTML = '';
                    showAlert(response.message, 'error');
                    
                    // Update scan status for error
                    document.getElementById('scanStatus').textContent = 'Quét thất bại - Sẵn sàng thử lại';
                    document.getElementById('scanStatus').className = 'scan-status error';
                    
                    // Reset processing sau lỗi
                    setTimeout(() => {
                        isProcessing = false;
                        document.getElementById('scanStatus').textContent = 'Máy quét đang hoạt động - Hướng camera vào mã QR bất kỳ đâu';
                        document.getElementById('scanStatus').className = 'scan-status active';
                        document.getElementById('scanResult').style.display = 'none';
                    }, 2000);
                }
            }

            // Animation for elements
            animateElements('.card-container', 100);
            animateElements('.stat-card', 50);
        });

        function changeEvent(eventId) {
            if (eventId) {
                window.location.href = `?event_id=${eventId}`;
            }
        }

        // Edit and Delete Attendee Functions
        function editAttendee(attendeeId) {
            console.log('Edit attendee:', attendeeId);
            
            // Get attendee data
            const formData = new FormData();
            formData.append('action', 'get_attendee');
            formData.append('attendee_id', attendeeId);
            formData.append('ajax', '1');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const attendee = data.attendee;
                    
                    // Fill form
                    document.getElementById('editAttendeeId').value = attendee.id;
                    document.getElementById('editName').value = attendee.name || '';
                    document.getElementById('editEmail').value = attendee.email || '';
                    document.getElementById('editPhone').value = attendee.phone || '';
                    document.getElementById('editStudentId').value = attendee.student_id || '';
                    document.getElementById('editClass').value = attendee.class || '';
                    
                    // Show modal
                    new bootstrap.Modal(document.getElementById('editAttendeeModal')).show();
                } else {
                    showAlert(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('Đã xảy ra lỗi khi tải thông tin!', 'error');
            });
        }

        function deleteAttendee(attendeeId) {
            if (confirm('Bạn có chắc chắn muốn xóa người tham gia này không?')) {
                const formData = new FormData();
                formData.append('action', 'delete_attendee');
                formData.append('attendee_id', attendeeId);
                formData.append('ajax', '1');
                
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showAlert(data.message, 'success');
                        
                        // Remove row from table and update search if needed
                        const row = document.querySelector(`tr[data-attendee-id="${attendeeId}"]`);
                        if (row) {
                            row.style.animation = 'fadeOut 0.5s ease';
                            setTimeout(() => {
                                row.remove();
                                
                                // Update original count and refresh search
                                originalResultCount--;
                                
                                // Update allAttendees array
                                allAttendees = allAttendees.filter(a => a.id !== attendeeId.toString());
                                
                                // Refresh search if active
                                const searchInput = document.getElementById('attendeeSearch');
                                if (searchInput && searchInput.value.trim()) {
                                    // Re-perform search with current query
                                    performSearch(searchInput.value.trim());
                                } else {
                                    // Update result count display
                                    const searchResultCount = document.getElementById('searchResultCount');
                                    if (searchResultCount) {
                                        searchResultCount.innerHTML = `Hiển thị ${originalResultCount} người tham gia`;
                                    }
                                }
                                
                                updateStatistics();
                            }, 500);
                        }
                    } else {
                        showAlert(data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showAlert('Đã xảy ra lỗi khi xóa!', 'error');
                });
            }
        }

        function deleteEvent(eventId) {
            if (confirm('Bạn có chắc chắn muốn xóa sự kiện này và tất cả người tham gia không?')) {
                const formData = new FormData();
                formData.append('action', 'delete_event');
                formData.append('event_id', eventId);
                formData.append('ajax', '1');

                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showAlert(data.message, 'success');
                        setTimeout(() => {
                            window.location.href = window.location.pathname;
                        }, 1000);
                    } else {
                        showAlert(data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showAlert('Đã xảy ra lỗi, vui lòng thử lại!', 'error');
                });
            }
        }

        function showQRModal(qrPath, attendeeName) {
            document.getElementById('qrCodeImage').src = qrPath;
            document.getElementById('qrAttendName').textContent = attendeeName;
            new bootstrap.Modal(document.getElementById('qrViewModal')).show();
        }

        function downloadQR() {
            const img = document.getElementById('qrCodeImage');
            const link = document.createElement('a');
            link.download = 'qr-code.png';
            link.href = img.src;
            link.click();
        }

        function showAlert(message, type = 'success') {
            const alertContainer = document.getElementById('alertContainer');
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert-message alert-${type}`;
            alertDiv.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
                ${message}
            `;
            alertContainer.appendChild(alertDiv);
            
            setTimeout(() => {
                alertDiv.remove();
            }, 4500);
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

        // Float animation
        document.head.appendChild(document.createElement('style')).textContent = `
            @keyframes float {
                0% { transform: translateY(0px); }
                50% { transform: translateY(-20px); }
                100% { transform: translateY(0px); }
            }

            @keyframes fadeOut {
                from { opacity: 1; transform: scale(1); }
                to { opacity: 0; transform: scale(0.8); }
            }
        `;
    </script>
</body>
</html>