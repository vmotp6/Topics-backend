<?php
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/includes/webauthn_helpers.php';

checkBackendLogin();

$page_title = 'Passkey/WebAuthn 檢查';
$current_page = 'webauthn_check';

$rp_id = getRelyingPartyId();
$rp_origin = getRelyingPartyOrigin();
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Topics 後台管理系統</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1890ff;
            --text-color: #262626;
            --text-secondary-color: #8c8c8c;
            --border-color: #f0f0f0;
            --background-color: #f0f2f5;
            --card-background-color: #fff;
            --success-color: #52c41a;
            --warning-color: #faad14;
            --danger-color: #f5222d;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background: var(--background-color); color: var(--text-color); }
        .dashboard { display: flex; min-height: 100vh; }
        .content { padding: 24px; width: 100%; }
        .breadcrumb { margin-bottom: 16px; font-size: 16px; color: var(--text-secondary-color); }
        .breadcrumb a { color: var(--primary-color); text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }
        .card { background: var(--card-background-color); border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.03); border: 1px solid var(--border-color); margin-bottom: 24px; }
        .card-header { padding: 16px 24px; border-bottom: 1px solid var(--border-color); background: #fafafa; }
        .card-header h3 { font-size: 18px; font-weight: 600; color: var(--text-color); }
        .card-body { padding: 24px; }
        .status-row { display: grid; grid-template-columns: 220px 1fr; gap: 12px; padding: 12px 0; border-bottom: 1px dashed #e8e8e8; }
        .status-row:last-child { border-bottom: none; }
        .status-label { color: var(--text-secondary-color); font-size: 14px; }
        .status-value { font-size: 14px; }
        .badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
        .badge.ok { background: #f6ffed; color: #135200; border: 1px solid #b7eb8f; }
        .badge.warn { background: #fffbe6; color: #ad6800; border: 1px solid #ffe58f; }
        .badge.bad { background: #fff1f0; color: #a8071a; border: 1px solid #ffccc7; }
        .btn { padding: 8px 16px; border: 1px solid #d9d9d9; border-radius: 6px; cursor: pointer; font-size: 14px; transition: all 0.3s; background: #fff; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; color: #595959; }
        .btn:hover { background: #f5f5f5; border-color: #40a9ff; color: #40a9ff; }
        .hint { color: var(--text-secondary-color); font-size: 13px; line-height: 1.6; margin-top: 8px; }
        pre { background: #fafafa; border: 1px solid #f0f0f0; padding: 12px; border-radius: 6px; font-size: 12px; overflow: auto; }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include 'sidebar.php'; ?>
        <div class="main-content" id="mainContent">
            <?php include 'header.php'; ?>
            <div class="content">
                <div class="breadcrumb">
                    <a href="index.php">首頁</a> / <?php echo $page_title; ?>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-fingerprint"></i> Passkey/WebAuthn 檢查</h3>
                    </div>
                    <div class="card-body">
                        <div class="status-row">
                            <div class="status-label">RP ID</div>
                            <div class="status-value"><code><?php echo htmlspecialchars($rp_id); ?></code></div>
                        </div>
                        <div class="status-row">
                            <div class="status-label">RP Origin</div>
                            <div class="status-value"><code><?php echo htmlspecialchars($rp_origin); ?></code></div>
                        </div>
                        <div class="status-row">
                            <div class="status-label">瀏覽器支援 WebAuthn</div>
                            <div class="status-value" id="supportStatus">檢查中...</div>
                        </div>
                        <div class="status-row">
                            <div class="status-label">平台驗證器可用</div>
                            <div class="status-value" id="platformStatus">檢查中...</div>
                        </div>
                        <div class="status-row">
                            <div class="status-label">已註冊憑證數量</div>
                            <div class="status-value" id="credentialCount">檢查中...</div>
                        </div>
                        <div class="status-row">
                            <div class="status-label">裝置/瀏覽器資訊</div>
                            <div class="status-value" id="uaInfo">檢查中...</div>
                        </div>
                        <div class="hint">如果 RP ID/Origin 與實際登入網址不一致，Passkey 會無法使用。</div>
                        <div style="margin-top: 16px; display: flex; gap: 12px; flex-wrap: wrap;">
                            <button class="btn" onclick="runCheck()"><i class="fas fa-sync"></i> 重新檢查</button>
                            <a class="btn" href="signature.php"><i class="fas fa-file-signature"></i> 回簽章頁</a>
                        </div>
                        <div style="margin-top: 16px;">
                            <div class="hint">檢查結果（JSON）</div>
                            <pre id="debugJson">尚未執行</pre>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function badge(label, type) {
            return '<span class="badge ' + type + '">' + label + '</span>';
        }

        async function runCheck() {
            const supportEl = document.getElementById('supportStatus');
            const platformEl = document.getElementById('platformStatus');
            const countEl = document.getElementById('credentialCount');
            const uaEl = document.getElementById('uaInfo');
            const debugEl = document.getElementById('debugJson');

            const result = {
                webauthnSupported: !!window.PublicKeyCredential,
                platformAuthenticatorAvailable: false,
                credentialCount: null,
                userAgent: navigator.userAgent
            };

            supportEl.innerHTML = result.webauthnSupported ? badge('支援', 'ok') : badge('不支援', 'bad');

            if (result.webauthnSupported && window.PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable) {
                try {
                    const available = await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
                    result.platformAuthenticatorAvailable = available;
                    platformEl.innerHTML = available ? badge('可用', 'ok') : badge('不可用', 'warn');
                } catch (e) {
                    platformEl.innerHTML = badge('檢查失敗', 'bad');
                }
            } else {
                platformEl.innerHTML = badge('不支援', 'warn');
            }

            try {
                const res = await fetch('check_credentials.php', { cache: 'no-store' });
                const data = await res.json();
                if (data && data.success) {
                    result.credentialCount = Array.isArray(data.credentials) ? data.credentials.length : 0;
                    countEl.innerHTML = badge(String(result.credentialCount) + ' 個', result.credentialCount > 0 ? 'ok' : 'warn');
                } else {
                    countEl.innerHTML = badge('無法取得', 'bad');
                }
                result.credentials = data;
            } catch (e) {
                countEl.innerHTML = badge('無法取得', 'bad');
                result.credentials = { error: String(e) };
            }

            uaEl.textContent = result.userAgent;
            debugEl.textContent = JSON.stringify(result, null, 2);
        }

        document.addEventListener('DOMContentLoaded', runCheck);
    </script>
</body>
</html>
