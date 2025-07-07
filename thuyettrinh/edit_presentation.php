<?php
session_start();
require_once '../db_config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$message = '';

// Function to sanitize input data
function sanitize_input($data) {
    global $conn;
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return mysqli_real_escape_string($conn, $data);
}

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

// Process form submission for updating presentation details
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_presentation'])) {
    $title = sanitize_input($_POST['title']);
    $description = sanitize_input($_POST['description']);
    
    if (empty($title)) {
        $message = 'Please enter a title for your presentation.';
    } else {
        $stmt = $conn->prepare("UPDATE presentations SET title = ?, description = ? WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ssii", $title, $description, $presentation_id, $user_id);
        
        if ($stmt->execute()) {
            $message = 'Presentation updated successfully.';
            // Refresh presentation data
            $presentation['title'] = $title;
            $presentation['description'] = $description;
        } else {
            $message = 'Error updating presentation: ' . $stmt->error;
        }
        $stmt->close();
    }
}

// Process form submission for adding a new slide
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_slide'])) {
    // Get the highest slide order
    $stmt = $conn->prepare("SELECT MAX(slide_order) as max_order FROM presentation_slides WHERE presentation_id = ?");
    $stmt->bind_param("i", $presentation_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $new_order = ($row['max_order'] ?? 0) + 1;
    $stmt->close();
    
    // Default values for new slide
    $slide_title = "New Slide";
    $slide_content = "Add your content here";
    
    $stmt = $conn->prepare("INSERT INTO presentation_slides (presentation_id, slide_order, title, content) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iiss", $presentation_id, $new_order, $slide_title, $slide_content);
    
    if ($stmt->execute()) {
        // Refresh the page to show the new slide
        header("Location: edit_presentation.php?id=" . $presentation_id);
        exit();
    } else {
        $message = 'Error adding slide: ' . $stmt->error;
    }
    $stmt->close();
}

// Process form submission for updating a slide
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_slide'])) {
    $slide_id = (int)$_POST['slide_id'];
    $slide_title = sanitize_input($_POST['slide_title']);
    $slide_content = sanitize_input($_POST['slide_content']);
    $background_color = sanitize_input($_POST['background_color']);
    
    // Verify that the slide belongs to this presentation
    $stmt = $conn->prepare("SELECT id FROM presentation_slides WHERE id = ? AND presentation_id = ?");
    $stmt->bind_param("ii", $slide_id, $presentation_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $stmt->close();
        
        $stmt = $conn->prepare("UPDATE presentation_slides SET title = ?, content = ?, background_color = ? WHERE id = ?");
        $stmt->bind_param("sssi", $slide_title, $slide_content, $background_color, $slide_id);
        
        if ($stmt->execute()) {
            $message = 'Slide updated successfully.';
            
            // Update the slide in the array
            foreach ($slides as &$slide) {
                if ($slide['id'] == $slide_id) {
                    $slide['title'] = $slide_title;
                    $slide['content'] = $slide_content;
                    $slide['background_color'] = $background_color;
                    break;
                }
            }
        } else {
            $message = 'Error updating slide: ' . $stmt->error;
        }
        $stmt->close();
    } else {
        $message = 'Invalid slide ID.';
        $stmt->close();
    }
}

// Process form submission for deleting a slide
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_slide'])) {
    $slide_id = (int)$_POST['slide_id'];
    
    // Verify that the slide belongs to this presentation
    $stmt = $conn->prepare("SELECT slide_order FROM presentation_slides WHERE id = ? AND presentation_id = ?");
    $stmt->bind_param("ii", $slide_id, $presentation_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $deleted_slide = $result->fetch_assoc();
        $deleted_order = $deleted_slide['slide_order'];
        $stmt->close();
        
        // Start a transaction
        $conn->begin_transaction();
        
        try {
            // Delete the slide
            $stmt = $conn->prepare("DELETE FROM presentation_slides WHERE id = ?");
            $stmt->bind_param("i", $slide_id);
            $stmt->execute();
            $stmt->close();
            
            // Reorder the remaining slides
            $stmt = $conn->prepare("UPDATE presentation_slides SET slide_order = slide_order - 1 WHERE presentation_id = ? AND slide_order > ?");
            $stmt->bind_param("ii", $presentation_id, $deleted_order);
            $stmt->execute();
            $stmt->close();
            
            $conn->commit();
            
            // Refresh the page to show updated slides
            header("Location: edit_presentation.php?id=" . $presentation_id);
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $message = 'Error deleting slide: ' . $e->getMessage();
        }
    } else {
        $message = 'Invalid slide ID.';
        $stmt->close();
    }
}

