<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    header('Location: /');
    exit;
}

// Lấy danh sách ảnh
$stmt = $pdo->query("SELECT p.id, p.file_path, p.created_at, u.username FROM photos p JOIN users u ON p.user_id = u.id ORDER BY p.created_at DESC");
$photos = $stmt->fetchAll();

// Xóa ảnh
if (isset($_GET['delete'])) {
    $photoId = filter_input(INPUT_GET, 'delete', FILTER_VALIDATE_INT);
    if ($photoId) {
        $stmt = $pdo->prepare("SELECT file_path FROM photos WHERE id = ?");
        $stmt->execute([$photoId]);
        $photo = $stmt->fetch();
        if ($photo && file_exists($photo['file_path'])) {
            unlink($photo['file_path']);
        }
        $stmt = $pdo->prepare("DELETE FROM photos WHERE id = ?");
        $stmt->execute([$photoId]);
        header('Location: manage_photos.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Quản lý ảnh đã tải lên trong PhotoBooth AdminCP.">
    <title>Quản Lý Ảnh - AdminCP</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="bg-gray-100 font-sans min-h-screen flex flex-col">
    <?php include '../includes/header.php'; ?>

    <main class="container mx-auto py-8 flex-grow">
        <h1 class="text-4xl font-bold text-center mb-8">Quản Lý Ảnh</h1>
        <div class="bg-white p-6 rounded-lg shadow-lg">
            <table class="w-full text-left">
                <thead>
                    <tr class="bg-gray-200">
                        <th class="p-3">ID</th>
                        <th class="p-3">Người Dùng</th>
                        <th class="p-3">Đường Dẫn</th>
                        <th class="p-3">Ngày Tải Lên</th>
                        <th class="p-3">Hành Động</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($photos as $photo): ?>
                        <tr class="border-b">
                            <td class="p-3"><?php echo htmlspecialchars($photo['id']); ?></td>
                            <td class="p-3"><?php echo htmlspecialchars($photo['username']); ?></td>
                            <td class="p-3"><a href="/<?php echo htmlspecialchars($photo['file_path']); ?>" target="_blank" class="text-blue-600 hover:underline">Xem ảnh</a></td>
                            <td class="p-3"><?php echo htmlspecialchars($photo['created_at']); ?></td>
                            <td class="p-3">
                                <a href="manage_photos.php?delete=<?php echo $photo['id']; ?>" class="text-red-600 hover:underline" onclick="return confirm('Bạn có chắc muốn xóa ảnh này?')">Xóa</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>

    <?php include '../includes/footer.php'; ?>
</body>
</html>