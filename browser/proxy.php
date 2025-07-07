<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Unauthorized');
}

// Include the ProxyManager class
require_once 'ProxyManager.php';

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');

// Get the target URL
$url = $_GET['url'] ?? '';
$useExternalProxy = $_GET['external'] ?? 'auto'; // auto, yes, no

if (empty($url)) {
    http_response_code(400);
    exit('No URL provided');
}

// Validate URL
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    exit('Invalid URL');
}

// Parse URL to ensure it's HTTP/HTTPS
$parsed = parse_url($url);
if (!in_array($parsed['scheme'] ?? '', ['http', 'https'])) {
    http_response_code(400);
    exit('Only HTTP/HTTPS URLs are allowed');
}

// Security: Block internal/private networks
$ip = gethostbyname($parsed['host']);
if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
    http_response_code(403);
    exit('Access to internal networks is not allowed');
}

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

$shouldUseExternal = false;
if ($useExternalProxy === 'yes') {
    $shouldUseExternal = true;
} elseif ($useExternalProxy === 'auto') {
    $shouldUseExternal = in_array($parsed['host'], $needsExternalProxy);
}

// Initialize response variables
$content = '';
$httpCode = 200;
$contentType = 'text/html';
$proxyUsed = 'direct';

if ($shouldUseExternal) {
    // Use external proxy
    $proxyManager = new ProxyManager();
    
    // Try multiple proxies if first one fails
    $maxAttempts = 3;
    $attempt = 0;
    $success = false;
    
    while ($attempt < $maxAttempts && !$success) {
        $proxy = $proxyManager->getBestProxy($attempt === 0); // Test on first attempt only
        
        if (!$proxy) {
            break;
        }
        
        $result = $proxyManager->requestThroughProxy($url, $proxy);
        
        if ($result['success']) {
            $content = $result['content'];
            $httpCode = $result['http_code'];
            $proxyUsed = $proxy['full'];
            $success = true;
        } else {
            error_log("Proxy attempt $attempt failed: " . $result['error']);
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
    // Use direct connection (original proxy method)
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.5',
            'Accept-Encoding: gzip, deflate',
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
            
            // Forward most headers but skip problematic ones
            $skip_headers = [
                'x-frame-options',
                'content-security-policy',
                'content-encoding',
                'transfer-encoding',
                'connection',
                'keep-alive',
                'upgrade',
                'proxy-authenticate',
                'proxy-authorization'
            ];
            
            if (!in_array($name, $skip_headers)) {
                // Modify location headers to go through proxy
                if ($name === 'location') {
                    $proxy_url = 'proxy.php?url=' . urlencode($value);
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
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    
    if ($content === false) {
        $error = curl_error($ch);
        curl_close($ch);
        http_response_code(500);
        exit("cURL Error: $error");
    }
    
    curl_close($ch);
}

// Set appropriate status code
http_response_code($httpCode);

// Process HTML content
if (strpos($contentType, 'text/html') !== false) {
    // Base URL for relative links
    $baseUrl = $parsed['scheme'] . '://' . $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
    $basePath = dirname($parsed['path'] ?? '/');
    if ($basePath === '.') $basePath = '/';
    
    // Inject JavaScript to handle navigation and show proxy info
    $jsInjection = '
    <script>
    (function() {
        // Show proxy info
        console.log("Loaded via proxy: ' . $proxyUsed . '");
        
        // Create proxy indicator
        const indicator = document.createElement("div");
        indicator.style.cssText = `
            position: fixed;
            top: 10px;
            right: 10px;
            background: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            font-family: monospace;
            font-size: 12px;
            z-index: 999999;
            cursor: pointer;
        `;
        indicator.textContent = "Proxy: ' . ($proxyUsed === 'direct' ? 'Direct' : 'External') . '";
        indicator.onclick = () => indicator.remove();
        
        // Add indicator when page loads
        if (document.readyState === "loading") {
            document.addEventListener("DOMContentLoaded", () => document.body.appendChild(indicator));
        } else {
            document.body.appendChild(indicator);
        }
        
        // Override form submissions
        document.addEventListener("submit", function(e) {
            const form = e.target;
            if (form.tagName === "FORM") {
                e.preventDefault();
                const formData = new FormData(form);
                const method = form.method.toLowerCase() || "get";
                let action = form.action || window.location.href;
                
                // Make action absolute
                if (!action.startsWith("http")) {
                    if (action.startsWith("/")) {
                        action = "' . $baseUrl . '" + action;
                    } else {
                        action = "' . $baseUrl . $basePath . '/" + action;
                    }
                }
                
                const proxyParam = "' . ($shouldUseExternal ? '&external=yes' : '') . '";
                
                if (method === "get") {
                    const params = new URLSearchParams(formData);
                    const separator = action.includes("?") ? "&" : "?";
                    action += separator + params.toString();
                    window.location.href = "proxy.php?url=" + encodeURIComponent(action) + proxyParam;
                } else {
                    // For POST requests, create a form and submit to proxy
                    const proxyForm = document.createElement("form");
                    proxyForm.method = "post";
                    proxyForm.action = "proxy_post.php";
                    
                    const urlInput = document.createElement("input");
                    urlInput.type = "hidden";
                    urlInput.name = "target_url";
                    urlInput.value = action;
                    proxyForm.appendChild(urlInput);
                    
                    if (proxyParam) {
                        const proxyInput = document.createElement("input");
                        proxyInput.type = "hidden";
                        proxyInput.name = "external";
                        proxyInput.value = "yes";
                        proxyForm.appendChild(proxyInput);
                    }
                    
                    for (const [key, value] of formData.entries()) {
                        const input = document.createElement("input");
                        input.type = "hidden";
                        input.name = key;
                        input.value = value;
                        proxyForm.appendChild(input);
                    }
                    
                    document.body.appendChild(proxyForm);
                    proxyForm.submit();
                }
            }
        });
        
        // Override link clicks
        document.addEventListener("click", function(e) {
            const link = e.target.closest("a");
            if (link && link.href && !link.hasAttribute("data-no-proxy")) {
                e.preventDefault();
                let href = link.href;
                
                // Make href absolute
                if (!href.startsWith("http")) {
                    if (href.startsWith("/")) {
                        href = "' . $baseUrl . '" + href;
                    } else {
                        href = "' . $baseUrl . $basePath . '/" + href;
                    }
                }
                
                const proxyParam = "' . ($shouldUseExternal ? '&external=yes' : '') . '";
                window.location.href = "proxy.php?url=" + encodeURIComponent(href) + proxyParam;
            }
        });
        
        // Update base href
        const base = document.createElement("base");
        base.href = "' . $baseUrl . '";
        document.head.insertBefore(base, document.head.firstChild);
        
        // Notify parent about successful load
        if (window.parent !== window) {
            window.parent.postMessage({
                type: "proxy_loaded",
                proxy: "' . $proxyUsed . '",
                url: "' . $url . '"
            }, "*");
        }
    })();
    </script>';
    
    // Insert before closing body tag
    $content = str_ireplace('</body>', $jsInjection . '</body>', $content);
    
    // Fix relative URLs in content
    $content = preg_replace_callback(
        '/(href|src|action)=(["\'])([^"\']*)\2/i',
        function($matches) use ($baseUrl, $basePath, $shouldUseExternal) {
            $attr = $matches[1];
            $quote = $matches[2];
            $url = $matches[3];
            
            // Skip if already absolute URL or data/javascript
            if (preg_match('/^(https?:|data:|javascript:|mailto:|tel:)/', $url)) {
                return $matches[0];
            }
            
            // Convert to absolute URL
            if ($url[0] === '/') {
                $absoluteUrl = $baseUrl . $url;
            } else {
                $absoluteUrl = $baseUrl . $basePath . '/' . $url;
            }
            
            // For links and forms, wrap with proxy
            if (in_array(strtolower($attr), ['href', 'action'])) {
                $proxyParam = $shouldUseExternal ? '&external=yes' : '';
                return $attr . '=' . $quote . 'proxy.php?url=' . urlencode($absoluteUrl) . $proxyParam . $quote;
            }
            
            return $attr . '=' . $quote . $absoluteUrl . $quote;
        },
        $content
    );
}

// Add proxy usage header for debugging
header('X-Proxy-Used: ' . $proxyUsed);

// Output the content
echo $content;
?>