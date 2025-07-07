<?php
session_start();
include 'db_config.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$error_message = '';
$success_message = '';

// Cải thiện xuất file CSV với UTF-8
if (isset($_GET['deck_id']) && is_numeric($_GET['deck_id'])) {
    $deck_id = (int)$_GET['deck_id'];
    
    // Kiểm tra quyền sở hữu bộ flashcard
    $sql_check = "SELECT * FROM flashcard_decks WHERE id = $deck_id AND user_id = $user_id";
    $result_check = mysqli_query($conn, $sql_check);
    
    if (mysqli_num_rows($result_check) > 0) {
        $deck = mysqli_fetch_assoc($result_check);
        
        // Lấy danh sách flashcards
        $sql_cards = "SELECT * FROM flashcards WHERE deck_id = $deck_id ORDER BY id ASC";
        $result_cards = mysqli_query($conn, $sql_cards);
        
        if (mysqli_num_rows($result_cards) > 0) {
            // Thiết lập header cho file CSV
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $deck['name'] . '_flashcards.csv"');
            
            // Tạo file CSV
            $output = fopen('php://output', 'w');
            
            // Thêm BOM để hỗ trợ UTF-8
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // Thêm tiêu đề
            fputcsv($output, ['Mặt trước', 'Mặt sau']);
            
            // Thêm dữ liệu
            while ($card = mysqli_fetch_assoc($result_cards)) {
                fputcsv($output, [$card['front'], $card['back']]);
            }
            
            fclose($output);
            exit;
        } else {
            $error_message = "Bộ flashcard này không có thẻ nào!";
        }
    } else {
        $error_message = "Bạn không có quyền xuất bộ flashcard này!";
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
    <title>Xuất Flashcards - Quản Lý</title>
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
            --success-color: #22c55e;
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

        /* Main container */
        .container {
            max-width: 1000px;
            margin: 2rem auto;
            padding: 0 1.5rem;
        }

        /* Page header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding: 1.5rem 2rem;
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
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

        .page-title {
            font-size: 1.75rem;
            font-weight: 700;
            background: linear-gradient(to right, var(--secondary), var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .page-title i {
            font-size: 1.5rem;
            color: var(--primary-light);
            background: linear-gradient(to right, var(--primary-light), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .back-links {
            display: flex;
            gap: 1rem;
        }

        .back-to-dashboard,
        .back-to-flashcards {
            padding: 0.75rem 1.25rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            color: var(--foreground);
            font-size: 0.875rem;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .back-to-dashboard:hover,
        .back-to-flashcards:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }
        
        /* Message alerts */
        .message-container {
            margin-bottom: 1.5rem;
        }

        .error-message, 
        .success-message {
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1rem;
            font-weight: 500;
            position: relative;
            padding-left: 3rem;
            animation: slideIn 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }

        @keyframes slideIn {
            from { transform: translateX(-20px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        .error-message {
            background: rgba(255, 61, 87, 0.1);
            color: var(--error-color);
            border-left: 4px solid var(--error-color);
        }

        .error-message::before {
            content: "\f071";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
        }

        .success-message {
            background: rgba(0, 224, 255, 0.1);
            color: var(--secondary);
            border-left: 4px solid var(--secondary);
        }

        .success-message::before {
            content: "\f00c";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
        }

        /* Content */
        .content-section {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            position: relative;
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .content-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                radial-gradient(circle at 80% 20%, rgba(0, 224, 255, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 20% 80%, rgba(255, 61, 255, 0.1) 0%, transparent 50%);
            pointer-events: none;
            border-radius: var(--radius-lg);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            position: relative;
            z-index: 1;
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            position: relative;
            padding-bottom: 0.75rem;
        }

        .section-title::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 50px;
            height: 3px;
            background: linear-gradient(to right, var(--primary), var(--secondary));
            border-radius: var(--radius-full);
        }

        .section-title i {
            color: var(--primary-light);
            background: linear-gradient(to right, var(--primary-light), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .section-desc {
            margin-bottom: 1.5rem;
            color: var(--foreground-muted);
            position: relative;
            z-index: 1;
            font-size: 1rem;
        }

        /* Deck grid */
        .deck-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1.25rem;
            position: relative;
            z-index: 1;
        }

        .deck-item {
            background: var(--card);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            overflow: hidden;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            position: relative;
        }

        .deck-item:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow);
            border-color: var(--border);
            background: var(--card-hover);
        }

        .deck-header {
            padding: 1.25rem;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            position: relative;
            overflow: hidden;
        }

        .deck-header::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                radial-gradient(circle at top right, rgba(255, 255, 255, 0.2), transparent 70%),
                linear-gradient(to bottom, rgba(255, 255, 255, 0.1), transparent);
            pointer-events: none;
        }

        .deck-title {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: white;
            position: relative;
            z-index: 1;
        }

        .deck-body {
            padding: 1.25rem;
            flex: 1;
        }

        .deck-info {
            color: var(--foreground-muted);
            font-size: 0.9rem;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .deck-info p {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .deck-info i {
            color: var(--secondary);
            font-size: 0.95rem;
            width: 20px;
            text-align: center;
        }

        .deck-actions {
            padding: 1rem;
            background: rgba(0, 0, 0, 0.2);
            border-top: 1px solid var(--border);
        }

        /* Buttons */
        .btn {
            padding: 0.875rem 1.5rem;
            border-radius: var(--radius-sm);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            border: none;
            font-size: 0.95rem;
            position: relative;
            overflow: hidden;
            text-decoration: none;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.3) 0%, rgba(255,255,255,0) 70%);
            opacity: 0;
            transform: scale(0.5);
            transition: opacity 0.5s ease, transform 0.5s ease;
        }

        .btn:hover::before {
            opacity: 1;
            transform: scale(1);
        }

        .btn-primary {
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            color: white;
            box-shadow: 0 4px 12px rgba(112, 0, 255, 0.3);
        }

        .btn-primary:hover {
            background: linear-gradient(90deg, var(--primary-light), var(--primary));
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(112, 0, 255, 0.4);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.05);
            color: var(--foreground);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .btn-sm {
            padding: 0.75rem 1rem;
            font-size: 0.85rem;
        }

        .btn[disabled] {
            opacity: 0.7;
            cursor: not-allowed;
        }

        .btn[disabled]:hover {
            transform: none;
            box-shadow: none;
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
            color: var(--foreground-muted);
            position: relative;
            z-index: 1;
        }

        .empty-state i {
            font-size: 3.5rem;
            margin-bottom: 1.25rem;
            background: linear-gradient(135deg, var(--primary-light), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            opacity: 0.8;
        }

        .empty-state p {
            font-size: 1.1rem;
            margin-bottom: 1.5rem;
        }

        /* Particles */
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

        /* Animations */
        @keyframes float {
            0% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
            100% { transform: translateY(0); }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
                margin: 1rem auto;
            }

            .page-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
                padding: 1.25rem;
            }
            
            .back-links {
                width: 100%;
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .back-to-dashboard,
            .back-to-flashcards {
                width: 100%;
                justify-content: center;
            }
            
            .deck-list {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .page-title {
                font-size: 1.5rem;
            }
            
            .section-title {
                font-size: 1.25rem;
            }
        }
    </style>
</head>
<body>
    <div class="particles" id="particles"></div>
    
    <div class="container">
        <div class="page-header">
            <h1 class="page-title"><i class="fas fa-file-export"></i> Xuất Flashcards</h1>
            <div class="back-links">
                <a href="flashcards.php" class="back-to-flashcards">
                    <i class="fas fa-layer-group"></i> Quay lại Flashcards
                </a>
                <a href="dashboard.php" class="back-to-dashboard">
                    <i class="fas fa-home"></i> Trang chủ
                </a>
            </div>
        </div>

        <?php if ($error_message || $success_message): ?>
        <div class="message-container">
            <?php if ($error_message): ?>
                <div class="error-message"><?php echo $error_message; ?></div>
            <?php endif; ?>
            <?php if ($success_message): ?>
                <div class="success-message"><?php echo $success_message; ?></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title"><i class="fas fa-file-export"></i> Xuất Flashcards ra CSV</h2>
            </div>
            
            <p class="section-desc">Chọn bộ flashcard bạn muốn xuất ra file CSV để sao lưu hoặc chia sẻ:</p>

            <?php if (mysqli_num_rows($result_decks) > 0): ?>
                <div class="deck-list">
                    <?php while ($deck = mysqli_fetch_assoc($result_decks)): ?>
                        <div class="deck-item">
                            <div class="deck-header">
                                <h3 class="deck-title"><?php echo htmlspecialchars($deck['name']); ?></h3>
                            </div>
                            <div class="deck-body">
                                <div class="deck-info">
                                    <p><i class="fas fa-layer-group"></i> <?php echo $deck['card_count']; ?> thẻ</p>
                                    <p><i class="fas fa-calendar-alt"></i> Tạo: <?php echo date('d/m/Y', strtotime($deck['created_at'])); ?></p>
                                    <?php if (isset($deck['description']) && !empty($deck['description'])): ?>
                                        <p><i class="fas fa-info-circle"></i> <?php echo htmlspecialchars(substr($deck['description'], 0, 70)); ?><?php echo strlen($deck['description']) > 70 ? '...' : ''; ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="deck-actions">
                                <?php if ($deck['card_count'] > 0): ?>
                                    <a href="export.php?deck_id=<?php echo $deck['id']; ?>" class="btn btn-primary">
                                        <i class="fas fa-download"></i> Xuất CSV
                                    </a>
                                <?php else: ?>
                                    <button class="btn btn-secondary" disabled>
                                        <i class="fas fa-exclamation-circle"></i> Không có thẻ
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-layer-group"></i>
                    <p>Bạn chưa có bộ flashcard nào. Hãy tạo bộ đầu tiên!</p>
                    <a href="flashcards.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Tạo bộ flashcard
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Tạo hiệu ứng particle
        function createParticles() {
            const particlesContainer = document.getElementById('particles');
            const particleCount = 50;
            
            for (let i = 0; i < particleCount; i++) {
                const particle = document.createElement('div');
                particle.classList.add('particle');
                
                // Random size
                const size = Math.random() * 5 + 1;
                particle.style.width = `${size}px`;
                particle.style.height = `${size}px`;
                
                // Random position
                const posX = Math.random() * 100;
                const posY = Math.random() * 100;
                particle.style.left = `${posX}%`;
                particle.style.top = `${posY}%`;
                
                // Random color
                const colors = ['#7000FF', '#00E0FF', '#FF3DFF'];
                const color = colors[Math.floor(Math.random() * colors.length)];
                particle.style.backgroundColor = color;
                particle.style.boxShadow = `0 0 ${size * 2}px ${color}`;
                
                // Random animation
                const duration = Math.random() * 20 + 10;
                const delay = Math.random() * 10;
                particle.style.animation = `float ${duration}s ease-in-out ${delay}s infinite`;
                
                particlesContainer.appendChild(particle);
            }
        }
        
        // Animation cho các phần tử
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

        document.addEventListener('DOMContentLoaded', function() {
            // Tạo hiệu ứng particles
            createParticles();
            
            // Animation cho các phần tử
            animateElements('.content-section', 100);
            animateElements('.deck-item', 50);
            
            // Hiển thị thông báo
            setTimeout(() => {
                const messages = document.querySelectorAll('.error-message, .success-message');
                messages.forEach(message => {
                    message.style.opacity = '0';
                    message.style.transform = 'translateY(-10px)';
                    message.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    
                    setTimeout(() => {
                        message.style.display = 'none';
                    }, 500);
                });
            }, 5000);
        });
    </script>
</body>
</html>
<?php mysqli_close($conn); ?>