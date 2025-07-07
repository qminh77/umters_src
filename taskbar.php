<?php
include 'db_config.php';

// Lấy user_id từ session
$user_id = (int)$_SESSION['user_id'];

// Lấy thông tin user an toàn
$stmt = $conn->prepare("SELECT username, full_name, is_main_admin, is_super_admin FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result_user = $stmt->get_result();
$user = $result_user->num_rows > 0 ? $result_user->fetch_assoc() : ['username' => 'Unknown', 'full_name' => '', 'is_main_admin' => 0, 'is_super_admin' => 0];
$stmt->close();
?>

<div class="taskbar">
    <a href="dashboard.php" class="home-btn">
        <i class="fas fa-home"></i> Trang Chủ
    </a>
</div>

<style>
    :root {
        --primary-gradient-start: #74ebd5;
        --primary-gradient-end: #acb6e5;
        --secondary-gradient-start: #acb6e5;
        --secondary-gradient-end: #74ebd5;
        --container-bg: rgba(255, 255, 255, 0.97);
        --card-bg: #ffffff;
        --text-color: #1e293b;
        --text-secondary: #64748b;
        --border-color: #e5e7eb;
        --shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
        --shadow-md: 0 4px 12px rgba(116,235,213,0.10);
        --shadow-lg: 0 12px 32px rgba(116,235,213,0.12);
        --radius-lg: 1.5rem;
        --radius-md: 0.75rem;
        --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .taskbar {
        background: var(--container-bg);
        border-radius: var(--radius-lg);
        padding: 1.5rem;
        box-shadow: var(--shadow);
        margin-bottom: 2rem;
        position: relative;
        z-index: 1000;
        backdrop-filter: blur(10px);
        border: 1px solid var(--border-color);
    }
    .home-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.75rem;
        padding: 1rem 1.5rem;
        background: linear-gradient(90deg, var(--primary-gradient-start), var(--primary-gradient-end));
        color: #fff;
        border-radius: var(--radius-md);
        text-decoration: none;
        font-size: 1rem;
        font-weight: 600;
        transition: var(--transition);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border: none;
        width: 100%;
        box-shadow: var(--shadow-md);
    }
    .home-btn:hover {
        transform: translateY(-2px) scale(1.04);
        box-shadow: var(--shadow-lg);
        background: linear-gradient(90deg, var(--secondary-gradient-start), var(--secondary-gradient-end));
        color: #fff;
    }
    .home-btn i {
        font-size: 1.125rem;
        transition: var(--transition);
    }
    .home-btn:hover i {
        transform: scale(1.1);
    }
    @media (max-width: 768px) {
        .taskbar {
            padding: 1rem;
            margin-bottom: 1rem;
        }
        .home-btn {
            padding: 0.75rem 1rem;
            font-size: 0.85rem;
        }
    }
</style>


