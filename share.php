<?php
session_start();
include 'db_config.php';
include 'media_functions.php';

// Check if share token is provided
if (!isset($_GET['token']) || empty($_GET['token'])) {
    header("Location: index.php");
    exit;
}

$token = mysqli_real_escape_string($conn, $_GET['token']);

// Get deck information from share token
$sql_deck = "SELECT d.*, u.username as owner_name 
             FROM flashcard_decks d 
             JOIN users u ON d.user_id = u.id
             WHERE d.share_token = '$token' AND d.is_shared = 1";
$result_deck = mysqli_query($conn, $sql_deck);

if (mysqli_num_rows($result_deck) == 0) {
    // Invalid or expired share token
    $error_message = "Bộ flashcard này không tồn tại hoặc không được chia sẻ.";
} else {
    $deck = mysqli_fetch_assoc($result_deck);
    $deck_id = $deck['id'];
    
    // Get flashcards in the deck
    $sql_cards = "SELECT * FROM flashcards WHERE deck_id = $deck_id ORDER BY id ASC";
    $result_cards = mysqli_query($conn, $sql_cards);
    $card_count = mysqli_num_rows($result_cards);
    
    // Check if user wants to import this shared deck
    if (isset($_POST['import_deck']) && isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
        
        // Create a copy of the deck for the current user
        $deck_name = mysqli_real_escape_string($conn, $deck['name'] . " (Đã nhập)");
        $deck_description = mysqli_real_escape_string($conn, $deck['description']);
        
        $sql_new_deck = "INSERT INTO flashcard_decks (name, description, user_id, created_at) 
                         VALUES ('$deck_name', '$deck_description', $user_id, NOW())";
        
        if (mysqli_query($conn, $sql_new_deck)) {
            $new_deck_id = mysqli_insert_id($conn);
            
            // Copy all flashcards to the new deck
            mysqli_data_seek($result_cards, 0); // Reset result pointer
            while ($card = mysqli_fetch_assoc($result_cards)) {
                $front = mysqli_real_escape_string($conn, $card['front']);
                $back = mysqli_real_escape_string($conn, $card['back']);
                
                $sql_new_card = "INSERT INTO flashcards (deck_id, front, back, created_at) 
                                VALUES ($new_deck_id, '$front', '$back', NOW())";
                
                if (mysqli_query($conn, $sql_new_card)) {
                    $card_id = mysqli_insert_id($conn);
                    
                    // Add to learning progress
                    $sql_progress = "INSERT INTO flashcard_progress (user_id, flashcard_id, deck_id, status, next_review_date) 
                                    VALUES ($user_id, $card_id, $new_deck_id, 'new', CURDATE())";
                    mysqli_query($conn, $sql_progress);
                }
            }
            
            $success_message = "Bộ flashcard đã được nhập thành công vào tài khoản của bạn!";
        } else {
            $error_message = "Lỗi: " . mysqli_error($conn);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bộ Flashcard Được Chia Sẻ</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            /* Gradient và màu chính */
            --primary-gradient-start: #74ebd5;
            --primary-gradient-end: #acb6e5;
            --secondary-gradient-start: #acb6e5;
            --secondary-gradient-end: #74ebd5;
            --background-gradient: linear-gradient(135deg, #f5f7fa, #e4e9f2);
            
            /* Màu nền */
            --container-bg: rgba(255, 255, 255, 0.98);
            --card-bg: #ffffff;
            --form-bg: #f8fafc;
            --hover-bg: #f1f5f9;
            
            /* Màu chữ */
            --text-color: #334155;
            --text-secondary: #64748b;
            --link-color: #38bdf8;
            --link-hover-color: #0ea5e9;
            
            /* Màu trạng thái */
            --error-color: #ef4444;
            --success-color: #22c55e;
            --warning-color: #f59e0b;
            --info-color: #3b82f6;
            
            /* Hiệu ứng và bo góc */
            --shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.05);
            --shadow-hover: 0 15px 35px rgba(0, 0, 0, 0.15);
            --border-radius: 1rem;
            --small-radius: 0.75rem;
            --button-radius: 1.5rem;
            
            /* Khoảng cách */
            --padding: 2rem;
            --small-padding: 1rem;
            
            /* Hiệu ứng */
            --transition-speed: 0.3s;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            min-height: 100vh;
            background: var(--background-gradient);
            color: var(--text-color);
            line-height: 1.6;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-color);
        }

        .logo i {
            font-size: 1.8rem;
            background: linear-gradient(135deg, var(--primary-gradient-start), var(--primary-gradient-end));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-menu a {
            color: var(--text-color);
            text-decoration: none;
            font-weight: 500;
            transition: color var(--transition-speed) ease;
        }

        .user-menu a:hover {
            color: var(--link-color);
        }

        .user-menu .btn {
            padding: 0.5rem 1rem;
            background: linear-gradient(90deg, var(--primary-gradient-start), var(--primary-gradient-end));
            color: white;
            border: none;
            border-radius: var(--button-radius);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .user-menu .btn:hover {
            background: linear-gradient(90deg, var(--secondary-gradient-start), var(--secondary-gradient-end));
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(116, 235, 213, 0.3);
        }

        .alert {
            padding: 1rem;
            border-radius: var(--small-radius);
            margin-bottom: 1.5rem;
            font-weight: 500;
        }

        .alert-success {
            background-color: rgba(34, 197, 94, 0.1);
            color: var(--success-color);
            border: 1px solid rgba(34, 197, 94, 0.2);
        }

        .alert-error {
            background-color: rgba(239, 68, 68, 0.1);
            color: var(--error-color);
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        .card {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-sm);
            padding: var(--padding);
            margin-bottom: 2rem;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .card-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-title i {
            color: var(--primary-gradient-start);
        }

        .deck-info {
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
        }

        .deck-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-top: 0.5rem;
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .deck-meta span {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .flashcard-list {
            margin-top: 1.5rem;
        }

        .flashcard-item {
            background: var(--form-bg);
            border-radius: var(--small-radius);
            padding: 1.5rem;
            margin-bottom: 1rem;
            transition: transform var(--transition-speed) ease, box-shadow var(--transition-speed) ease;
        }

        .flashcard-item:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-sm);
        }

        .flashcard-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        .flashcard-side {
            background: white;
            border-radius: var(--small-radius);
            padding: 1rem;
            border: 1px solid rgba(0, 0, 0, 0.1);
            min-height: 100px;
        }

        .flashcard-side h4 {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: var(--button-radius);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 1rem;
        }

        .btn-primary {
            background: linear-gradient(90deg, var(--primary-gradient-start), var(--primary-gradient-end));
            color: white;
            border: none;
        }

        .btn-primary:hover {
            background: linear-gradient(90deg, var(--secondary-gradient-start), var(--secondary-gradient-end));
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(116, 235, 213, 0.3);
        }

        .btn-secondary {
            background: var(--form-bg);
            color: var(--text-color);
            border: 1px solid rgba(0, 0, 0, 0.1);
        }

        .btn-secondary:hover {
            background: var(--hover-bg);
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
        }

        .form-actions {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-top: 2rem;
        }

        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--text-secondary);
        }

        .empty-state i {
            font-size: 3rem;
            color: var(--text-secondary);
            opacity: 0.5;
            margin-bottom: 1rem;
        }

        .empty-state p {
            font-size: 1.1rem;
            margin-bottom: 1.5rem;
        }

        /* Media styles */
        .flashcard-media {
            margin: 1rem 0;
            border-radius: var(--small-radius);
            overflow: hidden;
        }

        .flashcard-image img {
            max-width: 100%;
            height: auto;
            display: block;
        }

        .flashcard-video video {
            width: 100%;
            max-height: 300px;
        }

        .flashcard-youtube iframe {
            width: 100%;
            height: 250px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }

            .logo {
                justify-content: center;
            }

            .user-menu {
                justify-content: center;
                flex-wrap: wrap;
            }

            .flashcard-content {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
            }

            .form-actions .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div class="logo">
                <i class="fas fa-layer-group"></i>
                <span>FlashMaster</span>
            </div>
            <div class="user-menu">
                <?php if (isset($_SESSION['user_id'])): ?>
                    <a href="dashboard.php"><i class="fas fa-home"></i> Trang chủ</a>
                    <a href="flashcards.php"><i class="fas fa-layer-group"></i> Bộ thẻ</a>
                    <a href="logout.php" class="btn"><i class="fas fa-sign-out-alt"></i> Đăng xuất</a>
                <?php else: ?>
                    <a href="index.php"><i class="fas fa-home"></i> Trang chủ</a>
                    <a href="login.php" class="btn"><i class="fas fa-sign-in-alt"></i> Đăng nhập</a>
                <?php endif; ?>
            </div>
        </header>

        <?php if (isset($error_message)): ?>
            <div class="alert alert-error"><?php echo $error_message; ?></div>
        <?php endif; ?>
        <?php if (isset($success_message)): ?>
            <div class="alert alert-success"><?php echo $success_message; ?></div>
        <?php endif; ?>

        <div class="card">
            <?php if (isset($deck)): ?>
                <div class="card-header">
                    <h2 class="card-title"><i class="fas fa-share-alt"></i> Bộ Flashcard Được Chia Sẻ</h2>
                </div>

                <div class="deck-info">
                    <h3><?php echo htmlspecialchars($deck['name']); ?></h3>
                    <div class="deck-meta">
                        <span><i class="fas fa-user"></i> Chia sẻ bởi: <?php echo htmlspecialchars($deck['owner_name']); ?></span>
                        <span><i class="fas fa-layer-group"></i> <?php echo $card_count; ?> thẻ</span>
                        <span><i class="fas fa-calendar-alt"></i> Tạo: <?php echo date('d/m/Y', strtotime($deck['created_at'])); ?></span>
                    </div>
                    <p class="mt-2"><?php echo nl2br(htmlspecialchars($deck['description'])); ?></p>
                </div>

                <?php if (mysqli_num_rows($result_cards) > 0): ?>
                    <div class="flashcard-list">
                        <h3 class="mb-3">Xem trước các thẻ:</h3>
                        
                        <?php 
                        $preview_limit = 5; // Limit preview to 5 cards
                        $count = 0;
                        while ($card = mysqli_fetch_assoc($result_cards)): 
                            if ($count >= $preview_limit) break;
                            $count++;
                            
                            // Process media content
                            $front_content = process_media_content($card['front']);
                            $back_content = process_media_content($card['back']);
                        ?>
                            <div class="flashcard-item">
                                <div class="flashcard-content">
                                    <div class="flashcard-side">
                                        <h4>Mặt trước</h4>
                                        <div><?php echo $front_content; ?></div>
                                    </div>
                                    <div class="flashcard-side">
                                        <h4>Mặt sau</h4>
                                        <div><?php echo $back_content; ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                        
                        <?php if ($card_count > $preview_limit): ?>
                            <div class="text-center mt-4">
                                <p>Còn <?php echo $card_count - $preview_limit; ?> thẻ khác. Nhập bộ thẻ này để xem tất cả.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (isset($_SESSION['user_id'])): ?>
                        <form method="POST" action="">
                            <div class="form-actions">
                                <button type="submit" name="import_deck" class="btn btn-primary">
                                    <i class="fas fa-download"></i> Nhập vào bộ sưu tập của tôi
                                </button>
                                <a href="flashcards.php" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left"></i> Quay lại
                                </a>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="form-actions">
                            <a href="login.php" class="btn btn-primary">
                                <i class="fas fa-sign-in-alt"></i> Đăng nhập để nhập bộ thẻ này
                            </a>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-layer-group"></i>
                        <p>Bộ flashcard này không có thẻ nào.</p>
                        <a href="flashcards.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Quay lại
                        </a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Initialize video players if needed
        document.addEventListener('DOMContentLoaded', function() {
            const videos = document.querySelectorAll('video');
            videos.forEach(video => {
                video.controls = true;
            });
        });
    </script>
</body>
</html>
<?php mysqli_close($conn); ?>
