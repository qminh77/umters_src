<?php
/**
 * Helper functions for handling media in flashcards
 */

/**
 * Process content and convert media tags to HTML
 * 
 * @param string $content The content to process
 * @return string The processed content with media tags converted to HTML
 */
function process_media_content($content) {
    // Process image tags: [img:URL]
    $content = preg_replace_callback('/\[img:(.*?)\]/', function($matches) {
        $url = trim($matches[1]);
        // Validate URL
        if (filter_var($url, FILTER_VALIDATE_URL) || file_exists($url)) {
            return '<div class="flashcard-media flashcard-image">
                <img src="' . htmlspecialchars($url) . '" alt="Flashcard Image" class="img-fluid" loading="lazy" onload="this.parentNode.classList.add(\'loaded\')">
                <div class="media-loading"><i class="fas fa-spinner fa-spin"></i></div>
            </div>';
        }
        return $matches[0]; // Return original if invalid
    }, $content);
    
    // Process video tags: [video:URL]
    $content = preg_replace_callback('/\[video:(.*?)\]/', function($matches) {
        $url = trim($matches[1]);
        // Validate URL
        if (filter_var($url, FILTER_VALIDATE_URL) || file_exists($url)) {
            return '<div class="flashcard-media flashcard-video">
                <video controls class="video-player" preload="metadata" onloadstart="this.parentNode.classList.add(\'loading\')" oncanplay="this.parentNode.classList.add(\'loaded\')">
                    <source src="' . htmlspecialchars($url) . '" type="video/mp4">
                    Your browser does not support the video tag.
                </video>
                <div class="media-loading"><i class="fas fa-spinner fa-spin"></i></div>
            </div>';
        }
        return $matches[0]; // Return original if invalid
    }, $content);
    
    // Process YouTube tags: [youtube:VIDEO_ID]
    $content = preg_replace_callback('/\[youtube:(.*?)\]/', function($matches) {
        $video_id = trim($matches[1]);
        
        // Extract video ID if full URL was provided
        if (preg_match('/^https?:\/\/(www\.)?(youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $video_id, $id_matches)) {
            $video_id = $id_matches[3];
        }
        
        // Validate YouTube video ID (should be 11 characters)
        if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $video_id)) {
            return '<div class="flashcard-media flashcard-youtube">
                <div class="media-loading"><i class="fas fa-spinner fa-spin"></i></div>
                <iframe width="100%" height="200" src="https://www.youtube.com/embed/' . $video_id . '" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen onload="this.parentNode.classList.add(\'loaded\')"></iframe>
            </div>';
        }
        return $matches[0]; // Return original if invalid
    }, $content);
    
    // Add text-to-speech button for content
    $textContent = strip_tags($content);
    if (!empty($textContent)) {
        $content .= '<div class="tts-container"><button type="button" class="tts-button" onclick="speakText(this)" data-text="' . htmlspecialchars($textContent) . '"><i class="fas fa-volume-up"></i> Đọc</button></div>';
    }
    
    return $content;
}

/**
 * Generate a unique share token for a deck
 * 
 * @param int $deck_id The deck ID
 * @return string The generated share token
 */
function generate_share_token($deck_id) {
    // Generate a random token
    $token = bin2hex(random_bytes(16));
    return $token;
}

/**
 * Upload an image file and return the URL
 * 
 * @param array $file The uploaded file ($_FILES array element)
 * @param string $upload_dir The directory to upload to
 * @return string|false The URL of the uploaded file or false on failure
 */
function upload_media_file($file, $upload_dir = 'uploads/media/') {
    // Create directory if it doesn't exist
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    // Generate unique filename
    $filename = uniqid() . '_' . basename($file['name']);
    $target_file = $upload_dir . $filename;
    
    // Check file type
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'video/mp4', 'video/webm'];
    if (!in_array($file['type'], $allowed_types)) {
        return false;
    }
    
    // Upload file
    if (move_uploaded_file($file['tmp_name'], $target_file)) {
        return $target_file;
    }
    
    return false;
}

