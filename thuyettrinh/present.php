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

// Get all slides for this presentation
$stmt = $conn->prepare("SELECT * FROM presentation_slides WHERE presentation_id = ? ORDER BY slide_order ASC");
$stmt->bind_param("i", $presentation_id);
$stmt->execute();
$result = $stmt->get_result();
$slides = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Check if there are any slides
if (empty($slides)) {
    // Redirect to edit page to add slides
    header("Location: edit_presentation.php?id=" . $presentation_id . "&error=no_slides");
    exit();
}

// Check if there's an active session for this presentation
$stmt = $conn->prepare("SELECT * FROM presentation_sessions WHERE presentation_id = ? AND is_active = 1");
$stmt->bind_param("i", $presentation_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // Create a new session
    $stmt->close();
    $stmt = $conn->prepare("INSERT INTO presentation_sessions (presentation_id, current_slide) VALUES (?, 1)");
    $stmt->bind_param("i", $presentation_id);
    $stmt->execute();
    $session_id = $conn->insert_id;
} else {
    $session = $result->fetch_assoc();
    $session_id = $session['id'];
}
$stmt->close();

// Update current slide if requested
if (isset($_GET['slide']) && is_numeric($_GET['slide'])) {
    $slide_number = (int)$_GET['slide'];
    
    // Ensure slide number is valid
    if ($slide_number >= 1 && $slide_number <= count($slides)) {
        $stmt = $conn->prepare("UPDATE presentation_sessions SET current_slide = ? WHERE id = ?");
        $stmt->bind_param("ii", $slide_number, $session_id);
        $stmt->execute();
        $stmt->close();
    }
}

// Get current slide number
$stmt = $conn->prepare("SELECT current_slide FROM presentation_sessions WHERE id = ?");
$stmt->bind_param("i", $session_id);
$stmt->execute();
$result = $stmt->get_result();
$session = $result->fetch_assoc();
$current_slide = $session['current_slide'];
$stmt->close();

// Make sure current_slide is valid
if ($current_slide > count($slides)) {
    $current_slide = 1;
    // Update the session
    $stmt = $conn->prepare("UPDATE presentation_sessions SET current_slide = ? WHERE id = ?");
    $stmt->bind_param("ii", $current_slide, $session_id);
    $stmt->execute();
    $stmt->close();
}

// End presentation session
if (isset($_GET['end']) && $_GET['end'] == 1) {
    $stmt = $conn->prepare("UPDATE presentation_sessions SET is_active = 0, ended_at = NOW() WHERE id = ?");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $stmt->close();
    
    header("Location: presentation.php");
    exit();
}

