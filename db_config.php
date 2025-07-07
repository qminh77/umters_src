<?php
$host = "localhost";
$user = "";
$pass = "";
$dbname = "";

$conn = mysqli_connect($host, $user, $pass, $dbname);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Tạo bảng users nếu chưa có
$sql_users = "CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    is_main_admin TINYINT(1) DEFAULT 0,
    is_super_admin TINYINT(1) DEFAULT 0,
    phone VARCHAR(15) DEFAULT NULL,
    email VARCHAR(100) DEFAULT NULL,
    full_name VARCHAR(100) DEFAULT NULL,
    class VARCHAR(50) DEFAULT NULL,
    address VARCHAR(255) DEFAULT NULL
)";
mysqli_query($conn, $sql_users);

// Tạo bảng user_files nếu chưa có
$sql_user_files = "CREATE TABLE IF NOT EXISTS user_files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    filename VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    upload_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
)";
mysqli_query($conn, $sql_user_files) or die("Error creating user_files: " . mysqli_error($conn));

// Tạo bảng spin_wheel_items
$sql_spin_wheel = "CREATE TABLE IF NOT EXISTS spin_wheel_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    content VARCHAR(100) NOT NULL,
    percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_active TINYINT(1) DEFAULT 1
)";
mysqli_query($conn, $sql_spin_wheel) or die("Error creating spin_wheel_items: " . mysqli_error($conn));

// Tạo bảng settings
$sql_settings = "CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(50) NOT NULL UNIQUE,
    setting_value VARCHAR(255) NOT NULL
)";
mysqli_query($conn, $sql_settings) or die("Error creating settings: " . mysqli_error($conn));


// Thêm setting mặc định
$check_setting = mysqli_query($conn, "SELECT * FROM settings WHERE setting_key='delete_after_spin'");
if (mysqli_num_rows($check_setting) == 0) {
    mysqli_query($conn, "INSERT INTO settings (setting_key, setting_value) VALUES ('delete_after_spin', '1')") 
        or die("Error inserting setting: " . mysqli_error($conn));
}


// Tạo bảng files nếu chưa có
$sql_files = "CREATE TABLE IF NOT EXISTS files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    upload_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    user_agent VARCHAR(255) DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL
)";
mysqli_query($conn, $sql_files);

// Tạo bảng email_logs nếu chưa có
$sql_email_logs = "CREATE TABLE IF NOT EXISTS email_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recipient VARCHAR(100) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    send_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(50) DEFAULT 'Sent'
)";
mysqli_query($conn, $sql_email_logs);

// Tạo bảng qr_codes với cột expiry_time
$sql_qr_codes = "CREATE TABLE IF NOT EXISTS qr_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    qr_type VARCHAR(50) NOT NULL,
    qr_data TEXT NOT NULL,
    qr_path VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expiry_time DATETIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
)";
mysqli_query($conn, $sql_qr_codes);

// Thêm Main Admin mẫu nếu chưa có
$check_main = mysqli_query($conn, "SELECT * FROM users WHERE username='admin'");
if (mysqli_num_rows($check_main) == 0) {
    $hashed_password_main = password_hash('admin123', PASSWORD_DEFAULT);
    mysqli_query($conn, "INSERT INTO users (username, password, is_main_admin) VALUES ('admin', '$hashed_password_main', 1)");
}

// Tạo bảng short_links nếu chưa có
$sql_short_links = "CREATE TABLE IF NOT EXISTS short_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    short_code VARCHAR(50) NOT NULL UNIQUE,
    original_url TEXT NOT NULL,
    preview_image VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
)";
mysqli_query($conn, $sql_short_links);

// Thêm Super Admin mẫu nếu chưa có
$check_super = mysqli_query($conn, "SELECT * FROM users WHERE username='superadmin'");
if (mysqli_num_rows($check_super) == 0) {
    $hashed_password_super = password_hash('superadmin123', PASSWORD_DEFAULT);
    mysqli_query($conn, "INSERT INTO users (username, password, is_super_admin) VALUES ('superadmin', '$hashed_password_super', 1)");
}

