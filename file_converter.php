<?php
// Bật chế độ debug để tìm lỗi
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include 'db_config.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Cài đặt thư viện nếu chưa có
// composer require phpoffice/phpword
// composer require tecnickcom/tcpdf
// composer require smalot/pdfparser

require 'vendor/autoload.php';

use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpWord\Writer\HTML as HTMLWriter;
use Smalot\PdfParser\Parser as PdfParser;
use TCPDF;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['file'])) {
    $file = $_FILES['file'];
    $fileName = $file['name'];
    $fileTmpName = $file['tmp_name'];
    $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $uploadDir = __DIR__ . '/uploads/'; // Đường dẫn tuyệt đối
    $outputDir = __DIR__ . '/converted/'; // Đường dẫn tuyệt đối
    
    // Tạo thư mục nếu chưa tồn tại
    if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
    if (!file_exists($outputDir)) mkdir($outputDir, 0777, true);

    $newFileName = uniqid() . '.' . $fileExt;
    $uploadedFile = $uploadDir . $newFileName;

    if (move_uploaded_file($fileTmpName, $uploadedFile)) {
        $outputFile = '';
        $conversionType = $_POST['conversion_type'];

        if ($conversionType == 'word_to_pdf' && $fileExt == 'docx') {
            // Chuyển Word sang PDF
            try {
                // Đọc file Word
                $phpWord = WordIOFactory::load($uploadedFile);

                // Chuyển Word thành HTML trước
                $htmlWriter = new HTMLWriter($phpWord);
                $htmlFile = $uploadDir . uniqid() . '.html';
                $htmlWriter->save($htmlFile);

                // Đọc nội dung HTML
                $htmlContent = file_get_contents($htmlFile);
                // Sửa lỗi deprecated: thay mb_convert_encoding bằng htmlentities
                $htmlContent = htmlentities($htmlContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                // Sử dụng TCPDF để render HTML thành PDF
                $pdf = new TCPDF();
                $pdf->SetCreator(PDF_CREATOR);
                $pdf->SetAuthor('File Converter');
                $pdf->SetTitle('Converted Document');
                $pdf->SetMargins(15, 15, 15);
                $pdf->SetAutoPageBreak(true, 15);

                // Cấu hình font hỗ trợ tiếng Việt
                $pdf->SetFont('times', '', 12); // Sử dụng font Times hỗ trợ tiếng Việt
                $pdf->AddPage();

                // Đảm bảo nội dung là UTF-8
                $pdf->writeHTML($htmlContent, true, false, true, false, '');

                // Lưu file PDF với đường dẫn tuyệt đối
                $outputFile = $outputDir . uniqid() . '.pdf';
                $pdf->Output($outputFile, 'F');

                // Xóa file HTML tạm
                unlink($htmlFile);
            } catch (Exception $e) {
                $error = "Lỗi khi chuyển đổi Word sang PDF: " . $e->getMessage();
            }
        } elseif ($conversionType == 'pdf_to_word' && $fileExt == 'pdf') {
            // Chuyển PDF sang Word
            try {
                $pdfParser = new PdfParser();
                $pdf = $pdfParser->parseFile($uploadedFile);
                $text = $pdf->getText();

                if (empty($text)) {
                    $error = "Không thể trích xuất nội dung từ PDF. File có thể là hình ảnh hoặc mã hóa phức tạp (cần OCR).";
                } else {
                    $phpWord = new \PhpOffice\PhpWord\PhpWord();
                    $section = $phpWord->addSection();
                    $section->addText($text); // Thêm nội dung trích xuất từ PDF
                    $objWriter = WordIOFactory::createWriter($phpWord, 'Word2007');
                    $outputFile = $outputDir . uniqid() . '.docx';
                    $objWriter->save($outputFile);
                }
            } catch (Exception $e) {
                $error = "Lỗi khi chuyển đổi PDF sang Word: " . $e->getMessage();
            }
        }

        if (file_exists($outputFile)) {
            // Chuyển đường dẫn tuyệt đối thành đường dẫn tương đối để tải về
            $downloadLink = str_replace(__DIR__ . '/', '', $outputFile);
        } else {
            $error = $error ?? "Lỗi trong quá trình chuyển đổi.";
        }
    } else {
        $error = "Lỗi khi tải file lên.";
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>File Converter</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        :root {
            --border-color: #ddd;
            --card-bg: #f9f9f9;
            --small-radius: 5px;
            --small-padding: 15px;
            --delete-color: #ff4444;
            --delete-hover-color: #cc0000;
            --small-padding-mobile: 10px;
        }

        .convert-form {
            margin-top: 20px;
            max-width: 500px;
            margin-left: auto;
            margin-right: auto;
        }

        .convert-form select,
        .convert-form input[type="file"] {
            width: 100%;
            max-width: 300px;
            margin-bottom: 10px;
            padding: 10px;
            border: 2px solid var(--border-color);
            border-radius: var(--small-radius);
            background: var(--card-bg);
            font-size: 1rem;
        }

        .convert-form button {
            padding: 10px 20px;
            background: #007bff;
            color: #fff;
            border: none;
            border-radius: var(--small-radius);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .convert-form button:hover {
            background: #0056b3;
            transform: scale(1.05);
        }

        .progress-container {
            margin-top: 20px;
            display: none;
            max-width: 500px;
            margin-left: auto;
            margin-right: auto;
        }

        .progress-bar {
            height: 25px;
            background: #e0e0e0;
            border-radius: var(--small-radius);
            overflow: hidden;
            transition: width 0.5s ease-in-out;
        }

        .progress-bar-fill {
            height: 100%;
            background: #007bff;
            width: 0%;
            text-align: center;
            color: #fff;
            font-size: 14px;
            line-height: 25px;
            border-radius: var(--small-radius);
            transition: width 0.5s ease-in-out;
        }

        .convert-result {
            margin-top: 20px;
            text-align: center;
        }

        .convert-result a {
            padding: 8px 15px;
            background: #28a745;
            color: #fff;
            border-radius: var(--small-radius);
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .convert-result a:hover {
            background: #218838;
            transform: scale(1.05);
        }

        @media (max-width: 768px) {
            .convert-form select,
            .convert-form input[type="file"] {
                max-width: 100%;
                font-size: 14px;
                padding: 8px;
            }

            .convert-form button {
                font-size: 14px;
                padding: 8px 16px;
            }

            .progress-bar {
                height: 20px;
            }

            .progress-bar-fill {
                font-size: 12px;
                line-height: 20px;
            }

            .convert-result a {
                font-size: 14px;
                padding: 6px 12px;
            }
        }

        @media (max-width: 480px) {
            .convert-form select,
            .convert-form input[type="file"] {
                font-size: 12px;
                padding: 6px;
            }

            .convert-form button {
                font-size: 12px;
                padding: 6px 12px;
            }

            .progress-bar {
                height: 18px;
            }

            .progress-bar-fill {
                font-size: 10px;
                line-height: 18px;
            }

            .convert-result a {
                font-size: 12px;
                padding: 4px 10px;
            }
        }
    </style>
</head>
<body>
    <div class="container mt-5">
        <h2>File Converter</h2>
        <p>Chuyển đổi file Word sang PDF và ngược lại.</p>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if (isset($downloadLink)): ?>
            <div class="convert-result">
                <div class="alert alert-success">
                    Chuyển đổi thành công! <a href="<?php echo $downloadLink; ?>" download>Tải file về</a>
                </div>
            </div>
        <?php endif; ?>

        <div class="convert-form">
            <form id="convertForm" method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="file">Chọn file:</label>
                    <input type="file" id="file" name="file" accept=".docx,.pdf" required>
                </div>
                <div class="form-group">
                    <label for="conversion_type">Loại chuyển đổi:</label>
                    <select id="conversion_type" name="conversion_type" required>
                        <option value="word_to_pdf">Word sang PDF</option>
                        <option value="pdf_to_word">PDF sang Word</option>
                    </select>
                </div>
                <button type="submit">Chuyển đổi</button>
            </form>
        </div>

        <div class="progress-container" id="progressContainer">
            <h4>Tiến trình:</h4>
            <div class="progress-bar">
                <div class="progress-bar-fill" id="progressBar">0%</div>
            </div>
        </div>

        <a href="dashboard.php" class="btn btn-secondary mt-3">Quay lại Dashboard</a>
    </div>

    <script>
        document.getElementById('convertForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const form = this;
            const progressContainer = document.getElementById('progressContainer');
            const progressBar = document.getElementById('progressBar');

            progressContainer.style.display = 'block';
            progressBar.style.width = '0%';
            progressBar.textContent = '0%';

            const formData = new FormData(form);
            const xhr = new XMLHttpRequest();

            xhr.upload.addEventListener('progress', function(event) {
                if (event.lengthComputable) {
                    const percentComplete = Math.round((event.loaded / event.total) * 50);
                    progressBar.style.width = percentComplete + '%';
                    progressBar.textContent = percentComplete + '%';
                }
            });

            xhr.onreadystatechange = function() {
                if (xhr.readyState === XMLHttpRequest.DONE) {
                    let progress = 50;
                    const interval = setInterval(() => {
                        progress += 10;
                        if (progress <= 100) {
                            progressBar.style.width = progress + '%';
                            progressBar.textContent = progress + '%';
                        } else {
                            clearInterval(interval);
                            form.submit();
                        }
                    }, 200);
                }
            };

            xhr.open('POST', form.action, true);
            xhr.send(formData);
        });
    </script>
</body>
</html>