<?php
session_start();
require_once '../db_config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Check if presentation ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: presentation.php");
    exit();
}

$presentation_id = (int)$_GET['id'];

// Verify that the presentation belongs to the current user
$stmt = $conn->prepare("SELECT * FROM presentations WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $presentation_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: presentation.php");
    exit();
}

$presentation = $result->fetch_assoc();
$stmt->close();

// Generate QR code URL
$qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode('http://' . $_SERVER['HTTP_HOST'] . '/view.php?code=' . $presentation['access_code']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Share Presentation</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            margin-bottom: 20px;
        }
        .share-options {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-top: 30px;
        }
        .share-option {
            flex: 1;
            min-width: 300px;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 8px;
            text-align: center;
        }
        .qr-code {
            margin: 20px auto;
            max-width: 300px;
        }
        .qr-code img {
            width: 100%;
            height: auto;
        }
        .access-code {
            font-size: 24px;
            font-weight: bold;
            margin: 20px 0;
            padding: 10px;
            background-color: #f2f2f2;
            border-radius: 4px;
        }
        .btn {
            display: inline-block;
            padding: 10px 15px;
            background-color: #4CAF50;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            margin-right: 10px;
        }
        .btn:hover {
            background-color: #45a049;
        }
        .btn-secondary {
            background-color: #2196F3;
        }
        .btn-secondary:hover {
            background-color: #0b7dda;
        }
        .copy-input {
            width: 100%;
            padding: 10px;
            margin: 10px 0;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        .instructions {
            margin-top: 20px;
            padding: 15px;
            background-color: #f9f9f9;
            border-radius: 4px;
        }
        .instructions h3 {
            margin-top: 0;
        }
        .instructions ol {
            text-align: left;
            padding-left: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Share Presentation: <?php echo htmlspecialchars($presentation['title']); ?></h1>
        
        <div class="share-options">
            <div class="share-option">
                <h2>QR Code</h2>
                <p>Scan this QR code to join the presentation</p>
                
                <div class="qr-code">
                    <img src="<?php echo $qr_url; ?>" alt="QR Code">
                </div>
                
                <button class="btn" onclick="downloadQR()"><i class="fas fa-download"></i> Download QR Code</button>
            </div>
            
            <div class="share-option">
                <h2>Access Code</h2>
                <p>Share this code with your audience</p>
                
                <div class="access-code"><?php echo htmlspecialchars($presentation['access_code']); ?></div>
                
                <p>Direct Link:</p>
                <input type="text" class="copy-input" id="shareLink" value="<?php echo 'http://' . $_SERVER['HTTP_HOST'] . '/view.php?code=' . htmlspecialchars($presentation['access_code']); ?>" readonly>
                <button class="btn btn-secondary" onclick="copyLink()"><i class="fas fa-copy"></i> Copy Link</button>
            </div>
        </div>
        
        <div class="instructions">
            <h3>Instructions for Viewers</h3>
            <ol>
                <li>Scan the QR code or enter the access code at the presentation URL</li>
                <li>The presentation will automatically sync with your screen</li>
                <li>No login required - viewers can join instantly</li>
                <li>The presentation will update in real-time as you navigate through slides</li>
            </ol>
        </div>
        
        <div style="margin-top: 30px;">
            <a href="presentation.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to Presentations</a>
            <a href="present.php?id=<?php echo $presentation_id; ?>" class="btn"><i class="fas fa-play"></i> Start Presenting</a>
        </div>
    </div>
    
    <script>
        function copyLink() {
            const linkInput = document.getElementById('shareLink');
            linkInput.select();
            document.execCommand('copy');
            alert('Link copied to clipboard!');
        }
        
        function downloadQR() {
            const qrUrl = '<?php echo $qr_url; ?>';
            const link = document.createElement('a');
            link.href = qrUrl;
            link.download = 'presentation_qr_<?php echo $presentation_id; ?>.png';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    </script>
</body>
</html>
