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
$result_message = '';
$error_message = '';

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

// Hàm kiểm tra và làm sạch input
function sanitizeInput($input) {
    $input = trim($input);
    if (strlen($input) > 255) {
        throw new Exception("Input quá dài! Tối đa 255 ký tự.");
    }
    return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
}

// Hàm gọi API Google Safe Browsing
function checkSafeUrl($url, $apiKey) {
    try {
        if (empty($apiKey) || $apiKey === 'YOUR_GOOGLE_SAFE_BROWSING_API_KEY') {
            return "Lỗi: Vui lòng cung cấp Google Safe Browsing API key.";
        }
        $apiUrl = "https://safebrowsing.googleapis.com/v4/threatMatches:find?key=$apiKey";
        $payload = [
            'client' => [
                'clientId' => "umtersclub",
                'clientVersion' => "1.5.2"
            ],
            'threatInfo' => [
                'threatTypes' => ["MALWARE", "SOCIAL_ENGINEERING", "UNWANTED_SOFTWARE", "POTENTIALLY_HARMFUL_APPLICATION"],
                'platformTypes' => ["ANY_PLATFORM"],
                'threatEntryTypes' => ["URL"],
                'threatEntries' => [
                    ['url' => $url]
                ]
            ]
        ];

        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new Exception("cURL error: " . curl_error($ch));
        }
        curl_close($ch);

        $result = json_decode($response, true);
        if (!empty($result['matches'])) {
            return "URL không an toàn: " . json_encode($result['matches'], JSON_PRETTY_PRINT);
        }
        return "URL an toàn.";
    } catch (Exception $e) {
        error_log("Safe URL Checker error: " . $e->getMessage());
        return "Lỗi khi kiểm tra URL an toàn: " . htmlspecialchars($e->getMessage());
    }
}

