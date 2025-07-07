<?php
session_start();
require_once '../db_config.php';

// Function to sanitize input data
function sanitize_input($data) {
    global $conn;
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return mysqli_real_escape_string($conn, $data);
}

// Check if access code is provided
if (!isset($_GET['code'])) {
    echo "Access code is required.";
    exit();
}

$access_code = sanitize_input($_GET['code']);

// Get presentation by access code
$stmt = $conn->prepare("SELECT * FROM presentations WHERE access_code = ?");
$stmt->bind_param("s", $access_code);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo "Invalid access code or presentation not found.";
    exit();
}

$presentation = $result->fetch_assoc();
$stmt->close();

// Get active session for this presentation
$stmt = $conn->prepare("SELECT * FROM presentation_sessions WHERE presentation_id = ? AND is_active = 1");
$stmt->bind_param("i", $presentation['id']);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo "This presentation is not currently active.";
    exit();
}

$session = $result->fetch_assoc();
$session_id = $session['id'];
$current_slide = $session['current_slide'];
$stmt->close();

// Record viewer information
$viewer_ip = $_SERVER['REMOTE_ADDR'];
$viewer_agent = $_SERVER['HTTP_USER_AGENT'];

// Check if this viewer is already recorded
$stmt = $conn->prepare("SELECT id FROM presentation_viewers WHERE session_id = ? AND viewer_ip = ?");
$stmt->bind_param("is", $session_id, $viewer_ip);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // Add new viewer
    $stmt->close();
    $stmt = $conn->prepare("INSERT INTO presentation_viewers (session_id, viewer_ip, viewer_agent) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $session_id, $viewer_ip, $viewer_agent);
    $stmt->execute();
} else {
    // Update last active time
    $viewer = $result->fetch_assoc();
    $stmt->close();
    $stmt = $conn->prepare("UPDATE presentation_viewers SET last_active = NOW() WHERE id = ?");
    $stmt->bind_param("i", $viewer['id']);
    $stmt->execute();
}
$stmt->close();

// Get all slides for this presentation
$stmt = $conn->prepare("SELECT * FROM presentation_slides WHERE presentation_id = ? ORDER BY slide_order ASC");
$stmt->bind_param("i", $presentation['id']);
$stmt->execute();
$result = $stmt->get_result();
$slides = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Check if there are any slides
if (empty($slides)) {
    echo "This presentation has no slides.";
    exit();
}

