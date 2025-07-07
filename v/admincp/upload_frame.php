<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    header('Location: /');
    exit;
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['frame'])) {
    $uploadDir = '../frames/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $file = $_FILES['frame'];
    $fileName = basename($file['name']);
    $filePath = $uploadDir . $fileName;
    $fileType = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    // Kiểm tra loại file và kích thước
    if (in_array($fileType, ['png', 'jpg', 'jpeg']) && $file['size'] <= 5 * 1024 * 1024) {
        if (move_uploaded_file($file['tmp_name'], $filePath)) {
            $message = "Tải lên khung mẫu thành công: $fileName";
        } else {
            $message = "Lỗi khi tải lên khung mẫu.";
        }
    } else {
        $message = "Chỉ cho phép file PNG, JPG, JPEG và kích thước tối đa 5MB.";
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Tải lên khung mẫu cho PhotoBooth trong AdminCP.">
    <title>Tải Lên Khung Mẫu - AdminCP</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="bg-gray-100 font-sans min-h-screen flex flex-col">
    <?php include '../includes/header.php'; ?>

    <main class="container mx-auto py-8 flex-grow">
        <h1 class="text-4xl font-bold text-center mb-8">Tải Lên Khung Mẫu</h1>
        <div class="bg-white p-6 rounded-lg shadow-lg max-w-md mx-auto">
            <?php if ($message): ?>
                <div class="mb-4 p-4 rounded <?php echo strpos($message, 'thành công') !== false ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            <form action="" method="POST" enctype="multipart/form-data" class="space-y-4">
                <div>
                    <label for="frame" class="block text-sm font-medium">Chọn Khung Mẫu (PNG, JPG, JPEG)</label>
                    <input type="file" name="frame" id="frame" accept="image/png,image/jpeg,image/jpg" required class="w-full p-2 border rounded">
                </div>
                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white py-2 rounded transition">Tải Lên</button>
            </form>
        </div>
    </main>

    <?php include '../includes/footer.php'; ?>
</body>
</html>