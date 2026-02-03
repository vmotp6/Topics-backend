<?php
/**
 * 手機端 WebAuthn 認證頁面
 * 用戶掃描 QR 碼後，在此頁面使用手機生物驗證完成認證
 */

require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/includes/webauthn_helpers.php';

$session_id = $_GET['session_id'] ?? '';

if (empty($session_id)) {
    die('無效的認證連結');
}

// 檢查 session 是否存在
$session = $_SESSION['cross_device_sessions'][$session_id] ?? null;

if (!$session) {
    die('認證連結已過期或無效');
}

if (time() > $session['expires_at']) {
    unset($_SESSION['cross_device_sessions'][$session_id]);
    die('認證連結已過期');
}

$expected_user_id = $session['user_id'];
$expected_username = $session['username'] ?? '';

// 安全性檢查：手機端必須登入同一個帳號
$current_user_id = $_SESSION['user_id'] ?? 0;
$current_username = $_SESSION['username'] ?? '';

if ($current_user_id <= 0) {
    // 手機端未登入，要求登入
    $login_url = '/Topics-backend/frontend/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']);
    die('請先登入您的帳號：<a href="' . $login_url . '">點擊登入</a>');
}

// 嚴格驗證：用戶 ID 必須完全匹配
if ($current_user_id != $expected_user_id) {
    // 用戶 ID 不匹配，拒絕認證
    error_log("跨設備認證安全錯誤: 手機端用戶 ID ($current_user_id) 與 QR code 中的用戶 ID ($expected_user_id) 不匹配");
    die('安全錯誤：您登入的帳號（' . htmlspecialchars($current_username) . '）與認證請求的帳號（' . htmlspecialchars($expected_username) . '）不符。請確保使用同一個帳號登入。');
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>手機生物驗證 - Topics 電子簽章</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 20px;
            padding: 40px 30px;
            max-width: 400px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            text-align: center;
        }
        .icon {
            font-size: 64px;
            color: #667eea;
            margin-bottom: 20px;
        }
        h1 {
            font-size: 24px;
            color: #333;
            margin-bottom: 10px;
        }
        .subtitle {
            color: #666;
            font-size: 14px;
            margin-bottom: 30px;
        }
        .status {
            padding: 20px;
            background: #f5f5f5;
            border-radius: 10px;
            margin-bottom: 20px;
            min-height: 100px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
        }
        .btn {
            background: #667eea;
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: all 0.3s;
        }
        .btn:hover {
            background: #5568d3;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        .btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
        }
        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .success {
            color: #52c41a;
            font-size: 48px;
        }
        .error {
            color: #f5222d;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">
            <i class="fas fa-fingerprint"></i>
        </div>
        <h1>手機生物驗證</h1>
        <p class="subtitle">請使用您的指紋或臉部辨識完成驗證</p>
        
        <div class="status" id="status">
            <p>準備進行生物驗證...</p>
        </div>
        
        <button class="btn" id="authBtn" onclick="startAuth()">
            <i class="fas fa-fingerprint"></i> 開始生物驗證
        </button>
    </div>

    <script>
        const sessionId = '<?php echo htmlspecialchars($session_id); ?>';
        
        async function startAuth() {
            const btn = document.getElementById('authBtn');
            const status = document.getElementById('status');
            
            btn.disabled = true;
            status.innerHTML = '<div class="spinner"></div><p style="margin-top: 10px;">正在準備驗證...</p>';
            
            try {
                // 重要：確保手機端登入的帳號與 QR code 中的帳號一致
                // 這個檢查在 PHP 端已經完成，但我們在前端也添加提示
                const expectedUserId = <?php echo $expected_user_id; ?>;
                const currentUserId = <?php echo $current_user_id; ?>;
                
                if (currentUserId !== expectedUserId) {
                    throw new Error('安全錯誤：您登入的帳號與認證請求不符。請確保使用同一個帳號登入。');
                }
                
                // 1. 獲取認證選項（這會使用當前登入用戶的憑證）
                const startResponse = await fetch('webauthn_authenticate.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'start' })
                });
                
                const startData = await startResponse.json();
                
                if (!startData.success) {
                    throw new Error(startData.message || '獲取認證選項失敗');
                }
                
                // 2. 轉換選項
                const options = startData.options;
                options.challenge = base64UrlToArrayBuffer(options.challenge);
                
                if (options.allowCredentials && Array.isArray(options.allowCredentials)) {
                    options.allowCredentials = options.allowCredentials.map(cred => ({
                        ...cred,
                        id: base64UrlToArrayBuffer(cred.id)
                    }));
                }
                
                // 3. 調用 WebAuthn API
                status.innerHTML = '<p>請使用您的生物驗證設備進行驗證...</p>';
                
                const credential = await navigator.credentials.get({
                    publicKey: options
                });
                
                // 4. 發送認證結果
                const authResponse = await fetch('webauthn_authenticate.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'complete',
                        credential: {
                            id: credential.id,
                            rawId: arrayBufferToBase64(credential.rawId),
                            response: {
                                authenticatorData: arrayBufferToBase64(credential.response.authenticatorData),
                                clientDataJSON: arrayBufferToBase64(credential.response.clientDataJSON),
                                signature: arrayBufferToBase64(credential.response.signature),
                                userHandle: credential.response.userHandle ? arrayBufferToBase64(credential.response.userHandle) : null
                            },
                            type: credential.type
                        }
                    })
                });
                
                const authData = await authResponse.json();
                
                if (!authData.success) {
                    throw new Error(authData.message || '認證失敗');
                }
                
                // 5. 發送結果到跨設備 session
                const completeResponse = await fetch('webauthn_cross_device.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'complete',
                        session_id: sessionId,
                        auth_result: authData
                    })
                });
                
                const completeData = await completeResponse.json();
                
                if (completeData.success) {
                    status.innerHTML = '<i class="fas fa-check-circle success"></i><p style="margin-top: 10px; color: #52c41a;">驗證成功！</p><p style="font-size: 12px; color: #999; margin-top: 10px;">您可以關閉此頁面</p>';
                    btn.style.display = 'none';
                } else {
                    throw new Error(completeData.message || '儲存認證結果失敗');
                }
                
            } catch (error) {
                console.error('認證錯誤:', error);
                status.innerHTML = `<p class="error"><i class="fas fa-exclamation-circle"></i> ${error.message || '認證失敗'}</p>`;
                btn.disabled = false;
                btn.textContent = '重試';
            }
        }
        
        function base64UrlToArrayBuffer(base64url) {
            let base64 = base64url.replace(/-/g, '+').replace(/_/g, '/');
            while (base64.length % 4) {
                base64 += '=';
            }
            const binaryString = atob(base64);
            const bytes = new Uint8Array(binaryString.length);
            for (let i = 0; i < binaryString.length; i++) {
                bytes[i] = binaryString.charCodeAt(i);
            }
            return bytes.buffer;
        }
        
        function arrayBufferToBase64(buffer) {
            const bytes = new Uint8Array(buffer);
            let binary = '';
            for (let i = 0; i < bytes.byteLength; i++) {
                binary += String.fromCharCode(bytes[i]);
            }
            return btoa(binary);
        }
        
        // 自動開始（可選）
        // startAuth();
    </script>
</body>
</html>

