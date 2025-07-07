<?php
// Function to send email notifications using PHPMailer
function sendEmailNotification($to, $subject, $message, $smtp_config) {
    // Check if PHPMailer is installed
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        // If not installed, use the autoloader
        require 'vendor/autoload.php';
    }

    // If autoloader fails, try to include PHPMailer directly
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        require 'PHPMailer/src/Exception.php';
        require 'PHPMailer/src/PHPMailer.php';
        require 'PHPMailer/src/SMTP.php';
    }

    // Import PHPMailer classes
    use PHPMailer\PHPMailer\PHPMailer;
    use PHPMailer\PHPMailer\Exception;
    use PHPMailer\PHPMailer\SMTP;

    // Create a new PHPMailer instance
    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->SMTPDebug = 0;                      // Disable debug output
        $mail->isSMTP();                           // Send using SMTP
        $mail->Host       = $smtp_config['host'];  // SMTP server
        $mail->SMTPAuth   = $smtp_config['smtp_auth']; // Enable SMTP authentication
        $mail->Username   = $smtp_config['username']; // SMTP username
        $mail->Password   = $smtp_config['password']; // SMTP password
        $mail->SMTPSecure = $smtp_config['smtp_secure']; // Enable TLS encryption
        $mail->Port       = $smtp_config['port'];  // TCP port to connect to
        $mail->CharSet    = 'UTF-8';               // Set character encoding

        // Recipients
        $mail->setFrom($smtp_config['from_email'], $smtp_config['from_name']);
        $mail->addAddress($to);                    // Add a recipient

        // Content
        $mail->isHTML(true);                       // Set email format to HTML
        $mail->Subject = $subject;
        $mail->Body    = $message;
        $mail->AltBody = strip_tags($message);     // Plain text version

        // Send the email
        $mail->send();
        
        // Log the email
        logEmailSent($to, $subject, $message);
        
        return true;
    } catch (Exception $e) {
        // Log the error
        logEmailError($to, $subject, $message, $mail->ErrorInfo);
        return false;
    }
}

// Function to log sent emails
function logEmailSent($recipient, $subject, $content) {
    global $conn;
    
    $sql = "INSERT INTO email_logs (recipient, subject, content, status) VALUES (?, ?, ?, 'Sent')";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sss", $recipient, $subject, $content);
    $stmt->execute();
}

// Function to log email errors
function logEmailError($recipient, $subject, $content, $error) {
    global $conn;
    
    $error_message = "Error: " . $error;
    $sql = "INSERT INTO email_logs (recipient, subject, content, status) VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssss", $recipient, $subject, $content, $error_message);
    $stmt->execute();
}
?>
