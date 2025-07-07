<?php
session_start();
include 'db_config.php';

// Kiểm tra đăng nhập
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Lấy user_id từ session
$user_id = (int)$_SESSION['user_id'];

// Lấy thông tin user
$stmt = $conn->prepare("SELECT username, phone, email, full_name, class, address, is_main_admin, is_super_admin FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result_user = $stmt->get_result();
if ($result_user && $result_user->num_rows > 0) {
    $user = $result_user->fetch_assoc();
} else {
    $edit_error = "Lỗi khi lấy thông tin người dùng.";
    $user = [
        'username' => 'Unknown',
        'phone' => '',
        'email' => '',
        'full_name' => '',
        'class' => '',
        'address' => '',
        'is_main_admin' => 0,
        'is_super_admin' => 0
    ];
}
$stmt->close();

// Lấy số liệu cho tổng quan
$total_files = 0;
$total_users = 0;
$total_emails = 0;

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM files");
$stmt->execute();
$total_files = $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM users");
$stmt->execute();
$total_users = $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM email_logs");
$stmt->execute();
$total_emails = $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

// Định nghĩa biến để overview.php biết nó được gọi từ dashboard
define('INCLUDED_FROM_DASHBOARD', true);
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UMTERS Dashboard - Quản Lý Hiện Đại</title>
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
        .dashboard-container {
            display: grid;
            grid-template-columns: 1fr;
            grid-template-rows: auto 1fr;
            min-height: 100vh;
            padding: 1.5rem;
            gap: 1.5rem;
            position: relative;
            z-index: 1;
        }

        /* Header section */
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem;
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }

        .dashboard-header::before {
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

        .header-logo {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .logo-icon {
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            border-radius: var(--radius);
            font-size: 1.5rem;
            color: white;
            box-shadow: var(--glow);
            position: relative;
            overflow: hidden;
        }

        .logo-icon::after {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.3) 0%, rgba(255,255,255,0) 70%);
            opacity: 0;
            transform: scale(0.5);
            animation: pulse 3s ease-in-out infinite;
        }

        @keyframes pulse {
            0% { opacity: 0; transform: scale(0.5); }
            50% { opacity: 0.3; transform: scale(1); }
            100% { opacity: 0; transform: scale(0.5); }
        }

        .logo-text {
            font-weight: 800;
            font-size: 1.5rem;
            background: linear-gradient(to right, var(--secondary), var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: -0.5px;
            position: relative;
        }

        .logo-text::after {
            content: 'UMTERS';
            position: absolute;
            top: 0;
            left: 0;
            background: linear-gradient(to right, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            opacity: 0;
            animation: textFlicker 8s linear infinite;
        }

        @keyframes textFlicker {
            0%, 92%, 100% { opacity: 0; }
            94%, 96% { opacity: 1; }
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .search-bar {
            position: relative;
        }

        .search-input {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: var(--radius-full);
            padding: 0.75rem 1rem 0.75rem 3rem;
            color: var(--foreground);
            font-family: 'Outfit', sans-serif;
            font-size: 0.875rem;
            width: 250px;
            transition: all 0.3s ease;
            backdrop-filter: blur(5px);
            -webkit-backdrop-filter: blur(5px);
        }

        .search-input:focus {
            outline: none;
            background: rgba(255, 255, 255, 0.1);
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(112, 0, 255, 0.2);
            width: 300px;
        }

        .search-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--foreground-subtle);
            font-size: 1rem;
            pointer-events: none;
            transition: color 0.3s ease;
        }

        .search-input:focus + .search-icon {
            color: var(--primary-light);
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.5rem 1rem 0.5rem 0.5rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: var(--radius-full);
            border: 1px solid var(--border);
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .user-profile:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: var(--primary-light);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-full);
            background: linear-gradient(135deg, var(--primary), var(--accent));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1.25rem;
            box-shadow: 0 0 10px rgba(112, 0, 255, 0.5);
            position: relative;
            overflow: hidden;
        }

        .user-avatar::after {
            content: '';
            position: absolute;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, transparent, rgba(255, 255, 255, 0.3));
            top: 0;
            left: 0;
        }

        .user-info {
            display: flex;
            flex-direction: column;
        }

        .user-name {
            font-weight: 600;
            font-size: 0.875rem;
            color: var(--foreground);
        }

        .user-role {
            font-size: 0.75rem;
            color: var(--secondary);
            font-weight: 500;
        }

        /* Main content */
        .dashboard-content {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
        }

        /* Navigation panel */
        .nav-panel {
            grid-column: span 3;
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
            /*max-height: calc(100vh - 10rem);*/
            overflow-y: auto;
            position: relative;
        }

        .nav-panel::before {
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

        .nav-section {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .nav-section-title {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--foreground-subtle);
            margin-bottom: 0.5rem;
            padding-left: 0.75rem;
            font-weight: 600;
        }

        .nav-links {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.875rem 1rem;
            border-radius: var(--radius);
            color: var(--foreground);
            text-decoration: none;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            font-weight: 500;
            font-size: 0.875rem;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid transparent;
        }

        .nav-link::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 0;
            background: linear-gradient(to right, var(--primary-light), transparent);
            opacity: 0.2;
            transition: width 0.3s ease;
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.07);
            border-color: var(--border);
            transform: translateX(4px);
        }

        .nav-link:hover::before {
            width: 100%;
        }

        .nav-link.active {
            background: linear-gradient(to right, var(--primary), var(--primary-dark));
            color: white;
            box-shadow: 0 4px 12px rgba(112, 0, 255, 0.3);
            border-color: var(--primary-light);
        }

        .nav-link.active::before {
            display: none;
        }

        .nav-link i {
            font-size: 1rem;
            width: 20px;
            text-align: center;
            transition: transform 0.3s ease;
        }

        .nav-link:hover i {
            transform: scale(1.1);
        }

        .nav-link.active i {
            color: white;
        }

        .nav-link .link-text {
            position: relative;
            z-index: 1;
        }

        .nav-link .link-highlight {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            width: 6px;
            height: 6px;
            border-radius: var(--radius-full);
            background: var(--accent);
            box-shadow: 0 0 10px var(--accent);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .nav-link:hover .link-highlight {
            opacity: 1;
        }

        .logout-btn {
            margin-top: auto;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            padding: 1rem;
            background: linear-gradient(to right, var(--accent-dark), var(--accent));
            color: white;
            border-radius: var(--radius);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.875rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 12px rgba(255, 61, 255, 0.3);
            border: none;
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .logout-btn::before {
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

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(255, 61, 255, 0.4);
        }

        .logout-btn:hover::before {
            opacity: 1;
            transform: scale(1);
        }

        .logout-btn i {
            font-size: 1rem;
            transition: transform 0.3s ease;
        }

        .logout-btn:hover i {
            transform: translateX(3px);
        }

        /* Main panel */
        .main-panel {
            grid-column: span 9;
            display: grid;
            grid-template-rows: auto 1fr;
            gap: 1.5rem;
        }

        /* Stats grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.5rem;
        }

        .stats-card {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .stats-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at 50% 0%, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .stats-card:hover {
            transform: translateY(-5px) scale(1.02);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary-light);
        }

        .stats-card:hover::before {
            opacity: 1;
        }

        .stats-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .stats-title {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--foreground-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .stats-icon {
            width: 48px;
            height: 48px;
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            box-shadow: var(--shadow-sm);
            position: relative;
            overflow: hidden;
        }

        .stats-icon::after {
            content: '';
            position: absolute;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, transparent, rgba(255, 255, 255, 0.2));
            top: 0;
            left: 0;
        }

        .stats-icon.files {
            background: linear-gradient(135deg, #4A00E0, #8E2DE2);
            color: white;
            box-shadow: 0 0 15px rgba(74, 0, 224, 0.5);
        }

        .stats-icon.users {
            background: linear-gradient(135deg, #00B8D9, #0052CC);
            color: white;
            box-shadow: 0 0 15px rgba(0, 184, 217, 0.5);
        }

        .stats-icon.emails {
            background: linear-gradient(135deg, #FF007A, #FF5630);
            color: white;
            box-shadow: 0 0 15px rgba(255, 0, 122, 0.5);
        }

        .stats-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--foreground);
            line-height: 1;
            margin-top: 0.5rem;
            position: relative;
            display: inline-block;
        }

        .stats-value::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 0;
            width: 40px;
            height: 3px;
            background: linear-gradient(to right, var(--primary), transparent);
            border-radius: var(--radius-full);
        }

        .stats-description {
            font-size: 0.875rem;
            color: var(--foreground-subtle);
            margin-top: 0.5rem;
        }

        /* Content panels */
        .content-panels {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
        }

        .panel {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            position: relative;
            overflow: hidden;
        }

        .panel::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                radial-gradient(circle at 20% 30%, rgba(112, 0, 255, 0.05) 0%, transparent 50%),
                radial-gradient(circle at 80% 70%, rgba(0, 224, 255, 0.05) 0%, transparent 50%);
            pointer-events: none;
            border-radius: var(--radius-lg);
        }

        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
        }

        .panel-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--foreground);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .panel-title i {
            color: var(--primary-light);
            font-size: 1.25rem;
        }

        .panel-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .panel-btn {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 0.5rem;
            color: var(--foreground-muted);
            font-size: 0.875rem;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .panel-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            color: var(--foreground);
            border-color: var(--primary-light);
            transform: translateY(-2px);
        }

        /* Quick actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
        }

        .action-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.25rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1rem;
            text-decoration: none;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            text-align: center;
        }

        .action-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: linear-gradient(to right, var(--primary), var(--secondary));
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .action-card:hover {
            transform: translateY(-5px);
            background: rgba(255, 255, 255, 0.07);
            border-color: var(--primary-light);
            box-shadow: var(--shadow);
        }

        .action-card:hover::before {
            opacity: 1;
        }

        .action-icon {
            width: 50px;
            height: 50px;
            border-radius: var(--radius);
            background: rgba(255, 255, 255, 0.05);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            color: var(--primary-light);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .action-icon::after {
            content: '';
            position: absolute;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, transparent, rgba(255, 255, 255, 0.1));
            top: 0;
            left: 0;
        }

        .action-card:hover .action-icon {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            transform: scale(1.1);
            box-shadow: 0 0 15px rgba(112, 0, 255, 0.3);
        }

        .action-title {
            font-weight: 600;
            font-size: 0.875rem;
            color: var(--foreground);
        }

        /* Activity list */
        .activity-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            padding: 1rem;
            border-radius: var(--radius);
            background: rgba(255, 255, 255, 0.03);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            border: 1px solid var(--border-light);
        }

        .activity-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 3px;
            background: linear-gradient(to bottom, var(--primary), var(--secondary));
            opacity: 0.7;
        }

        .activity-item:hover {
            background: rgba(255, 255, 255, 0.05);
            transform: translateX(4px);
            border-color: var(--border);
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: var(--radius);
            background: rgba(255, 255, 255, 0.05);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            color: var(--primary-light);
            box-shadow: var(--shadow-sm);
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 600;
            font-size: 0.875rem;
            color: var(--foreground);
            margin-bottom: 0.25rem;
        }

        .activity-description {
            font-size: 0.75rem;
            color: var(--foreground-subtle);
        }

        .activity-time {
            font-size: 0.75rem;
            color: var(--secondary);
            font-weight: 500;
        }

        /* Notifications */
        .notifications-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .notification-item {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            padding: 1rem;
            border-radius: var(--radius);
            background: rgba(255, 255, 255, 0.03);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            border: 1px solid var(--border-light);
        }

        .notification-item::before {
            content: '';
            position: absolute;
            right: 0;
            top: 0;
            height: 100%;
            width: 3px;
            background: linear-gradient(to bottom, var(--accent), var(--secondary));
            opacity: 0.7;
        }

        .notification-item:hover {
            background: rgba(255, 255, 255, 0.05);
            transform: translateX(-4px);
            border-color: var(--border);
        }

        .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: var(--radius);
            background: rgba(255, 255, 255, 0.05);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            color: var(--accent-light);
            box-shadow: var(--shadow-sm);
        }

        .notification-content {
            flex: 1;
        }

        .notification-title {
            font-weight: 600;
            font-size: 0.875rem;
            color: var(--foreground);
            margin-bottom: 0.25rem;
        }

        .notification-description {
            font-size: 0.75rem;
            color: var(--foreground-subtle);
        }

        .notification-time {
            font-size: 0.75rem;
            color: var(--accent-light);
            font-weight: 500;
        }

        /* Error and success messages */
        .error-message,
        .success-message {
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            font-weight: 500;
            position: relative;
            padding-left: 3rem;
            animation: slideIn 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }

        .error-message {
            background: rgba(255, 61, 87, 0.1);
            color: #FF3D57;
            border: 1px solid rgba(255, 61, 87, 0.3);
        }

        .error-message::before {
            content: "⚠️";
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
        }

        .success-message {
            background: rgba(0, 224, 255, 0.1);
            color: #00E0FF;
            border: 1px solid rgba(0, 224, 255, 0.3);
        }

        .success-message::before {
            content: "✅";
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
        }

        /* Animations */
        @keyframes slideIn {
            from { transform: translateX(-20px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes float {
            0% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
            100% { transform: translateY(0); }
        }

        @keyframes glow {
            0% { box-shadow: 0 0 5px rgba(112, 0, 255, 0.5); }
            50% { box-shadow: 0 0 20px rgba(112, 0, 255, 0.8); }
            100% { box-shadow: 0 0 5px rgba(112, 0, 255, 0.5); }
        }

        /* Responsive styles */
        @media (max-width: 1200px) {
            .dashboard-content {
                grid-template-columns: 1fr;
            }

            .nav-panel {
                grid-column: span 12;
                max-height: none;
                overflow-y: visible;
            }

            .main-panel {
                grid-column: span 12;
            }

            .content-panels {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .dashboard-container {
                padding: 1rem;
                gap: 1rem;
            }

            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
                padding: 1rem;
            }

            .header-actions {
                width: 100%;
                flex-direction: column;
                align-items: stretch;
                gap: 1rem;
            }

            .search-bar {
                width: 100%;
            }

            .search-input {
                width: 100%;
            }

            .user-profile {
                width: 100%;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .quick-actions {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 480px) {
            .quick-actions {
                grid-template-columns: 1fr;
            }
        }

        /* Floating elements */
        .floating {
            animation: float 6s ease-in-out infinite;
        }

        .floating-slow {
            animation: float 8s ease-in-out infinite;
        }

        .floating-fast {
            animation: float 4s ease-in-out infinite;
        }

        /* Glowing elements */
        .glowing {
            animation: glow 3s ease-in-out infinite;
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

        /* Custom scrollbar for panels */
        .custom-scrollbar {
            scrollbar-width: thin;
            scrollbar-color: var(--primary) var(--surface);
        }

        .custom-scrollbar::-webkit-scrollbar {
            width: 6px;
        }

        .custom-scrollbar::-webkit-scrollbar-track {
            background: var(--surface);
            border-radius: var(--radius-full);
        }

        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: var(--primary);
            border-radius: var(--radius-full);
        }

        /* Neon text effect */
        .neon-text {
            text-shadow: 0 0 5px rgba(112, 0, 255, 0.5), 0 0 10px rgba(112, 0, 255, 0.3);
        }

        /* Glass card effect */
        .glass-card {
            background: rgba(30, 30, 60, 0.3);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
        }

        /* Gradient text */
        .gradient-text {
            background: linear-gradient(to right, var(--primary-light), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* Hover glow effect */
        .hover-glow {
            transition: all 0.3s ease;
        }

        .hover-glow:hover {
            box-shadow: 0 0 15px var(--primary);
        }

        /* Ripple effect */
        .ripple {
            position: relative;
            overflow: hidden;
        }

        .ripple::after {
            content: '';
            position: absolute;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            pointer-events: none;
            background-image: radial-gradient(circle, rgba(255, 255, 255, 0.3) 10%, transparent 10.01%);
            background-repeat: no-repeat;
            background-position: 50%;
            transform: scale(10, 10);
            opacity: 0;
            transition: transform 0.5s, opacity 1s;
        }

        .ripple:active::after {
            transform: scale(0, 0);
            opacity: 0.3;
            transition: 0s;
        }

        /* 3D transform on hover */
        .transform-3d {
            transition: transform 0.3s ease;
            transform-style: preserve-3d;
        }

        .transform-3d:hover {
            transform: perspective(1000px) rotateX(5deg) rotateY(5deg) scale(1.05);
        }

        /* Spotlight effect */
        .spotlight {
            position: relative;
            overflow: hidden;
        }

        .spotlight::before {
            content: '';
            position: absolute;
            top: -100%;
            left: -100%;
            width: 50%;
            height: 50%;
            background: radial-gradient(circle, rgba(255,255,255,0.8) 0%, rgba(255,255,255,0) 80%);
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
        }

        .spotlight:hover::before {
            opacity: 0.1;
            animation: spotlight 2s infinite linear;
        }

        @keyframes spotlight {
            0% { top: -100%; left: -100%; }
            25% { top: -100%; left: 150%; }
            50% { top: 150%; left: 150%; }
            75% { top: 150%; left: -100%; }
            100% { top: -100%; left: -100%; }
        }

        /* Gradient border */
        .gradient-border {
            position: relative;
            background-clip: padding-box;
            border: 1px solid transparent;
        }

        .gradient-border::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            bottom: 0;
            left: 0;
            z-index: -1;
            margin: -1px;
            border-radius: inherit;
            background: linear-gradient(to right, var(--primary), var(--secondary), var(--accent));
        }

        /* Animated gradient background */
        .animated-gradient {
            background: linear-gradient(-45deg, var(--primary-dark), var(--primary), var(--secondary), var(--accent));
            background-size: 400% 400%;
            animation: gradientBG 15s ease infinite;
        }

        @keyframes gradientBG {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        /* Blinking effect */
        .blink {
            animation: blink 2s infinite;
        }

        @keyframes blink {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        /* Typing effect */
        .typing {
            border-right: 2px solid var(--primary);
            white-space: nowrap;
            overflow: hidden;
            animation: typing 3.5s steps(30, end), blink-caret 0.75s step-end infinite;
        }

        @keyframes typing {
            from { width: 0; }
            to { width: 100%; }
        }

        @keyframes blink-caret {
            from, to { border-color: transparent; }
            50% { border-color: var(--primary); }
        }
    </style>
</head>
<body>
    <div class="particles" id="particles"></div>
    
    <div class="dashboard-container">
        <div class="dashboard-header">
            <div class="header-logo">
                <div class="logo-icon floating"><i class="fas fa-bolt"></i></div>
                <div class="logo-text">UMTERS</div>
            </div>
            
            <div class="header-actions">
                <!--<div class="search-bar">-->
                <!--    <input type="text" class="search-input" placeholder="Tìm kiếm...">-->
                <!--    <i class="fas fa-search search-icon"></i>-->
                <!--</div>-->
                
                <div class="user-profile">
                    <div class="user-avatar glowing"><?php echo strtoupper(substr($user['username'], 0, 1)); ?></div>
                    <div class="user-info">
                        <p class="user-name"><?php echo htmlspecialchars($user['full_name'] ?: $user['username']); ?></p>
                        <p class="user-role">
                            <?php echo $user['is_super_admin'] ? 'Super Admin' : ($user['is_main_admin'] ? 'Main Admin' : 'Admin'); ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="dashboard-content">
            <div class="nav-panel custom-scrollbar">
                <div class="nav-section">
                    <div class="nav-section-title">Quản Lý</div>
                    <div class="nav-links">
                        <a href="/dashboard" class="nav-link active ripple">
                            <i class="fas fa-tachometer-alt"></i>
                            <span class="link-text">Dashboard</span>
                            <span class="link-highlight"></span>
                        </a>
                        <?php if ($user['is_super_admin']): ?>
                            <a href="/add_user" class="nav-link ripple">
                                <i class="fas fa-user-plus"></i>
                                <span class="link-text">Thêm User</span>
                                <span class="link-highlight"></span>
                            </a>
                            <a href="/manage_users" class="nav-link ripple">
                                <i class="fas fa-users-cog"></i>
                                <span class="link-text">Quản Lý User</span>
                                <span class="link-highlight"></span>
                            </a>
                            <a href="/website_settings" class="nav-link ripple">
                                <i class="fas fa-cog"></i>
                                <span class="link-text">Cài Đặt Website</span>
                                <span class="link-highlight"></span>
                            </a>
                            <a href="/send_email" class="nav-link ripple">
                                <i class="fas fa-envelope"></i>
                                <span class="link-text">Gửi Email</span>
                                <span class="link-highlight"></span>
                            </a>
                            <a href="/email_logs" class="nav-link ripple">
                                <i class="fas fa-history"></i>
                                <span class="link-text">Lịch Sử Email</span>
                                <span class="link-highlight"></span>
                            </a>
                            <a href="/data_key" class="nav-link ripple">
                                <i class="fas fa-key"></i>
                                <span class="link-text">API</span>
                                <span class="link-highlight"></span>
                            </a>
                        <?php elseif ($user['is_main_admin']): ?>
                            <a href="/send_email" class="nav-link ripple">
                                <i class="fas fa-envelope"></i>
                                <span class="link-text">Gửi Email</span>
                                <span class="link-highlight"></span>
                            </a>
                        <?php endif; ?>
                        <a href="/file_manager" class="nav-link ripple">
                            <i class="fas fa-folder"></i>
                            <span class="link-text">Quản Lý File</span>
                            <span class="link-highlight"></span>
                        </a>
                    </div>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Tài Khoản</div>
                    <div class="nav-links">
                        <a href="/profile" class="nav-link ripple">
                            <i class="fas fa-user"></i>
                            <span class="link-text">Profile</span>
                            <span class="link-highlight"></span>
                        </a>
                        <a href="/change_password" class="nav-link ripple">
                            <i class="fas fa-key"></i>
                            <span class="link-text">Đổi Mật Khẩu</span>
                            <span class="link-highlight"></span>
                        </a>
                        <a href="/email_manager" class="nav-link ripple">
                            <i class="fas fa-envelope"></i>
                            <span class="link-text">Email</span>
                            <span class="link-highlight"></span>
                        </a>
                    </div>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Công Cụ</div>
                    <div class="nav-links">
                        <a href="/convert_image" class="nav-link ripple">
                            <i class="fas fa-exchange-alt"></i>
                            <span class="link-text">Chuyển Đổi Ảnh</span>
                            <span class="link-highlight"></span>
                        </a>
                        <a href="/minifier" class="nav-link ripple">
                            <i class="fas fa-tools"></i>
                            <span class="link-text">Minifier</span>
                            <span class="link-highlight"></span>
                        </a>
                        <a href="/converters" class="nav-link ripple">
                            <i class="fas fa-cog"></i>
                            <span class="link-text">Công Cụ</span>
                            <span class="link-highlight"></span>
                        </a>
                        <a href="/lookup_tools" class="nav-link ripple">
                            <i class="fas fa-search"></i>
                            <span class="link-text">Tra Cứu</span>
                            <span class="link-highlight"></span>
                        </a>
                        <a href="/shortlink" class="nav-link ripple">
                            <i class="fas fa-link"></i>
                            <span class="link-text">Shortlink</span>
                            <span class="link-highlight"></span>
                        </a>
                        <a href="/youtube_thumbnail_downloader" class="nav-link ripple <?php echo basename($_SERVER['PHP_SELF']) == 'youtube_thumbnail_downloader.php' ? 'active' : ''; ?>">
                        <i class="fas fa-video"></i> Tải Thumbnail Youtube
                                                        <span class="link-highlight"></span>

                    </a>
                    <a href="/online_code_editor" class="nav-link ripple <?php echo basename($_SERVER['PHP_SELF']) == 'online_code_editor.php' ? 'active' : ''; ?>">
                        <i class="fas fa-code"></i> Online Code Editor
                                                        <span class="link-highlight"></span>

                    </a>
                    <a href="/qrcode" class="nav-link ripple <?php echo basename($_SERVER['PHP_SELF']) == 'qrcode.php' ? 'active' : ''; ?>">
                        <i class="fas fa-qrcode"></i> QR Code
                                                        <span class="link-highlight"></span>

                    </a>
                                                            <a href="teleprompter.php" class="nav-link ripple <?php echo basename($_SERVER['PHP_SELF']) == 'teleprompter.php' ? 'active' : ''; ?>">
                        <i class="fas fa-scroll"></i> Teleprompter
                                                        <span class="link-highlight"></span>

                    </a>
                                        </a>
                                        <a href="document-review.php" class="nav-link ripple <?php echo basename($_SERVER['PHP_SELF']) == 'document-review.php' ? 'active' : ''; ?>">
                        <i class="fas fa-file-alt"></i> Kiểm tra tài liệu
                                                        <span class="link-highlight"></span>

                    </a>
                                        </a>
                    </div>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Học Tập & Giải Trí</div>
                    <div class="nav-links">
                        <a href="/calendar" class="nav-link ripple">
                            <i class="fas fa-calendar"></i>
                            <span class="link-text">Quản Lý Lịch</span>
                            <span class="link-highlight"></span>
                        </a>
                        <a href="/documents" class="nav-link ripple">
                            <i class="fas fa-file-alt"></i>
                            <span class="link-text">Tài Liệu</span>
                            <span class="link-highlight"></span>
                        </a>
                                            <a href="/flashcards" class="nav-link ripple <?php echo basename($_SERVER['PHP_SELF']) == '/flashcards' ? 'active' : ''; ?>">
                        <i class="fas fa-layer-group"></i> Học Flashcards
                                                        <span class="link-highlight"></span>

                    </a>
                    <a href="/quiz" class="nav-link ripple <?php echo basename($_SERVER['PHP_SELF']) == '/quiz' ? 'active' : ''; ?>">
                        <i class="fas fa-layer-group"></i> Học Quizlet
                                                        <span class="link-highlight"></span>

                    </a>
                    <a href="/qna" class="nav-link ripple <?php echo basename($_SERVER['PHP_SELF']) == '/qna' ? 'active' : ''; ?>">
                        <i class="fas fa-question"></i> Q&A
                                                        <span class="link-highlight"></span>

                    </a>
                        <a href="chat.php" class="nav-link ripple">
                            <i class="fas fa-comments"></i>
                            <span class="link-text">Chat</span>
                            <span class="link-highlight"></span>
                        </a>
                    <a href="/spin_wheel" class="nav-link ripple <?php echo basename($_SERVER['PHP_SELF']) == '/spin_wheel' ? 'active' : ''; ?>">
                        <i class="fas fa-random"></i> Quay Random
                                                        <span class="link-highlight"></span>

                    </a>
                    <a href="/movie_player" class="nav-link ripple <?php echo basename($_SERVER['PHP_SELF']) == '/movie_player' ? 'active' : ''; ?>">
                        <i class="fas fa-film"></i> VinPhim
                                                        <span class="link-highlight"></span>

                    </a>
                    <a href="/automation" class="nav-link ripple <?php echo basename($_SERVER['PHP_SELF']) == '/automation' ? 'active' : ''; ?>">
                        <i class="fas fa-robot"></i> Tự Động Hóa
                    </a>
                                                            <a href="/tainguyen" class="nav-link ripple <?php echo basename($_SERVER['PHP_SELF']) == '/tainguyen' ? 'active' : ''; ?>">
                        <i class="fas fa-key"></i> Tài nguyên
                                                        <span class="link-highlight"></span>
                                                                                                                    <a href="/store" class="nav-link ripple <?php echo basename($_SERVER['PHP_SELF']) == '/store' ? 'active' : ''; ?>">
                        <i class="fas fa-store"></i> Store
                                                        <span class="link-highlight"></span>

                    </a>
                    </div>
                    
                    <div class="nav-section">
                    <div class="nav-section-title">Công việc</div>
                    <div class="nav-links">
                                            <a href="/static_page_manager" class="nav-link ripple <?php echo basename($_SERVER['PHP_SELF']) == '/static_page_manager' ? 'active' : ''; ?>">
                        <i class="fas fa-file"></i> Trang Tĩnh
                    </a>
                    </div>
                                        <div class="nav-links">
                                            <a href="/events-task" class="nav-link ripple <?php echo basename($_SERVER['PHP_SELF']) == '/events-task' ? 'active' : ''; ?>">
                        <i class="fas fa-check"></i> Check in 
                    </a>
                    </div>
                    </div>

                </div>
                
                <a href="logout.php" class="logout-btn ripple">
                    <i class="fas fa-sign-out-alt"></i> Đăng Xuất
                </a>
            </div>
            
            <div class="main-panel">
                <?php if (isset($edit_error)): ?>
                    <div class="error-message">
                        <?php echo htmlspecialchars($edit_error); ?>
                    </div>
                <?php endif; ?>
                
                <div class="stats-grid">
                    <div class="stats-card transform-3d">
                        <div class="stats-header">
                            <h3 class="stats-title">Files</h3>
                            <div class="stats-icon files floating">
                                <i class="fas fa-file"></i>
                            </div>
                        </div>
                        <div class="stats-value"><?php echo number_format($total_files); ?></div>
                        <div class="stats-description">Tổng số file trong hệ thống</div>
                    </div>
                    
                    <div class="stats-card transform-3d">
                        <div class="stats-header">
                            <h3 class="stats-title">Users</h3>
                            <div class="stats-icon users floating">
                                <i class="fas fa-users"></i>
                            </div>
                        </div>
                        <div class="stats-value"><?php echo number_format($total_users); ?></div>
                        <div class="stats-description">Tổng số người dùng đã đăng ký</div>
                    </div>
                    
                    <div class="stats-card transform-3d">
                        <div class="stats-header">
                            <h3 class="stats-title">Emails</h3>
                            <div class="stats-icon emails floating">
                                <i class="fas fa-envelope"></i>
                            </div>
                        </div>
                        <div class="stats-value"><?php echo number_format($total_emails); ?></div>
                        <div class="stats-description">Tổng số email đã gửi</div>
                    </div>
                </div>
                
                <div class="content-panels">
                    <div class="panel">
                        <div class="panel-header">
                            <h2 class="panel-title"><i class="fas fa-bolt"></i> Truy Cập Nhanh</h2>
                            <div class="panel-actions">
                                <button class="panel-btn"><i class="fas fa-sync-alt"></i></button>
                                <button class="panel-btn"><i class="fas fa-ellipsis-v"></i></button>
                            </div>
                        </div>
                        
                        <div class="quick-actions">
                            <a href="file_manager.php" class="action-card spotlight">
                                <div class="action-icon">
                                    <i class="fas fa-folder"></i>
                                </div>
                                <h3 class="action-title">Quản Lý File</h3>
                            </a>
                            
                            <a href="profile.php" class="action-card spotlight">
                                <div class="action-icon">
                                    <i class="fas fa-user"></i>
                                </div>
                                <h3 class="action-title">Hồ Sơ Cá Nhân</h3>
                            </a>
                            
                            <?php if ($user['is_super_admin'] || $user['is_main_admin']): ?>
                            <a href="send_email.php" class="action-card spotlight">
                                <div class="action-icon">
                                    <i class="fas fa-envelope"></i>
                                </div>
                                <h3 class="action-title">Gửi Email</h3>
                            </a>
                            <?php endif; ?>
                            
                            <a href="/converters" class="action-card spotlight">
                                <div class="action-icon">
                                    <i class="fas fa-cog"></i>
                                </div>
                                <h3 class="action-title">Công Cụ</h3>
                            </a>
                            
                            <a href="/document-review" class="action-card spotlight">
                                <div class="action-icon">
                                    <i class="fas fa-file-alt"></i>
                                </div>
                                <h3 class="action-title">Kiểm Tra Tài Liệu</h3>
                            </a>
                            
                            <a href="/calendar" class="action-card spotlight">
                                <div class="action-icon">
                                    <i class="fas fa-calendar"></i>
                                </div>
                                <h3 class="action-title">Lịch</h3>
                            </a>
                        </div>
                        
                        <div class="panel-header" style="margin-top: 2rem;">
                            <h2 class="panel-title"><i class="fas fa-history"></i> Hoạt Động Gần Đây</h2>
                            <div class="panel-actions">
                                <button class="panel-btn"><i class="fas fa-filter"></i></button>
                                <button class="panel-btn"><i class="fas fa-ellipsis-v"></i></button>
                            </div>
                        </div>
                        
                        <div class="activity-list">
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <i class="fas fa-file-upload"></i>
                                </div>
                                <div class="activity-content">
                                    <h3 class="activity-title">File mới được tải lên</h3>
                                    <p class="activity-description">Một file mới đã được tải lên hệ thống</p>
                                </div>
                                <div class="activity-time">5 phút trước</div>
                            </div>
                            
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <i class="fas fa-user-plus"></i>
                                </div>
                                <div class="activity-content">
                                    <h3 class="activity-title">Người dùng mới đăng ký</h3>
                                    <p class="activity-description">Một người dùng mới đã đăng ký tài khoản</p>
                                </div>
                                <div class="activity-time">1 giờ trước</div>
                            </div>
                            
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <i class="fas fa-envelope"></i>
                                </div>
                                <div class="activity-content">
                                    <h3 class="activity-title">Email đã được gửi</h3>
                                    <p class="activity-description">Một email thông báo đã được gửi đến người dùng</p>
                                </div>
                                <div class="activity-time">3 giờ trước</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="panel">
                        <div class="panel-header">
                            <h2 class="panel-title"><i class="fas fa-bell"></i> Thông Báo</h2>
                            <div class="panel-actions">
                                <button class="panel-btn"><i class="fas fa-check-double"></i></button>
                                <button class="panel-btn"><i class="fas fa-ellipsis-v"></i></button>
                            </div>
                        </div>
                        
                        <div class="notifications-list">
                            <div class="notification-item">
                                <div class="notification-icon">
                                    <i class="fas fa-exclamation-circle"></i>
                                </div>
                                <div class="notification-content">
                                    <h3 class="notification-title">Cập nhật hệ thống</h3>
                                    <p class="notification-description">Hệ thống sẽ được nâng cấp vào ngày mai</p>
                                </div>
                                <div class="notification-time">1 giờ trước</div>
                            </div>
                            
                            <div class="notification-item">
                                <div class="notification-icon">
                                    <i class="fas fa-lock"></i>
                                </div>
                                <div class="notification-content">
                                    <h3 class="notification-title">Bảo mật tài khoản</h3>
                                    <p class="notification-description">Vui lòng cập nhật mật khẩu của bạn</p>
                                </div>
                                <div class="notification-time">1 ngày trước</div>
                            </div>
                            
                            <div class="notification-item">
                                <div class="notification-icon">
                                    <i class="fas fa-star"></i>
                                </div>
                                <div class="notification-content">
                                    <h3 class="notification-title">Tính năng mới</h3>
                                    <p class="notification-description">Khám phá các tính năng mới của hệ thống</p>
                                </div>
                                <div class="notification-time">3 ngày trước</div>
                            </div>
                            
                            <div class="notification-item">
                                <div class="notification-icon">
                                    <i class="fas fa-chart-line"></i>
                                </div>
                                <div class="notification-content">
                                    <h3 class="notification-title">Báo cáo hoạt động</h3>
                                    <p class="notification-description">Báo cáo hoạt động tháng đã sẵn sàng</p>
                                </div>
                                <div class="notification-time">1 tuần trước</div>
                            </div>
                        </div>
                        
                        <div class="panel-header" style="margin-top: 2rem;">
                            <h2 class="panel-title"><i class="fas fa-chart-pie"></i> Thống Kê</h2>
                            <div class="panel-actions">
                                <button class="panel-btn"><i class="fas fa-calendar"></i></button>
                                <button class="panel-btn"><i class="fas fa-ellipsis-v"></i></button>
                            </div>
                        </div>
                        
                        <div id="stats-chart" style="width: 100%; height: 200px; margin-top: 1rem; position: relative;">
                            <div style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; display: flex; flex-direction: column; justify-content: center; align-items: center; gap: 1rem;">
                                <div style="font-size: 1.25rem; font-weight: 600; color: var(--foreground);" class="gradient-text">Dữ liệu thống kê</div>
                                <div style="font-size: 0.875rem; color: var(--foreground-subtle); text-align: center;">Biểu đồ thống kê sẽ hiển thị ở đây.<br>Đang tải dữ liệu...</div>
                                <div class="animated-gradient" style="width: 80%; height: 4px; border-radius: var(--radius-full);"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Tạo hiệu ứng particle
            createParticles();
            
            // Animation cho các phần tử
            animateElements('.stats-card', 100, 'translateY(20px)');
            animateElements('.action-card', 100, 'translateY(20px)');
            animateElements('.activity-item', 100, 'translateX(-20px)');
            animateElements('.notification-item', 100, 'translateX(20px)');
            animateElements('.nav-link', 50, 'translateX(-20px)');
            
            // Hiệu ứng hover cho nav links
            const navLinks = document.querySelectorAll('.nav-link');
            navLinks.forEach(link => {
                link.addEventListener('mouseenter', () => {
                    if (!link.classList.contains('active')) {
                        link.style.transform = 'translateX(4px)';
                    }
                });
                
                link.addEventListener('mouseleave', () => {
                    if (!link.classList.contains('active')) {
                        link.style.transform = 'translateX(0)';
                    }
                });
            });
            
            // Hiệu ứng 3D tilt cho cards
            const cards = document.querySelectorAll('.transform-3d');
            cards.forEach(card => {
                card.addEventListener('mousemove', function(e) {
                    const rect = this.getBoundingClientRect();
                    const x = e.clientX - rect.left;
                    const y = e.clientY - rect.top;
                    
                    const xPercent = (x / rect.width - 0.5) * 10;
                    const yPercent = (y / rect.height - 0.5) * 10;
                    
                    this.style.transform = `perspective(1000px) rotateX(${-yPercent}deg) rotateY(${xPercent}deg) translateZ(10px)`;
                });
                
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'perspective(1000px) rotateX(0) rotateY(0) translateZ(0)';
                });
            });
        });
        
        // Hàm tạo hiệu ứng particle
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
        
        // Hàm animation cho các phần tử
        function animateElements(selector, delay = 100, transform = 'translateY(20px)') {
            const elements = document.querySelectorAll(selector);
            elements.forEach((el, index) => {
                el.style.opacity = '0';
                el.style.transform = transform;
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
<?php
if (isset($conn) && $conn) {
    $conn->close();
}
?>
