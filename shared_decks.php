<?php
session_start();
include 'db_config.php';
include 'media_functions.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$error_message = '';
$success_message = '';

// Xử lý chia sẻ/hủy chia sẻ bộ flashcard
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['toggle_share'])) {
    $deck_id = (int)$_POST['deck_id'];
    
    // Kiểm tra quyền sở hữu
    $sql_check = "SELECT * FROM flashcard_decks WHERE id = $deck_id AND user_id = $user_id";
    $result_check = mysqli_query($conn, $sql_check);
    
    if (mysqli_num_rows($result_check) > 0) {
        $deck = mysqli_fetch_assoc($result_check);
        $is_shared = $deck['is_shared'] ? 0 : 1;
        $share_token = $is_shared ? generate_share_token($deck_id) : '';
        
        $sql = "UPDATE flashcard_decks SET is_shared = $is_shared, share_token = '$share_token' WHERE id = $deck_id";
        
        if (mysqli_query($conn, $sql)) {
            if ($is_shared) {
                $success_message = "Bộ flashcard đã được chia sẻ thành công!";
            } else {
                $success_message = "Đã hủy chia sẻ bộ flashcard!";
            }
        } else {
            $error_message = "Lỗi: " . mysqli_error($conn);
        }
    } else {
        $error_message = "Bạn không có quyền thực hiện hành động này!";
    }
}

// Lấy danh sách bộ flashcard của người dùng
$sql_decks = "SELECT d.*, 
              (SELECT COUNT(*) FROM flashcards f WHERE f.deck_id = d.id) as card_count
              FROM flashcard_decks d 
              WHERE d.user_id = $user_id 
              ORDER BY d.created_at DESC";
