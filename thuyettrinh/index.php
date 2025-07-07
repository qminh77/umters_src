<?php
session_start();
require_once '../db_config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Function to sanitize input data
function sanitize_input($data) {
    global $conn;
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return mysqli_real_escape_string($conn, $data);
}

// Create tables if they don't exist
$sql_presentations = "CREATE TABLE IF NOT EXISTS presentations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    qr_code VARCHAR(255),
    access_code VARCHAR(10) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if (!$conn->query($sql_presentations)) {
    die("Error creating presentations table: " . $conn->error);
}

$sql_slides = "CREATE TABLE IF NOT EXISTS presentation_slides (
    id INT AUTO_INCREMENT PRIMARY KEY,
    presentation_id INT NOT NULL,
    slide_order INT NOT NULL,
    title VARCHAR(255),
    content TEXT,
    background_color VARCHAR(20) DEFAULT '#FFFFFF',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (presentation_id) REFERENCES presentations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if (!$conn->query($sql_slides)) {
    die("Error creating presentation_slides table: " . $conn->error);
}

$sql_slide_images = "CREATE TABLE IF NOT EXISTS slide_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slide_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    position_x INT DEFAULT 0,
    position_y INT DEFAULT 0,
    width INT DEFAULT 300,
    height INT DEFAULT 200,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (slide_id) REFERENCES presentation_slides(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if (!$conn->query($sql_slide_images)) {
    die("Error creating slide_images table: " . $conn->error);
}

$sql_presentation_sessions = "CREATE TABLE IF NOT EXISTS presentation_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    presentation_id INT NOT NULL,
    current_slide INT DEFAULT 1,
    is_active BOOLEAN DEFAULT TRUE,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ended_at TIMESTAMP NULL,
    FOREIGN KEY (presentation_id) REFERENCES presentations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if (!$conn->query($sql_presentation_sessions)) {
    die("Error creating presentation_sessions table: " . $conn->error);
}

$sql_presentation_viewers = "CREATE TABLE IF NOT EXISTS presentation_viewers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    viewer_ip VARCHAR(45) NOT NULL,
    viewer_agent VARCHAR(255),
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_active TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES presentation_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if (!$conn->query($sql_presentation_viewers)) {
    die("Error creating presentation_viewers table: " . $conn->error);
}

// Get user's presentations
$stmt = $conn->prepare("SELECT id, title, description, created_at FROM presentations WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$presentations = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Presentations</title>
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
        h1 {
            color: #333;
            margin-bottom: 20px;
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
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background-color: #f2f2f2;
            font-weight: 600;
        }
        tr:hover {
            background-color: #f9f9f9;
        }
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        .empty-state i {
            font-size: 48px;
            margin-bottom: 20px;
            color: #ccc;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>My Presentations</h1>
        <a href="create_presentation.php" class="btn"><i class="fas fa-plus"></i> Create New Presentation</a>
        
        <?php if (empty($presentations)): ?>
            <div class="empty-state">
                <i class="fas fa-presentation"></i>
                <p>You haven't created any presentations yet.</p>
                <p>Click the button above to create your first presentation!</p>
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Description</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($presentations as $presentation): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($presentation['title']); ?></td>
                        <td><?php echo htmlspecialchars($presentation['description']); ?></td>
                        <td><?php echo date('M d, Y', strtotime($presentation['created_at'])); ?></td>
                        <td>
                            <a href="edit_presentation.php?id=<?php echo $presentation['id']; ?>" class="btn btn-secondary"><i class="fas fa-edit"></i> Edit</a>
                            <a href="present.php?id=<?php echo $presentation['id']; ?>" class="btn"><i class="fas fa-play"></i> Present</a>
                            <a href="share_presentation.php?id=<?php echo $presentation['id']; ?>" class="btn btn-secondary"><i class="fas fa-share"></i> Share</a>
                            <a href="delete_presentation.php?id=<?php echo $presentation['id']; ?>" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this presentation?');"><i class="fas fa-trash"></i> Delete</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</body>
</html>
