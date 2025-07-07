<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Unauthorized');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

// Include the ProxyManager class
require_once 'ProxyManager.php';

// Get target URL and external proxy preference
$targetUrl = $_POST['target_url'] ?? '';
$useExternalProxy = $_POST['external'] ?? 'auto';

if (empty($targetUrl)) {
    http_response_code(400);
    exit('No target URL provided');
}

// Validate URL
if (!filter_var($targetUrl, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    exit('Invalid URL');
}

// Remove system parameters from POST data
unset($_POST['target_url']);
unset($_POST['external']);

// Websites that typically need external proxies
$needsExternalProxy = [
    'login.microsoftonline.com',
    'accounts.google.com',
    'www.facebook.com',
    'login.yahoo.com',
    'signin.aws.amazon.com',
    'github.com',
    'twitter.com',
    'x.com',
    'linkedin.com',
    'instagram.com'
];

$parsed = parse_url($targetUrl);
$shouldUseExternal = false;

if ($useExternalProxy === 'yes') {
    $shouldUseExternal = true;
} elseif ($useExternalProxy === 'auto') {
    $shouldUseExternal = in_array($parsed['host'], $needsExternalProxy);
}

// Prepare POST data
$postData = http_build_query($_POST);

$content = '';
$httpCode = 200;
$redirectUrl = '';
$proxyUsed = 'direct';

if ($shouldUseExternal) {
    // Use external proxy for POST request
    $proxyManager = new ProxyManager();
    
    $maxAttempts = 3;
    $attempt = 0;
    $success = false;
    
    while ($attempt < $maxAttempts && !$success) {
        $proxy = $proxyManager->getBestProxy($attempt === 0);
        
        if (!$proxy) {
            break;
        }
        
        $result = $proxyManager->requestThroughProxy($targetUrl, $proxy, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.5',
                'DNT: 1',
                'Connection: keep-alive',
                'Upgrade-Insecure-Requests: 1',
            ]
        ]);
        
        if ($result['success']) {
            $content = $result['content'];
            $httpCode = $result['http_code'];
            $proxyUsed = $proxy['full'];
            $success = true;
        } else {
            error_log("External proxy POST attempt $attempt failed: " . $result['error']);
            $attempt++;
        }
    }
    
    if (!$success) {
        // Fallback to direct connection
        $shouldUseExternal = false;
        $proxyUsed = 'direct_fallback';
    }
}

if (!$shouldUseExternal) {
    // Use direct connection (original method)
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $targetUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.5',
            'DNT: 1',
            'Connection: keep-alive',
            'Upgrade-Insecure-Requests: 1',
        ],
        CURLOPT_HEADERFUNCTION => function($curl, $header) {
            $length = strlen($header);
            $header = explode(':', $header, 2);
            
            if (count($header) < 2) return $length;
            
            $name = strtolower(trim($header[0]));
            $value = trim($header[1]);
            
            // Forward headers but skip problematic ones
            $skip_headers = [
                'x-frame-options',
                'content-security-policy',
                'content-encoding',
                'transfer-encoding',
                'connection',
                'keep-alive',
                'upgrade'
            ];
            
            if (!in_array($name, $skip_headers)) {
                if ($name === 'location') {
                    // Redirect through proxy
                    global $shouldUseExternal;
                    $proxyParam = $shouldUseExternal ? '&external=yes' : '';
                    $proxy_url = 'proxy.php?url=' . urlencode($value) . $proxyParam;
                    header("Location: $proxy_url");
                } else {
                    header("$name: $value");
                }
            }
            
            return $length;
        }
    ]);
    
    // Execute request
    $content = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    
    if ($content === false) {
        $error = curl_error($ch);
        curl_close($ch);
        http_response_code(500);
        exit("cURL Error: $error");
    }
    
    curl_close($ch);
}

// Handle redirects
if ($httpCode >= 300 && $httpCode < 400 && $redirectUrl) {
    $proxyParam = $shouldUseExternal ? '&external=yes' : '';
    header("Location: proxy.php?url=" . urlencode($redirectUrl) . $proxyParam);
    exit;
}

// Set status code
http_response_code($httpCode);

// Add proxy usage header for debugging
header('X-Proxy-Used: ' . $proxyUsed);

// If it's HTML, process it through proxy
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?? '';
if (strpos($contentType, 'text/html') !== false) {
    // Redirect to GET proxy for HTML content
    $proxyParam = $shouldUseExternal ? '&external=yes' : '';
    header("Location: proxy.php?url=" . urlencode($targetUrl) . $proxyParam);
    exit;
}

// Output the content for non-HTML responses
echo $content;
?>