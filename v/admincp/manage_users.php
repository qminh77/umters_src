<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    header('Location: /');
    exit;
}

// Lấy danh sách user
$stmt = $pdo->query("SELECT id, username, email, role, created_at FROM users");
$users = $stmt->fetchAll();

// Xóa user
if (isset($_GET['delete'])) {
    $userId = filter_input(INPUT_GET, 'delete', FILTER_VALIDATE_INT);
    if ($userId && $userId !== $_SESSION['user_id']) { // Không cho xóa chính mình
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        header('Location: manage_users.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Quản lý user trong PhotoBooth AdminCP.">
    <title>Quản Lý User - AdminCP</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="bg-gray-100 font-sans min-h-screen flex flex-col">
    <?php include '../includes/header.php'; ?>

    <main class="container mx-auto py-8 flex-grow">
        <h1 class="text-4xl font-bold text-center mb-8">Quản Lý User</h1>
        <div class="bg-white p-6 rounded-lg shadow-lg">
            <table class="w-full text-left">
                <thead>
                    <tr class="bg-gray-200">
                        <th class="p-3">ID</th>
                        <th class="p-3">Tên Người Dùng</th>
                        <th class="p-3">Email</th>
                        <th class="p-3">Vai Trò</th>
                        <th class="p-3">Ngày Tạo</th>
                        <th class="p-3">Hành Động</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr class="border-b">
                            <td class="p-3"><?php echo htmlspecialchars($user['id']); ?></td>
                            <td class="p-3"><?php echo htmlspecialchars($user['username']); ?></td>
                            <td class="p-3"><?php echo htmlspecialchars($user['email']); ?></td>
                            <td class="p-3"><?php echo htmlspecialchars($user['role']); ?></td>
                            <td class="p-3"><?php echo htmlspecialchars($user['created_at']); ?></td>
                            <td class="p-3">
                                <?php if ($user['id'] !== $_SESSION['user_id']): ?>
                                    <a href="manage_users.php?delete=<?php echo $user['id']; ?>" class="text-red-600 hover:underline" onclick="return confirm('Bạn có chắc muốn xóa user này?')">Xóa</a>
                                <?php endif; ?>
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