$result_decks = mysqli_query($conn, $sql_decks);
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chia Sẻ Flashcards</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            /* Màu chính */
            --primary: #7000FF;
            --primary-light: #9D50FF;
            --primary-dark: #4A00B0;
            --secondary: #00E0FF;
            --secondary-light: #70EFFF;
            --secondary-dark: #00B0C7;
            --accent: #FF3DFF;
            --accent-light: #FF7DFF;
            --accent-dark: #C700C7;
            
            /* Màu nền và text */
            --background: #0A0A1A;
            --surface: #12122A;
            --surface-light: #1A1A3A;
            --foreground: #FFFFFF;
            --foreground-muted: rgba(255, 255, 255, 0.7);
            --foreground-subtle: rgba(255, 255, 255, 0.5);
            
            /* Màu card */
            --card: rgba(30, 30, 60, 0.6);
            --card-hover: rgba(40, 40, 80, 0.8);
            --card-active: rgba(50, 50, 100, 0.9);
            
            /* Border và shadow */
            --border: rgba(255, 255, 255, 0.1);
            --border-light: rgba(255, 255, 255, 0.05);
            --shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            --shadow-sm: 0 4px 16px rgba(0, 0, 0, 0.2);
            --shadow-lg: 0 16px 48px rgba(0, 0, 0, 0.4);
            --glow: 0 0 20px rgba(112, 0, 255, 0.5);
            --glow-accent: 0 0 20px rgba(255, 61, 255, 0.5);
            --glow-secondary: 0 0 20px rgba(0, 224, 255, 0.5);
            
            /* Border radius */
            --radius-sm: 0.75rem;
            --radius: 1.5rem;
            --radius-lg: 2rem;
            --radius-xl: 3rem;
            --radius-full: 9999px;
            
            /* Màu trạng thái */
            --error-color: #FF3D57;
            --success-color: #00E0FF;
            --warning-color: #f59e0b;
            --info-color: #3b82f6;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Outfit', sans-serif;
            background-color: var(--background);
            color: var(--foreground);
            min-height: 100vh;
            line-height: 1.6;
            overflow-x: hidden;
            background-image: 
                radial-gradient(circle at 20% 30%, rgba(112, 0, 255, 0.15) 0%, transparent 40%),
                radial-gradient(circle at 80% 70%, rgba(0, 224, 255, 0.15) 0%, transparent 40%),
                radial-gradient(circle at 50% 50%, rgba(255, 61, 255, 0.1) 0%, transparent 60%);
            background-attachment: fixed;
        }

        /* Scrollbar styling */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: var(--surface);
            border-radius: var(--radius-full);
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(to bottom, var(--primary), var(--secondary));
            border-radius: var(--radius-full);
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(to bottom, var(--primary-light), var(--secondary-light));
        }

        /* Main layout */
        .container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1.5rem;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding: 1.5rem 2rem;
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, var(--primary), var(--secondary), var(--accent), var(--primary));
            background-size: 300% 100%;
            animation: gradientBorder 3s linear infinite;
        }

        @keyframes gradientBorder {
            0% { background-position: 0% 0%; }
            100% { background-position: 300% 0%; }
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 1.75rem;
            font-weight: 700;
        }

        .logo i {
            font-size: 2rem;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-menu a {
            color: var(--foreground);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .user-menu a:hover {
            color: var(--secondary);
            transform: translateY(-2px);
        }

        .user-menu .btn {
            padding: 0.75rem 1.25rem;
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            color: white;
            border: none;
            border-radius: var(--radius);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .user-menu .btn:hover {
            background: linear-gradient(90deg, var(--primary-light), var(--primary));
            transform: translateY(-2px);
            box-shadow: var(--glow);
        }

        /* Message styles */
        .message-container {
            margin-bottom: 1.5rem;
        }

        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1rem;
            font-weight: 500;
            position: relative;
            padding-left: 3rem;
            animation: slideIn 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(10px);
        }

        @keyframes slideIn {
            from { transform: translateX(-20px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        .alert-success {
            background: rgba(0, 224, 255, 0.1);
            color: var(--success-color);
            border-left: 4px solid var(--success-color);
        }

        .alert-success::before {
            content: "\f00c";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
        }

        .alert-error {
            background: rgba(255, 61, 87, 0.1);
            color: var(--error-color);
            border-left: 4px solid var(--error-color);
        }

        .alert-error::before {
            content: "\f071";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
        }

        /* Card styles */
        .card {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 2rem;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }

        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                radial-gradient(circle at 20% 30%, rgba(112, 0, 255, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 70%, rgba(0, 224, 255, 0.1) 0%, transparent 50%);
            pointer-events: none;
            border-radius: var(--radius-lg);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .card-title {
            font-size: 1.75rem;
            font-weight: 700;
            background: linear-gradient(to right, var(--secondary), var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .card-title i {
            font-size: 1.5rem;
            color: var(--primary-light);
        }

        .card p {
            color: var(--foreground-muted);
            margin-bottom: 1rem;
        }

        /* Deck list */
        .deck-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .deck-item {
            background: rgba(255, 255, 255, 0.03);
            border-radius: var(--radius);
            padding: 1.5rem;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            border: 1px solid var(--border-light);
        }

        .deck-item:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-sm);
            background: rgba(255, 255, 255, 0.05);
        }

        .deck-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--foreground);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .deck-info {
            color: var(--foreground-subtle);
            font-size: 0.875rem;
            margin-bottom: 1rem;
        }

        .deck-info p {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .deck-actions {
            margin-top: auto;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .share-link {
            padding: 0.75rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            margin-top: 0.5rem;
            position: relative;
            display: flex;
            align-items: center;
        }

        .share-link input {
            width: 100%;
            border: none;
            background: transparent;
            font-size: 0.875rem;
            color: var(--foreground);
            padding-right: 2rem;
            font-family: 'Outfit', sans-serif;
        }

        .share-link input:focus {
            outline: none;
        }

        .copy-btn {
            position: absolute;
            right: 0.5rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--foreground-muted);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .copy-btn:hover {
            color: var(--secondary);
        }

        /* Button styles */
        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: var(--radius);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.22, 1, 0.36, 1);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 1rem;
            border: none;
            color: white;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
        }

        .btn-primary {
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
        }

        .btn-primary:hover {
            background: linear-gradient(90deg, var(--primary-light), var(--primary));
            transform: translateY(-2px);
            box-shadow: var(--glow);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            color: var(--foreground);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .btn-danger {
            background: linear-gradient(90deg, var(--error-color), #dc2626);
        }

        .btn-danger:hover {
            background: linear-gradient(90deg, #dc2626, var(--error-color));
            transform: translateY(-2px);
            box-shadow: var(--glow-accent);
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            background: rgba(255, 255, 255, 0.03);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
        }

        .empty-state i {
            font-size: 3rem;
            color: var(--foreground-subtle);
            margin-bottom: 1rem;
            animation: float 6s ease-in-out infinite;
        }

        .empty-state p {
            font-size: 1.1rem;
            margin-bottom: 1.5rem;
            color: var(--foreground-muted);
        }

        /* Badge */
        .badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            font-weight: 500;
            margin-left: 0.5rem;
            background: rgba(0, 224, 255, 0.2);
            color: var(--success-color);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 0 1rem;
                margin: 1rem auto;
            }

            .page-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
                padding: 1.25rem;
            }

            .deck-list {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .card-title {
                font-size: 1.5rem;
            }

            .deck-title {
                font-size: 1.1rem;
            }

            .btn {
                padding: 0.6rem 1rem;
                font-size: 0.875rem;
            }
        }

        /* Animations */
        @keyframes float {
            0% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
            100% { transform: translateY(0); }
        }

        .floating {
            animation: float 6s ease-in-out infinite;
        }

        /* Particle background */
        .particles {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 0;
        }

        .particle {
            position: absolute;
            border-radius: 50%;
            opacity: 0.3;
            pointer-events: none;
        }
    </style>
</head>
<body>
    <div class="particles" id="particles"></div>

    <div class="container">
        <div class="page-header">
            <div class="logo">
                <i class="fas fa-layer-group"></i>
                <span>FlashMaster</span>
            </div>
            <div class="user-menu">
                <a href="dashboard.php"><i class="fas fa-home"></i> Trang chủ</a>
                <a href="flashcards.php"><i class="fas fa-layer-group"></i> Bộ thẻ</a>
                <a href="logout.php" class="btn"><i class="fas fa-sign-out-alt"></i> Đăng xuất</a>
            </div>
        </div>

        <?php if ($error_message || $success_message): ?>
        <div class="message-container">
            <?php if ($error_message): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>
            <?php if ($success_message): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-share-alt"></i> Chia Sẻ Bộ Flashcard</h2>
                <a href="flashcards.php" class="btn btn-secondary btn-sm">
                    <i class="fas fa-arrow-left"></i> Quay lại
                </a>
            </div>

            <p>Chọn bộ flashcard bạn muốn chia sẻ với người khác:</p>

            <?php if (mysqli_num_rows($result_decks) > 0): ?>
                <div class="deck-list">
                    <?php while ($deck = mysqli_fetch_assoc($result_decks)): ?>
                        <div class="deck-item">
                            <h3 class="deck-title">
                                <?php echo htmlspecialchars($deck['name']); ?>
                                <?php if ($deck['is_shared']): ?>
                                    <span class="badge">Đã chia sẻ</span>
                                <?php endif; ?>
                            </h3>
                            <div class="deck-info">
                                <p><i class="fas fa-layer-group"></i> <?php echo $deck['card_count']; ?> thẻ</p>
                                <p><i class="fas fa-calendar-alt"></i> Tạo: <?php echo date('d/m/Y', strtotime($deck['created_at'])); ?></p>
                            </div>
                            
                            <div class="deck-actions">
                                <?php if ($deck['is_shared']): ?>
                                    <div class="share-link">
                                        <input type="text" value="<?php echo htmlspecialchars('https://' . $_SERVER['HTTP_HOST'] . '/share.php?token=' . $deck['share_token']); ?>" id="share-link-<?php echo $deck['id']; ?>" readonly>
                                        <button class="copy-btn" onclick="copyShareLink(<?php echo $deck['id']; ?>)">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                    </div>
                                    
                                    <form method="POST" action="">
                                        <input type="hidden" name="deck_id" value="<?php echo $deck['id']; ?>">
                                        <button type="submit" name="toggle_share" class="btn btn-danger btn-sm" style="width: 100%;">
                                            <i class="fas fa-ban"></i> Hủy chia sẻ
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" action="">
                                        <input type="hidden" name="deck_id" value="<?php echo $deck['id']; ?>">
                                        <button type="submit" name="toggle_share" class="btn btn-primary btn-sm" style="width: 100%;">
                                            <i class="fas fa-share-alt"></i> Chia sẻ bộ này
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-layer-group floating"></i>
                    <p>Bạn chưa có bộ flashcard nào. Hãy tạo bộ đầu tiên!</p>
                    <a href="flashcards.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Tạo bộ flashcard
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            createParticles();
            animateElements('.deck-item', 100);
            animateElements('.card', 50);
            
            const messages = document.querySelectorAll('.alert');
            messages.forEach(message => {
                setTimeout(() => {
                    message.style.opacity = '0';
                    message.style.transform = 'translateY(-10px)';
                    message.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    setTimeout(() => {
                        message.style.display = 'none';
                    }, 500);
                }, 5000);
            });
        });

        function copyShareLink(deckId) {
            const shareLink = document.getElementById(`share-link-${deckId}`);
            shareLink.select();
            document.execCommand('copy');
            
            const copyBtn = shareLink.nextElementSibling;
            const originalIcon = copyBtn.innerHTML;
            copyBtn.innerHTML = '<i class="fas fa-check"></i>';
            
            setTimeout(() => {
                copyBtn.innerHTML = originalIcon;
            }, 2000);
        }

        function createParticles() {
            const particlesContainer = document.getElementById('particles');
            const particleCount = 50;
            
            for (let i = 0; i < particleCount; i++) {
                const particle = document.createElement('div');
                particle.classList.add('particle');
                
                const size = Math.random() * 5 + 1;
                particle.style.width = `${size}px`;
                particle.style.height = `${size}px`;
                
                const posX = Math.random() * 100;
                const posY = Math.random() * 100;
                particle.style.left = `${posX}%`;
                particle.style.top = `${posY}%`;
                
                const colors = ['#7000FF', '#00E0FF', '#FF3DFF'];
                const color = colors[Math.floor(Math.random() * colors.length)];
                particle.style.backgroundColor = color;
                particle.style.boxShadow = `0 0 ${size * 2}px ${color}`;
                
                const duration = Math.random() * 20 + 10;
                const delay = Math.random() * 10;
                particle.style.animation = `float ${duration}s ease-in-out ${delay}s infinite`;
                
                particlesContainer.appendChild(particle);
            }
        }

        function animateElements(selector, delay = 100) {
            const elements = document.querySelectorAll(selector);
            elements.forEach((el, index) => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    el.style.opacity = '1';
                    el.style.transform = 'translateY(0)';
                }, index * delay);
            });
        }
    </script>
</body>
</html>
<?php mysqli_close($conn); ?>