// Tạo bảng short_links nếu chưa có
$sql_short_links = "CREATE TABLE IF NOT EXISTS short_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    short_code VARCHAR(50) NOT NULL UNIQUE,
    original_url TEXT NOT NULL,
    password VARCHAR(255) DEFAULT NULL,
    expiry_time DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
)";
mysqli_query($conn, $sql_short_links) or die("Error creating short_links: " . mysqli_error($conn));

// Tạo bảng short_link_logs nếu chưa có
$sql_short_link_logs = "CREATE TABLE IF NOT EXISTS short_link_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    short_link_id INT NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    access_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (short_link_id) REFERENCES short_links(id)
)";
mysqli_query($conn, $sql_short_link_logs) or die("Error creating short_link_logs: " . mysqli_error($conn));

// Tạo bảng remember_tokens nếu chưa tồn tại
$sql = "CREATE TABLE IF NOT EXISTS remember_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)";
mysqli_query($conn, $sql);

// Tạo bảng quiz_sets nếu chưa có
$sql_quiz_sets = "CREATE TABLE IF NOT EXISTS quiz_sets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    share_token VARCHAR(255),
    shuffle_options TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)";
mysqli_query($conn, $sql_quiz_sets);

// Tạo bảng quiz_questions nếu chưa có
$sql_quiz_questions = "CREATE TABLE IF NOT EXISTS quiz_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quiz_id INT NOT NULL,
    question TEXT NOT NULL,
    option1 TEXT NOT NULL,
    option2 TEXT NOT NULL,
    option3 TEXT NOT NULL,
    option4 TEXT NOT NULL,
    is_multiple_choice TINYINT(1) DEFAULT 0,
    correct_answers VARCHAR(10) DEFAULT NULL,
    correct_answer INT DEFAULT NULL,
    explanation TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (quiz_id) REFERENCES quiz_sets(id) ON DELETE CASCADE
)";
mysqli_query($conn, $sql_quiz_questions);

// Tạo bảng teleprompter_scripts nếu chưa tồn tại
$create_teleprompter_table = "CREATE TABLE IF NOT EXISTS teleprompter_scripts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    script_name VARCHAR(255) NOT NULL,
    script_content TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)";

if (!$conn->query($create_teleprompter_table)) {
    die("Lỗi khi tạo bảng teleprompter_scripts: " . $conn->error);
}

// Thiết lập UTF-8 cho kết nối
mysqli_set_charset($conn, "utf8mb4");
// Cấu hình SMTP với hỗ trợ UTF-8
$smtp_config = [
    'host' => 'smtp.hostinger.com',
    'smtp_auth' => true,
    'username' => '',
    'password' => '',
    'smtp_secure' => 'tls',
    'port' => 587,
    'from_email' => '',
    'from_name' => mb_convert_encoding('Umters Team', 'UTF-8')
];

// // Tạo bảng cấu hình SMTP
// $sql = "CREATE TABLE IF NOT EXISTS smtp_configs (
//     id INT AUTO_INCREMENT PRIMARY KEY,
//     user_id INT NOT NULL,
//     smtp_name VARCHAR(255) NOT NULL,
//     smtp_host VARCHAR(255) NOT NULL,
//     smtp_port INT NOT NULL,
//     smtp_username VARCHAR(255) NOT NULL,
//     smtp_password VARCHAR(255) NOT NULL,
//     smtp_encryption VARCHAR(10) NOT NULL,
//     from_name VARCHAR(255) NOT NULL,
//     from_email VARCHAR(255) NOT NULL,
//     is_default BOOLEAN DEFAULT FALSE,
//     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
//     updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
//     FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
// ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

// if (!$conn->query($sql)) {
//     die("Error creating smtp_configs table: " . $conn->error);
// }

