<?php
class ProxyManager {
    private $apiUrl = 'https://api.proxyscrape.com/v4/free-proxy-list/get?request=display_proxies&proxy_format=protocolipport&format=text';
    private $cacheFile = 'proxy_cache.json';
    private $cacheExpiry = 3600; // 1 hour
    private $maxRetries = 3;
    
    public function __construct() {
        // Create cache directory if not exists
        $cacheDir = dirname($this->cacheFile);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
    }
    
    /**
     * Get available proxies from cache or API
     */
    public function getProxies() {
        // Check cache first
        if (file_exists($this->cacheFile)) {
            $cacheData = json_decode(file_get_contents($this->cacheFile), true);
            if ($cacheData && time() - $cacheData['timestamp'] < $this->cacheExpiry) {
                return $cacheData['proxies'];
            }
        }
        
        // Fetch fresh proxies from API
        return $this->fetchProxiesFromAPI();
    }
    
    /**
     * Fetch proxies from ProxyScrape API
     */
    private function fetchProxiesFromAPI() {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ProxyFetcher/1.0)',
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$response) {
            error_log("Failed to fetch proxies from API. HTTP Code: $httpCode");
            return $this->getFallbackProxies();
        }
        
        $proxies = $this->parseProxyList($response);
        
        // Cache the results
        $cacheData = [
            'timestamp' => time(),
            'proxies' => $proxies
        ];
        file_put_contents($this->cacheFile, json_encode($cacheData));
        
        return $proxies;
    }
    
    /**
     * Parse proxy list from API response
     */
    private function parseProxyList($response) {
        $lines = explode("\n", trim($response));
        $proxies = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Parse protocol://ip:port format
            if (preg_match('/^(https?|socks[45]?):\/\/([^:]+):(\d+)$/', $line, $matches)) {
                $proxies[] = [
                    'full' => $line,
                    'protocol' => $matches[1],
                    'ip' => $matches[2],
                    'port' => (int)$matches[3],
                    'status' => 'untested',
                    'speed' => 0,
                    'last_tested' => null
                ];
            }
        }
        
        return $proxies;
    }
    
    /**
     * Get fallback proxies if API fails
     */
    private function getFallbackProxies() {
        return [
            [
                'full' => 'direct',
                'protocol' => 'direct',
                'ip' => 'localhost',
                'port' => 0,
                'status' => 'active',
                'speed' => 100,
                'last_tested' => time()
            ]
        ];
    }
    
    /**
     * Test proxy connectivity
     */
    public function testProxy($proxy, $testUrl = 'http://httpbin.org/ip') {
        $startTime = microtime(true);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $testUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ProxyTester/1.0)',
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        // Set proxy based on protocol
        if ($proxy['protocol'] === 'http' || $proxy['protocol'] === 'https') {
            curl_setopt($ch, CURLOPT_PROXY, $proxy['ip'] . ':' . $proxy['port']);
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
        } elseif (strpos($proxy['protocol'], 'socks') === 0) {
            curl_setopt($ch, CURLOPT_PROXY, $proxy['ip'] . ':' . $proxy['port']);
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        $endTime = microtime(true);
        $speed = round(($endTime - $startTime) * 1000); // milliseconds
        
        $isWorking = ($httpCode === 200 && !$error && $response);
        
        return [
            'working' => $isWorking,
            'speed' => $speed,
            'error' => $error,
            'response_code' => $httpCode
        ];
    }
    
    /**
     * Get best working proxy
     */
    public function getBestProxy($testFirst = true) {
        $proxies = $this->getProxies();
        
        if (!$testFirst) {
            return $proxies[0] ?? null;
        }
        
        $workingProxies = [];
        
        // Test first 10 proxies to find working ones
        $testProxies = array_slice($proxies, 0, 10);
        
        foreach ($testProxies as $proxy) {
            $result = $this->testProxy($proxy);
            if ($result['working']) {
                $proxy['speed'] = $result['speed'];
                $proxy['status'] = 'active';
                $proxy['last_tested'] = time();
                $workingProxies[] = $proxy;
            }
        }
        
        if (empty($workingProxies)) {
            // Return direct connection as fallback
            return $this->getFallbackProxies()[0];
        }
        
        // Sort by speed (fastest first)
        usort($workingProxies, function($a, $b) {
            return $a['speed'] <=> $b['speed'];
        });
        
        return $workingProxies[0];
    }
    
    /**
     * Make HTTP request through proxy
     */
    public function requestThroughProxy($url, $proxy = null, $options = []) {
        if (!$proxy) {
            $proxy = $this->getBestProxy();
        }
        
        $ch = curl_init();
        
        $defaultOptions = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.5',
                'Accept-Encoding: gzip, deflate',
                'DNT: 1',
                'Connection: keep-alive',
                'Upgrade-Insecure-Requests: 1',
            ]
        ];
        
        // Merge with custom options
        $curlOptions = array_replace($defaultOptions, $options);
        
        // Set proxy if not direct connection
        if ($proxy['protocol'] !== 'direct') {
            if ($proxy['protocol'] === 'http' || $proxy['protocol'] === 'https') {
                $curlOptions[CURLOPT_PROXY] = $proxy['ip'] . ':' . $proxy['port'];
                $curlOptions[CURLOPT_PROXYTYPE] = CURLPROXY_HTTP;
            } elseif (strpos($proxy['protocol'], 'socks') === 0) {
                $curlOptions[CURLOPT_PROXY] = $proxy['ip'] . ':' . $proxy['port'];
                $curlOptions[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5;
            }
        }
        
        curl_setopt_array($ch, $curlOptions);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        return [
            'success' => ($httpCode >= 200 && $httpCode < 400 && !$error),
            'http_code' => $httpCode,
            'content' => $response,
            'error' => $error,
            'proxy_used' => $proxy
        ];
    }
}
?>