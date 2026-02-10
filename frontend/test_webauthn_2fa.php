<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WebAuthn 2FA 功能測試</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Microsoft JhengHei', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 40px 20px;
            margin: 0;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.2);
            padding: 40px;
        }
        h1 {
            color: #333;
            text-align: center;
            margin-bottom: 10px;
        }
        .subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 40px;
        }
        .test-section {
            background: #f9f9f9;
            border-radius: 8px;
            padding: 24px;
            margin-bottom: 24px;
        }
        .test-section h2 {
            color: #333;
            font-size: 18px;
            margin: 0 0 16px 0;
            padding-bottom: 12px;
            border-bottom: 2px solid #667eea;
        }
        .test-item {
            background: white;
            padding: 16px;
            border-radius: 6px;
            margin-bottom: 12px;
            border: 1px solid #e0e0e0;
        }
        .test-item h3 {
            margin: 0 0 12px 0;
            font-size: 16px;
            color: #555;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
            margin-right: 8px;
            margin-bottom: 8px;
        }
        .btn-primary {
            background: #667eea;
            color: white;
        }
        .btn-primary:hover {
            background: #5568d3;
        }
        .btn-success {
            background: #52c41a;
            color: white;
        }
        .btn-success:hover {
            background: #45a617;
        }
        .btn-danger {
            background: #f5222d;
            color: white;
        }
        .btn-danger:hover {
            background: #d91f2a;
        }
        .input-group {
            margin-bottom: 12px;
        }
        .input-group label {
            display: block;
            margin-bottom: 6px;
            color: #666;
            font-weight: 500;
            font-size: 14px;
        }
        .input-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            font-size: 14px;
        }
        .result {
            margin-top: 12px;
            padding: 12px;
            border-radius: 6px;
            font-size: 14px;
            display: none;
        }
        .result.success {
            background: #f6ffed;
            border: 1px solid #b7eb8f;
            color: #389e0d;
        }
        .result.error {
            background: #fff2f0;
            border: 1px solid #ffccc7;
            color: #a8071a;
        }
        .result.info {
            background: #e6f7ff;
            border: 1px solid #91d5ff;
            color: #0050b3;
        }
        .result.show {
            display: block;
        }
        pre {
            background: #f5f5f5;
            padding: 12px;
            border-radius: 4px;
            overflow-x: auto;
            font-size: 12px;
        }
        .status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .status.pending {
            background: #fff3cd;
            color: #856404;
        }
        .status.running {
            background: #d1ecf1;
            color: #0c5460;
        }
        .status.success {
            background: #d4edda;
            color: #155724;
        }
        .status.failed {
            background: #f8d7da;
            color: #721c24;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1><i class="fas fa-shield-alt"></i> WebAuthn 2FA 功能測試</h1>
        <p class="subtitle">測試設備註冊前的郵件 2FA 驗證功能</p>

        <!-- 測試 1: 獲取用戶資訊 -->
        <div class="test-section">
            <h2><i class="fas fa-user"></i> 測試 1: 獲取用戶資訊</h2>
            <div class="test-item">
                <h3>測試獲取當前登入用戶的資訊</h3>
                <button class="btn btn-primary" onclick="testGetUserInfo()">
                    <i class="fas fa-play"></i> 執行測試
                </button>
                <div id="result1" class="result"></div>
            </div>
        </div>

        <!-- 測試 2: 發送 2FA 驗證碼 -->
        <div class="test-section">
            <h2><i class="fas fa-envelope"></i> 測試 2: 發送 2FA 驗證碼</h2>
            <div class="test-item">
                <h3>測試發送郵件驗證碼</h3>
                <button class="btn btn-primary" onclick="testSend2FA()">
                    <i class="fas fa-paper-plane"></i> 發送驗證碼
                </button>
                <div id="result2" class="result"></div>
            </div>
        </div>

        <!-- 測試 3: 驗證 2FA 驗證碼 -->
        <div class="test-section">
            <h2><i class="fas fa-check-circle"></i> 測試 3: 驗證 2FA 驗證碼</h2>
            <div class="test-item">
                <h3>測試驗證碼驗證功能</h3>
                <div class="input-group">
                    <label>驗證碼（6位數）</label>
                    <input type="text" id="testCode" maxlength="6" placeholder="請輸入收到的驗證碼" 
                           oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                </div>
                <button class="btn btn-success" onclick="testVerify2FA()">
                    <i class="fas fa-check"></i> 驗證
                </button>
                <button class="btn btn-danger" onclick="testVerify2FAWithWrongCode()">
                    <i class="fas fa-times"></i> 測試錯誤驗證碼
                </button>
                <div id="result3" class="result"></div>
            </div>
        </div>

        <!-- 測試 4: WebAuthn 註冊檢查 -->
        <div class="test-section">
            <h2><i class="fas fa-fingerprint"></i> 測試 4: WebAuthn 註冊前置檢查</h2>
            <div class="test-item">
                <h3>測試未通過 2FA 驗證時無法開始註冊</h3>
                <button class="btn btn-primary" onclick="testWebAuthnWithout2FA()">
                    <i class="fas fa-play"></i> 測試（應失敗）
                </button>
                <div id="result4" class="result"></div>
            </div>
            <div class="test-item">
                <h3>測試通過 2FA 驗證後可以開始註冊</h3>
                <p style="color: #666; font-size: 14px; margin-bottom: 12px;">
                    <i class="fas fa-info-circle"></i> 請先完成測試 3，通過 2FA 驗證後再執行此測試
                </p>
                <button class="btn btn-success" onclick="testWebAuthnWith2FA()">
                    <i class="fas fa-play"></i> 測試（應成功）
                </button>
                <div id="result4b" class="result"></div>
            </div>
        </div>

        <!-- 測試總結 -->
        <div class="test-section">
            <h2><i class="fas fa-clipboard-check"></i> 測試總結</h2>
            <div id="summary" style="padding: 20px; background: white; border-radius: 6px;">
                <p style="color: #666; text-align: center;">
                    <i class="fas fa-info-circle"></i> 執行測試後，這裡將顯示測試結果摘要
                </p>
            </div>
        </div>
    </div>

    <script>
        let testResults = {
            test1: null,
            test2: null,
            test3: null,
            test4: null,
            test4b: null
        };

        function showResult(elementId, message, type, data = null) {
            const element = document.getElementById(elementId);
            element.className = `result ${type} show`;
            
            let html = `<strong>${type === 'success' ? '✓' : type === 'error' ? '✗' : 'ℹ'}</strong> ${message}`;
            
            if (data) {
                html += `<pre>${JSON.stringify(data, null, 2)}</pre>`;
            }
            
            element.innerHTML = html;
            updateSummary();
        }

        function updateSummary() {
            const summary = document.getElementById('summary');
            const total = Object.keys(testResults).length;
            const completed = Object.values(testResults).filter(v => v !== null).length;
            const passed = Object.values(testResults).filter(v => v === true).length;
            const failed = Object.values(testResults).filter(v => v === false).length;
            
            summary.innerHTML = `
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px; text-align: center;">
                    <div>
                        <div style="font-size: 32px; font-weight: bold; color: #667eea;">${completed}</div>
                        <div style="color: #666; font-size: 14px;">已執行</div>
                    </div>
                    <div>
                        <div style="font-size: 32px; font-weight: bold; color: #52c41a;">${passed}</div>
                        <div style="color: #666; font-size: 14px;">通過</div>
                    </div>
                    <div>
                        <div style="font-size: 32px; font-weight: bold; color: #f5222d;">${failed}</div>
                        <div style="color: #666; font-size: 14px;">失敗</div>
                    </div>
                    <div>
                        <div style="font-size: 32px; font-weight: bold; color: #999;">${total - completed}</div>
                        <div style="color: #666; font-size: 14px;">待執行</div>
                    </div>
                </div>
            `;
        }

        async function testGetUserInfo() {
            try {
                showResult('result1', '正在獲取用戶資訊...', 'info');
                
                const response = await fetch('get_user_info.php');
                const data = await response.json();
                
                if (data.success) {
                    testResults.test1 = true;
                    showResult('result1', '成功獲取用戶資訊', 'success', data);
                } else {
                    testResults.test1 = false;
                    showResult('result1', '獲取用戶資訊失敗: ' + data.message, 'error', data);
                }
            } catch (error) {
                testResults.test1 = false;
                showResult('result1', '測試失敗: ' + error.message, 'error');
            }
        }

        async function testSend2FA() {
            try {
                showResult('result2', '正在發送驗證碼...', 'info');
                
                const response = await fetch('api/send_webauthn_2fa.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' }
                });
                const data = await response.json();
                
                if (data.success) {
                    testResults.test2 = true;
                    showResult('result2', '成功發送驗證碼！請檢查您的信箱', 'success', data);
                } else {
                    testResults.test2 = false;
                    showResult('result2', '發送驗證碼失敗: ' + data.message, 'error', data);
                }
            } catch (error) {
                testResults.test2 = false;
                showResult('result2', '測試失敗: ' + error.message, 'error');
            }
        }

        async function testVerify2FA() {
            const code = document.getElementById('testCode').value.trim();
            
            if (!/^\d{6}$/.test(code)) {
                showResult('result3', '請輸入 6 位數驗證碼', 'error');
                return;
            }
            
            try {
                showResult('result3', '正在驗證...', 'info');
                
                const response = await fetch('api/verify_webauthn_2fa.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ code: code })
                });
                const data = await response.json();
                
                if (data.success) {
                    testResults.test3 = true;
                    showResult('result3', '驗證成功！', 'success', data);
                } else {
                    testResults.test3 = false;
                    showResult('result3', '驗證失敗: ' + data.message, 'error', data);
                }
            } catch (error) {
                testResults.test3 = false;
                showResult('result3', '測試失敗: ' + error.message, 'error');
            }
        }

        async function testVerify2FAWithWrongCode() {
            try {
                showResult('result3', '測試錯誤驗證碼...', 'info');
                
                const response = await fetch('api/verify_webauthn_2fa.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ code: '999999' })
                });
                const data = await response.json();
                
                if (!data.success) {
                    // 預期應該失敗
                    showResult('result3', '✓ 正確拒絕了錯誤的驗證碼', 'success', data);
                } else {
                    showResult('result3', '⚠ 異常：錯誤的驗證碼卻通過了驗證', 'error', data);
                }
            } catch (error) {
                showResult('result3', '測試失敗: ' + error.message, 'error');
            }
        }

        async function testWebAuthnWithout2FA() {
            try {
                showResult('result4', '測試未通過 2FA 時開始註冊...', 'info');
                
                const response = await fetch('webauthn_register.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'start' })
                });
                const data = await response.json();
                
                if (!data.success && data.message.includes('驗證碼')) {
                    testResults.test4 = true;
                    showResult('result4', '✓ 正確阻止了未經 2FA 驗證的註冊請求', 'success', data);
                } else {
                    testResults.test4 = false;
                    showResult('result4', '⚠ 異常：未經 2FA 驗證卻能開始註冊', 'error', data);
                }
            } catch (error) {
                testResults.test4 = false;
                showResult('result4', '測試失敗: ' + error.message, 'error');
            }
        }

        async function testWebAuthnWith2FA() {
            try {
                showResult('result4b', '測試通過 2FA 後開始註冊...', 'info');
                
                const response = await fetch('webauthn_register.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'start' })
                });
                const data = await response.json();
                
                if (data.success) {
                    testResults.test4b = true;
                    showResult('result4b', '✓ 成功開始 WebAuthn 註冊流程', 'success', data);
                } else {
                    testResults.test4b = false;
                    showResult('result4b', '開始註冊失敗: ' + data.message, 'error', data);
                }
            } catch (error) {
                testResults.test4b = false;
                showResult('result4b', '測試失敗: ' + error.message, 'error');
            }
        }
    </script>
</body>
</html>
