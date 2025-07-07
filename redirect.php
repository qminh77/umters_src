<?php
include 'db_config.php';

if (isset($_GET['code'])) {
    $short_code = mysqli_real_escape_string($conn, $_GET['code']);
    
    $sql = "SELECT * FROM short_links WHERE short_code = '$short_code'";
    $result = mysqli_query($conn, $sql);
    
    if (mysqli_num_rows($result) > 0) {
        $link = mysqli_fetch_assoc($result);
        
        // Kiểm tra ngày hết hạn
        if ($link['expiry_time'] && strtotime($link['expiry_time']) < time()) {
            die("Shortlink đã hết hạn!");
        }
        
        // Kiểm tra mật khẩu
        if ($link['password']) {
            if (!isset($_POST['password'])) {
                echo '<form method="POST">
                        <label>Nhập mật khẩu:</label>
                        <input type="password" name="password" required>
                        <button type="submit">Xác nhận</button>
                      </form>';
                exit;
            } else {
                if (!password_verify($_POST['password'], $link['password'])) {
                    die("Mật khẩu không đúng!");
                }
            }
        }
        
        // Ghi log truy cập
        $ip = $_SERVER['REMOTE_ADDR'];
        $user_agent = $_SERVER['HTTP_USER_AGENT'];
        $sql_log = "INSERT INTO short_link_logs (short_link_id, ip_address, user_agent, access_time) 
                    VALUES ({$link['id']}, '$ip', '$user_agent', NOW())";
        mysqli_query($conn, $sql_log);
        
        // Chuyển hướng
        header("Location: " . $link['original_url']);
        exit;
    } else {
        die("Shortlink không tồn tại!");
    }
}
mysqli_close($conn);
?>