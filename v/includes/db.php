<?php
try {
    $dsn = "mysql:host=localhost;dbname=u459537937_v;charset=utf8mb4";
    $pdo = new PDO($dsn, "u459537937_v", "qMinh@070706", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    die("Kết nối database thất bại: " . $e->getMessage());
}
?>