// Get the full URL for the view page
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
$view_url = $protocol . "://" . $host . dirname($_SERVER['PHP_SELF']) . "/view.php?code=" . $presentation['access_code'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Presenting: <?php echo htmlspecialchars($presentation['title']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3f37c9;
            --accent-color: #4895ef;
            --success-color: #4cc9f0;
            --danger-color: #f72585;
            --dark-color: #212529;
            --light-color: #f8f9fa;
            --transition-speed: 0.3s;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            overflow: hidden;
            height: 100vh;
            display: flex;
            flex-direction: column;
            background-color: var(--light-color);
        }
        
        .presentation-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            position: relative;
            overflow: hidden;
        }
        
        .slide-container {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            transition: background-color var(--transition-speed) ease;
        }
        
        .slide {
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 40px;
            box-sizing: border-box;
            transition: transform var(--transition-speed) ease, opacity var(--transition-speed) ease;
        }
        
        .slide.animate-in {
            animation: fadeIn 0.5s ease forwards;
        }
        
        .slide h1 {
            font-size: 3.5em;
            margin-bottom: 30px;
            color: var(--dark-color);
            text-shadow: 1px 1px 3px rgba(0,0,0,0.1);
        }
        
        .slide-content {
            font-size: 1.8em;
            line-height: 1.6;
            max-width: 800px;
            color: var(--dark-color);
        }
        
        .controls {
            display: flex;
            justify-content: space-between;
            padding: 15px 25px;
            background-color: var(--dark-color);
            color: white;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
            z-index: 100;
        }
        
        .control-btn {
            background: none;
            border: none;
            color: white;
            font-size: 16px;
            cursor: pointer;
            padding: 8px 15px;
            border-radius: 4px;
            transition: background-color var(--transition-speed) ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .control-btn:hover {
            background-color: rgba(255,255,255,0.1);
        }
        
        .control-btn:disabled {
            color: #777;
            cursor: not-allowed;
        }
        
        .slide-number {
            display: flex;
            align-items: center;
            font-size: 14px;
            background-color: rgba(255,255,255,0.1);
            padding: 5px 12px;
            border-radius: 20px;
        }
        
        .qr-info {
            display: flex;
            align-items: center;
            margin-right: 20px;
        }
        
        .qr-code {
            margin-left: 10px;
            cursor: pointer;
            position: relative;
            display: flex;
            align-items: center;
            gap: 8px;
            background-color: rgba(255,255,255,0.1);
            padding: 8px 15px;
            border-radius: 4px;
            transition: background-color var(--transition-speed) ease;
        }
        
        .qr-code:hover {
            background-color: rgba(255,255,255,0.2);
        }
        
        .qr-popup {
            display: none;
            position: absolute;
            bottom: 50px;
            right: 0;
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 5px 25px rgba(0,0,0,0.2);
            z-index: 100;
            text-align: center;
            color: var(--dark-color);
            min-width: 250px;
            animation: fadeInUp 0.3s ease forwards;
        }
        
        .qr-popup img {
            max-width: 200px;
            margin-bottom: 15px;
            border: 1px solid #eee;
            border-radius: 4px;
        }
        
        .qr-popup .access-code {
            background-color: #f5f5f5;
            padding: 8px;
            border-radius: 4px;
            margin: 10px 0;
            font-size: 18px;
            font-weight: bold;
            letter-spacing: 1px;
        }
        
        .qr-popup .url-display {
            margin-top: 10px;
            word-break: break-all;
            font-size: 12px;
            color: #666;
            background-color: #f5f5f5;
            padding: 8px;
            border-radius: 4px;
            text-align: left;
        }
        
        .copy-btn {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 4px;
            cursor: pointer;
            margin-top: 10px;
            transition: background-color var(--transition-speed) ease;
            display: flex;
            align-items: center;
            gap: 8px;
            justify-content: center;
            width: 100%;
        }
        
        .copy-btn:hover {
            background-color: var(--secondary-color);
        }
        
        .slide-image {
            max-width: 80%;
            max-height: 60%;
            margin: 20px 0;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: transform var(--transition-speed) ease;
        }
        
        .slide-image:hover {
            transform: scale(1.02);
        }
        
        .viewers-count {
            position: absolute;
            top: 20px;
            right: 20px;
            background-color: rgba(0,0,0,0.5);
            color: white;
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .keyboard-shortcuts {
            position: absolute;
            bottom: 20px;
            left: 20px;
            background-color: rgba(0,0,0,0.5);
            color: white;
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            z-index: 10;
        }
        
        .keyboard-key {
            background-color: rgba(255,255,255,0.2);
            padding: 2px 8px;
            border-radius: 4px;
            margin: 0 2px;
        }
        
        .end-btn {
            background-color: var(--danger-color);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 4px;
            cursor: pointer;
            transition: background-color var(--transition-speed) ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .end-btn:hover {
            background-color: #d90166;
        }
    </style>
</head>
<body>
    <div class="presentation-container">
        <div class="viewers-count">
            <i class="fas fa-users"></i>
            <span id="viewersCount">Loading...</span>
        </div>
        
        <div class="keyboard-shortcuts">
            <i class="fas fa-keyboard"></i>
            <span>Use <span class="keyboard-key">←</span> <span class="keyboard-key">→</span> to navigate</span>
        </div>
        
        <?php if (isset($slides[$current_slide - 1])): ?>
            <?php $current = $slides[$current_slide - 1]; ?>
            <div class="slide-container" id="slideContainer" style="background-color: <?php echo htmlspecialchars($current['background_color']); ?>">
                <div class="slide animate-in">
                    <h1 class="animate__animated animate__fadeInDown"><?php echo htmlspecialchars($current['title']); ?></h1>
                    
                    <?php
                    // Get images for this slide
                    $stmt = $conn->prepare("SELECT * FROM slide_images WHERE slide_id = ?");
                    $stmt->bind_param("i", $current['id']);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($result->num_rows > 0) {
                        while ($image = $result->fetch_assoc()) {
                            echo '<img src="' . htmlspecialchars($image['image_path']) . '" alt="Slide Image" class="slide-image animate__animated animate__fadeIn animate__delay-1s">';
                        }
                    }
                    $stmt->close();
                    ?>
                    
                    <div class="slide-content animate__animated animate__fadeIn animate__delay-1s"><?php echo nl2br(htmlspecialchars($current['content'])); ?></div>
                </div>
            </div>
        <?php else: ?>
            <div class="slide-container">
                <div class="slide">
                    <h1>No slides available</h1>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="controls">
        <div>
            <button class="control-btn" id="prevBtn" onclick="goToSlide(<?php echo max(1, $current_slide - 1); ?>)" <?php echo $current_slide <= 1 ? 'disabled' : ''; ?>>
                <i class="fas fa-arrow-left"></i> Previous
            </button>
            <button class="control-btn" id="nextBtn" onclick="goToSlide(<?php echo min(count($slides), $current_slide + 1); ?>)" <?php echo $current_slide >= count($slides) ? 'disabled' : ''; ?>>
                Next <i class="fas fa-arrow-right"></i>
            </button>
        </div>
        
        <div class="slide-number">
            <i class="fas fa-file-alt" style="margin-right: 8px;"></i>
            Slide <?php echo $current_slide; ?> of <?php echo count($slides); ?>
        </div>
        
        <div style="display: flex; align-items: center; gap: 10px;">
            <div class="qr-code" onclick="toggleQRCode()">
                <i class="fas fa-qrcode"></i>
                <span>Share</span>
                <div class="qr-popup" id="qrPopup">
                    <h3 style="margin-top: 0;">Share Presentation</h3>
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?php echo urlencode($view_url); ?>" alt="QR Code">
                    <div>Access Code:</div>
                    <div class="access-code"><?php echo htmlspecialchars($presentation['access_code']); ?></div>
                    <div>Scan to join the presentation</div>
                    <div class="url-display"><?php echo htmlspecialchars($view_url); ?></div>
                    <button class="copy-btn" onclick="copyViewLink()">
                        <i class="fas fa-copy"></i> Copy Link
                    </button>
                </div>
            </div>
            
            <button class="end-btn" onclick="endPresentation()">
                <i class="fas fa-times"></i> End Presentation
            </button>
        </div>
    </div>
    
    <script>
        // Track current slide
        let currentSlide = <?php echo $current_slide; ?>;
        const totalSlides = <?php echo count($slides); ?>;
        
        // Function to toggle QR code popup
        function toggleQRCode() {
            const popup = document.getElementById('qrPopup');
            popup.style.display = popup.style.display === 'block' ? 'none' : 'block';
        }
        
        // Function to copy view link
        function copyViewLink() {
            const viewUrl = '<?php echo $view_url; ?>';
            navigator.clipboard.writeText(viewUrl).then(() => {
                alert('Link copied to clipboard!');
            }).catch(err => {
                console.error('Could not copy text: ', err);
            });
        }
        
        // Function to end presentation with confirmation
        function endPresentation() {
            if (confirm('Are you sure you want to end this presentation? Viewers will no longer be able to access it.')) {
                window.location.href = 'present.php?id=<?php echo $presentation_id; ?>&end=1';
            }
        }
        
        // Function to navigate to a specific slide with animation
        function goToSlide(slideNumber) {
            if (slideNumber < 1 || slideNumber > totalSlides || slideNumber === currentSlide) return;
            
            // Add exit animation
            const slide = document.querySelector('.slide');
            slide.classList.remove('animate-in');
            
            // Wait for animation to complete
            setTimeout(() => {
                window.location.href = 'present.php?id=<?php echo $presentation_id; ?>&slide=' + slideNumber;
            }, 100);
        }
        
        // Keyboard navigation
        document.addEventListener('keydown', function(event) {
            if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
                if (currentSlide > 1) {
                    goToSlide(currentSlide - 1);
                }
            } else if (event.key === 'ArrowRight' || event.key === 'ArrowDown' || event.key === ' ') {
                if (currentSlide < totalSlides) {
                    goToSlide(currentSlide + 1);
                }
            }
        });
        
        // Close popup when clicking outside
        document.addEventListener('click', function(event) {
            const popup = document.getElementById('qrPopup');
            const qrCode = document.querySelector('.qr-code');
            
            if (popup.style.display === 'block' && !qrCode.contains(event.target)) {
                popup.style.display = 'none';
            }
        });
        
        // Function to update viewers count
        function updateViewersCount() {
            fetch('get_viewers_count.php?session_id=<?php echo $session_id; ?>')
                .then(response => response.json())
                .then(data => {
                    document.getElementById('viewersCount').textContent = data.count + ' viewer' + (data.count !== 1 ? 's' : '');
                })
                .catch(error => {
                    console.error('Error fetching viewers count:', error);
                });
        }
        
        // Update viewers count every 5 seconds
        updateViewersCount();
        setInterval(updateViewersCount, 5000);
        
        // Auto-refresh to check for updates (for presenter)
        setInterval(function() {
            fetch('get_current_slide.php?session_id=<?php echo $session_id; ?>')
                .then(response => response.json())
                .then(data => {
                    if (data.current_slide !== currentSlide) {
                        window.location.href = 'present.php?id=<?php echo $presentation_id; ?>&slide=' + data.current_slide;
                    }
                });
        }, 5000);
    </script>
</body>
</html>
