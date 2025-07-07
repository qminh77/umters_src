<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'ProxyManager.php';

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    $proxyManager = new ProxyManager();
    
    switch ($_POST['action']) {
        case 'get_proxies':
            $proxies = $proxyManager->getProxies();
            echo json_encode(['success' => true, 'proxies' => array_slice($proxies, 0, 20)]);
            exit;
            
        case 'test_proxy':
            $proxyData = json_decode($_POST['proxy'], true);
            $result = $proxyManager->testProxy($proxyData);
            echo json_encode(['success' => true, 'result' => $result]);
            exit;
            
        case 'refresh_proxies':
            // Force refresh by deleting cache
            if (file_exists('proxy_cache.json')) {
                unlink('proxy_cache.json');
            }
            $proxies = $proxyManager->getProxies();
            echo json_encode(['success' => true, 'proxies' => array_slice($proxies, 0, 20)]);
            exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Proxy Status Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #7000FF;
            --primary-light: #9D50FF;
            --primary-dark: #4A00B0;
            --secondary: #00E0FF;
            --secondary-light: #70EFFF;
            --secondary-dark: #00B0C7;
            --accent: #FF3DFF;
            --success: #00E080;
            --warning: #FFB800;
            --danger: #FF3D57;
            --background: #0A0A1A;
            --surface: #12122A;
            --surface-light: #1A1A3A;
            --foreground: #FFFFFF;
            --foreground-muted: rgba(255, 255, 255, 0.7);
            --border: rgba(255, 255, 255, 0.1);
            --shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            --radius: 1.5rem;
            --radius-lg: 2rem;
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
            background-image: 
                radial-gradient(circle at 20% 30%, rgba(112, 0, 255, 0.15) 0%, transparent 40%),
                radial-gradient(circle at 80% 70%, rgba(0, 224, 255, 0.15) 0%, transparent 40%);
        }

        .dashboard-container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 1.5rem;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding: 1.5rem 2rem;
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 700;
            background: linear-gradient(to right, var(--secondary), var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .dashboard-controls {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .control-btn {
            padding: 0.75rem 1.25rem;
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            color: white;
            border: none;
            border-radius: var(--radius);
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .control-btn:hover {
            background: linear-gradient(90deg, var(--primary-light), var(--primary));
            transform: translateY(-2px);
            color: white;
        }

        .control-btn.refresh {
            background: linear-gradient(90deg, var(--secondary), var(--secondary-dark));
        }

        .control-btn.test-all {
            background: linear-gradient(90deg, var(--warning), #e6a700);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            padding: 1.5rem;
            text-align: center;
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: var(--foreground-muted);
            font-size: 0.875rem;
        }

        .proxy-table-container {
            background: rgba(30, 30, 60, 0.5);
            backdrop-filter: blur(20px);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            padding: 1.5rem;
            overflow-x: auto;
        }

        .proxy-table {
            width: 100%;
            border-collapse: collapse;
        }

        .proxy-table th,
        .proxy-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        .proxy-table th {
            background: rgba(255, 255, 255, 0.05);
            font-weight: 600;
            color: var(--foreground);
        }

        .proxy-table td {
            color: var(--foreground-muted);
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 2rem;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-online {
            background: rgba(0, 224, 128, 0.2);
            color: var(--success);
        }

        .status-offline {
            background: rgba(255, 61, 87, 0.2);
            color: var(--danger);
        }

        .status-testing {
            background: rgba(255, 184, 0, 0.2);
            color: var(--warning);
        }

        .status-untested {
            background: rgba(255, 255, 255, 0.1);
            color: var(--foreground-muted);
        }

        .test-btn {
            padding: 0.25rem 0.75rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 0.5rem;
            font-size: 0.75rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .test-btn:hover {
            background: var(--primary-light);
        }

        .test-btn:disabled {
            background: var(--border);
            cursor: not-allowed;
        }

        .loading-spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid var(--border);
            border-top: 2px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .alert {
            position: fixed;
            top: 2rem;
            right: 2rem;
            z-index: 1000;
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            color: white;
            font-weight: 500;
            transform: translateX(100%);
            opacity: 0;
            transition: all 0.3s ease;
        }

        .alert.show {
            transform: translateX(0);
            opacity: 1;
        }

        .alert.success {
            background: var(--success);
        }

        .alert.error {
            background: var(--danger);
        }

        .alert.warning {
            background: var(--warning);
        }

        .security-warning {
            background: rgba(255, 184, 0, 0.1);
            border: 1px solid rgba(255, 184, 0, 0.3);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .security-warning h4 {
            color: var(--warning);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .security-warning ul {
            color: var(--foreground-muted);
            padding-left: 1.5rem;
        }

        .security-warning li {
            margin-bottom: 0.5rem;
        }

        .badge {
            padding: 0.25rem 0.75rem;
            border-radius: 2rem;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .bg-primary {
            background: var(--primary) !important;
        }

        .text-primary {
            color: var(--primary) !important;
        }

        .text-success {
            color: var(--success) !important;
        }

        .text-danger {
            color: var(--danger) !important;
        }

        .text-warning {
            color: var(--warning) !important;
        }

        .text-center {
            text-align: center;
        }

        @media (max-width: 768px) {
            .dashboard-controls {
                flex-direction: column;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .proxy-table-container {
                overflow-x: scroll;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-network-wired"></i>
                Proxy Status Dashboard
            </h1>
            <a href="browser.php" class="control-btn">
                <i class="fas fa-arrow-left"></i>
                Back to Browser
            </a>
        </div>

        <div class="security-warning">
            <h4>
                <i class="fas fa-exclamation-triangle"></i>
                Security Warning - External Proxies
            </h4>
            <ul>
                <li><strong>⚠️ Privacy Risk:</strong> Free public proxies can monitor your traffic and steal sensitive data</li>
                <li><strong>🚫 Never use for:</strong> Banking, passwords, personal information, or confidential business data</li>
                <li><strong>✅ Safe for:</strong> Public information, testing, development, and non-sensitive browsing only</li>
                <li><strong>🛡️ Recommendation:</strong> Use Server Proxy mode for important websites</li>
            </ul>
        </div>

        <div class="dashboard-controls">
            <button class="control-btn refresh" id="refreshBtn">
                <i class="fas fa-sync-alt"></i>
                Refresh Proxies
            </button>
            <button class="control-btn test-all" id="testAllBtn">
                <i class="fas fa-vial"></i>
                Test All Proxies
            </button>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value text-primary" id="totalProxies">0</div>
                <div class="stat-label">Total Proxies</div>
            </div>
            <div class="stat-card">
                <div class="stat-value text-success" id="onlineProxies">0</div>
                <div class="stat-label">Online Proxies</div>
            </div>
            <div class="stat-card">
                <div class="stat-value text-danger" id="offlineProxies">0</div>
                <div class="stat-label">Offline Proxies</div>
            </div>
            <div class="stat-card">
                <div class="stat-value text-warning" id="avgSpeed">0ms</div>
                <div class="stat-label">Average Speed</div>
            </div>
        </div>

        <div class="proxy-table-container">
            <table class="proxy-table">
                <thead>
                    <tr>
                        <th>Protocol</th>
                        <th>IP Address</th>
                        <th>Port</th>
                        <th>Status</th>
                        <th>Speed</th>
                        <th>Last Tested</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="proxyTableBody">
                    <tr>
                        <td colspan="7" class="text-center">
                            <div class="loading-spinner"></div>
                            Loading proxies...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let proxies = [];
        let testing = false;

        document.addEventListener('DOMContentLoaded', function() {
            loadProxies();

            document.getElementById('refreshBtn').addEventListener('click', refreshProxies);
            document.getElementById('testAllBtn').addEventListener('click', testAllProxies);
        });

        async function loadProxies() {
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=get_proxies'
                });

                const data = await response.json();
                if (data.success) {
                    proxies = data.proxies;
                    updateProxyTable();
                    updateStats();
                }
            } catch (error) {
                console.error('Error loading proxies:', error);
                showAlert('Failed to load proxies', 'error');
            }
        }

        async function refreshProxies() {
            const btn = document.getElementById('refreshBtn');
            const icon = btn.querySelector('i');
            
            btn.disabled = true;
            icon.classList.add('fa-spin');

            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=refresh_proxies'
                });

                const data = await response.json();
                if (data.success) {
                    proxies = data.proxies;
                    updateProxyTable();
                    updateStats();
                    showAlert('Proxies refreshed successfully', 'success');
                }
            } catch (error) {
                console.error('Error refreshing proxies:', error);
                showAlert('Failed to refresh proxies', 'error');
            } finally {
                btn.disabled = false;
                icon.classList.remove('fa-spin');
            }
        }

        async function testProxy(index) {
            const proxy = proxies[index];
            const row = document.querySelector(`tr[data-index="${index}"]`);
            const statusCell = row.querySelector('.status-cell');
            const speedCell = row.querySelector('.speed-cell');
            const testBtn = row.querySelector('.test-btn');

            testBtn.disabled = true;
            statusCell.innerHTML = '<span class="status-badge status-testing">Testing...</span>';
            speedCell.textContent = '-';

            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=test_proxy&proxy=${encodeURIComponent(JSON.stringify(proxy))}`
                });

                const data = await response.json();
                if (data.success) {
                    const result = data.result;
                    proxy.status = result.working ? 'active' : 'offline';
                    proxy.speed = result.speed;
                    proxy.last_tested = new Date().toISOString();

                    const statusClass = result.working ? 'status-online' : 'status-offline';
                    const statusText = result.working ? 'Online' : 'Offline';
                    statusCell.innerHTML = `<span class="status-badge ${statusClass}">${statusText}</span>`;
                    speedCell.textContent = result.working ? `${result.speed}ms` : 'N/A';

                    updateStats();
                }
            } catch (error) {
                console.error('Error testing proxy:', error);
                statusCell.innerHTML = '<span class="status-badge status-offline">Error</span>';
            } finally {
                testBtn.disabled = false;
            }
        }

        async function testAllProxies() {
            if (testing) return;

            testing = true;
            const btn = document.getElementById('testAllBtn');
            const icon = btn.querySelector('i');
            
            btn.disabled = true;
            icon.classList.add('fa-spin');

            showAlert('Testing all proxies...', 'warning');

            for (let i = 0; i < Math.min(proxies.length, 10); i++) {
                await testProxy(i);
                // Small delay to prevent overwhelming the server
                await new Promise(resolve => setTimeout(resolve, 500));
            }

            testing = false;
            btn.disabled = false;
            icon.classList.remove('fa-spin');
            showAlert('Proxy testing completed', 'success');
        }

        function updateProxyTable() {
            const tbody = document.getElementById('proxyTableBody');
            tbody.innerHTML = '';

            if (proxies.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="7" class="text-center">
                            <i class="fas fa-exclamation-triangle text-warning"></i>
                            No proxies available. Try refreshing.
                        </td>
                    </tr>
                `;
                return;
            }

            proxies.forEach((proxy, index) => {
                const row = document.createElement('tr');
                row.setAttribute('data-index', index);

                const statusClass = proxy.status === 'active' ? 'status-online' : 
                                  proxy.status === 'offline' ? 'status-offline' : 'status-untested';
                const statusText = proxy.status === 'active' ? 'Online' : 
                                 proxy.status === 'offline' ? 'Offline' : 'Untested';

                const lastTested = proxy.last_tested ? 
                    new Date(proxy.last_tested * 1000).toLocaleString() : 'Never';

                const protocolColor = proxy.protocol === 'http' ? 'primary' : 
                                    proxy.protocol === 'https' ? 'success' : 'warning';

                row.innerHTML = `
                    <td><span class="badge bg-${protocolColor}">${proxy.protocol.toUpperCase()}</span></td>
                    <td><code>${proxy.ip}</code></td>
                    <td>${proxy.port}</td>
                    <td class="status-cell">
                        <span class="status-badge ${statusClass}">${statusText}</span>
                    </td>
                    <td class="speed-cell">${proxy.speed ? proxy.speed + 'ms' : 'N/A'}</td>
                    <td>${lastTested}</td>
                    <td>
                        <button class="test-btn" onclick="testProxy(${index})">
                            <i class="fas fa-vial"></i> Test
                        </button>
                    </td>
                `;

                tbody.appendChild(row);
            });
        }

        function updateStats() {
            const total = proxies.length;
            const online = proxies.filter(p => p.status === 'active').length;
            const offline = proxies.filter(p => p.status === 'offline').length;
            
            const activeSpeeds = proxies.filter(p => p.status === 'active' && p.speed).map(p => p.speed);
            const avgSpeed = activeSpeeds.length > 0 ? 
                Math.round(activeSpeeds.reduce((a, b) => a + b, 0) / activeSpeeds.length) : 0;

            document.getElementById('totalProxies').textContent = total;
            document.getElementById('onlineProxies').textContent = online;
            document.getElementById('offlineProxies').textContent = offline;
            document.getElementById('avgSpeed').textContent = avgSpeed + 'ms';
        }

        function showAlert(message, type) {
            const alert = document.createElement('div');
            alert.className = `alert ${type}`;
            alert.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'exclamation-triangle'}"></i>
                ${message}
            `;
            document.body.appendChild(alert);

            setTimeout(() => alert.classList.add('show'), 100);
            setTimeout(() => {
                alert.classList.remove('show');
                setTimeout(() => alert.remove(), 300);
            }, 3000);
        }

        // Auto-refresh every 5 minutes
        setInterval(() => {
            if (!testing) {
                loadProxies();
            }
        }, 300000);
    </script>
</body>
</html>