// Xử lý các chức năng tra cứu
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['tool']) && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    $tool = filter_input(INPUT_POST, 'tool', FILTER_SANITIZE_STRING);

    // DNS Lookup
    if ($tool == 'dns_lookup' && isset($_POST['domain'])) {
        try {
            $domain = sanitizeInput($_POST['domain']);
            $records = [];
            $dns_types = [DNS_A, DNS_AAAA, DNS_CNAME, DNS_MX, DNS_NS, DNS_TXT, DNS_SOA];
            foreach ($dns_types as $type) {
                $records[dns_record_type($type)] = dns_get_record($domain, $type) ?: [];
            }
            $result_message = "<h4>Kết quả DNS Lookup cho $domain:</h4><pre>" . json_encode($records, JSON_PRETTY_PRINT) . "</pre>";
        } catch (Exception $e) {
            error_log("DNS Lookup error: " . $e->getMessage());
            $error_message = "Lỗi khi tra cứu DNS: " . htmlspecialchars($e->getMessage());
        }
    }

    // IP Lookup
    if ($tool == 'ip_lookup' && isset($_POST['ip'])) {
        try {
            $ip = sanitizeInput($_POST['ip']);
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                throw new Exception("Địa chỉ IP không hợp lệ.");
            }
            $apiUrl = "http://ip-api.com/json/$ip";
            $ch = curl_init($apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $response = curl_exec($ch);
            if (curl_errno($ch)) {
                throw new Exception("cURL error: " . curl_error($ch));
            }
            curl_close($ch);

            $data = json_decode($response, true);
            if ($data['status'] == 'success') {
                $result_message = "<h4>Kết quả IP Lookup cho $ip:</h4><pre>" . json_encode($data, JSON_PRETTY_PRINT) . "</pre>";
            } else {
                throw new Exception("Không thể tra cứu thông tin IP: " . ($data['message'] ?? 'Lỗi không xác định'));
            }
        } catch (Exception $e) {
            error_log("IP Lookup error: " . $e->getMessage());
            $error_message = "Lỗi khi tra cứu IP: " . htmlspecialchars($e->getMessage());
        }
    }

    // Reverse IP Lookup
    if ($tool == 'reverse_ip_lookup' && isset($_POST['ip'])) {
        try {
            $ip = sanitizeInput($_POST['ip']);
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                throw new Exception("Địa chỉ IP không hợp lệ.");
            }
            $host = gethostbyaddr($ip);
            if ($host && $host != $ip) {
                $result_message = "<h4>Kết quả Reverse IP Lookup cho $ip:</h4><p>Domain/Host: $host</p>";
            } else {
                throw new Exception("Không tìm thấy domain/host liên quan đến IP này.");
            }
        } catch (Exception $e) {
            error_log("Reverse IP Lookup error: " . $e->getMessage());
            $error_message = "Lỗi khi tra cứu Reverse IP: " . htmlspecialchars($e->getMessage());
        }
    }

    // SSL Lookup
    if ($tool == 'ssl_lookup' && isset($_POST['domain'])) {
        try {
            $domain = sanitizeInput($_POST['domain']);
            $context = stream_context_create(['ssl' => ['capture_peer_cert' => true]]);
            $socket = @stream_socket_client("ssl://$domain:443", $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
            if ($socket) {
                $params = stream_context_get_params($socket);
                $cert = $params['options']['ssl']['peer_certificate'];
                $certInfo = openssl_x509_parse($cert);
                $result_message = "<h4>Kết quả SSL Lookup cho $domain:</h4><pre>" . json_encode($certInfo, JSON_PRETTY_PRINT) . "</pre>";
                fclose($socket);
            } else {
                throw new Exception("Không thể kết nối để lấy thông tin SSL: $errstr ($errno)");
            }
        } catch (Exception $e) {
            error_log("SSL Lookup error: " . $e->getMessage());
            $error_message = "Lỗi khi tra cứu SSL: " . htmlspecialchars($e->getMessage());
        }
    }

    // Whois Lookup
    if ($tool == 'whois_lookup' && isset($_POST['domain'])) {
        try {
            $domain = sanitizeInput($_POST['domain']);
            $whoisServer = 'whois.internic.net';
            $socket = @fsockopen($whoisServer, 43, $errno, $errstr, 10);
            if ($socket) {
                fwrite($socket, "$domain\r\n");
                $response = '';
                while (!feof($socket)) {
                    $response .= fgets($socket, 128);
                }
                fclose($socket);
                $result_message = "<h4>Kết quả Whois Lookup cho $domain:</h4><pre>" . htmlspecialchars($response) . "</pre>";
            } else {
                throw new Exception("Không thể kết nối đến WHOIS server: $errstr ($errno)");
            }
        } catch (Exception $e) {
            error_log("Whois Lookup error: " . $e->getMessage());
            $error_message = "Lỗi khi tra cứu Whois: " . htmlspecialchars($e->getMessage());
        }
    }

    // Ping
    if ($tool == 'ping' && isset($_POST['host'])) {
        try {
            $host = sanitizeInput($_POST['host']);
            $port = isset($_POST['port']) ? (int)$_POST['port'] : 80;
            $timeout = 10;
            $start = microtime(true);
            $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
            $end = microtime(true);
            if ($socket) {
                $time = round(($end - $start) * 1000, 2);
                $result_message = "<h4>Kết quả Ping cho $host (port $port):</h4><p>Thời gian phản hồi: $time ms</p>";
                fclose($socket);
            } else {
                throw new Exception("Không thể ping $host (port $port): $errstr ($errno)");
            }
        } catch (Exception $e) {
            error_log("Ping error: " . $e->getMessage());
            $error_message = "Lỗi khi ping: " . htmlspecialchars($e->getMessage());
        }
    }

    // HTTP Headers Lookup
    if ($tool == 'http_headers_lookup' && isset($_POST['url'])) {
        try {
            $url = sanitizeInput($_POST['url']);
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            $response = curl_exec($ch);
            if (curl_errno($ch)) {
                throw new Exception("cURL error: " . curl_error($ch));
            }
            $headers = curl_getinfo($ch);
            curl_close($ch);
            $result_message = "<h4>Kết quả HTTP Headers Lookup cho $url:</h4><pre>" . json_encode($headers, JSON_PRETTY_PRINT) . "</pre>";
        } catch (Exception $e) {
            error_log("HTTP Headers Lookup error: " . $e->getMessage());
            $error_message = "Lỗi khi tra cứu HTTP Headers: " . htmlspecialchars($e->getMessage());
        }
    }

    // Google Cache Checker
    if ($tool == 'google_cache_checker' && isset($_POST['url'])) {
        try {
            $url = sanitizeInput($_POST['url']);
            $cacheUrl = "http://webcache.googleusercontent.com/search?q=cache:$url";
            $ch = curl_init($cacheUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $response = curl_exec($ch);
            if (curl_errno($ch)) {
                throw new Exception("cURL error: " . curl_error($ch));
            }
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpCode == 200) {
                $result_message = "<h4>Kết quả Google Cache Checker cho $url:</h4><p>URL đã được Google lưu vào cache. Xem tại: <a href='$cacheUrl' target='_blank'>$cacheUrl</a></p>";
            } else {
                $result_message = "<h4>Kết quả Google Cache Checker cho $url:</h4><p>URL chưa được Google lưu vào cache.</p>";
            }
        } catch (Exception $e) {
            error_log("Google Cache Checker error: " . $e->getMessage());
            $error_message = "Lỗi khi kiểm tra Google Cache: " . htmlspecialchars($e->getMessage());
        }
    }

    // URL Redirect Checker
    if ($tool == 'url_redirect_checker' && isset($_POST['url'])) {
        try {
            $url = sanitizeInput($_POST['url']);
            $redirects = [];
            $maxRedirects = 10;
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            for ($i = 0; $i < $maxRedirects; $i++) {
                curl_setopt($ch, CURLOPT_URL, $url);
                $response = curl_exec($ch);
                if (curl_errno($ch)) {
                    throw new Exception("cURL error: " . curl_error($ch));
                }
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
                $redirects[] = "$url (HTTP $httpCode)";
                if ($httpCode != 301 && $httpCode != 302) break;
                if (!$redirectUrl) break;
                $url = $redirectUrl;
            }
            curl_close($ch);
            $result_message = "<h4>Kết quả URL Redirect Checker cho $url:</h4><pre>" . implode("\n", array_map('htmlspecialchars', $redirects)) . "</pre>";
        } catch (Exception $e) {
            error_log("URL Redirect Checker error: " . $e->getMessage());
            $error_message = "Lỗi khi kiểm tra Redirect: " . htmlspecialchars($e->getMessage());
        }
    }

    // Password Strength Checker
    if ($tool == 'password_strength_checker' && isset($_POST['password'])) {
        try {
            $password = $_POST['password'];
            if (strlen($password) > 255) {
                throw new Exception("Mật khẩu quá dài! Tối đa 255 ký tự.");
            }
            $strength = 0;
            $feedback = [];
            if (strlen($password) >= 8) $strength += 20;
            if (preg_match('/[A-Z]/', $password)) $strength += 20;
            if (preg_match('/[a-z]/', $password)) $strength += 20;
            if (preg_match('/[0-9]/', $password)) $strength += 20;
            if (preg_match('/[^A-Za-z0-9]/', $password)) $strength += 20;
            if ($strength < 40) $feedback[] = "Mật khẩu yếu. Hãy thêm ký tự đặc biệt, số, chữ hoa và chữ thường.";
            elseif ($strength < 80) $feedback[] = "Mật khẩu trung bình. Hãy tăng độ dài và đa dạng ký tự.";
            else $feedback[] = "Mật khẩu mạnh!";
            $result_message = "<h4>Kết quả Password Strength Checker:</h4><p>Độ mạnh: $strength%</p><p>" . implode("<br>", array_map('htmlspecialchars', $feedback)) . "</p>";
        } catch (Exception $e) {
            error_log("Password Strength Checker error: " . $e->getMessage());
            $error_message = "Lỗi khi kiểm tra độ mạnh mật khẩu: " . htmlspecialchars($e->getMessage());
        }
    }

    // Meta Tags Checker
    if ($tool == 'meta_tags_checker' && isset($_POST['url'])) {
        try {
            $url = sanitizeInput($_POST['url']);
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $response = curl_exec($ch);
            if (curl_errno($ch)) {
                throw new Exception("cURL error: " . curl_error($ch));
            }
            curl_close($ch);
            $doc = new DOMDocument();
            @$doc->loadHTML($response);
            $metaTags = $doc->getElementsByTagName('meta');
            $tags = [];
            foreach ($metaTags as $tag) {
                $name = $tag->getAttribute('name') ?: $tag->getAttribute('property');
                $content = $tag->getAttribute('content');
                if ($name && $content) {
                    $tags[$name] = $content;
                }
            }
            $result_message = "<h4>Kết quả Meta Tags Checker cho $url:</h4><pre>" . json_encode($tags, JSON_PRETTY_PRINT) . "</pre>";
        } catch (Exception $e) {
            error_log("Meta Tags Checker error: " . $e->getMessage());
            $error_message = "Lỗi khi kiểm tra Meta Tags: " . htmlspecialchars($e->getMessage());
        }
    }

    // Website Hosting Checker
    if ($tool == 'website_hosting_checker' && isset($_POST['domain'])) {
        try {
            $domain = sanitizeInput($_POST['domain']);
            $ip = gethostbyname($domain);
            if ($ip && $ip != $domain) {
                $apiUrl = "http://ip-api.com/json/$ip";
                $ch = curl_init($apiUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                $response = curl_exec($ch);
                if (curl_errno($ch)) {
                    throw new Exception("cURL error: " . curl_error($ch));
                }
                curl_close($ch);
                $data = json_decode($response, true);
                if ($data['status'] == 'success') {
                    $result_message = "<h4>Kết quả Website Hosting Checker cho $domain:</h4><pre>" . json_encode($data, JSON_PRETTY_PRINT) . "</pre>";
                } else {
                    throw new Exception("Không thể tra cứu thông tin hosting: " . ($data['message'] ?? 'Lỗi không xác định'));
                }
            } else {
                throw new Exception("Không thể lấy IP của domain $domain.");
            }
        } catch (Exception $e) {
            error_log("Website Hosting Checker error: " . $e->getMessage());
            $error_message = "Lỗi khi kiểm tra Hosting: " . htmlspecialchars($e->getMessage());
        }
    }

    // File MIME Type Checker
    if ($tool == 'file_mime_type_checker' && isset($_FILES['file'])) {
        try {
            $file = $_FILES['file'];
            if ($file['error'] != UPLOAD_ERR_OK) {
                throw new Exception("Lỗi khi tải file lên: " . $file['error']);
            }
            $max_size = 5 * 1024 * 1024; // 5MB
            if ($file['size'] > $max_size) {
                throw new Exception("File quá lớn! Tối đa 5MB.");
            }
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            $fileInfo = [
                'name' => $file['name'],
                'mime_type' => $mimeType,
                'size' => $file['size'],
                'last_modified' => date('Y-m-d H:i:s', filemtime($file['tmp_name']))
            ];
            $result_message = "<h4>Kết quả File MIME Type Checker:</h4><pre>" . json_encode($fileInfo, JSON_PRETTY_PRINT) . "</pre>";
        } catch (Exception $e) {
            error_log("File MIME Type Checker error: " . $e->getMessage());
            $error_message = "Lỗi khi kiểm tra MIME Type: " . htmlspecialchars($e->getMessage());
        }
    }
}

// Hàm hỗ trợ để lấy tên loại DNS record
function dns_record_type($type) {
    $types = [
        DNS_A => 'A',
        DNS_AAAA => 'AAAA',
        DNS_CNAME => 'CNAME',
        DNS_MX => 'MX',
        DNS_NS => 'NS',
        DNS_TXT => 'TXT',
        DNS_SOA => 'SOA'
    ];
    return $types[$type] ?? 'Unknown';
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UMTERS - Công Cụ Tra Cứu</title>
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
            
            /* Status colors */
            --success: #22c55e;
            --success-bg: rgba(34, 197, 94, 0.1);
            --success-border: rgba(34, 197, 94, 0.3);
            --error: #ef4444;
            --error-bg: rgba(239, 68, 68, 0.1);
            --error-border: rgba(239, 68, 68, 0.3);
            --warning: #f59e0b;
            --warning-bg: rgba(245, 158, 11, 0.1);
            --warning-border: rgba(245, 158, 11, 0.3);
            --info: #3b82f6;
            --info-bg: rgba(59, 130, 246, 0.1);
            --info-border: rgba(59, 130, 246, 0.3);
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
            display: grid;
            grid-template-columns: 1fr;
            grid-template-rows: auto 1fr;
            min-height: 100vh;
            padding: 1.5rem;
            gap: 1.5rem;
            position: relative;
            z-index: 1;
        }

        /* Header section */
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem;
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

        .header-logo {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .logo-icon {
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            border-radius: var(--radius);
            font-size: 1.5rem;
            color: white;
            box-shadow: var(--glow);
            position: relative;
            overflow: hidden;
        }

        .logo-icon::after {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.3) 0%, rgba(255,255,255,0) 70%);
            opacity: 0;
            transform: scale(0.5);
            animation: pulse 3s ease-in-out infinite;
        }

        @keyframes pulse {
            0% { opacity: 0; transform: scale(0.5); }
            50% { opacity: 0.3; transform: scale(1); }
            100% { opacity: 0; transform: scale(0.5); }
        }

        .logo-text {
            font-weight: 800;
            font-size: 1.5rem;
            background: linear-gradient(to right, var(--secondary), var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: -0.5px;
            position: relative;
        }

        .logo-text::after {
            content: 'UMTERS';
            position: absolute;
            top: 0;
            left: 0;
            background: linear-gradient(to right, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            opacity: 0;
            animation: textFlicker 8s linear infinite;
        }

        @keyframes textFlicker {
            0%, 92%, 100% { opacity: 0; }
            94%, 96% { opacity: 1; }
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .back-to-home {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: var(--radius-full);
            border: 1px solid var(--border);
            color: var(--foreground);
            text-decoration: none;
            transition: all 0.3s ease;
            font-weight: 500;
            font-size: 0.875rem;
        }

        .back-to-home:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: var(--primary-light);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.5rem 1rem 0.5rem 0.5rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: var(--radius-full);
            border: 1px solid var(--border);
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .user-profile:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: var(--primary-light);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-full);
            background: linear-gradient(135deg, var(--primary), var(--accent));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.25rem;
            box-shadow: 0 0 10px rgba(112, 0, 255, 0.5);
            position: relative;
            overflow: hidden;
        }

        .user-avatar::after {
            content: '';
            position: absolute;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, transparent, rgba(255, 255, 255, 0.3));
            top: 0;
            left: 0;
        }

        .user-info {
            display: flex;
            flex-direction: column;
        }

        .user-name {
            font-weight: 600;
            font-size: 0.875rem;
            color: var(--foreground);
        }

        .user-role {
            font-size: 0.75rem;
            color: var(--secondary);
            font-weight: 500;
        }

        /* Main content */
        .main-content {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
        }

        /* Page title */
        .page-title {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            text-align: center;
            background: linear-gradient(to right, var(--primary-light), var(--secondary-light), var(--accent-light));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            position: relative;
            padding-bottom: 0.75rem;
        }

        .page-title::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 100px;
            height: 3px;
            background: linear-gradient(90deg, var(--primary), var(--secondary), var(--accent));
            border-radius: var(--radius-full);
        }

        /* Error and success messages */
        .error-message,
        .success-message {
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            font-weight: 500;
            position: relative;
            padding-left: 3rem;
            animation: slideIn 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }

        .error-message {
            background: var(--error-bg);
            color: var(--error);
            border: 1px solid var(--error-border);
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
            background: var(--success-bg);
            color: var(--success);
            border: 1px solid var(--success-border);
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

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* Tools container */
        .tools-container {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            overflow: hidden;
            position: relative;
        }

        .tools-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                radial-gradient(circle at 20% 30%, rgba(112, 0, 255, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 70%, rgba(0, 224, 255, 0.1) 0%, transparent 50%);
            pointer-events: none;
            opacity: 0.5;
        }

        .tools-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border);
        }

        .tools-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--foreground);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .tools-title i {
            color: var(--accent);
            font-size: 1.5rem;
        }

        /* Tools selector */
        .tools-selector {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            padding: 1.5rem;
            background: rgba(18, 18, 42, 0.7);
            border-bottom: 1px solid var(--border);
        }

        .tool-btn {
            padding: 0.75rem 1.25rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            color: var(--foreground-muted);
            font-weight: 600;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .tool-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            color: var(--foreground);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .tool-btn.active {
            background: rgba(112, 0, 255, 0.2);
            border-color: var(--primary);
            color: var(--secondary);
            box-shadow: var(--glow);
        }

        /* Tool content */
        .tool-content {
            display: none;
            padding: 1.5rem;
            animation: fadeIn 0.3s ease;
        }

        .tool-content.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        /* Form elements */
        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
            color: var(--foreground-muted);
            font-weight: 500;
        }

        .form-input,
        .form-select {
            width: 100%;
            padding: 0.75rem 1rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            color: var(--foreground);
            font-family: 'Outfit', sans-serif;
            font-size: 0.875rem;
            transition: all 0.3s ease;
        }

        .form-input:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(112, 0, 255, 0.25);
            background: rgba(255, 255, 255, 0.1);
        }

        .form-input::placeholder {
            color: var(--foreground-subtle);
        }

        .form-input[type="file"] {
            padding: 0.5rem;
            cursor: pointer;
            border-style: dashed;
        }

        .form-input[type="file"]::file-selector-button {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: var(--radius-sm);
            font-family: 'Outfit', sans-serif;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-right: 1rem;
        }

        .form-input[type="file"]::file-selector-button:hover {
            background: linear-gradient(135deg, var(--accent), var(--primary));
            transform: translateY(-2px);
        }

        .form-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            font-family: 'Outfit', sans-serif;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: var(--shadow-sm);
        }

        .form-button:hover {
            background: linear-gradient(135deg, var(--primary-light), var(--accent-light));
            transform: translateY(-2px);
            box-shadow: var(--glow);
        }

        /* Result container */
        .result-container {
            margin-top: 1.5rem;
            padding: 1.5rem;
            background: rgba(18, 18, 42, 0.7);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            animation: slideIn 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .result-container h4 {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--secondary);
        }

        .result-container pre {
            background: rgba(10, 10, 26, 0.5);
            padding: 1rem;
            border-radius: var(--radius-sm);
            overflow-x: auto;
            color: var(--foreground-muted);
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 0.875rem;
            border: 1px solid var(--border);
        }

        .result-container a {
            color: var(--secondary);
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .result-container a:hover {
            color: var(--secondary-light);
            text-decoration: underline;
        }

        /* Responsive styles */
        @media (max-width: 1024px) {
            .tools-selector {
                gap: 0.5rem;
            }
            
            .tool-btn {
                padding: 0.5rem 1rem;
                font-size: 0.75rem;
            }
        }

        @media (max-width: 768px) {
            .dashboard-container {
                padding: 1rem;
                gap: 1rem;
            }
            
            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
                padding: 1rem;
            }
            
            .header-actions {
                width: 100%;
                flex-wrap: wrap;
                justify-content: space-between;
            }
            
            .back-to-home, .user-profile {
                flex: 1;
                min-width: 150px;
            }
            
            .page-title {
                font-size: 1.5rem;
            }
            
            .tools-selector {
                flex-direction: column;
                align-items: stretch;
                padding: 1rem;
            }
            
            .tool-btn {
                width: 100%;
                justify-content: center;
            }
            
            .tool-content {
                padding: 1rem;
            }
        }

        @media (max-width: 480px) {
            .dashboard-header {
                padding: 0.75rem;
            }
            
            .header-actions {
                flex-direction: column;
                align-items: stretch;
            }
            
            .back-to-home, .user-profile {
                width: 100%;
                justify-content: center;
            }
            
            .page-title {
                font-size: 1.25rem;
            }
            
            .tools-title {
                font-size: 1.25rem;
            }
            
            .tool-content {
                padding: 0.75rem;
            }
            
            .result-container {
                padding: 1rem;
            }
            
            .result-container h4 {
                font-size: 1rem;
            }
            
            .result-container pre {
                font-size: 0.75rem;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Header -->
        <div class="dashboard-header">
            <div class="header-logo">
                <div class="logo-icon"><i class="fas fa-search"></i></div>
                <div class="logo-text">UMTERS</div>
            </div>
            
            <div class="header-actions">
                <a href="dashboard.php" class="back-to-home">
                    <i class="fas fa-arrow-left"></i> Trở về trang chủ
                </a>
                
                <div class="user-profile">
                    <div class="user-avatar"><?php echo strtoupper(substr($user['username'], 0, 1)); ?></div>
                    <div class="user-info">
                        <p class="user-name"><?php echo htmlspecialchars($user['full_name'] ?: $user['username']); ?></p>
                        <p class="user-role">
                            <?php echo $user['is_super_admin'] ? 'Super Admin' : ($user['is_main_admin'] ? 'Main Admin' : 'Admin'); ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <h1 class="page-title">Công Cụ Tra Cứu</h1>
            
            <?php if (!empty($edit_error)): ?>
                <div class="error-message"><?php echo htmlspecialchars($edit_error); ?></div>
            <?php endif; ?>
            
            <?php if (!empty($error_message)): ?>
                <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>
            
            <div class="tools-container">
                <div class="tools-header">
                    <h2 class="tools-title"><i class="fas fa-tools"></i> Các Công Cụ Tra Cứu</h2>
                </div>
                
                <div class="tools-selector">
                    <button class="tool-btn active" data-tool="dns_lookup"><i class="fas fa-network-wired"></i> DNS Lookup</button>
                    <button class="tool-btn" data-tool="ip_lookup"><i class="fas fa-globe"></i> IP Lookup</button>
                    <button class="tool-btn" data-tool="reverse_ip_lookup"><i class="fas fa-exchange-alt"></i> Reverse IP Lookup</button>
                    <button class="tool-btn" data-tool="ssl_lookup"><i class="fas fa-lock"></i> SSL Lookup</button>
                    <button class="tool-btn" data-tool="whois_lookup"><i class="fas fa-info-circle"></i> Whois Lookup</button>
                    <button class="tool-btn" data-tool="ping"><i class="fas fa-signal"></i> Ping</button>
                    <button class="tool-btn" data-tool="http_headers_lookup"><i class="fas fa-code"></i> HTTP Headers</button>
                    <button class="tool-btn" data-tool="google_cache_checker"><i class="fas fa-history"></i> Google Cache</button>
                    <button class="tool-btn" data-tool="url_redirect_checker"><i class="fas fa-link"></i> URL Redirect</button>
                    <button class="tool-btn" data-tool="password_strength_checker"><i class="fas fa-key"></i> Password Strength</button>
                    <button class="tool-btn" data-tool="meta_tags_checker"><i class="fas fa-tags"></i> Meta Tags</button>
                    <button class="tool-btn" data-tool="website_hosting_checker"><i class="fas fa-server"></i> Website Hosting</button>
                    <button class="tool-btn" data-tool="file_mime_type_checker"><i class="fas fa-file"></i> File MIME Type</button>
                </div>
                
                <!-- Form DNS Lookup -->
                <div class="tool-content active" id="dns_lookup">
                    <form method="POST">
                        <input type="hidden" name="tool" value="dns_lookup">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        
                        <div class="form-group">
                            <label class="form-label" for="dns_domain">Nhập tên miền</label>
                            <input type="text" id="dns_domain" name="domain" class="form-input" placeholder="Ví dụ: google.com" value="<?php echo isset($_POST['domain']) && $_POST['tool'] === 'dns_lookup' ? htmlspecialchars($_POST['domain']) : ''; ?>" required>
                        </div>
                        
                        <button type="submit" class="form-button">
                            <i class="fas fa-search"></i> Tra cứu DNS
                        </button>
                    </form>
                </div>
                
                <!-- Form IP Lookup -->
                <div class="tool-content" id="ip_lookup">
                    <form method="POST">
                        <input type="hidden" name="tool" value="ip_lookup">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        
                        <div class="form-group">
                            <label class="form-label" for="ip_address">Nhập địa chỉ IP</label>
                            <input type="text" id="ip_address" name="ip" class="form-input" placeholder="Ví dụ: 8.8.8.8" value="<?php echo isset($_POST['ip']) && $_POST['tool'] === 'ip_lookup' ? htmlspecialchars($_POST['ip']) : ''; ?>" required>
                        </div>
                        
                        <button type="submit" class="form-button">
                            <i class="fas fa-search"></i> Tra cứu IP
                        </button>
                    </form>
                </div>
                
                <!-- Form Reverse IP Lookup -->
                <div class="tool-content" id="reverse_ip_lookup">
                    <form method="POST">
                        <input type="hidden" name="tool" value="reverse_ip_lookup">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        
                        <div class="form-group">
                            <label class="form-label" for="reverse_ip">Nhập địa chỉ IP</label>
                            <input type="text" id="reverse_ip" name="ip" class="form-input" placeholder="Ví dụ: 8.8.8.8" value="<?php echo isset($_POST['ip']) && $_POST['tool'] === 'reverse_ip_lookup' ? htmlspecialchars($_POST['ip']) : ''; ?>" required>
                        </div>
                        
                        <button type="submit" class="form-button">
                            <i class="fas fa-search"></i> Tra cứu Reverse IP
                        </button>
                    </form>
                </div>
                
                <!-- Form SSL Lookup -->
                <div class="tool-content" id="ssl_lookup">
                    <form method="POST">
                        <input type="hidden" name="tool" value="ssl_lookup">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        
                        <div class="form-group">
                            <label class="form-label" for="ssl_domain">Nhập tên miền</label>
                            <input type="text" id="ssl_domain" name="domain" class="form-input" placeholder="Ví dụ: google.com" value="<?php echo isset($_POST['domain']) && $_POST['tool'] === 'ssl_lookup' ? htmlspecialchars($_POST['domain']) : ''; ?>" required>
                        </div>
                        
                        <button type="submit" class="form-button">
                            <i class="fas fa-search"></i> Tra cứu SSL
                        </button>
                    </form>
                </div>
                
                <!-- Form Whois Lookup -->
                <div class="tool-content" id="whois_lookup">
                    <form method="POST">
                        <input type="hidden" name="tool" value="whois_lookup">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        
                        <div class="form-group">
                            <label class="form-label" for="whois_domain">Nhập tên miền</label>
                            <input type="text" id="whois_domain" name="domain" class="form-input" placeholder="Ví dụ: google.com" value="<?php echo isset($_POST['domain']) && $_POST['tool'] === 'whois_lookup' ? htmlspecialchars($_POST['domain']) : ''; ?>" required>
                        </div>
                        
                        <button type="submit" class="form-button">
                            <i class="fas fa-search"></i> Tra cứu Whois
                        </button>
                    </form>
                </div>
                
                <!-- Form Ping -->
                <div class="tool-content" id="ping">
                    <form method="POST">
                        <input type="hidden" name="tool" value="ping">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        
                        <div class="form-group">
                            <label class="form-label" for="ping_host">Nhập host</label>
                            <input type="text" id="ping_host" name="host" class="form-input" placeholder="Ví dụ: google.com" value="<?php echo isset($_POST['host']) && $_POST['tool'] === 'ping' ? htmlspecialchars($_POST['host']) : ''; ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="ping_port">Nhập port (mặc định 80)</label>
                            <input type="text" id="ping_port" name="port" class="form-input" placeholder="Ví dụ: 80, 443, 21, ..." value="<?php echo isset($_POST['port']) && $_POST['tool'] === 'ping' ? htmlspecialchars($_POST['port']) : '80'; ?>">
                        </div>
                        
                        <button type="submit" class="form-button">
                            <i class="fas fa-signal"></i> Ping
                        </button>
                    </form>
                </div>
                
                <!-- Form HTTP Headers Lookup -->
                <div class="tool-content" id="http_headers_lookup">
                    <form method="POST">
                        <input type="hidden" name="tool" value="http_headers_lookup">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        
                        <div class="form-group">
                            <label class="form-label" for="http_url">Nhập URL</label>
                            <input type="text" id="http_url" name="url" class="form-input" placeholder="Ví dụ: https://google.com" value="<?php echo isset($_POST['url']) && $_POST['tool'] === 'http_headers_lookup' ? htmlspecialchars($_POST['url']) : ''; ?>" required>
                        </div>
                        
                        <button type="submit" class="form-button">
                            <i class="fas fa-search"></i> Tra cứu HTTP Headers
                        </button>
                    </form>
                </div>
                
                <!-- Form Google Cache Checker -->
                <div class="tool-content" id="google_cache_checker">
                    <form method="POST">
                        <input type="hidden" name="tool" value="google_cache_checker">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        
                        <div class="form-group">
                            <label class="form-label" for="cache_url">Nhập URL</label>
                            <input type="text" id="cache_url" name="url" class="form-input" placeholder="Ví dụ: https://google.com" value="<?php echo isset($_POST['url']) && $_POST['tool'] === 'google_cache_checker' ? htmlspecialchars($_POST['url']) : ''; ?>" required>
                        </div>
                        
                        <button type="submit" class="form-button">
                            <i class="fas fa-history"></i> Kiểm tra Google Cache
                        </button>
                    </form>
                </div>
                
                <!-- Form URL Redirect Checker -->
                <div class="tool-content" id="url_redirect_checker">
                    <form method="POST">
                        <input type="hidden" name="tool" value="url_redirect_checker">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        
                        <div class="form-group">
                            <label class="form-label" for="redirect_url">Nhập URL</label>
                            <input type="text" id="redirect_url" name="url" class="form-input" placeholder="Ví dụ: https://google.com" value="<?php echo isset($_POST['url']) && $_POST['tool'] === 'url_redirect_checker' ? htmlspecialchars($_POST['url']) : ''; ?>" required>
                        </div>
                        
                        <button type="submit" class="form-button">
                            <i class="fas fa-link"></i> Kiểm tra Redirect
                        </button>
                    </form>
                </div>
                
                <!-- Form Password Strength Checker -->
                <div class="tool-content" id="password_strength_checker">
                    <form method="POST">
                        <input type="hidden" name="tool" value="password_strength_checker">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        
                        <div class="form-group">
                            <label class="form-label" for="password">Nhập mật khẩu</label>
                            <input type="password" id="password" name="password" class="form-input" placeholder="Nhập mật khẩu cần kiểm tra" required>
                        </div>
                        
                        <button type="submit" class="form-button">
                            <i class="fas fa-key"></i> Kiểm tra độ mạnh mật khẩu
                        </button>
                    </form>
                </div>
                
                <!-- Form Meta Tags Checker -->
                <div class="tool-content" id="meta_tags_checker">
                    <form method="POST">
                        <input type="hidden" name="tool" value="meta_tags_checker">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        
                        <div class="form-group">
                            <label class="form-label" for="meta_url">Nhập URL</label>
                            <input type="text" id="meta_url" name="url" class="form-input" placeholder="Ví dụ: https://google.com" value="<?php echo isset($_POST['url']) && $_POST['tool'] === 'meta_tags_checker' ? htmlspecialchars($_POST['url']) : ''; ?>" required>
                        </div>
                        
                        <button type="submit" class="form-button">
                            <i class="fas fa-tags"></i> Kiểm tra Meta Tags
                        </button>
                    </form>
                </div>
                
                <!-- Form Website Hosting Checker -->
                <div class="tool-content" id="website_hosting_checker">
                    <form method="POST">
                        <input type="hidden" name="tool" value="website_hosting_checker">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        
                        <div class="form-group">
                            <label class="form-label" for="hosting_domain">Nhập tên miền</label>
                            <input type="text" id="hosting_domain" name="domain" class="form-input" placeholder="Ví dụ: google.com" value="<?php echo isset($_POST['domain']) && $_POST['tool'] === 'website_hosting_checker' ? htmlspecialchars($_POST['domain']) : ''; ?>" required>
                        </div>
                        
                        <button type="submit" class="form-button">
                            <i class="fas fa-server"></i> Kiểm tra Hosting
                        </button>
                    </form>
                </div>
                
                <!-- Form File MIME Type Checker -->
                <div class="tool-content" id="file_mime_type_checker">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="tool" value="file_mime_type_checker">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        
                        <div class="form-group">
                            <label class="form-label" for="file">Chọn file (tối đa 5MB)</label>
                            <input type="file" id="file" name="file" class="form-input" required>
                        </div>
                        
                        <button type="submit" class="form-button">
                            <i class="fas fa-file"></i> Kiểm tra MIME Type
                        </button>
                    </form>
                </div>
            </div>
            
            <?php if (!empty($result_message)): ?>
                <div class="result-container"><?php echo $result_message; ?></div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Xử lý chuyển đổi tab công cụ
            const toolButtons = document.querySelectorAll('.tool-btn');
            toolButtons.forEach(button => {
                button.addEventListener('click', function() {
                    // Bỏ active tất cả các nút và nội dung
                    toolButtons.forEach(btn => btn.classList.remove('active'));
                    document.querySelectorAll('.tool-content').forEach(content => content.classList.remove('active'));
                    
                    // Thêm active cho nút được click và nội dung tương ứng
                    this.classList.add('active');
                    const toolId = this.getAttribute('data-tool');
                    document.getElementById(toolId).classList.add('active');
                });
            });

            // Hiệu ứng xuất hiện cho các phần tử
            const elements = document.querySelectorAll('.tools-container, .result-container');
            elements.forEach((el, index) => {
                el.style.opacity = 0;
                el.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    el.style.transition = 'opacity 0.5s cubic-bezier(0.4, 0, 0.2, 1), transform 0.5s cubic-bezier(0.4, 0, 0.2, 1)';
                    el.style.opacity = 1;
                    el.style.transform = 'translateY(0)';
                }, index * 100);
            });

            // Tự động ẩn thông báo sau 5 giây
            setTimeout(() => {
                const messages = document.querySelectorAll('.error-message, .success-message');
                messages.forEach(message => {
                    message.style.opacity = '0';
                    message.style.transform = 'translateY(-10px)';
                    message.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    setTimeout(() => {
                        if (message.parentNode) {
                            message.parentNode.removeChild(message);
                        }
                    }, 500);
                });
            }, 5000);
            
            // Tạo hiệu ứng particles
            createParticles();
        });
        
        function createParticles() {
            const container = document.createElement('div');
            container.style.position = 'fixed';
            container.style.top = '0';
            container.style.left = '0';
            container.style.width = '100%';
            container.style.height = '100%';
            container.style.pointerEvents = 'none';
            container.style.zIndex = '0';
            document.body.appendChild(container);
            
            const particleCount = 20;
            
            for (let i = 0; i < particleCount; i++) {
                const particle = document.createElement('div');
                
                // Random size
                const size = Math.random() * 5 + 2;
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
                
                // Style
                particle.style.position = 'absolute';
                particle.style.borderRadius = '50%';
                particle.style.background = color;
                particle.style.opacity = '0.3';
                particle.style.boxShadow = `0 0 ${size * 2}px ${color}`;
                
                // Animation
                const duration = Math.random() * 60 + 30;
                const delay = Math.random() * 10;
                particle.style.animation = `floatParticle ${duration}s ease-in-out ${delay}s infinite`;
                
                container.appendChild(particle);
            }
            
            // Add keyframes
            const style = document.createElement('style');
            style.textContent = `
                @keyframes floatParticle {
                    0%, 100% { transform: translate(0, 0); }
                    25% { transform: translate(${Math.random() * 100 - 50}px, ${Math.random() * 100 - 50}px); }
                    50% { transform: translate(${Math.random() * 100 - 50}px, ${Math.random() * 100 - 50}px); }
                    75% { transform: translate(${Math.random() * 100 - 50}px, ${Math.random() * 100 - 50}px); }
                }
            `;
            document.head.appendChild(style);
        }
    </script>
</body>
</html>
<?php
if (isset($conn) && $conn) {
    $conn->close();
}
?>
