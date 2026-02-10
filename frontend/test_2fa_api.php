<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>2FA API 測試</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
        }
        .test-section {
            border: 1px solid #ddd;
            padding: 15px;
            margin: 15px 0;
            background: #f9f9f9;
        }
        .test-result {
            margin-top: 10px;
            padding: 10px;
            border-radius: 4px;
        }
        .success {
            background: #d4edda;
            color: #155724;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
        }
        button {
            padding: 8px 16px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        button:hover {
            background: #0056b3;
        }
        pre {
            background: #f4f4f4;
            padding: 10px;
            overflow-x: auto;
        }
    </style>
</head>
<body>
    <h1>2FA API 測試頁面</h1>
    
    <div class="test-section">
        <h3>1. 測試 get_user_info.php</h3>
        <button onclick="testGetUserInfo()">測試用戶資訊 API</button>
        <div id="userInfoResult"></div>
    </div>
    
    <div class="test-section">
        <h3>2. 測試 send_webauthn_2fa.php</h3>
        <button onclick="testSend2FA()">測試發送驗證碼 API</button>
        <div id="send2FAResult"></div>
    </div>
    
    <div class="test-section">
        <h3>3. 診斷結果</h3>
        <div id="diagnosticsResult"></div>
    </div>

    <script>
        async function testGetUserInfo() {
            const resultDiv = document.getElementById('userInfoResult');
            resultDiv.innerHTML = '<p>測試中...</p>';
            
            try {
                const response = await fetch('get_user_info.php', {
                    headers: { 'Accept': 'application/json' }
                });
                
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    const text = await response.text();
                    resultDiv.innerHTML = `<div class="test-result error">
                        <strong>失敗: 非 JSON 響應</strong>
                        <pre>${text.substring(0, 500)}</pre>
                    </div>`;
                    return;
                }
                
                const data = await response.json();
                if (data.success) {
                    resultDiv.innerHTML = `<div class="test-result success">
                        <strong>成功！</strong>
                        <pre>${JSON.stringify(data, null, 2)}</pre>
                    </div>`;
                } else {
                    resultDiv.innerHTML = `<div class="test-result error">
                        <strong>API 返回錯誤:</strong>
                        <pre>${JSON.stringify(data, null, 2)}</pre>
                    </div>`;
                }
            } catch (error) {
                resultDiv.innerHTML = `<div class="test-result error">
                    <strong>異常:</strong>
                    <pre>${error.message}</pre>
                </div>`;
            }
        }
        
        async function testSend2FA() {
            const resultDiv = document.getElementById('send2FAResult');
            resultDiv.innerHTML = '<p>測試中...</p>';
            
            try {
                const response = await fetch('api/send_webauthn_2fa.php', {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    }
                });
                
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    const text = await response.text();
                    resultDiv.innerHTML = `<div class="test-result error">
                        <strong>失敗: 非 JSON 響應</strong>
                        <pre>${text.substring(0, 500)}</pre>
                    </div>`;
                    return;
                }
                
                const data = await response.json();
                if (data.success) {
                    resultDiv.innerHTML = `<div class="test-result success">
                        <strong>成功！驗證碼已發送。</strong>
                        <pre>${JSON.stringify(data, null, 2)}</pre>
                    </div>`;
                } else {
                    resultDiv.innerHTML = `<div class="test-result error">
                        <strong>API 返回錯誤:</strong>
                        <pre>${JSON.stringify(data, null, 2)}</pre>
                    </div>`;
                }
            } catch (error) {
                resultDiv.innerHTML = `<div class="test-result error">
                    <strong>異常:</strong>
                    <pre>${error.message}</pre>
                </div>`;
            }
        }
        
        // 頁面加載時自動執行診斷
        window.addEventListener('load', async () => {
            const resultDiv = document.getElementById('diagnosticsResult');
            resultDiv.innerHTML = '<p>診斷中...</p>';
            
            try {
                const response = await fetch('api/test_2fa_flow.php', {
                    headers: { 'Accept': 'application/json' }
                });
                const data = await response.json();
                
                let html = '<h4>診斷結果:</h4>';
                if (data.status === 'OK') {
                    html += '<div class="test-result success">✓ 所有測試通過</div>';
                } else {
                    html += '<div class="test-result error">✗ 發現問題</div>';
                }
                
                html += '<h4>詳細結果:</h4>';
                data.tests.forEach((test, i) => {
                    const status = test.passed ? '✓' : '✗';
                    const className = test.passed ? 'success' : 'error';
                    html += `<div class="test-result ${className}">
                        <strong>${i + 1}. ${test.name}</strong>: ${status}
                        <br>${test.message}
                    </div>`;
                });
                
                if (data.errors.length > 0) {
                    html += '<h4>錯誤訊息:</h4>';
                    data.errors.forEach(err => {
                        html += `<div class="test-result error">${err}</div>`;
                    });
                }
                
                resultDiv.innerHTML = html;
            } catch (error) {
                resultDiv.innerHTML = `<div class="test-result error">
                    <strong>診斷失敗:</strong>
                    <pre>${error.message}</pre>
                </div>`;
            }
        });
    </script>
</body>
</html>
