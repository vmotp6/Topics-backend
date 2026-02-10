<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Android WebAuthn 診斷工具</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .section {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin: 15px 0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
        }
        h2 {
            color: #1890ff;
            border-bottom: 2px solid #1890ff;
            padding-bottom: 10px;
        }
        .status {
            padding: 12px;
            border-radius: 4px;
            margin: 10px 0;
            font-weight: bold;
        }
        .success {
            background: #f6ffed;
            color: #389e0d;
            border-left: 4px solid #389e0d;
        }
        .warning {
            background: #fffbe6;
            color: #d48806;
            border-left: 4px solid #d48806;
        }
        .error {
            background: #fff1f0;
            color: #cf1322;
            border-left: 4px solid #cf1322;
        }
        .info {
            background: #e6f7ff;
            color: #0050b3;
            border-left: 4px solid #0050b3;
        }
        button {
            padding: 10px 20px;
            background: #1890ff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            margin: 10px 5px 10px 0;
        }
        button:hover {
            background: #0050b3;
        }
        pre {
            background: #f0f0f0;
            padding: 12px;
            border-radius: 4px;
            overflow-x: auto;
            font-size: 12px;
        }
        .feature-list {
            list-style: none;
            padding: 0;
        }
        .feature-list li {
            padding: 8px;
            margin: 5px 0;
            background: #f9f9f9;
            border-left: 3px solid #1890ff;
            padding-left: 12px;
        }
    </style>
