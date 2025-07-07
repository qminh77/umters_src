<?php
session_start();

// Bật báo lỗi để debug (tắt trên production)
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);
// ini_set('log_errors', 1);
// ini_set('error_log', '/home/u459537937/domains/umters.club/public_html/error_log.txt');

// Kiểm tra tệp db_config.php
if (!file_exists('db_config.php') || !is_readable('db_config.php')) {
    error_log("File db_config.php not found or not readable");
    die("Lỗi hệ thống: Không tìm thấy hoặc không đọc được tệp cấu hình cơ sở dữ liệu.");
}
include 'db_config.php';

// Kiểm tra kết nối database
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    die("Lỗi hệ thống: Không thể kết nối cơ sở dữ liệu.");
}

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header("Location: /login");
    exit;
}

// Tạo CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Lấy user_id từ session
$user_id = (int)$_SESSION['user_id'];

// Khởi tạo các biến
$edit_error = '';
$message = '';
$result = '';

// Lấy thông tin user an toàn
$stmt = $conn->prepare("SELECT username, full_name, is_main_admin, is_super_admin FROM users WHERE id = ?");
if (!$stmt) {
    error_log("Prepare failed: " . $conn->error);
    $edit_error = "Lỗi kết nối cơ sở dữ liệu. Vui lòng thử lại sau.";
} else {
    $stmt->bind_param("i", $user_id);
    if (!$stmt->execute()) {
        error_log("Execute failed: " . $stmt->error);
        $edit_error = "Lỗi truy vấn cơ sở dữ liệu. Vui lòng thử lại sau.";
    } else {
        $result_user = $stmt->get_result();
        if ($result_user && $result_user->num_rows > 0) {
            $user = $result_user->fetch_assoc();
        } else {
            $edit_error = "Lỗi khi lấy thông tin người dùng.";
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

// Các hàm hỗ trợ
function rgbToHsl($r, $g, $b) {
    try {
        $r /= 255; $g /= 255; $b /= 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $h = $s = $l = ($max + $min) / 2;
        if ($max != $min) {
            $d = $max - $min;
            $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
            switch ($max) {
                case $r: $h = ($g - $b) / $d + ($g < $b ? 6 : 0); break;
                case $g: $h = ($b - $r) / $d + 2; break;
                case $b: $h = ($r - $g) / $d + 4; break;
            }
            $h /= 6;
        }
        return sprintf("hsl(%d, %d%%, %d%%)", $h * 360, $s * 100, $l * 100);
    } catch (Exception $e) {
        error_log("RGB to HSL error: " . $e->getMessage());
        return "Lỗi khi chuyển đổi màu HSL.";
    }
}

function strToBinary($str) {
    try {
        return implode(' ', array_map(function($c) { return sprintf("%08b", ord($c)); }, str_split($str)));
    } catch (Exception $e) {
        error_log("Binary encode error: " . $e->getMessage());
        return "Lỗi khi mã hóa nhị phân.";
    }
}

function binaryToStr($bin) {
    try {
        $chars = array_map(function($b) { return chr(bindec($b)); }, explode(' ', trim($bin)));
        return empty($chars) ? false : implode('', $chars);
    } catch (Exception $e) {
        error_log("Binary decode error: " . $e->getMessage());
        return false;
    }
}

function strToAscii($str) {
    try {
        return implode(' ', array_map('ord', str_split($str, 1)));
    } catch (Exception $e) {
        error_log("ASCII encode error: " . $e->getMessage());
        return "Lỗi khi mã hóa ASCII.";
    }
}

function asciiToStr($ascii) {
    try {
        $nums = explode(' ', trim($ascii));
        return empty($nums) ? false : implode('', array_map('chr', array_filter($nums, 'is_numeric')));
    } catch (Exception $e) {
        error_log("ASCII decode error: " . $e->getMessage());
        return false;
    }
}

function strToDecimal($str) {
    try {
        return implode(' ', array_map(function($c) { return ord($c); }, str_split($str, 1)));
    } catch (Exception $e) {
        error_log("Decimal encode error: " . $e->getMessage());
        return "Lỗi khi mã hóa Decimal.";
    }
}

function decimalToStr($dec) {
    try {
        $nums = explode(' ', trim($dec));
        return empty($nums) ? false : implode('', array_map('chr', array_filter($nums, 'is_numeric')));
    } catch (Exception $e) {
        error_log("Decimal decode error: " . $e->getMessage());
        return false;
    }
}

function strToOctal($str) {
    try {
        return implode(' ', array_map(function($c) { return sprintf("%o", ord($c)); }, str_split($str, 1)));
    } catch (Exception $e) {
        error_log("Octal encode error: " . $e->getMessage());
        return "Lỗi khi mã hóa Octal.";
    }
}

function octalToStr($oct) {
    try {
        $nums = explode(' ', trim($oct));
        return empty($nums) ? false : implode('', array_map(function($o) { return chr(octdec($o)); }, $nums));
    } catch (Exception $e) {
        error_log("Octal decode error: " . $e->getMessage());
        return false;
    }
}

function strToMorse($str) {
    try {
        $morse = ['A' => '.-', 'B' => '-...', 'C' => '-.-.', 'D' => '-..', 'E' => '.', 'F' => '..-.', 'G' => '--.', 'H' => '....', 'I' => '..', 'J' => '.---',
                  'K' => '-.-', 'L' => '.-..', 'M' => '--', 'N' => '-.', 'O' => '---', 'P' => '.--.', 'Q' => '--.-', 'R' => '.-.', 'S' => '...', 'T' => '-',
                  'U' => '..-', 'V' => '...-', 'W' => '.--', 'X' => '-..-', 'Y' => '-.--', 'Z' => '--..', '0' => '-----', '1' => '.----', '2' => '..---',
                  '3' => '...--', '4' => '....-', '5' => '.....', '6' => '-....', '7' => '--...', '8' => '---..', '9' => '----.', ' ' => '/'];
        return implode(' ', array_filter(array_map(function($c) use ($morse) { return $morse[strtoupper($c)] ?? ''; }, str_split($str, 1))));
    } catch (Exception $e) {
        error_log("Morse encode error: " . $e->getMessage());
        return "Lỗi khi mã hóa Morse.";
    }
}

function morseToStr($morse) {
    try {
        $morse_dict = array_flip(['.-' => 'A', '-...' => 'B', '-.-.' => 'C', '-..' => 'D', '.' => 'E', '..-.' => 'F', '--.' => 'G', '....' => 'H', '..' => 'I', '.---' => 'J',
                                  '-.-' => 'K', '.-..' => 'L', '--' => 'M', '-.' => 'N', '---' => 'O', '.--.' => 'P', '--.-' => 'Q', '.-.' => 'R', '...' => 'S', '-' => 'T',
                                  '..-' => 'U', '...-' => 'V', '.--' => 'W', '-..-' => 'X', '-.--' => 'Y', '--..' => 'Z', '-----' => '0', '.----' => '1', '..---' => '2',
                                  '...--' => '3', '....-' => '4', '.....' => '5', '-....' => '6', '--...' => '7', '---..' => '8', '----.' => '9', '/' => ' ']);
        $chars = array_map(function($m) use ($morse_dict) { return $morse_dict[$m] ?? ''; }, explode(' ', trim($morse)));
        return empty($chars) ? false : implode('', $chars);
    } catch (Exception $e) {
        error_log("Morse decode error: " . $e->getMessage());
        return false;
    }
}

function numberToWords($num) {
    try {
        if (!is_numeric($num)) return "Không phải số hợp lệ!";
        $ones = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
        $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
        $thousands = ['', 'thousand', 'million', 'billion'];

        $num = (int)$num;
        if ($num == 0) return 'zero';
        $words = [];
        for ($i = 0; $num > 0; $i++) {
            $chunk = $num % 1000;
            if ($chunk) {
                $sub_words = [];
                if ($chunk >= 100) {
                    $sub_words[] = $ones[floor($chunk / 100)] . ' hundred';
                    $chunk %= 100;
                }
                if ($chunk >= 20) {
                    $sub_words[] = $tens[floor($chunk / 10)];
                    $chunk %= 10;
                }
                if ($chunk > 0) $sub_words[] = $ones[$chunk];
                $words[] = implode(' ', $sub_words) . ($i > 0 ? ' ' . $thousands[$i] : '');
            }
            $num = floor($num / 1000);
        }
        return implode(', ', array_reverse($words));
    } catch (Exception $e) {
        error_log("Number to words error: " . $e->getMessage());
        return "Lỗi khi chuyển số thành chữ.";
    }
}

// Xử lý yêu cầu chuyển đổi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['convert']) && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $type = filter_input(INPUT_POST, 'convert_type', FILTER_SANITIZE_STRING);
    $input = filter_input(INPUT_POST, 'input', FILTER_SANITIZE_STRING);

    // Giới hạn độ dài input (1MB)
    if ($type !== 'image_to_base64' && strlen($input) > 1048576) {
        $message = "Dữ liệu đầu vào quá lớn! Tối đa 1MB.";
    } elseif (empty($type)) {
        $message = "Vui lòng chọn loại chuyển đổi!";
    } elseif (empty($input) && $type !== 'image_to_base64') {
        $message = "Vui lòng nhập dữ liệu!";
    } else {
        switch ($type) {
            case 'base64_encode':
                try {
                    $result = base64_encode($input);
                    $message = "Đã mã hóa Base64 thành công!";
                } catch (Exception $e) {
                    error_log("Base64 encode error: " . $e->getMessage());
                    $message = "Lỗi khi mã hóa Base64.";
                }
                break;
            case 'base64_decode':
                try {
                    $decoded = base64_decode($input, true);
                    $result = $decoded !== false ? $decoded : "Chuỗi Base64 không hợp lệ!";
                    $message = $decoded !== false ? "Đã giải mã Base64 thành công!" : "Lỗi giải mã Base64!";
                } catch (Exception $e) {
                    error_log("Base64 decode error: " . $e->getMessage());
                    $message = "Lỗi khi giải mã Base64.";
                }
                break;
            case 'base64_to_image':
                try {
                    $decoded = base64_decode($input, true);
                    if ($decoded !== false && preg_match('/^(?:[A-Za-z0-9+\/]{4})*(?:[A-Za-z0-9+\/]{2}==|[A-Za-z0-9+\/]{3}=)?$/', $input)) {
                        $image_path = 'Uploads/base64_' . time() . '.png';
                        if (!is_dir('Uploads')) {
                            mkdir('Uploads', 0777, true);
                            chmod('Uploads', 0755);
                        }
                        if (file_put_contents($image_path, $decoded)) {
                            $result = "<img src='$image_path' alt='Base64 Image' style='max-width: 300px;'>";
                            $message = "Đã chuyển Base64 thành hình ảnh thành công!";
                        } else {
                            $result = "Lỗi khi lưu hình ảnh!";
                            $message = "Lỗi lưu file!";
                        }
                    } else {
                        $result = "Chuỗi Base64 không hợp lệ!";
                        $message = "Lỗi chuyển đổi Base64 thành hình ảnh!";
                    }
                } catch (Exception $e) {
                    error_log("Base64 to image error: " . $e->getMessage());
                    $message = "Lỗi khi chuyển Base64 thành hình ảnh.";
                }
                break;
            case 'image_to_base64':
                try {
                    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                        // Kiểm tra loại file và kích thước (tối đa 5MB)
                        $allowed_types = ['image/png', 'image/jpeg', 'image/gif'];
                        $max_size = 5 * 1024 * 1024; // 5MB
                        $file_info = finfo_open(FILEINFO_MIME_TYPE);
                        $mime_type = finfo_file($file_info, $_FILES['image']['tmp_name']);
                        finfo_close($file_info);

                        if (!in_array($mime_type, $allowed_types)) {
                            $message = "Chỉ hỗ trợ file PNG, JPEG, GIF!";
                        } elseif ($_FILES['image']['size'] > $max_size) {
                            $message = "File quá lớn! Tối đa 5MB.";
                        } else {
                            $image = file_get_contents($_FILES['image']['tmp_name']);
                            $result = base64_encode($image);
                            $message = "Đã chuyển hình ảnh thành Base64 thành công!";
                        }
                    } else {
                        $result = "Vui lòng tải lên một hình ảnh!";
                        $message = "Lỗi tải lên hình ảnh: " . ($_FILES['image']['error'] ?? 'Không có file');
                    }
                } catch (Exception $e) {
                    error_log("Image to Base64 error: " . $e->getMessage());
                    $message = "Lỗi khi chuyển hình ảnh thành Base64.";
                }
                break;
            case 'url_encode':
                try {
                    $result = urlencode($input);
                    $message = "Đã mã hóa URL thành công!";
                } catch (Exception $e) {
                    error_log("URL encode error: " . $e->getMessage());
                    $message = "Lỗi khi mã hóa URL.";
                }
                break;
            case 'url_decode':
                try {
                    $result = urldecode($input);
                    $message = "Đã giải mã URL thành công!";
                } catch (Exception $e) {
                    error_log("URL decode error: " . $e->getMessage());
                    $message = "Lỗi khi giải mã URL.";
                }
                break;
            case 'color_convert':
                try {
                    $color = str_replace('#', '', $input);
                    if (preg_match('/^[0-9A-Fa-f]{6}$/', $color)) {
                        $r = hexdec(substr($color, 0, 2));
                        $g = hexdec(substr($color, 2, 2));
                        $b = hexdec(substr($color, 4, 2));
                        $result = "HEX: #$color\nRGB: ($r, $g, $b)\nHSL: " . rgbToHsl($r, $g, $b);
                        $message = "Đã chuyển đổi màu thành công!";
                    } else {
                        $result = "Mã màu HEX không hợp lệ!";
                        $message = "Lỗi chuyển đổi màu!";
                    }
                } catch (Exception $e) {
                    error_log("Color convert error: " . $e->getMessage());
                    $message = "Lỗi khi chuyển đổi màu.";
                }
                break;
            case 'binary_encode':
                $result = strToBinary($input);
                $message = "Đã chuyển thành nhị phân thành công!";
                break;
            case 'binary_decode':
                $result = binaryToStr($input);
                $message = $result !== false ? "Đã giải mã nhị phân thành công!" : "Chuỗi nhị phân không hợp lệ!";
                break;
            case 'hex_encode':
                try {
                    $result = bin2hex($input);
                    $message = "Đã chuyển thành Hex thành công!";
                } catch (Exception $e) {
                    error_log("Hex encode error: " . $e->getMessage());
                    $message = "Lỗi khi mã hóa Hex.";
                }
                break;
            case 'hex_decode':
                try {
                    $result = @hex2bin($input);
                    $message = $result !== false ? "Đã giải mã Hex thành công!" : "Chuỗi Hex không hợp lệ!";
                } catch (Exception $e) {
                    error_log("Hex decode error: " . $e->getMessage());
                    $message = "Lỗi khi giải mã Hex.";
                }
                break;
            case 'ascii_encode':
                $result = strToAscii($input);
                $message = "Đã chuyển thành ASCII thành công!";
                break;
            case 'ascii_decode':
                $result = asciiToStr($input);
                $message = $result !== false ? "Đã giải mã ASCII thành công!" : "Chuỗi ASCII không hợp lệ!";
                break;
            case 'decimal_encode':
                $result = strToDecimal($input);
                $message = "Đã chuyển thành Decimal thành công!";
                break;
            case 'decimal_decode':
                $result = decimalToStr($input);
                $message = $result !== false ? "Đã giải mã Decimal thành công!" : "Chuỗi Decimal không hợp lệ!";
                break;
            case 'octal_encode':
                $result = strToOctal($input);
                $message = "Đã chuyển thành Octal thành công!";
                break;
            case 'octal_decode':
                $result = octalToStr($input);
                $message = $result !== false ? "Đã giải mã Octal thành công!" : "Chuỗi Octal không hợp lệ!";
                break;
            case 'morse_encode':
                $result = strToMorse($input);
                $message = "Đã chuyển thành Morse thành công!";
                break;
            case 'morse_decode':
                $result = morseToStr($input);
                $message = $result !== false ? "Đã giải mã Morse thành công!" : "Chuỗi Morse không hợp lệ!";
                break;
            case 'number_to_words':
                try {
                    $result = numberToWords($input);
                    $message = is_numeric($input) ? "Đã chuyển số thành chữ thành công!" : "Vui lòng nhập số hợp lệ!";
                } catch (Exception $e) {
                    error_log("Number to words error: " . $e->getMessage());
                    $message = "Lỗi khi chuyển số thành chữ.";
                }
                break;
            default:
                $message = "Chức năng không hợp lệ!";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Công Cụ Chuyển Đổi - Quản Lý</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
        .dashboard-container {
            max-width: 1200px;
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

        .back-to-dashboard {
            padding: 0.75rem 1.25rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            color: var(--foreground);
            font-size: 0.875rem;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .back-to-dashboard:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        /* Message styles */
        .message-container {
            margin-bottom: 1.5rem;
        }

        .error-message, 
        .success-message {
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1rem;
            font-weight: 500;
            position: relative;
            padding-left: 3rem;
            animation: slideIn 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }

        @keyframes slideIn {
            from { transform: translateX(-20px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        .error-message {
            background: rgba(255, 61, 87, 0.1);
            color: #FF3D57;
            border-left: 4px solid #FF3D57;
        }

        .error-message::before {
            content: "\f071";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
        }

        .success-message {
            background: rgba(0, 224, 255, 0.1);
            color: var(--secondary);
            border-left: 4px solid var(--secondary);
        }

        .success-message::before {
            content: "\f00c";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
        }

        /* Content section */
        .content-section {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            position: relative;
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .content-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                radial-gradient(circle at 80% 20%, rgba(0, 224, 255, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 20% 80%, rgba(255, 61, 255, 0.1) 0%, transparent 50%);
            pointer-events: none;
            border-radius: var(--radius-lg);
        }

        .content-section:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            position: relative;
            padding-bottom: 0.75rem;
        }

        .section-title::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 50px;
            height: 3px;
            background: linear-gradient(to right, var(--primary), var(--secondary));
            border-radius: var(--radius-full);
        }

        .section-title i {
            color: var(--primary-light);
            background: linear-gradient(to right, var(--primary-light), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* Form container */
        .form-container {
            background: var(--card);
            border-radius: var(--radius);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .form-container:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow);
        }

        .form-group {
            margin-bottom: 1rem;
            width: 100%;
        }

        .form-group label {
            display: block;
            font-weight: 500;
            margin-bottom: 0.5rem;
            color: var(--foreground);
            font-size: 0.875rem;
        }

        .form-group select,
        .form-group textarea,
        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group input[type="file"] {
            width: 100%;
            padding: 0.75rem 1rem;
            background: rgba(18, 18, 42, 0.9);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            color: var(--foreground);
            font-family: 'Outfit', sans-serif;
            transition: all 0.3s ease;
        }
        
        /* Styling for dropdown options */
        select option {
            background-color: var(--surface);
            color: var(--foreground);
            padding: 10px;
        }

        .form-group textarea {
            min-height: 150px;
            resize: vertical;
        }

        .form-group input[type="file"] {
            border-style: dashed;
            cursor: pointer;
            padding: 1rem;
        }

        .form-group select:focus,
        .form-group textarea:focus,
        .form-group input[type="text"]:focus,
        .form-group input[type="number"]:focus,
        .form-group input[type="file"]:focus {
            outline: none;
            background: rgba(255, 255, 255, 0.08);
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(112, 0, 255, 0.2);
        }

        .form-group button {
            padding: 0.875rem 1.5rem;
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(112, 0, 255, 0.3);
            width: 100%;
        }

        .form-group button::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.3) 0%, rgba(255,255,255,0) 70%);
            opacity: 0;
            transform: scale(0.5);
            transition: opacity 0.5s ease, transform 0.5s ease;
        }

        .form-group button:hover {
            background: linear-gradient(90deg, var(--primary-light), var(--primary));
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(112, 0, 255, 0.4);
        }

        .form-group button:hover::before {
            opacity: 1;
            transform: scale(1);
        }

        .form-group button:active {
            transform: translateY(0);
        }

        .form-group button i {
            font-size: 1.125rem;
        }

        .field-group {
            display: none;
            width: 100%;
        }

        .field-group.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Result section */
        .result-section {
            background: var(--card);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-top: 1.5rem;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-sm);
            position: relative;
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .result-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--secondary), var(--accent));
            opacity: 0.8;
        }

        .result-section:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow);
        }

        .result-section h3 {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding-bottom: 0.75rem;
            position: relative;
        }

        .result-section h3::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 40px;
            height: 3px;
            background: linear-gradient(to right, var(--secondary), var(--accent));
            border-radius: var(--radius-full);
        }

        .result-section h3 i {
            color: var(--secondary);
        }

        .result-content {
            background: rgba(255, 255, 255, 0.03);
            padding: 1rem;
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            white-space: pre-wrap;
            word-wrap: break-word;
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid var(--border-light);
            color: var(--foreground);
            font-family: 'Outfit', monospace;
        }

        .result-content img {
            max-width: 100%;
            border-radius: var(--radius-sm);
            margin: 1rem 0;
        }

        .copy-button {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: rgba(255, 255, 255, 0.05);
            color: var(--foreground);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 1rem;
        }

        .copy-button:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        /* Responsive styles */
        @media (max-width: 768px) {
            .dashboard-container {
                padding: 0 1rem;
                margin: 1rem auto;
            }
            
            .page-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
                padding: 1.25rem;
            }
            
            .content-section, 
            .form-container, 
            .result-section {
                padding: 1.25rem;
            }
        }

        @media (max-width: 480px) {
            .page-title {
                font-size: 1.5rem;
            }
            
            .section-title {
                font-size: 1.25rem;
            }
            
            .form-group select,
            .form-group textarea,
            .form-group input,
            .form-group button {
                font-size: 0.875rem;
            }
            
            .result-content {
                font-size: 0.875rem;
            }
        }

        /* Particle animation */
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

        /* Animations */
        @keyframes float {
            0% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
            100% { transform: translateY(0); }
        }

        .floating {
            animation: float 6s ease-in-out infinite;
        }

        .floating-slow {
            animation: float 8s ease-in-out infinite;
        }

        .floating-fast {
            animation: float 4s ease-in-out infinite;
        }
    </style>
    <script>
        function toggleFields() {
            const convertType = document.getElementById('convert_type').value;
            document.querySelectorAll('.field-group').forEach(group => {
                group.classList.remove('active');
                group.querySelectorAll('input, textarea').forEach(input => {
                    input.setAttribute('disabled', 'disabled');
                    input.removeAttribute('required');
                });
            });
            const fieldGroup = document.getElementById(convertType + '_fields');
            if (fieldGroup) {
                fieldGroup.classList.add('active');
                fieldGroup.querySelectorAll('input, textarea').forEach(input => {
                    input.removeAttribute('disabled');
                    input.setAttribute('required', 'required');
                });
            }
        }

        function copyToClipboard() {
            const resultContent = document.querySelector('.result-content');
            if (!resultContent) return;
            const text = resultContent.textContent;
            navigator.clipboard.writeText(text).then(() => {
                const copyButton = document.querySelector('.copy-button');
                copyButton.innerHTML = '<i class="fas fa-check"></i> Đã sao chép!';
                setTimeout(() => {
                    copyButton.innerHTML = '<i class="fas fa-copy"></i> Sao chép kết quả';
                }, 2000);
            }).catch(err => {
                console.error('Lỗi khi sao chép: ', err);
            });
        }

        document.addEventListener('DOMContentLoaded', () => {
            // Tạo hiệu ứng particle
            createParticles();
            
            toggleFields();
            
            // Animation cho các phần tử
            animateElements('.content-section', 100);
            animateElements('.form-container', 200);
            animateElements('.result-section', 250);
            animateElements('.form-group', 50);
            
            // Hiệu ứng hiển thị thông báo
            setTimeout(() => {
                const messages = document.querySelectorAll('.success-message, .error-message');
                messages.forEach(message => {
                    message.style.opacity = '0';
                    message.style.transform = 'translateY(-10px)';
                    message.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    
                    setTimeout(() => {
                        message.style.display = 'none';
                    }, 500);
                });
            }, 5000);
        });
        
        // Hàm tạo hiệu ứng particle
        function createParticles() {
            const particlesContainer = document.createElement('div');
            particlesContainer.classList.add('particles');
            document.body.appendChild(particlesContainer);
            
            const particleCount = 50;
            
            for (let i = 0; i < particleCount; i++) {
                const particle = document.createElement('div');
                particle.classList.add('particle');
                
                // Random size
                const size = Math.random() * 5 + 1;
                particle.style.width = `${size}px`;
                particle.style.height = `${size}px`;
                
                // Random position
                const posX = Math.random() * 100;
                const posY = Math.random() * 100;
                particle.style.left = `${posX}%`;
                particle.style.top = `${posY}%`;
                
                // Random color
                const colors = ['#7000FF', '#00E0FF', '#FF3DFF'];
                const color = colors[Math.floor(Math.random() * colors.length)];
                particle.style.backgroundColor = color;
                particle.style.boxShadow = `0 0 ${size * 2}px ${color}`;
                
                // Random animation
                const duration = Math.random() * 20 + 10;
                const delay = Math.random() * 10;
                particle.style.animation = `float ${duration}s ease-in-out ${delay}s infinite`;
                
                particlesContainer.appendChild(particle);
            }
        }
        
        // Hàm animation cho các phần tử
        function animateElements(selector, delay = 100) {
            const elements = document.querySelectorAll(selector);
            elements.forEach((el, index) => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    el.style.opacity = '1';
                    el.style.transform = 'translateY(0)';
                }, index * delay);
            });
        }
    </script>