// Process form submission for uploading an image to a slide
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_image'])) {
    $slide_id = (int)$_POST['slide_id'];
    
    // Verify that the slide belongs to this presentation
    $stmt = $conn->prepare("SELECT id FROM presentation_slides WHERE id = ? AND presentation_id = ?");
    $stmt->bind_param("ii", $slide_id, $presentation_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $stmt->close();
        
        // Check if file was uploaded without errors
        if (isset($_FILES['slide_image']) && $_FILES['slide_image']['error'] == 0) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];
            $filename = $_FILES['slide_image']['name'];
            $filetype = pathinfo($filename, PATHINFO_EXTENSION);
            
            // Verify file extension
            if (in_array(strtolower($filetype), $allowed)) {
                // Create upload directory if it doesn't exist
                $upload_dir = 'slide_images';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                // Create a unique filename
                $new_filename = 'slide_' . $slide_id . '_' . time() . '.' . $filetype;
                $upload_path = $upload_dir . '/' . $new_filename;
                
                // Move the uploaded file
                if (move_uploaded_file($_FILES['slide_image']['tmp_name'], $upload_path)) {
                    // Save image information to database
                    $stmt = $conn->prepare("INSERT INTO slide_images (slide_id, image_path) VALUES (?, ?)");
                    $stmt->bind_param("is", $slide_id, $upload_path);
                    
                    if ($stmt->execute()) {
                        $message = 'Image uploaded successfully.';
                    } else {
                        $message = 'Error saving image information: ' . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $message = 'Error uploading file.';
                }
            } else {
                $message = 'Invalid file type. Only JPG, JPEG, PNG and GIF files are allowed.';
            }
        } else {
            $message = 'Please select an image to upload.';
        }
    } else {
        $message = 'Invalid slide ID.';
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Presentation</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1, h2 {
            color: #333;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
        }
        input[type="text"], textarea, input[type="color"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
        }
        input[type="color"] {
            height: 40px;
            padding: 2px;
        }
        textarea {
            height: 100px;
            resize: vertical;
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
        .btn-danger {
            background-color: #f44336;
        }
        .btn-danger:hover {
            background-color: #d32f2f;
        }
        .message {
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .message-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .message-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .slides-container {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-top: 20px;
        }
        .slide-card {
            width: calc(33.333% - 20px);
            border: 1px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
        }
        .slide-card:hover {
            transform: translateY(-5px);
        }
        .slide-header {
            padding: 10px;
            background-color: #f2f2f2;
            border-bottom: 1px solid #ddd;
        }
        .slide-content {
            padding: 15px;
            min-height: 100px;
        }
        .slide-actions {
            padding: 10px;
            border-top: 1px solid #ddd;
            display: flex;
            justify-content: space-between;
        }
        .slide-preview {
            margin-bottom: 10px;
            height: 150px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            background-size: cover;
            background-position: center;
        }
        .tabs {
            display: flex;
            border-bottom: 1px solid #ddd;
            margin-bottom: 20px;
        }
        .tab {
            padding: 10px 20px;
            cursor: pointer;
            border: 1px solid transparent;
        }
        .tab.active {
            border: 1px solid #ddd;
            border-bottom-color: #fff;
            border-radius: 4px 4px 0 0;
            margin-bottom: -1px;
            font-weight: bold;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Edit Presentation: <?php echo htmlspecialchars($presentation['title']); ?></h1>
        
        <?php if (!empty($message)): ?>
            <div class="message <?php echo strpos($message, 'Error') !== false ? 'message-error' : 'message-success'; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <div class="tabs">
            <div class="tab active" data-tab="details">Presentation Details</div>
            <div class="tab" data-tab="slides">Slides (<?php echo count($slides); ?>)</div>
        </div>
        
        <div id="details" class="tab-content active">
            <form method="POST" action="">
                <div class="form-group">
                    <label for="title">Presentation Title</label>
                    <input type="text" id="title" name="title" value="<?php echo htmlspecialchars($presentation['title']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description"><?php echo htmlspecialchars($presentation['description']); ?></textarea>
                </div>
                
                <div class="form-group">
                    <label>Access Code</label>
                    <input type="text" value="<?php echo htmlspecialchars($presentation['access_code']); ?>" readonly>
                    <p><small>Share this code with viewers to access your presentation.</small></p>
                </div>
                
                <div class="form-group">
                    <button type="submit" name="update_presentation" class="btn"><i class="fas fa-save"></i> Update Details</button>
                    <a href="presentation.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to Presentations</a>
                </div>
            </form>
        </div>
        
        <div id="slides" class="tab-content">
            <form method="POST" action="">
                <button type="submit" name="add_slide" class="btn"><i class="fas fa-plus"></i> Add New Slide</button>
            </form>
            
            <div class="slides-container">
                <?php foreach ($slides as $slide): ?>
                    <div class="slide-card">
                        <div class="slide-header">
                            <strong>Slide <?php echo $slide['slide_order']; ?>: <?php echo htmlspecialchars($slide['title']); ?></strong>
                        </div>
                        <div class="slide-preview" style="background-color: <?php echo htmlspecialchars($slide['background_color']); ?>">
                            <?php
                            // Get images for this slide
                            $stmt = $conn->prepare("SELECT * FROM slide_images WHERE slide_id = ? LIMIT 1");
                            $stmt->bind_param("i", $slide['id']);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            if ($result->num_rows > 0) {
                                $image = $result->fetch_assoc();
                                echo '<img src="' . htmlspecialchars($image['image_path']) . '" alt="Slide Image" style="max-width: 100%; max-height: 100%;">';
                            } else {
                                echo htmlspecialchars(substr($slide['content'], 0, 50)) . (strlen($slide['content']) > 50 ? '...' : '');
                            }
                            $stmt->close();
                            ?>
                        </div>
                        <div class="slide-content">
                            <form method="POST" action="">
                                <input type="hidden" name="slide_id" value="<?php echo $slide['id']; ?>">
                                
                                <div class="form-group">
                                    <label for="slide_title_<?php echo $slide['id']; ?>">Title</label>
                                    <input type="text" id="slide_title_<?php echo $slide['id']; ?>" name="slide_title" value="<?php echo htmlspecialchars($slide['title']); ?>">
                                </div>
                                
                                <div class="form-group">
                                    <label for="slide_content_<?php echo $slide['id']; ?>">Content</label>
                                    <textarea id="slide_content_<?php echo $slide['id']; ?>" name="slide_content"><?php echo htmlspecialchars($slide['content']); ?></textarea>
                                </div>
                                
                                <div class="form-group">
                                    <label for="background_color_<?php echo $slide['id']; ?>">Background Color</label>
                                    <input type="color" id="background_color_<?php echo $slide['id']; ?>" name="background_color" value="<?php echo htmlspecialchars($slide['background_color']); ?>">
                                </div>
                                
                                <div class="slide-actions">
                                    <button type="submit" name="update_slide" class="btn btn-secondary"><i class="fas fa-save"></i> Update</button>
                                    <?php if (count($slides) > 1): ?>
                                        <button type="submit" name="delete_slide" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this slide?');"><i class="fas fa-trash"></i> Delete</button>
                                    <?php endif; ?>
                                </div>
                            </form>
                            
                            <form method="POST" action="" enctype="multipart/form-data">
                                <input type="hidden" name="slide_id" value="<?php echo $slide['id']; ?>">
                                <div class="form-group">
                                    <label for="slide_image_<?php echo $slide['id']; ?>">Add Image</label>
                                    <input type="file" id="slide_image_<?php echo $slide['id']; ?>" name="slide_image" accept="image/*">
                                </div>
                                <button type="submit" name="upload_image" class="btn btn-secondary"><i class="fas fa-upload"></i> Upload Image</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <script>
        // Tab functionality
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', function() {
                // Remove active class from all tabs
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                
                // Add active class to clicked tab
                this.classList.add('active');
                document.getElementById(this.getAttribute('data-tab')).classList.add('active');
            });
        });
    </script>
</body>
</html>
