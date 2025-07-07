<?php
include 'db_config.php';

$room_id = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
$room = null;
$message = '';

if ($room_id) {
    $sql = "SELECT * FROM qna_rooms WHERE id = $room_id";
    $result = mysqli_query($conn, $sql);
    if ($result && mysqli_num_rows($result) > 0) {
        $room = mysqli_fetch_assoc($result);
    } else {
        $message = "Phòng không tồn tại!";
    }
}

// Xử lý gửi form (chỉ khi phòng active)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $room && $room['is_active']) {
    $content = trim($_POST['content']);
    $full_name = isset($_POST['full_name']) ? trim($_POST['full_name']) : null;
    $email = isset($_POST['email']) ? trim($_POST['email']) : null;
    $phone = isset($_POST['phone']) ? trim($_POST['phone']) : null;
    $rating = isset($_POST['rating']) ? (int)$_POST['rating'] : null;

    if ($content) {
        $content_escaped = mysqli_real_escape_string($conn, $content);
        $full_name_escaped = $full_name ? mysqli_real_escape_string($conn, $full_name) : null;
        $email_escaped = $email ? mysqli_real_escape_string($conn, $email) : null;
        $phone_escaped = $phone ? mysqli_real_escape_string($conn, $phone) : null;

        $sql = "INSERT INTO qna_submissions (room_id, full_name, email, phone, content, rating) VALUES (
            {$room['id']}, 
            " . ($full_name_escaped ? "'$full_name_escaped'" : "NULL") . ",
            " . ($email_escaped ? "'$email_escaped'" : "NULL") . ",
            " . ($phone_escaped ? "'$phone_escaped'" : "NULL") . ",
            '$content_escaped',
            " . ($rating ? $rating : "NULL") . "
        )";
        if (mysqli_query($conn, $sql)) {
            $message = "Gửi câu hỏi/đánh giá thành công!";
        } else {
            $message = "Lỗi khi gửi: " . mysqli_error($conn);
        }
    } else {
        $message = "Vui lòng nhập nội dung!";
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gửi câu hỏi / Đánh giá</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .ripple { position: relative; overflow: hidden; }
        .ripple::after {
            content: '';
            position: absolute;
            top: 50%; left: 50%;
            width: 0; height: 0;
            background: rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }
        .ripple:hover::after {
            width: 200px; height: 200px;
        }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-teal-50 via-blue-100 to-purple-100 font-inter flex items-center justify-center p-6">
    <div class="w-full max-w-md bg-white/90 rounded-3xl shadow-2xl p-8 transition-all duration-300 hover:shadow-3xl">
        <h2 class="text-3xl font-bold text-gray-900 text-center mb-8"><?php echo $room ? htmlspecialchars($room['room_name']) : 'Phòng không tồn tại'; ?></h2>
        <div class="mb-6">
            <?php if ($message): ?>
                <div class="p-4 rounded-xl <?php echo strpos($message, 'thành công') !== false ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?> transition-all duration-500">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>
            <?php if ($room && !$room['is_active']): ?>
                <div class="p-4 rounded-xl bg-yellow-100 text-yellow-700 transition-all duration-500">
                    Hiện không nhận câu hỏi nữa. Vui lòng liên hệ quản trị viên.
                </div>
            <?php endif; ?>
        </div>
        <?php if ($room && $room['is_active']): ?>
            <form method="POST" class="space-y-5">
                <?php if ($room['room_type'] == 'register'): ?>
                    <input type="text" name="full_name" placeholder="Họ tên" class="w-full p-4 border border-gray-200 rounded-xl focus:ring-2 focus:ring-teal-500 focus:border-transparent bg-white/50" required>
                    <input type="email" name="email" placeholder="Email" class="w-full p-4 border border-gray-200 rounded-xl focus:ring-2 focus:ring-teal-500 focus:border-transparent bg-white/50" required>
                    <input type="text" name="phone" placeholder="Số điện thoại" class="w-full p-4 border border-gray-200 rounded-xl focus:ring-2 focus:ring-teal-500 focus:border-transparent bg-white/50" required>
                    <textarea name="content" placeholder="Nội dung" class="w-full p-4 border border-gray-200 rounded-xl focus:ring-2 focus:ring-teal-500 focus:border-transparent bg-white/50" rows="4" required></textarea>
                <?php elseif ($room['room_type'] == 'rating'): ?>
                    <select name="rating" class="w-full p-4 border border-gray-200 rounded-xl focus:ring-2 focus:ring-teal-500 focus:border-transparent bg-white/50" required>
                        <option value="">Chọn điểm đánh giá</option>
                        <option value="1">1/5</option>
                        <option value="2">2/5</option>
                        <option value="3">3/5</option>
                        <option value="4">4/5</option>
                        <option value="5">5/5</option>
                    </select>
                    <textarea name="content" placeholder="Nhận xét" class="w-full p-4 border border-gray-200 rounded-xl focus:ring-2 focus:ring-teal-500 focus:border-transparent bg-white/50" rows="4" required></textarea>
                <?php else: ?>
                    <textarea name="content" placeholder="Câu hỏi của bạn" class="w-full p-4 border border-gray-200 rounded-xl focus:ring-2 focus:ring-teal-500 focus:border-transparent bg-white/50" rows="4" required></textarea>
                <?php endif; ?>
                <button type="submit" class="w-full inline-flex items-center justify-center px-6 py-3 bg-teal-500 text-white rounded-full hover:bg-teal-600 ripple transition-all">
                    <i class="fas fa-paper-plane mr-2"></i> Gửi
                </button>
            </form>
        <?php endif; ?>
    </div>
    <script>
        // Ẩn thông báo sau 5 giây
        setTimeout(() => {
            const messageContainer = document.querySelector('.bg-green-100, .bg-red-100, .bg-yellow-100');
            if (messageContainer) {
                messageContainer.style.transition = 'opacity 0.5s, transform 0.5s';
                messageContainer.style.opacity = '0';
                messageContainer.style.transform = 'translateY(-20px)';
                setTimeout(() => messageContainer.style.display = 'none', 500);
            }
        }, 5000);
    </script>
</body>
</html>
<?php mysqli_close($conn); ?>