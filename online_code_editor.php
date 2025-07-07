<?php
// Bật chế độ debug để tìm lỗi
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include 'db_config.php';

// Kiểm tra quyền truy cập (chỉ cần đăng nhập, không cần super admin)
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Lấy thông tin người dùng từ database
$user_id = (int)$_SESSION['user_id'];
$sql_user = "SELECT is_super_admin FROM users WHERE id = ?";
$stmt = mysqli_prepare($conn, $sql_user);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result_user = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result_user);

// Nếu không tìm thấy user, chuyển hướng về login
if (!$user) {
    header("Location: login.php");
    exit;
}
mysqli_stmt_close($stmt);

// Xử lý chạy code backend
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['backend_code']) && isset($_POST['language'])) {
    $code = trim($_POST['backend_code']);
    $language = trim($_POST['language']);
    $stdin = trim($_POST['backend_input'] ?? ''); // Lấy dữ liệu input từ người dùng

    // Kiểm tra cấu hình API JDoodle
    $clientId = "3687cbf14e6804a1d2298e38a43a1f6f"; // Thay bằng Client ID của bạn
    $clientSecret = "325109cff5988649d6d413893307641cfb966c290a5afec268022deab5b4f2b5"; // Thay bằng Client Secret của bạn

    if (empty($clientId) || empty($clientSecret) || $clientId === "3687cbf14e6804a1d2298e38a43a1f6f" || $clientSecret === "325109cff5988649d6d413893307641cfb966c290a5afec268022deab5b4f2b5") {
        $backend_output = "Lỗi: Vui lòng cấu hình Client ID và Client Secret của JDoodle trong mã nguồn (online_code_editor.php). Đăng ký tại https://www.jdoodle.com/compiler-api/";
    } else {
        // Gọi API JDoodle để chạy code
        $script = $code;
        $versionIndex = "0"; // Chỉ số phiên bản ngôn ngữ

        $data = [
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
            'script' => $script,
            'language' => $language,
            'versionIndex' => $versionIndex,
            'stdin' => $stdin // Gửi dữ liệu input
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.jdoodle.com/v1/execute");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Đặt timeout 10 giây

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            $backend_output = "Lỗi khi gọi API JDoodle: " . $error;
        } elseif ($httpCode !== 200) {
            $backend_output = "Lỗi HTTP: Mã trạng thái $httpCode. Kiểm tra giới hạn API JDoodle hoặc thông tin xác thực.";
        } else {
            $result = json_decode($response, true);
            if (isset($result['output'])) {
                $backend_output = $result['output'];
            } elseif (isset($result['error'])) {
                $backend_output = "Lỗi từ JDoodle: " . $result['error'];
            } else {
                $backend_output = "Lỗi không xác định: " . $response;
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Online Code Editor</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<style>
    :root {
        --primary: #6366f1;
        --primary-dark: #4f46e5;
        --secondary: #ec4899;
        --accent: #8b5cf6;
        --background: #0f172a;
        --foreground: #f8fafc;
        --card: rgba(255, 255, 255, 0.05);
        --card-hover: rgba(255, 255, 255, 0.08);
        --border: rgba(255, 255, 255, 0.1);
        --input: rgba(255, 255, 255, 0.1);
        --ring: rgba(99, 102, 241, 0.3);
        --radius: 1rem;
        --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        --editor-bg: #1e293b;
        --editor-text: #f8fafc;
    }

    body {
        font-family: 'Be Vietnam Pro', system-ui, sans-serif;
        background-color: var(--background);
        color: var(--foreground);
        line-height: 1.6;
        padding-bottom: 50px;
        position: relative;
        min-height: 100vh;
        overflow-x: hidden;
        text-rendering: optimizeLegibility;
        -webkit-font-smoothing: antialiased;
    }

    .container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 20px;
    }

    .editor-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding: 1rem;
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        backdrop-filter: blur(10px);
        transition: var(--transition);
    }

    .editor-header:hover {
        background: var(--card-hover);
        box-shadow: 0 10px 30px -15px rgba(0, 0, 0, 0.3);
    }

    .editor-header h2 {
        margin: 0;
        font-size: 1.8rem;
        font-weight: 600;
        background: linear-gradient(to right, var(--primary), var(--secondary));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .editor-header h2 i {
        color: var(--primary);
    }

    .nav-tabs {
        border-bottom: 1px solid var(--border);
        margin-bottom: 20px;
        background: var(--card);
        border-radius: var(--radius);
        padding: 0.5rem;
    }

    .nav-tabs .nav-link {
        border: 1px solid transparent;
        border-radius: var(--radius);
        color: var(--foreground);
        font-weight: 500;
        padding: 10px 20px;
        transition: var(--transition);
        position: relative;
        overflow: hidden;
    }

    .nav-tabs .nav-link::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
        transition: var(--transition);
    }

    .nav-tabs .nav-link:hover::before {
        left: 100%;
    }

    .nav-tabs .nav-link:hover {
        color: var(--primary);
        background: var(--card-hover);
    }

    .nav-tabs .nav-link.active {
        color: var(--primary);
        background: var(--card-hover);
        border-color: var(--primary);
    }

    .tab-content {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 20px;
        backdrop-filter: blur(10px);
        transition: var(--transition);
    }

    .tab-content:hover {
        box-shadow: 0 10px 30px -15px rgba(0, 0, 0, 0.3);
    }

    .editor-panels {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 15px;
        margin-bottom: 20px;
    }

    .editor-panel {
        border: 1px solid var(--border);
        border-radius: var(--radius);
        overflow: hidden;
        background: var(--editor-bg);
        transition: var(--transition);
    }

    .editor-panel:hover {
        border-color: var(--primary);
        box-shadow: 0 10px 30px -15px rgba(0, 0, 0, 0.3);
    }

    .panel-header {
        background: var(--editor-bg);
        color: var(--editor-text);
        padding: 8px 15px;
        font-size: 0.9rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid var(--border);
    }

    .panel-header .language-badge {
        background: var(--card-hover);
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 0.75rem;
        color: var(--primary);
    }

    .CodeMirror {
        height: 250px;
        font-family: 'Consolas', monospace;
        font-size: 14px;
        background: var(--editor-bg);
        color: var(--editor-text);
    }

    .preview-container {
        margin-top: 20px;
        border: 1px solid var(--border);
        border-radius: var(--radius);
        overflow: hidden;
        background: var(--card);
        transition: var(--transition);
    }

    .preview-container:hover {
        border-color: var(--primary);
    }

    .preview-header {
        background: var(--editor-bg);
        color: var(--editor-text);
        padding: 8px 15px;
        font-size: 0.9rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    #preview {
        width: 100%;
        height: 400px;
        border: none;
        background: var(--foreground);
    }

    .form-group {
        margin-bottom: 15px;
    }

    .form-group label {
        display: block;
        margin-bottom: 5px;
        font-weight: 500;
        color: var(--foreground);
    }

    .form-control {
        width: 100%;
        padding: 10px 15px;
        border: 1px solid var(--border);
        border-radius: calc(var(--radius) / 2);
        background: var(--input);
        color: var(--foreground);
        transition: var(--transition);
    }

    .form-control:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 2px var(--ring);
    }

    select.form-control {
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23f8fafc' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 15px center;
        padding-right: 40px;
    }

    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0.75rem 1.5rem;
        border-radius: 2rem;
        font-weight: 500;
        transition: var(--transition);
        cursor: pointer;
        gap: 8px;
        border: none;
    }

    .btn-primary {
        background: linear-gradient(to right, var(--primary), var(--accent));
        color: var(--foreground);
    }

    .btn-primary:hover {
        background: linear-gradient(to right, var(--accent), var(--primary));
        transform: translateY(-3px);
        box-shadow: 0 5px 15px rgba(99, 102, 241, 0.4);
    }

    .btn-secondary {
        background: var(--secondary);
        color: var(--foreground);
    }

    .btn-secondary:hover {
        background: var(--accent);
        transform: translateY(-3px);
        box-shadow: 0 5px 15px rgba(139, 92, 246, 0.4);
    }

    .btn-group {
        display: flex;
        gap: 10px;
        margin-top: 20px;
        flex-wrap: wrap;
    }

    .output-container {
        margin-top: 20px;
        border: 1px solid var(--border);
        border-radius: var(--radius);
        overflow: hidden;
        background: var(--card);
        transition: var(--transition);
    }

    .output-container:hover {
        border-color: var(--primary);
    }

    .output-header {
        background: var(--editor-bg);
        color: var(--editor-text);
        padding: 8px 15px;
        font-size: 0.9rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    #backend-output {
        background: var(--card-hover);
        padding: 15px;
        min-height: 200px;
        max-height: 400px;
        overflow-y: auto;
        font-family: 'Consolas', monospace;
        white-space: pre-wrap;
        word-wrap: break-word;
        color: var(--foreground);
        font-size: 14px;
        line-height: 1.5;
        margin: 0;
    }

    .loading {
        display: none;
        text-align: center;
        padding: 20px;
        background: var(--card);
        border-radius: var(--radius);
        border: 1px solid var(--border);
    }

    .loading i {
        font-size: 2rem;
        color: var(--primary);
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    .features-list {
        margin: 20px 0;
        padding: 0;
        list-style: none;
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 10px;
    }

    .features-list li {
        padding: 10px 15px;
        background: var(--card-hover);
        border-radius: var(--radius);
        border: 1px solid var(--border);
        display: flex;
        align-items: center;
        gap: 10px;
        transition: var(--transition);
    }

    .features-list li:hover {
        transform: translateY(-5px);
        border-color: var(--primary);
    }

    .features-list li i {
        color: var(--primary);
    }

    .template-selector {
        margin-bottom: 20px;
    }

    .template-selector select {
        width: 100%;
        max-width: 300px;
    }

    @media (max-width: 768px) {
        .editor-panels {
            grid-template-columns: 1fr;
        }

        .btn {
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
        }

        .editor-header h2 {
            font-size: 1.5rem;
        }

        .features-list {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 480px) {
        .nav-tabs .nav-link {
            padding: 8px 12px;
            font-size: 0.9rem;
        }

        .btn {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
        }

        .editor-header h2 {
            font-size: 1.3rem;
        }
    }
</style>
</head>
<body>
    <div class="container mt-5">
        <h2>Online Code Editor</h2>
        <p>Viết và chạy code trực tiếp trong trình duyệt. Hỗ trợ HTML, CSS, JavaScript với live preview, và C++, C#, Java qua backend.</p>

        <!-- Phần frontend (HTML, CSS, JS) -->
        <div class="editor-container">
            <div class="editor-box">
                <label for="html-code">HTML:</label>
                <textarea id="html-code" class="form-control" placeholder="Nhập code HTML"></textarea>
            </div>
            <div class="editor-box">
                <label for="css-code">CSS:</label>
                <textarea id="css-code" class="form-control" placeholder="Nhập code CSS"></textarea>
            </div>
            <div class="editor-box">
                <label for="js-code">JavaScript:</label>
                <textarea id="js-code" class="form-control" placeholder="Nhập code JavaScript"></textarea>
            </div>
        </div>
        <div class="qr-result">
            <h4>Preview:</h4>
            <iframe id="preview"></iframe>
        </div>

        <!-- Phần backend (C++, C#, Java, v.v.) -->
        <div id="backend-section" class="qr-form">
            <h4>Backend Code Execution:</h4>
            <form method="POST" action="" id="backend-form">
                <div class="form-group">
                    <label for="language">Chọn ngôn ngữ:</label>
                    <select id="language" name="language" class="form-control">
                        <option value="cpp">C++</option>
                        <option value="csharp">C#</option>
                        <option value="java">Java</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="backend_code">Nhập code:</label>
                    <textarea id="backend_code" name="backend_code" class="form-control" rows="5" placeholder="Nhập code của bạn"><?php echo htmlspecialchars($code ?? ''); ?></textarea>
                </div>
                <div class="form-group">
                    <label for="backend_input">Nhập dữ liệu đầu vào (mỗi giá trị trên một dòng):</label>
                    <textarea id="backend_input" name="backend_input" class="form-control" rows="3" placeholder="Nhập dữ liệu đầu vào (stdin), ví dụ: 5\n3\n7"><?php echo htmlspecialchars($stdin ?? ''); ?></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Chạy Code</button>
            </form>
            <div class="qr-result" id="backend-result">
                <h4>Kết quả:</h4>
                <pre id="backend-output"><?php if (isset($backend_output)) echo htmlspecialchars($backend_output); ?></pre>
            </div>
        </div>

        <a href="dashboard.php" class="btn btn-secondary mt-3">Quay lại Dashboard</a>
    </div>

    <script>
        // Live preview cho HTML, CSS, JS
        const htmlCode = document.getElementById('html-code');
        const cssCode = document.getElementById('css-code');
        const jsCode = document.getElementById('js-code');
        const preview = document.getElementById('preview');

        function updatePreview() {
            const html = htmlCode.value || '';
            const css = `<style>${cssCode.value || ''}</style>`;
            const js = `<script>${jsCode.value || ''}<\/script>`;
            const content = `${html}${css}${js}`;
            
            const previewDoc = preview.contentDocument || preview.contentWindow.document;
            previewDoc.open();
            previewDoc.write(content);
            previewDoc.close();
        }

        htmlCode.addEventListener('input', updatePreview);
        cssCode.addEventListener('input', updatePreview);
        jsCode.addEventListener('input', updatePreview);

        // Cập nhật preview lần đầu
        updatePreview();

        // Mô phỏng tương tác từng bước (hiển thị kết quả dần dần)
        const backendOutput = document.getElementById('backend-output');
        if (backendOutput) {
            const outputText = backendOutput.textContent;
            const lines = outputText.split('\n');
            backendOutput.textContent = ''; // Xóa nội dung ban đầu

            let currentIndex = 0;
            function displayNextLine() {
                if (currentIndex < lines.length) {
                    backendOutput.textContent += lines[currentIndex] + '\n';
                    currentIndex++;
                    setTimeout(displayNextLine, 500); // Hiển thị từng dòng sau 0.5 giây
                }
            }

            if (outputText) {
                displayNextLine();
            }
        }
    </script>
</body>
</html>