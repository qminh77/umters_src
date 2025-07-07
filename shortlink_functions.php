<?php
require_once 'db_config.php';

class ShortLink {
    private $conn;
    private $base_url = "https://umters.club/";

    public function __construct($conn) {
        $this->conn = $conn;
    }

    private function generateRandomSlug($length = 6) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $slug = '';
        for ($i = 0; $i < $length; $i++) {
            $slug .= $characters[rand(0, strlen($characters) - 1)];
        }
        return $slug;
    }

    private function isSlugExists($slug) {
        $stmt = $this->conn->prepare("SELECT id FROM short_links WHERE short_code = ?");
        if (!$stmt) {
            error_log("Prepare failed: " . $this->conn->error);
            return false;
        }
        $stmt->bind_param("s", $slug);
        $stmt->execute();
        $result = $stmt->get_result();
        $exists = $result->num_rows > 0;
        $stmt->close();
        return $exists;
    }

    public function createShortLink($user_id, $original_url, $custom_slug = null, $password = null, $expiry_time = null) {
        if (!filter_var($original_url, FILTER_VALIDATE_URL)) {
            return ['status' => 'error', 'message' => 'URL không hợp lệ'];
        }

        if ($custom_slug) {
            if (!preg_match('/^[a-zA-Z0-9-]+$/', $custom_slug)) {
                return ['status' => 'error', 'message' => 'Custom slug chứa ký tự không hợp lệ'];
            }
            if ($this->isSlugExists($custom_slug)) {
                return ['status' => 'error', 'message' => 'Custom slug đã tồn tại'];
            }
            $slug = $custom_slug;
        } else {
            do {
                $slug = $this->generateRandomSlug();
            } while ($this->isSlugExists($slug));
        }

        $hashed_password = $password ? password_hash($password, PASSWORD_DEFAULT) : null;

        $stmt = $this->conn->prepare(
            "INSERT INTO short_links (user_id, short_code, original_url, password, expiry_time) 
            VALUES (?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            error_log("Prepare failed: " . $this->conn->error);
            return ['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu: Không thể chuẩn bị truy vấn'];
        }
        $stmt->bind_param("issss", $user_id, $slug, $original_url, $hashed_password, $expiry_time);

        if ($stmt->execute()) {
            $stmt->close();
            return [
                'status' => 'success',
                'short_url' => $this->base_url . $slug,
                'slug' => $slug
            ];
        } else {
            $error = $stmt->error;
            $stmt->close();
            error_log("Execute failed: " . $error);
            return ['status' => 'error', 'message' => 'Không thể tạo short link: ' . $error];
        }
    }

    public function redirect($slug) {
        $stmt = $this->conn->prepare(
            "SELECT original_url, password, expiry_time 
            FROM short_links 
            WHERE short_code = ? AND (expiry_time IS NULL OR expiry_time > NOW())"
        );
        if (!$stmt) {
            error_log("Prepare failed: " . $this->conn->error);
            return ['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'];
        }
        $stmt->bind_param("s", $slug);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $stmt->close();
            return ['status' => 'error', 'message' => 'Short link không tồn tại hoặc đã hết hạn'];
        }

        $link = $result->fetch_assoc();
        $stmt->close();

        // Kiểm tra mật khẩu chỉ khi password không null và không rỗng
        if (!empty($link['password'])) {
            return ['status' => 'password_required', 'message' => 'Yêu cầu mật khẩu'];
        }

        $this->logAccess($slug);
        return [
            'status' => 'success',
            'original_url' => $link['original_url']
        ];
    }

    private function logAccess($slug) {
        $short_link_id = $this->getShortLinkId($slug);
        if (!$short_link_id) {
            return;
        }
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $stmt = $this->conn->prepare(
            "INSERT INTO short_link_logs (short_link_id, ip_address, user_agent) 
            VALUES (?, ?, ?)"
        );
        if (!$stmt) {
            error_log("Prepare failed: " . $this->conn->error);
            return;
        }
        $stmt->bind_param("iss", $short_link_id, $ip_address, $user_agent);
        $stmt->execute();
        $stmt->close();
    }

    private function getShortLinkId($slug) {
        $stmt = $this->conn->prepare("SELECT id FROM short_links WHERE short_code = ?");
        if (!$stmt) {
            error_log("Prepare failed: " . $this->conn->error);
            return null;
        }
        $stmt->bind_param("s", $slug);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        return $row ? $row['id'] : null;
    }

    public function getShortLinks($user_id, $search = null) {
        $query = "SELECT id, short_code, original_url, created_at, 
                        (SELECT COUNT(*) FROM short_link_logs WHERE short_link_id = short_links.id) as click_count 
                  FROM short_links 
                  WHERE user_id = ?";
        
        if ($search) {
            $query .= " AND (short_code LIKE ? OR original_url LIKE ?)";
            $search = "%$search%";
        }

        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            error_log("Prepare failed: " . $this->conn->error);
            return [];
        }
        
        if ($search) {
            $stmt->bind_param("iss", $user_id, $search, $search);
        } else {
            $stmt->bind_param("i", $user_id);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $links = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $links;
    }

    public function deleteShortLink($user_id, $short_link_id) {
        // First delete all related logs
        $stmt = $this->conn->prepare(
            "DELETE FROM short_link_logs WHERE short_link_id = ?"
        );
        if (!$stmt) {
            error_log("Prepare failed: " . $this->conn->error);
            return ['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'];
        }
        $stmt->bind_param("i", $short_link_id);
        $stmt->execute();
        $stmt->close();

        // Then delete the short link
        $stmt = $this->conn->prepare(
            "DELETE FROM short_links WHERE id = ? AND user_id = ?"
        );
        if (!$stmt) {
            error_log("Prepare failed: " . $this->conn->error);
            return ['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'];
        }
        $stmt->bind_param("ii", $short_link_id, $user_id);
        
        if ($stmt->execute()) {
            $stmt->close();
            return ['status' => 'success', 'message' => 'Short link đã được xóa'];
        }
        $error = $stmt->error;
        $stmt->close();
        return ['status' => 'error', 'message' => 'Không thể xóa short link: ' . $error];
    }

    public function updateShortLink($user_id, $short_link_id, $original_url, $password = null, $expiry_time = null) {
        if (!filter_var($original_url, FILTER_VALIDATE_URL)) {
            return ['status' => 'error', 'message' => 'URL không hợp lệ'];
        }

        $hashed_password = $password ? password_hash($password, PASSWORD_DEFAULT) : null;
        
        $stmt = $this->conn->prepare(
            "UPDATE short_links 
            SET original_url = ?, password = ?, expiry_time = ? 
            WHERE id = ? AND user_id = ?"
        );
        if (!$stmt) {
            error_log("Prepare failed: " . $this->conn->error);
            return ['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'];
        }
        $stmt->bind_param("sssii", $original_url, $hashed_password, $expiry_time, $short_link_id, $user_id);
        
        if ($stmt->execute()) {
            $stmt->close();
            return ['status' => 'success', 'message' => 'Short link đã được cập nhật'];
        }
        $error = $stmt->error;
        $stmt->close();
        return ['status' => 'error', 'message' => 'Không thể cập nhật short link: ' . $error];
    }

    public function verifyPassword($slug, $password) {
        $stmt = $this->conn->prepare(
            "SELECT password, original_url 
            FROM short_links 
            WHERE short_code = ?"
        );
        if (!$stmt) {
            error_log("Prepare failed: " . $this->conn->error);
            return ['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'];
        }
        $stmt->bind_param("s", $slug);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $stmt->close();
            return ['status' => 'error', 'message' => 'Short link không tồn tại'];
        }

        $link = $result->fetch_assoc();
        $stmt->close();
        
        error_log("Debug: slug=$slug, input_password=$password, stored_password=" . ($link['password'] ?? 'null'));
        if (!empty($link['password']) && password_verify($password, $link['password'])) {
            $this->logAccess($slug);
            return [
                'status' => 'success',
                'original_url' => $link['original_url']
            ];
        }
        return ['status' => 'error', 'message' => 'Mật khẩu không đúng hoặc không yêu cầu mật khẩu'];
    }
}
?>