</head>
<body>
    <div class="dashboard-container">
        <div class="page-header">
            <h1 class="page-title"><i class="fas fa-exchange-alt"></i> Công Cụ Chuyển Đổi</h1>
            <a href="dashboard.php" class="back-to-dashboard">
                <i class="fas fa-arrow-left"></i> Quay lại Dashboard
            </a>
        </div>

        <?php if (!empty($edit_error)): ?>
            <div class="error-message"><?php echo htmlspecialchars($edit_error); ?></div>
        <?php endif; ?>
        <?php if (!empty($message)): ?>
            <div class="<?php echo strpos($message, 'thành công') !== false ? 'success-message' : 'error-message'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="content-section">
            <h2 class="section-title"><i class="fas fa-exchange-alt"></i> Công Cụ Chuyển Đổi</h2>
            <div class="form-container">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <div class="form-group">
                        <label for="convert_type">Chọn loại chuyển đổi</label>
                        <select name="convert_type" id="convert_type" onchange="toggleFields()" required>
                            <option value="">-- Chọn loại chuyển đổi --</option>
                            <option value="base64_encode" <?php echo isset($_POST['convert_type']) && $_POST['convert_type'] === 'base64_encode' ? 'selected' : ''; ?>>Base64 Encoder</option>
                            <option value="base64_decode" <?php echo isset($_POST['convert_type']) && $_POST['convert_type'] === 'base64_decode' ? 'selected' : ''; ?>>Base64 Decoder</option>
                            <option value="base64_to_image" <?php echo isset($_POST['convert_type']) && $_POST['convert_type'] === 'base64_to_image' ? 'selected' : ''; ?>>Base64 to Image</option>
                            <option value="image_to_base64" <?php echo isset($_POST['convert_type']) && $_POST['convert_type'] === 'image_to_base64' ? 'selected' : ''; ?>>Image to Base64</option>
                            <option value="url_encode" <?php echo isset($_POST['convert_type']) && $_POST['convert_type'] === 'url_encode' ? 'selected' : ''; ?>>URL Encoder</option>
                            <option value="url_decode" <?php echo isset($_POST['convert_type']) && $_POST['convert_type'] === 'url_decode' ? 'selected' : ''; ?>>URL Decoder</option>
                            <option value="color_convert" <?php echo isset($_POST['convert_type']) && $_POST['convert_type'] === 'color_convert' ? 'selected' : ''; ?>>Color Converter</option>
                            <option value="binary_encode" <?php echo isset($_POST['convert_type']) && $_POST['convert_type'] === 'binary_encode' ? 'selected' : ''; ?>>Text to Binary</option>
                            <option value="binary_decode" <?php echo isset($_POST['convert_type']) && $_POST['convert_type'] === 'binary_decode' ? 'selected' : ''; ?>>Binary to Text</option>
                            <option value="hex_encode" <?php echo isset($_POST['convert_type']) && $_POST['convert_type'] === 'hex_encode' ? 'selected' : ''; ?>>Text to Hex</option>
                            <option value="hex_decode" <?php echo isset($_POST['convert_type']) && $_POST['convert_type'] === 'hex_decode' ? 'selected' : ''; ?>>Hex to Text</option>
                            <option value="ascii_encode" <?php echo isset($_POST['convert_type']) && $_POST['convert_type'] === 'ascii_encode' ? 'selected' : ''; ?>>Text to ASCII</option>
                            <option value="ascii_decode" <?php echo isset($_POST['convert_type']) && $_POST['convert_type'] === 'ascii_decode' ? 'selected' : ''; ?>>ASCII to Text</option>
                            <option value="decimal_encode" <?php echo isset($_POST['convert_type']) && $_POST['convert_type'] === 'decimal_encode' ? 'selected' : ''; ?>>Text to Decimal</option>
                            <option value="decimal_decode" <?php echo isset($_POST['convert_type']) && $_POST['convert_type'] === 'decimal_decode' ? 'selected' : ''; ?>>Decimal to Text</option>
                            <option value="octal_encode" <?php echo isset($_POST['convert_type']) && $_POST['convert_type'] === 'octal_encode' ? 'selected' : ''; ?>>Text to Octal</option>
                            <option value="octal_decode" <?php echo isset($_POST['convert_type']) && $_POST['convert_type'] === 'octal_decode' ? 'selected' : ''; ?>>Octal to Text</option>
                            <option value="morse_encode" <?php echo isset($_POST['convert_type']) && $_POST['convert_type'] === 'morse_encode' ? 'selected' : ''; ?>>Text to Morse</option>
                            <option value="morse_decode" <?php echo isset($_POST['convert_type']) && $_POST['convert_type'] === 'morse_decode' ? 'selected' : ''; ?>>Morse to Text</option>
                            <option value="number_to_words" <?php echo isset($_POST['convert_type']) && $_POST['convert_type'] === 'number_to_words' ? 'selected' : ''; ?>>Number to Words</option>
                        </select>
                    </div>

                    <div id="base64_encode_fields" class="field-group">
                        <div class="form-group">
                            <label for="base64_encode_input">Nhập chuỗi để mã hóa Base64</label>
                            <textarea name="input" id="base64_encode_input" placeholder="Nhập chuỗi để mã hóa Base64"><?php echo isset($_POST['input']) && $_POST['convert_type'] === 'base64_encode' ? htmlspecialchars($_POST['input']) : ''; ?></textarea>
                        </div>
                    </div>
                    <div id="base64_decode_fields" class="field-group">
                        <div class="form-group">
                            <label for="base64_decode_input">Nhập chuỗi Base64 để giải mã</label>
                            <textarea name="input" id="base64_decode_input" placeholder="Nhập chuỗi Base64 để giải mã"><?php echo isset($_POST['input']) && $_POST['convert_type'] === 'base64_decode' ? htmlspecialchars($_POST['input']) : ''; ?></textarea>
                        </div>
                    </div>
                    <div id="base64_to_image_fields" class="field-group">
                        <div class="form-group">
                            <label for="base64_to_image_input">Nhập chuỗi Base64 để chuyển thành hình ảnh</label>
                            <textarea name="input" id="base64_to_image_input" placeholder="Nhập chuỗi Base64 để chuyển thành hình ảnh"><?php echo isset($_POST['input']) && $_POST['convert_type'] === 'base64_to_image' ? htmlspecialchars($_POST['input']) : ''; ?></textarea>
                        </div>
                    </div>
                    <div id="image_to_base64_fields" class="field-group">
                        <div class="form-group">
                            <label for="image_input">Tải lên hình ảnh</label>
                            <input type="file" name="image" id="image_input" accept="image/png,image/jpeg,image/gif">
                        </div>
                    </div>
                    <div id="url_encode_fields" class="field-group">
                        <div class="form-group">
                            <label for="url_encode_input">Nhập chuỗi để mã hóa URL</label>
                            <textarea name="input" id="url_encode_input" placeholder="Nhập chuỗi để mã hóa URL"><?php echo isset($_POST['input']) && $_POST['convert_type'] === 'url_encode' ? htmlspecialchars($_POST['input']) : ''; ?></textarea>
                        </div>
                    </div>
                    <div id="url_decode_fields" class="field-group">
                        <div class="form-group">
                            <label for="url_decode_input">Nhập chuỗi URL để giải mã</label>
                            <textarea name="input" id="url_decode_input" placeholder="Nhập chuỗi URL để giải mã"><?php echo isset($_POST['input']) && $_POST['convert_type'] === 'url_decode' ? htmlspecialchars($_POST['input']) : ''; ?></textarea>
                        </div>
                    </div>
                    <div id="color_convert_fields" class="field-group">
                        <div class="form-group">
                            <label for="color_convert_input">Nhập mã màu HEX (VD: #FF5733)</label>
                            <input type="text" name="input" id="color_convert_input" placeholder="Nhập mã màu HEX (VD: #FF5733)" value="<?php echo isset($_POST['input']) && $_POST['convert_type'] === 'color_convert' ? htmlspecialchars($_POST['input']) : ''; ?>">
                        </div>
                    </div>
                    <div id="binary_encode_fields" class="field-group">
                        <div class="form-group">
                            <label for="binary_encode_input">Nhập chuỗi để chuyển thành nhị phân</label>
                            <textarea name="input" id="binary_encode_input" placeholder="Nhập chuỗi để chuyển thành nhị phân"><?php echo isset($_POST['input']) && $_POST['convert_type'] === 'binary_encode' ? htmlspecialchars($_POST['input']) : ''; ?></textarea>
                        </div>
                    </div>
                    <div id="binary_decode_fields" class="field-group">
                        <div class="form-group">
                            <label for="binary_decode_input">Nhập chuỗi nhị phân để giải mã</label>
                            <textarea name="input" id="binary_decode_input" placeholder="Nhập chuỗi nhị phân để giải mã"><?php echo isset($_POST['input']) && $_POST['convert_type'] === 'binary_decode' ? htmlspecialchars($_POST['input']) : ''; ?></textarea>
                        </div>
                    </div>
                    <div id="hex_encode_fields" class="field-group">
                        <div class="form-group">
                            <label for="hex_encode_input">Nhập chuỗi để chuyển thành Hex</label>
                            <textarea name="input" id="hex_encode_input" placeholder="Nhập chuỗi để chuyển thành Hex"><?php echo isset($_POST['input']) && $_POST['convert_type'] === 'hex_encode' ? htmlspecialchars($_POST['input']) : ''; ?></textarea>
                        </div>
                    </div>
                    <div id="hex_decode_fields" class="field-group">
                        <div class="form-group">
                            <label for="hex_decode_input">Nhập chuỗi Hex để giải mã</label>
                            <textarea name="input" id="hex_decode_input" placeholder="Nhập chuỗi Hex để giải mã"><?php echo isset($_POST['input']) && $_POST['convert_type'] === 'hex_decode' ? htmlspecialchars($_POST['input']) : ''; ?></textarea>
                        </div>
                    </div>
                    <div id="ascii_encode_fields" class="field-group">
                        <div class="form-group">
                            <label for="ascii_encode_input">Nhập chuỗi để chuyển thành ASCII</label>
                            <textarea name="input" id="ascii_encode_input" placeholder="Nhập chuỗi để chuyển thành ASCII"><?php echo isset($_POST['input']) && $_POST['convert_type'] === 'ascii_encode' ? htmlspecialchars($_POST['input']) : ''; ?></textarea>
                        </div>
                    </div>
                    <div id="ascii_decode_fields" class="field-group">
                        <div class="form-group">
                            <label for="ascii_decode_input">Nhập chuỗi ASCII để giải mã</label>
                            <textarea name="input" id="ascii_decode_input" placeholder="Nhập chuỗi ASCII để giải mã"><?php echo isset($_POST['input']) && $_POST['convert_type'] === 'ascii_decode' ? htmlspecialchars($_POST['input']) : ''; ?></textarea>
                        </div>
                    </div>
                    <div id="decimal_encode_fields" class="field-group">
                        <div class="form-group">
                            <label for="decimal_encode_input">Nhập chuỗi để chuyển thành Decimal</label>
                            <textarea name="input" id="decimal_encode_input" placeholder="Nhập chuỗi để chuyển thành Decimal"><?php echo isset($_POST['input']) && $_POST['convert_type'] === 'decimal_encode' ? htmlspecialchars($_POST['input']) : ''; ?></textarea>
                        </div>
                    </div>
                    <div id="decimal_decode_fields" class="field-group">
                        <div class="form-group">
                            <label for="decimal_decode_input">Nhập chuỗi Decimal để giải mã</label>
                            <textarea name="input" id="decimal_decode_input" placeholder="Nhập chuỗi Decimal để giải mã"><?php echo isset($_POST['input']) && $_POST['convert_type'] === 'decimal_decode' ? htmlspecialchars($_POST['input']) : ''; ?></textarea>
                        </div>
                    </div>
                    <div id="octal_encode_fields" class="field-group">
                        <div class="form-group">
                            <label for="octal_encode_input">Nhập chuỗi để chuyển thành Octal</label>
                            <textarea name="input" id="octal_encode_input" placeholder="Nhập chuỗi để chuyển thành Octal"><?php echo isset($_POST['input']) && $_POST['convert_type'] === 'octal_encode' ? htmlspecialchars($_POST['input']) : ''; ?></textarea>
                        </div>
                    </div>
                    <div id="octal_decode_fields" class="field-group">
                        <div class="form-group">
                            <label for="octal_decode_input">Nhập chuỗi Octal để giải mã</label>
                            <textarea name="input" id="octal_decode_input" placeholder="Nhập chuỗi Octal để giải mã"><?php echo isset($_POST['input']) && $_POST['convert_type'] === 'octal_decode' ? htmlspecialchars($_POST['input']) : ''; ?></textarea>
                        </div>
                    </div>
                    <div id="morse_encode_fields" class="field-group">
                        <div class="form-group">
                            <label for="morse_encode_input">Nhập chuỗi để chuyển thành Morse</label>
                            <textarea name="input" id="morse_encode_input" placeholder="Nhập chuỗi để chuyển thành Morse"><?php echo isset($_POST['input']) && $_POST['convert_type'] === 'morse_encode' ? htmlspecialchars($_POST['input']) : ''; ?></textarea>
                        </div>
                    </div>
                    <div id="morse_decode_fields" class="field-group">
                        <div class="form-group">
                            <label for="morse_decode_input">Nhập chuỗi Morse để giải mã</label>
                            <textarea name="input" id="morse_decode_input" placeholder="Nhập chuỗi Morse để giải mã"><?php echo isset($_POST['input']) && $_POST['convert_type'] === 'morse_decode' ? htmlspecialchars($_POST['input']) : ''; ?></textarea>
                        </div>
                    </div>
                    <div id="number_to_words_fields" class="field-group">
                        <div class="form-group">
                            <label for="number_to_words_input">Nhập số để chuyển thành chữ</label>
                            <input type="number" name="input" id="number_to_words_input" placeholder="Nhập số để chuyển thành chữ" value="<?php echo isset($_POST['input']) && $_POST['convert_type'] === 'number_to_words' ? htmlspecialchars($_POST['input']) : ''; ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <button type="submit" name="convert"><i class="fas fa-exchange-alt"></i> Chuyển Đổi Ngay</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if (!empty($result)): ?>
            <div class="result-section">
                <h3><i class="fas fa-check-circle"></i> Kết Quả Chuyển Đổi</h3>
                <div class="result-content">
                    <?php 
                    if (strpos($result, '<img') !== false) {
                        echo $result;
                    } else {
                        echo nl2br(htmlspecialchars($result));
                    }
                    ?>
                </div>
                <button class="copy-button" onclick="copyToClipboard()"><i class="fas fa-copy"></i> Sao chép kết quả</button>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
<?php
if (isset($conn) && $conn) {
    $conn->close();
}
?>