<?php
// Bật hiển thị lỗi để dễ debug
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Kết nối database
include 'db_config.php';

// Kiểm tra kết nối
if (mysqli_connect_errno()) {
    die("Kết nối database thất bại: " . mysqli_connect_error());
}

echo "<h1>Kiểm tra và sửa chữa bảng static_pages</h1>";

// Kiểm tra bảng static_pages
$check_table_sql = "SHOW TABLES LIKE 'static_pages'";
$table_exists = mysqli_query($conn, $check_table_sql);

if (mysqli_num_rows($table_exists) == 0) {
    echo "<p>Bảng static_pages chưa tồn tại. Đang tạo bảng mới...</p>";
    
    // Tạo bảng static_pages
    $create_table_sql = "CREATE TABLE static_pages (
        id INT(11) NOT NULL AUTO_INCREMENT,
        folder_name VARCHAR(255) NOT NULL,
        url VARCHAR(255) NOT NULL,
        user_id INT(11) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY unique_folder_user (folder_name, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    
    if (mysqli_query($conn, $create_table_sql)) {
        echo "<p style='color:green'>Tạo bảng static_pages thành công!</p>";
    } else {
        echo "<p style='color:red'>Lỗi khi tạo bảng: " . mysqli_error($conn) . "</p>";
    }
} else {
    echo "<p>Bảng static_pages đã tồn tại. Đang kiểm tra cấu trúc...</p>";
    
    // Kiểm tra cấu trúc bảng
    $check_structure_sql = "DESCRIBE static_pages";
    $structure_result = mysqli_query($conn, $check_structure_sql);
    
    $has_id_column = false;
    $has_primary_key = false;
    $has_auto_increment = false;
    
    echo "<h2>Cấu trúc bảng hiện tại:</h2>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    
    while ($row = mysqli_fetch_assoc($structure_result)) {
        echo "<tr>";
        echo "<td>" . $row['Field'] . "</td>";
        echo "<td>" . $row['Type'] . "</td>";
        echo "<td>" . $row['Null'] . "</td>";
        echo "<td>" . $row['Key'] . "</td>";
        echo "<td>" . $row['Default'] . "</td>";
        echo "<td>" . $row['Extra'] . "</td>";
        echo "</tr>";
        
        if ($row['Field'] == 'id') {
            $has_id_column = true;
            if ($row['Key'] == 'PRI') {
                $has_primary_key = true;
            }
            if ($row['Extra'] == 'auto_increment') {
                $has_auto_increment = true;
            }
        }
    }
    echo "</table>";
    
    // Sửa chữa bảng nếu cần
    if (!$has_id_column) {
        echo "<p>Cột 'id' không tồn tại. Đang thêm cột id...</p>";
        
        $add_id_sql = "ALTER TABLE static_pages ADD COLUMN id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST";
        
        if (mysqli_query($conn, $add_id_sql)) {
            echo "<p style='color:green'>Thêm cột id thành công!</p>";
            $has_id_column = true;
            $has_primary_key = true;
            $has_auto_increment = true;
        } else {
            echo "<p style='color:red'>Lỗi khi thêm cột id: " . mysqli_error($conn) . "</p>";
        }
    } else if (!$has_primary_key || !$has_auto_increment) {
        echo "<p>Cột 'id' tồn tại nhưng không phải là PRIMARY KEY AUTO_INCREMENT. Đang sửa...</p>";
        
        // Xóa khóa chính cũ nếu có
        $drop_primary_sql = "ALTER TABLE static_pages DROP PRIMARY KEY";
        mysqli_query($conn, $drop_primary_sql);
        
        // Sửa cột id
        $modify_id_sql = "ALTER TABLE static_pages MODIFY COLUMN id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY";
        
        if (mysqli_query($conn, $modify_id_sql)) {
            echo "<p style='color:green'>Sửa cột id thành công!</p>";
        } else {
            echo "<p style='color:red'>Lỗi khi sửa cột id: " . mysqli_error($conn) . "</p>";
        }
    }
    
    // Kiểm tra xem có bản ghi nào có id = 0 không
    $check_zero_id_sql = "SELECT * FROM static_pages WHERE id = 0";
    $zero_id_result = mysqli_query($conn, $check_zero_id_sql);
    
    if (mysqli_num_rows($zero_id_result) > 0) {
        echo "<p>Phát hiện bản ghi có id = 0. Đang sửa...</p>";
        
        // Cập nhật id = 0 thành NULL để auto_increment tự động gán giá trị mới
        $update_zero_id_sql = "UPDATE static_pages SET id = NULL WHERE id = 0";
        
        if (mysqli_query($conn, $update_zero_id_sql)) {
            echo "<p style='color:green'>Sửa bản ghi id = 0 thành công!</p>";
        } else {
            echo "<p style='color:red'>Lỗi khi sửa bản ghi id = 0: " . mysqli_error($conn) . "</p>";
        }
    }
    
    // Kiểm tra xem có khóa duy nhất cho folder_name và user_id không
    $has_unique_key = false;
    $check_indexes_sql = "SHOW INDEX FROM static_pages";
    $indexes_result = mysqli_query($conn, $check_indexes_sql);
    
    while ($row = mysqli_fetch_assoc($indexes_result)) {
        if ($row['Key_name'] == 'unique_folder_user') {
            $has_unique_key = true;
            break;
        }
    }
    
    if (!$has_unique_key) {
        echo "<p>Không có khóa duy nhất cho folder_name và user_id. Đang thêm...</p>";
        
        $add_unique_key_sql = "ALTER TABLE static_pages ADD UNIQUE KEY unique_folder_user (folder_name, user_id)";
        
        if (mysqli_query($conn, $add_unique_key_sql)) {
            echo "<p style='color:green'>Thêm khóa duy nhất thành công!</p>";
        } else {
            echo "<p style='color:red'>Lỗi khi thêm khóa duy nhất: " . mysqli_error($conn) . "</p>";
        }
    }
}

// Kiểm tra thư mục static_pages
$static_dir = "site_static/";
if (!file_exists($static_dir)) {
    echo "<p>Thư mục $static_dir chưa tồn tại. Đang tạo...</p>";
    
    if (mkdir($static_dir, 0755, true)) {
        echo "<p style='color:green'>Tạo thư mục $static_dir thành công!</p>";
    } else {
        echo "<p style='color:red'>Không thể tạo thư mục $static_dir. Vui lòng kiểm tra quyền truy cập.</p>";
    }
} else {
    echo "<p>Thư mục $static_dir đã tồn tại.</p>";
    
    if (is_writable($static_dir)) {
        echo "<p style='color:green'>Thư mục $static_dir có quyền ghi.</p>";
    } else {
        echo "<p style='color:red'>Thư mục $static_dir không có quyền ghi. Vui lòng cấp quyền ghi (chmod 755 hoặc 777).</p>";
    }
}

echo "<h2>Kiểm tra hoàn tất!</h2>";
echo "<p><a href='static_page_manager.php'>Quay lại trang quản lý trang tĩnh</a></p>";

mysqli_close($conn);
?>