</head>
<body>
    <h1>📱 Android WebAuthn 診斷工具</h1>
    
    <div class="section">
        <h2>1. 瀏覽器和設備信息</h2>
        <div id="deviceInfo"></div>
        <button onclick="checkDeviceInfo()">檢查設備信息</button>
    </div>
    
    <div class="section">
        <h2>2. WebAuthn 支持檢查</h2>
        <div id="webauthnSupport"></div>
        <button onclick="checkWebAuthnSupport()">檢查 WebAuthn 支持</button>
    </div>
    
    <div class="section">
        <h2>3. 生物驗證可用性</h2>
        <div id="biometricStatus"></div>
        <button onclick="checkBiometricSupport()">檢查生物驗證</button>
    </div>
    
    <div class="section">
        <h2>4. Android 特定檢查</h2>
        <div id="androidCheck"></div>
        <button onclick="checkAndroidSpecific()">檢查 Android 特性</button>
    </div>
    
    <div class="section">
        <h2>5. 建議和解決方案</h2>
        <div id="recommendations"></div>
    </div>

    <script>
        function checkDeviceInfo() {
            const result = document.getElementById('deviceInfo');
            const info = {
                userAgent: navigator.userAgent,
                platform: navigator.platform,
                hardwareConcurrency: navigator.hardwareConcurrency,
                deviceMemory: navigator.deviceMemory,
                maxTouchPoints: navigator.maxTouchPoints,
                vendor: navigator.vendor,
                language: navigator.language,
                onLine: navigator.onLine
            };
            
            let html = '';
            for (const [key, value] of Object.entries(info)) {
                html += `<p><strong>${key}:</strong> ${value ?? 'N/A'}</p>`;
            }
            
            result.innerHTML = html;
        }
        
        function checkWebAuthnSupport() {
            const result = document.getElementById('webauthnSupport');
            let html = '';
            
            if (window.PublicKeyCredential === undefined) {
                html += '<div class="status error">❌ WebAuthn 不支援</div>';
                html += '<p>此瀏覽器不支援 WebAuthn API。請升級到最新版本的 Chrome、Firefox 或 Edge。</p>';
            } else {
                html += '<div class="status success">✓ WebAuthn 支援</div>';
                
                if (window.PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable) {
                    window.PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable()
                        .then(available => {
                            const status = available ? '✓ 可用' : '✗ 不可用';
                            const className = available ? 'success' : 'warning';
                            document.getElementById('webauthnSupport').innerHTML += 
                                `<div class="status ${className}">平台認證器驗證: ${status}</div>`;
                        });
                } else {
                    html += '<div class="status info">ℹ 無法檢查平台認證器可用性</div>';
                }
                
                if (window.PublicKeyCredential.isConditionalMediationAvailable) {
                    window.PublicKeyCredential.isConditionalMediationAvailable()
                        .then(available => {
                            const status = available ? '✓ 可用' : '✗ 不可用';
                            document.getElementById('webauthnSupport').innerHTML += 
                                `<div class="status ${available ? 'success' : 'warning'}">條件式 Mediation: ${status}</div>`;
                        });
                }
            }
            
            result.innerHTML = html;
        }
        
        function checkBiometricSupport() {
            const result = document.getElementById('biometricStatus');
            let html = '';
            
            const isAndroid = /Android/i.test(navigator.userAgent);
            const isChrome = /Chrome/i.test(navigator.userAgent);
            const isEdge = /Edge/i.test(navigator.userAgent);
            const isFirefox = /Firefox/i.test(navigator.userAgent);
            
            if (isAndroid) {
                html += '<div class="status info">✓ Android 設備偵測</div>';
                
                if (isChrome) {
                    html += '<div class="status success">✓ Chrome 瀏覽器</div>';
                    html += '<p>Chrome 支援 Android 上的生物驗證。</p>';
                } else if (isEdge) {
                    html += '<div class="status success">✓ Edge 瀏覽器</div>';
                    html += '<p>Edge 支援 Android 上的生物驗證。</p>';
                } else if (isFirefox) {
                    html += '<div class="status warning">⚠ Firefox 瀏覽器</div>';
                    html += '<p>Firefox 在某些 Android 版本上支援有限。建議使用 Chrome。</p>';
                } else {
                    html += '<div class="status warning">⚠ 未知瀏覽器</div>';
                    html += '<p>建議使用 Chrome 或 Edge 以獲得最佳支援。</p>';
                }
            } else {
                html += '<div class="status warning">⚠ 非 Android 設備</div>';
                html += '<p>此工具設計用於 Android 設備診斷。</p>';
            }
            
            result.innerHTML = html;
        }
        
        function checkAndroidSpecific() {
            const result = document.getElementById('androidCheck');
            let html = '';
            
            const checks = {
                'Android API 版本': getAndroidApiLevel(),
                '螢幕觸控支援': navigator.maxTouchPoints > 0 ? '✓ 支援' : '✗ 不支援',
                '設備記憶體': navigator.deviceMemory ? `${navigator.deviceMemory} GB` : '未知',
                'GPU 硬體': navigator.hardwareConcurrency ? `${navigator.hardwareConcurrency} 核心` : '未知'
            };
            
            for (const [check, value] of Object.entries(checks)) {
                html += `<p><strong>${check}:</strong> ${value}</p>`;
            }
            
            result.innerHTML = html;
        }
        
        function getAndroidApiLevel() {
            const ua = navigator.userAgent;
            const match = ua.match(/Android (\d+)/);
            return match ? `Android ${match[1]}` : '未知';
        }
        
        function generateRecommendations() {
            const result = document.getElementById('recommendations');
            let html = '<ul class="feature-list">';
            
            const isAndroid = /Android/i.test(navigator.userAgent);
            const isChrome = /Chrome/i.test(navigator.userAgent);
            
            if (!isAndroid) {
                html += '<li>📱 請在 Android 手機上訪問此頁面進行完整診斷</li>';
            }
            
            if (isAndroid && !isChrome) {
                html += '<li>🌐 建議安裝或升級 Chrome 瀏覽器到最新版本</li>';
            }
            
            if (isAndroid) {
                html += '<li>🔐 確保已在手機設定中設定至少一種生物驗證（指紋或臉部辨識）</li>';
                html += '<li>🔄 更新 Google Play 服務到最新版本</li>';
                html += '<li>⚙️ 確保瀏覽器權限允許存取生物驗證功能</li>';
                html += '<li>🌐 確保已啟用「Google Play 中的生物驗證」功能</li>';
            }
            
            html += '<li>🔄 重新啟動瀏覽器後再試一次</li>';
            html += '<li>📲 嘗試在瀏覽器設定中重置站點設定</li>';
            html += '<li>🆘 若問題持續，請在瀏覽器開發者工具中查看錯誤訊息</li>';
            html += '</ul>';
            
            result.innerHTML = html;
        }
        
        // 頁面加載時自動執行檢查
        window.addEventListener('load', () => {
            checkDeviceInfo();
            checkWebAuthnSupport();
            checkBiometricSupport();
            checkAndroidSpecific();
            generateRecommendations();
        });
    </script>
</body>
</html>