// Make sure current_slide is valid
if ($current_slide > count($slides)) {
    $current_slide = 1;
    // Update the session
    $stmt = $conn->prepare("UPDATE presentation_sessions SET current_slide = ? WHERE id = ?");
    $stmt->bind_param("ii", $current_slide, $session_id);
    $stmt->execute();
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Viewing: <?php echo htmlspecialchars($presentation['title']); ?></title>
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
        
        .footer {
            padding: 15px 25px;
            background-color: var(--dark-color);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
        }
        
        .slide-image {
            max-width: 80%;
            max-height: 60%;
            margin: 20px 0;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: transform var(--transition-speed) ease;
        }
        
        .status {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background-color: #4CAF50;
            margin-right: 8px;
            animation: pulse 2s infinite;
        }
        
        .status-text {
            display: flex;
            align-items: center;
            background-color: rgba(255,255,255,0.1);
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 14px;
        }
        
        .slide-number {
            background-color: rgba(255,255,255,0.1);
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0,0,0,0.7);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            color: white;
            display: none;
        }
        
        .loading-spinner {
            border: 5px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top: 5px solid white;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin-bottom: 20px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        @keyframes pulse {
            0% { opacity: 0.6; }
            50% { opacity: 1; }
            100% { opacity: 0.6; }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
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
        
        .presentation-title {
            position: absolute;
            top: 20px;
            left: 20px;
            background-color: rgba(0,0,0,0.5);
            color: white;
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 14px;
            max-width: 50%;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        /* Slide transitions */
        .slide-transition {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.5s ease;
        }
        
        .slide-transition.active {
            opacity: 1;
        }
        
        /* Toast notifications */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
        }
        
        .toast {
            background-color: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 12px 20px;
            border-radius: 4px;
            margin-bottom: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
            display: flex;
            align-items: center;
            gap: 10px;
            animation: fadeInRight 0.3s ease forwards;
            max-width: 300px;
        }
        
        .toast.success {
            background-color: rgba(40, 167, 69, 0.9);
        }
        
        .toast.error {
            background-color: rgba(220, 53, 69, 0.9);
        }
        
        .toast.info {
            background-color: rgba(23, 162, 184, 0.9);
        }
        
        @keyframes fadeInRight {
            from { opacity: 0; transform: translateX(20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        @keyframes fadeOutRight {
            from { opacity: 1; transform: translateX(0); }
            to { opacity: 0; transform: translateX(20px); }
        }
    </style>
</head>
<body>
    <div id="loadingOverlay" class="loading-overlay">
        <div class="loading-spinner"></div>
        <div>Loading new slide...</div>
    </div>
    
    <div class="toast-container" id="toastContainer"></div>
    
    <div class="presentation-container">
        <div class="presentation-title">
            <i class="fas fa-presentation"></i>
            <?php echo htmlspecialchars($presentation['title']); ?>
        </div>
        
        <div class="viewers-count">
            <i class="fas fa-users"></i>
            <span id="viewersCount">Loading...</span>
        </div>
        
        <div id="slidesContainer">
            <!-- Slides will be loaded here dynamically -->
        </div>
    </div>
    
    <div class="footer">
        <div class="status-text">
            <span class="status"></span> <span id="connectionStatus">Connected to presentation</span>
        </div>
        <div class="slide-number">
            <i class="fas fa-file-alt"></i>
            Slide <span id="currentSlideNumber"><?php echo $current_slide; ?></span> of <span id="totalSlides"><?php echo count($slides); ?></span>
        </div>
    </div>
    
    <script>
        // Global variables
        const sessionId = <?php echo $session_id; ?>;
        let currentSlide = <?php echo $current_slide; ?>;
        let totalSlides = <?php echo count($slides); ?>;
        let slides = <?php echo json_encode($slides); ?>;
        let slideImages = {};
        let isActive = true;
        let checkingForUpdates = false;
        let lastUpdateTime = Date.now();
        
        // Initialize the presentation
        document.addEventListener('DOMContentLoaded', function() {
            initializePresentation();
            updateViewersCount();
            setInterval(updateViewersCount, 5000);
            setInterval(checkForUpdates, 2000);
            setInterval(updateViewerActivity, 30000);
        });
        
        // Function to initialize the presentation
        async function initializePresentation() {
            showLoading();
            
            try {
                // Load slide images
                await loadSlideImages();
                
                // Render all slides
                renderSlides();
                
                // Show the current slide
                showSlide(currentSlide);
                
                hideLoading();
                showToast('Connected to presentation', 'success');
            } catch (error) {
                console.error('Error initializing presentation:', error);
                hideLoading();
                showToast('Error loading presentation', 'error');
            }
        }
        
        // Function to load slide images
        async function loadSlideImages() {
            for (const slide of slides) {
                try {
                    const response = await fetch(`get_slide_images.php?slide_id=${slide.id}`);
                    const data = await response.json();
                    slideImages[slide.id] = data;
                } catch (error) {
                    console.error(`Error loading images for slide ${slide.id}:`, error);
                    slideImages[slide.id] = [];
                }
            }
        }
        
        // Function to render all slides
        function renderSlides() {
            const container = document.getElementById('slidesContainer');
            container.innerHTML = '';
            
            slides.forEach((slide, index) => {
                const slideNumber = index + 1;
                const slideElement = document.createElement('div');
                slideElement.className = 'slide-transition';
                slideElement.id = `slide-${slideNumber}`;
                slideElement.style.backgroundColor = slide.background_color;
                
                let imagesHtml = '';
                if (slideImages[slide.id] && slideImages[slide.id].length > 0) {
                    slideImages[slide.id].forEach(image => {
                        imagesHtml += `<img src="${image.image_path}" alt="Slide Image" class="slide-image">`;
                    });
                }
                
                slideElement.innerHTML = `
                    <div class="slide">
                        <h1>${slide.title}</h1>
                        ${imagesHtml}
                        <div class="slide-content">${slide.content.replace(/\n/g, '<br>')}</div>
                    </div>
                `;
                
                container.appendChild(slideElement);
            });
        }
        
        // Function to show a specific slide
        function showSlide(slideNumber) {
            const oldSlide = document.querySelector('.slide-transition.active');
            const newSlide = document.getElementById(`slide-${slideNumber}`);
            
            if (!newSlide) return;
            
            // Apply transition effect
            if (oldSlide) {
                oldSlide.style.opacity = '0';
                
                setTimeout(() => {
                    oldSlide.classList.remove('active');
                    newSlide.classList.add('active');
                    newSlide.style.opacity = '1';
                }, 300);
            } else {
                newSlide.classList.add('active');
                newSlide.style.opacity = '1';
            }
            
            // Update current slide
            currentSlide = slideNumber;
            document.getElementById('currentSlideNumber').textContent = currentSlide;
        }
        
        // Function to check for updates
        function checkForUpdates() {
            if (!isActive || checkingForUpdates) return;
            
            checkingForUpdates = true;
            
            fetch(`get_current_slide.php?session_id=${sessionId}`)
                .then(response => response.json())
                .then(data => {
                    checkingForUpdates = false;
                    lastUpdateTime = Date.now();
                    
                    if (data.error) {
                        console.error(data.error);
                        return;
                    }
                    
                    // Check if session is still active
                    if (!data.is_active) {
                        isActive = false;
                        document.querySelector('.status').style.backgroundColor = '#f44336';
                        document.getElementById('connectionStatus').textContent = 'Presentation has ended';
                        showToast('This presentation has ended', 'info');
                        return;
                    }
                    
                    // Check if slide has changed
                    if (data.current_slide !== currentSlide) {
                        showLoading();
                        
                        // Change slide with animation
                        setTimeout(() => {
                            showSlide(data.current_slide);
                            hideLoading();
                        }, 300);
                    }
                })
                .catch(error => {
                    checkingForUpdates = false;
                    console.error('Error checking for updates:', error);
                    
                    // Check if we haven't received updates for a while
                    if (Date.now() - lastUpdateTime > 10000) {
                        document.querySelector('.status').style.backgroundColor = '#ffc107';
                        document.getElementById('connectionStatus').textContent = 'Connection issues...';
                    }
                });
        }
        
        // Function to update viewer's activity
        function updateViewerActivity() {
            if (isActive) {
                fetch(`update_viewer.php?session_id=${sessionId}`)
                    .catch(error => {
                        console.error('Error updating viewer activity:', error);
                    });
            }
        }
        
        // Function to update viewers count
        function updateViewersCount() {
            fetch(`get_viewers_count.php?session_id=${sessionId}`)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('viewersCount').textContent = data.count + ' viewer' + (data.count !== 1 ? 's' : '');
                })
                .catch(error => {
                    console.error('Error fetching viewers count:', error);
                });
        }
        
        // Function to show loading overlay
        function showLoading() {
            document.getElementById('loadingOverlay').style.display = 'flex';
        }
        
        // Function to hide loading overlay
        function hideLoading() {
            document.getElementById('loadingOverlay').style.display = 'none';
        }
        
        // Function to show toast notification
        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            
            let icon = 'info-circle';
            if (type === 'success') icon = 'check-circle';
            if (type === 'error') icon = 'exclamation-circle';
            
            toast.innerHTML = `
                <i class="fas fa-${icon}"></i>
                <span>${message}</span>
            `;
            
            document.getElementById('toastContainer').appendChild(toast);
            
            setTimeout(() => {
                toast.style.animation = 'fadeOutRight 0.3s ease forwards';
                setTimeout(() => {
                    toast.remove();
                }, 300);
            }, 3000);
        }
    </script>
</body>
</html>