// // Tạo bảng lịch sử email
// $sql = "CREATE TABLE IF NOT EXISTS email_history (
//     id INT AUTO_INCREMENT PRIMARY KEY,
//     user_id INT NOT NULL,
//     smtp_config_id INT NOT NULL,
//     recipient_email VARCHAR(255) NOT NULL,
//     subject VARCHAR(255) NOT NULL,
//     body TEXT NOT NULL,
//     status ENUM('sent', 'failed') NOT NULL,
//     error_message TEXT,
//     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
//     FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
//     FOREIGN KEY (smtp_config_id) REFERENCES smtp_configs(id) ON DELETE CASCADE
// ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

// if (!$conn->query($sql)) {
//     die("Error creating email_history table: " . $conn->error);
// }

// // Tạo bảng email_drafts
// $sql = "CREATE TABLE IF NOT EXISTS email_drafts (
//     id INT AUTO_INCREMENT PRIMARY KEY,
//     user_id INT NOT NULL,
//     smtp_config_id INT,
//     recipient_email VARCHAR(255),
//     subject VARCHAR(255),
//     body TEXT,
//     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
//     updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
//     FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
//     FOREIGN KEY (smtp_config_id) REFERENCES smtp_configs(id) ON DELETE SET NULL
// ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

// if (!$conn->query($sql)) {
//     die("Error creating email_drafts table: " . $conn->error);
// }

// // Tạo bảng email_attachments
// $sql = "CREATE TABLE IF NOT EXISTS email_attachments (
//     id INT AUTO_INCREMENT PRIMARY KEY,
//     email_id INT NOT NULL,
//     file_name VARCHAR(255) NOT NULL,
//     file_path VARCHAR(255) NOT NULL,
//     file_size INT NOT NULL,
//     mime_type VARCHAR(100) NOT NULL,
//     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
//     FOREIGN KEY (email_id) REFERENCES email_history(id) ON DELETE CASCADE
// ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

// if (!$conn->query($sql)) {
//     die("Error creating email_attachments table: " . $conn->error);
// }

// // Tạo bảng email_inbox
// $sql = "CREATE TABLE IF NOT EXISTS email_inbox (
//     id INT AUTO_INCREMENT PRIMARY KEY,
//     user_id INT NOT NULL,
//     smtp_config_id INT NOT NULL,
//     sender_email VARCHAR(255) NOT NULL,
//     sender_name VARCHAR(255),
//     subject VARCHAR(255) NOT NULL,
//     body TEXT NOT NULL,
//     is_read BOOLEAN DEFAULT FALSE,
//     is_starred BOOLEAN DEFAULT FALSE,
//     is_important BOOLEAN DEFAULT FALSE,
//     is_hidden BOOLEAN DEFAULT FALSE,
//     received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
//     FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
//     FOREIGN KEY (smtp_config_id) REFERENCES smtp_configs(id) ON DELETE CASCADE
// ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

// if (!$conn->query($sql)) {
//     die("Error creating email_inbox table: " . $conn->error);
// }

// // Tạo bảng email_inbox_attachments
// $sql = "CREATE TABLE IF NOT EXISTS email_inbox_attachments (
//     id INT AUTO_INCREMENT PRIMARY KEY,
//     email_id INT NOT NULL,
//     file_name VARCHAR(255) NOT NULL,
//     file_path VARCHAR(255) NOT NULL,
//     file_size INT NOT NULL,
//     mime_type VARCHAR(100) NOT NULL,
//     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
//     FOREIGN KEY (email_id) REFERENCES email_inbox(id) ON DELETE CASCADE
// ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

// if (!$conn->query($sql)) {
//     die("Error creating email_inbox_attachments table: " . $conn->error);
// }

// // Cập nhật bảng email_history
// $sql = "ALTER TABLE email_history 
//     ADD COLUMN is_starred BOOLEAN DEFAULT FALSE,
//     ADD COLUMN is_important BOOLEAN DEFAULT FALSE,
//     ADD COLUMN is_hidden BOOLEAN DEFAULT FALSE,
//     ADD COLUMN is_deleted BOOLEAN DEFAULT FALSE";

if (!$conn->query($sql)) {
    // Bỏ qua lỗi nếu cột đã tồn tại
    if ($conn->errno != 1060) {
        die("Error updating email_history table: " . $conn->error);
    }
}

?>