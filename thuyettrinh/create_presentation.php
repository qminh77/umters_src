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

// Function to generate a random access code
function generate_access_code($length = 6) {
    $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $code;
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = sanitize_input($_POST['title']);
    $description = sanitize_input($_POST['description']);
    
    if (empty($title)) {
        $message = 'Please enter a title for your presentation.';
    } else {
        // Generate a unique access code
        $access_code = generate_access_code();
        
        // Check if access code already exists
        $stmt = $conn->prepare("SELECT id FROM presentations WHERE access_code = ?");
        $stmt->bind_param("s", $access_code);
        $stmt->execute();
        $result = $stmt->get_result();
        
        // If access code exists, generate a new one
        while ($result->num_rows > 0) {
            $access_code = generate_access_code();
            $stmt->execute();
            $result = $stmt->get_result();
        }
        $stmt->close();
        
        // Insert new presentation
        $stmt = $conn->prepare("INSERT INTO presentations (user_id, title, description, access_code) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isss", $user_id, $title, $description, $access_code);
        
        if ($stmt->execute()) {
            $presentation_id = $conn->insert_id;
            
            // Create a default first slide
            $default_title = "Welcome";
            $default_content = "Welcome to my presentation";
            $slide_order = 1;
            
            $stmt = $conn->prepare("INSERT INTO presentation_slides (presentation_id, slide_order, title, content) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("iiss", $presentation_id, $slide_order, $default_title, $default_content);
            $stmt->execute();
            
            // Generate QR code
            $qr_data = json_encode([
                'type' => 'presentation',
                'access_code' => $access_code
            ]);
            
            // Create directory if it doesn't exist
            $qr_dir = 'qr_codes';
            if (!file_exists($qr_dir)) {
                mkdir($qr_dir, 0755, true);
            }
            
            $qr_filename = 'presentation_' . $presentation_id . '_' . time() . '.png';
            $qr_path = $qr_dir . '/' . $qr_filename;
            
            // Update presentation with QR code path
            $stmt = $conn->prepare("UPDATE presentations SET qr_code = ? WHERE id = ?");
            $stmt->bind_param("si", $qr_path, $presentation_id);
            $stmt->execute();
            
            header("Location: edit_presentation.php?id=" . $presentation_id);
            exit();
        } else {
            $message = 'Error creating presentation: ' . $stmt->error;
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create New Presentation</title>
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
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
        }
        input[type="text"], textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
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
            background-color: #f44336;
        }
        .btn-secondary:hover {
            background-color: #d32f2f;
        }
        .message {
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 4px;
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Create New Presentation</h1>
        
        <?php if (!empty($message)): ?>
            <div class="message"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group">
                <label for="title">Presentation Title</label>
                <input type="text" id="title" name="title" required>
            </div>
            
            <div class="form-group">
                <label for="description">Description (Optional)</label>
                <textarea id="description" name="description"></textarea>
            </div>
            
            <div class="form-group">
                <button type="submit" class="btn"><i class="fas fa-save"></i> Create Presentation</button>
                <a href="presentation.php" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</a>
            </div>
        </form>
    </div>
</body>
</html>
