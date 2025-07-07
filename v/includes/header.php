<header class="bg-blue-600 text-white py-4 shadow">
    <div class="container mx-auto flex justify-between items-center">
        <a href="/" class="text-2xl font-bold">PhotoBooth</a>
        <nav>
            <?php if (isLoggedIn()): ?>
                <a href="/photobooth" class="px-4 hover:underline">Chụp Ảnh</a>
                <?php if (isAdmin()): ?>
                    <a href="/admincp" class="px-4 hover:underline">AdminCP</a>
                <?php endif; ?>
                <a href="/logout" class="px-4 hover:underline">Đăng Xuất</a>
            <?php else: ?>
                <a href="/login" class="px-4 hover:underline">Đăng Nhập</a>
                <a href="/register" class="px-4 hover:underline">Đăng Ký</a>
            <?php endif; ?>
        </nav>
    </div>
</header>