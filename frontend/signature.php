<?php
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/includes/department_session_access.php';

// 檢查登入


/* =========================
   取得角色
========================= */

$user_role = $_SESSION['role'] ?? '';

$role_map = [
    '管理員' => 'ADM',
    'admin' => 'ADM',
    'Admin' => 'ADM',

    '行政人員' => 'STA',
    '學校行政人員' => 'STA',
    'staff' => 'STA',

    '主任' => 'DI',
    'director' => 'DI',

    '老師' => 'TEA',
    'teacher' => 'TEA',

    '招生中心組員' => 'STAM',

    '科助' => 'AS',
    'assistant' => 'AS',

    'ADM' => 'ADM',
    'STA' => 'STA',
    'DI' => 'DI',
    'TEA' => 'TEA',
    'STAM' => 'STAM',
    'AS' => 'AS'
];

if (isset($role_map[$user_role])) {
    $user_role = $role_map[$user_role];
}

/* =========================
   權限判斷
========================= */

$is_admin = ($user_role === 'ADM');
$is_staff = ($user_role === 'STA');
$is_director = ($user_role === 'DI');
$is_teacher = ($user_role === 'TEA');
$is_stam = ($user_role === 'STAM');
$is_as = ($user_role === 'AS');
$is_stu =($user_role === 'STU');

/* =========================
   電子簽章權限
========================= */

/*if (!$is_admin && !$is_director &&!$is_stu) {
    die("您沒有權限使用電子簽章功能");
}*/

/* =========================
   使用者資訊
========================= */

$user_id = $_SESSION['user_id'] ?? 0;
$username = $_SESSION['username'] ?? '';
$user_name = $_SESSION['name'] ?? $username;

/* =========================
   文件資訊
========================= */

$document_id = isset($_GET['document_id']) ? (int)$_GET['document_id'] : null;
$document_type = $_GET['document_type'] ?? 'general';

$embed = (isset($_GET['embed']) && $_GET['embed'] === '1');

/* =========================
   頁面設定
========================= */

$page_title = '電子簽章';
$current_page = 'signature';
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
        }
        * {
            
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: var(--background-color);
            color: var(--text-color);
        }
        .dashboard {
            display: flex;
            min-height: 100vh;
        }
        .content {
            padding: 24px;
            width: 100%;
        }
        .breadcrumb {
            margin-bottom: 16px;
            font-size: 16px;
            color: var(--text-secondary-color);
        }
        .breadcrumb a {
            color: var(--primary-color);
            text-decoration: none;
        }
        .breadcrumb a:hover {
            text-decoration: underline;
        }
        .card {
            background: var(--card-background-color);
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
        }
        .card-header {
            padding: 16px 24px;
            border-bottom: 1px solid var(--border-color);
            background: #fafafa;
        }
        .card-header h3 {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-color);
        }
        .card-body {
            padding: 24px;
        }
        .signature-container {
            max-width: 900px;
            margin: 0 auto;
        }
        .signature-info {
            text-align: center;
            color: var(--text-secondary-color);
            margin-bottom: 20px;
            padding: 12px;
            background: #f5f5f5;
            border-radius: 4px;
        }
        #signatureCanvas {
            border: 2px solid #d9d9d9;
            border-radius: 8px;
            cursor: crosshair;
            background: white;
            display: block;
            margin: 20px auto;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            touch-action: none;
        }
        #signatureCanvas:active {
            cursor: crosshair;
        }
        .controls {
            text-align: center;
            margin: 30px 0;
            display: flex;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .btn {
            padding: 10px 24px;
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
            background: #fff;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        .btn-primary {
            background: #1890ff;
            color: white;
            border-color: #1890ff;
        }
        .btn-primary:hover {
            background: #40a9ff;
            border-color: #40a9ff;
        }
        .btn-danger {
            background: #f5222d;
            color: white;
            border-color: #f5222d;
        }
        .btn-danger:hover {
            background: #ff4d4f;
            border-color: #ff4d4f;
        }
        .btn-secondary {
            background: #d9d9d9;
            color: #333;
        }
        .btn-secondary:hover {
            background: #bfbfbf;
        }
        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .signature-preview {
            margin-top: 30px;
            text-align: center;
            padding: 20px;
            background: #fafafa;
            border-radius: 8px;
            display: none;
        }
        .signature-preview.active {
            display: block;
        }
        .signature-preview h4 {
            margin-bottom: 16px;
            color: var(--text-color);
        }
        .signature-preview img {
            max-width: 100%;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            background-color: #333;
            color: white;
            padding: 15px 20px;
            border-radius: 8px;
            z-index: 9999;
            display: none;
            opacity: 0;
            transition: opacity 0.5s;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .toast.show {
            display: block;
            opacity: 1;
        }
        .toast.success {
            background-color: #52c41a;
        }
        .toast.error {
            background-color: #f5222d;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        @media (max-width: 768px) {
            #signatureCanvas {
                width: 100%;
                height: 250px;
            }
            .controls {
                flex-direction: column;
            }
            .btn {
                width: 100%;
            }
        }
    </style>
        <?php if ($embed): ?>
        <style>
            body.embed { background: #f5f7fb; }
            body.embed .content { padding: 12px; }
            body.embed .card { margin-bottom: 0; }
        </style>
        <?php endif; ?>
</head>
<body class="<?php echo $embed ? 'embed' : ''; ?>">
    <?php if (!$embed): ?>
    <div class="dashboard">
        <?php include 'sidebar.php'; ?>
        <div class="main-content" id="mainContent">
            <?php include 'header.php'; ?>
            <div class="content">
                <div class="breadcrumb">
                    <a href="index.php">首頁</a> / <?php echo $page_title; ?>
                    <span style="float: right;">
                        <a href="signature_list.php" style="color: var(--primary-color); text-decoration: none;">
                            <i class="fas fa-list"></i> 查看簽名記錄
                        </a>
                    </span>
                </div>
    <?php else: ?>
    <div class="content">
    <?php endif; ?>

                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-file-signature"></i> 電子簽章</h3>
                    </div>
                    <div class="card-body">
                        <div class="signature-container">
                            <div class="signature-info">
                                <p><strong>簽名者：</strong><?php echo htmlspecialchars($user_name); ?> (<?php echo htmlspecialchars($username); ?>)</p>
                                <p style="margin-top: 8px; font-size: 14px;">請使用手機生物驗證（指紋/臉部辨識）或傳統簽名方式</p>
                            </div>
                            
                            <!-- WebAuthn 生物驗證區域 -->
                            <div id="webauthnSection" style="text-align: center; padding: 40px 20px; background: #f5f5f5; border-radius: 8px; margin-bottom: 20px;">
                                <div id="webauthnStatus" style="margin-bottom: 20px;">
                                    <i class="fas fa-fingerprint" style="font-size: 48px; color: #1890ff; margin-bottom: 16px;"></i>
                                    <p style="font-size: 16px; color: #666; margin-bottom: 8px;">使用生物驗證進行簽名</p>
                                    <p style="font-size: 14px; color: #999; margin-bottom: 12px;" id="webauthnDescription">
                                        支援手機指紋辨識、臉部辨識，或 USB 安全性金鑰
                                    </p>
                                    <div id="deviceHint" style="display: none; padding: 12px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; margin-top: 12px; font-size: 13px; color: #856404; text-align: left;">
                                        <!-- 內容由 JavaScript 動態填充 -->
                                    </div>
                                </div>
                                <div id="webauthnButtons" style="display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;">
                                    <button class="btn btn-primary" onclick="startWebAuthnAuth()" id="webauthnAuthBtn">
                                        <i class="fas fa-fingerprint"></i> 使用生物驗證簽名
                                    </button>
                                    <button class="btn btn-secondary" onclick="showRegisterModal()" id="registerDeviceBtn">
                                        <i class="fas fa-mobile-alt"></i> 註冊新設備
                                    </button>
                                    <button class="btn btn-secondary" onclick="switchToCanvas()" id="switchToCanvasBtn">
                                        <i class="fas fa-pen"></i> 使用傳統簽名
                                    </button>
                                </div>
                                <div id="webauthnError" style="display: none; margin-top: 16px; padding: 12px; background: #fff2f0; border: 1px solid #ffccc7; border-radius: 4px; color: #a8071a;"></div>
                            </div>
                            
                            <!-- Canvas 傳統簽名區域（預設隱藏） -->
                            <div id="canvasSection" style="display: none;">
                                <canvas id="signatureCanvas" width="800" height="350"></canvas>
                                
                                <div class="controls">
                                    <button class="btn btn-danger" onclick="clearSignature()">
                                        <i class="fas fa-eraser"></i> 清除
                                    </button>
                                    <button class="btn btn-secondary" onclick="saveAsImage()">
                                        <i class="fas fa-download"></i> 下載圖片
                                    </button>
                                    <button class="btn btn-secondary" onclick="switchToWebAuthn()">
                                        <i class="fas fa-fingerprint"></i> 改用生物驗證
                                    </button>
                                    <button class="btn btn-primary" onclick="submitCanvasSignature()" id="submitCanvasBtn">
                                        <i class="fas fa-check"></i> 確認簽名
                                    </button>
                                </div>
                                
                                <div class="signature-preview" id="preview">
                                    <h4>簽名預覽</h4>
                                    <img id="previewImage" src="" alt="簽名預覽">
                                </div>
                            </div>
                            
                            <!-- 簽名狀態顯示 -->
                            <div id="signatureStatus" style="display: none; margin-top: 20px; padding: 16px; background: #f6ffed; border: 1px solid #b7eb8f; border-radius: 6px;">
                                <p style="color: #52c41a; margin: 0;">
                                    <i class="fas fa-check-circle"></i> <span id="statusMessage">簽名已完成</span>
                                </p>
                            </div>
                        </div>
                        
                        <!-- 註冊設備模態框 -->
                        <div id="registerModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center;">
                            <div style="background: white; padding: 30px; border-radius: 8px; max-width: 500px; width: 90%;">
                                <h3 style="margin-top: 0;"><i class="fas fa-shield-alt"></i> 註冊生物驗證設備</h3>
                                
                                <!-- 步驟 1: 郵件驗證 -->
                                <div id="step1EmailVerification" style="display: block;">
                                    <div style="background: #e6f7ff; border: 1px solid #91d5ff; padding: 16px; border-radius: 6px; margin-bottom: 20px;">
                                        <p style="margin: 0; color: #0050b3; font-size: 14px;">
                                            <i class="fas fa-info-circle"></i> 為了確保您的帳號安全，註冊新設備前需要先進行郵件驗證。
                                        </p>
                                    </div>
                                    <p style="color: #666; margin-bottom: 16px;">點擊下方按鈕，我們將發送驗證碼到您的註冊信箱。</p>
                                    <div id="emailDisplay" style="background: #f5f5f5; padding: 12px; border-radius: 4px; margin-bottom: 16px; text-align: center; font-family: monospace;">
                                        載入中...
                                    </div>
                                    <button class="btn btn-primary" onclick="sendWebAuthn2FA()" id="send2FABtn" style="width: 100%; margin-bottom: 12px;">
                                        <i class="fas fa-envelope"></i> 發送驗證碼
                                    </button>
                                    <div id="verificationCodeInput" style="display: none; margin-top: 16px;">
                                        <label style="display: block; margin-bottom: 8px; color: #666; font-weight: 500;">請輸入 6 位數驗證碼</label>
                                        <input type="text" id="verification2FACode" maxlength="6" placeholder="000000" autocomplete="off"
                                               style="width: 100%; padding: 12px; border: 1px solid #d9d9d9; border-radius: 6px; font-size: 24px; text-align: center; letter-spacing: 8px; font-family: monospace; margin-bottom: 12px;"
                                               oninput="this.value = this.value.replace(/[^0-9]/g, '')"
                                               onkeypress="if(event.key === 'Enter') verifyWebAuthn2FA()">
                                        <button class="btn btn-primary" onclick="verifyWebAuthn2FA()" id="verify2FABtn" style="width: 100%;">
                                            <i class="fas fa-check"></i> 驗證並繼續
                                        </button>
                                        <p style="font-size: 13px; color: #999; text-align: center; margin-top: 12px;">
                                            沒收到驗證碼？<a href="#" onclick="sendWebAuthn2FA(); return false;" style="color: #1890ff;">重新發送</a>
                                        </p>
                                    </div>
                                </div>
                                
                                <!-- 步驟 2: WebAuthn 註冊 -->
                                <div id="step2WebAuthnRegister" style="display: none;">
                                    <div id="webauthnLocalhostWarning" style="display: none; background: #fffbe6; border: 1px solid #ffe58f; padding: 12px 16px; border-radius: 6px; margin-bottom: 16px;">
                                        <p style="margin: 0; color: #ad6800; font-size: 13px;">
                                            <i class="fas fa-exclamation-triangle"></i> 目前使用 IP 位址（127.0.0.1）存取，生物驗證無法使用。請改用 <strong>http://localhost</strong> 開啟本頁後再試。
                                        </p>
                                    </div>
                                    <div style="background: #f6ffed; border: 1px solid #b7eb8f; padding: 16px; border-radius: 6px; margin-bottom: 20px;">
                                        <p style="margin: 0; color: #389e0d; font-size: 14px;">
                                            <i class="fas fa-check-circle"></i> 郵件驗證成功！現在可以開始註冊設備。
                                        </p>
                                    </div>
                                    <p style="color: #666; margin-bottom: 20px;">請按照瀏覽器提示完成生物驗證設備註冊。</p>
                                    <button class="btn btn-primary" onclick="startWebAuthnRegister()" id="startRegisterBtn" style="width: 100%;">
                                        <i class="fas fa-fingerprint"></i> 開始註冊設備
                                    </button>
                                </div>
                                
                                <div id="registerStatus" style="margin-top: 16px;"></div>
                                
                                <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 20px; padding-top: 20px; border-top: 1px solid #f0f0f0;">
                                    <button class="btn btn-secondary" onclick="closeRegisterModal()">取消</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
    <?php if (!$embed): ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- 訊息提示框 -->
    <div id="toast" class="toast"></div>

    <script>
        // WebAuthn 相關變數
        let webauthnAuthResult = null;
        let currentSignatureMethod = 'webauthn'; // 'webauthn' 或 'canvas'
        
        // Canvas 相關變數
        const canvas = document.getElementById('signatureCanvas');
        let ctx = null;
        let isDrawing = false;
        let lastX = 0;
        let lastY = 0;
        
        // 檢查 WebAuthn 支援
        function checkWebAuthnSupport() {
            if (!window.PublicKeyCredential) {
                showToast('您的瀏覽器不支援 WebAuthn，將使用傳統簽名方式', 'error');
                switchToCanvas();
                return false;
            }
            return true;
        }
        
        // 檢測設備類型
        function detectDeviceType() {
            const ua = navigator.userAgent;
            const isMobile = /Mobile|Android|iPhone|iPad/i.test(ua);
            return isMobile ? 'mobile' : 'desktop';
        }
        
        // 檢測是否支援平台認證器（Windows Hello 等）
        async function checkPlatformAuthenticator() {
            if (!window.PublicKeyCredential) {
                return false;
            }
            
            try {
                // 嘗試檢查平台認證器是否可用
                const available = await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
                return available;
            } catch (e) {
                // 如果 API 不支援，返回 false
                return false;
            }
        }
        
        // 初始化
        document.addEventListener('DOMContentLoaded', async function() {
            if (checkWebAuthnSupport()) {
                const deviceType = detectDeviceType();
                const hasPlatformAuth = await checkPlatformAuthenticator();
                
                // 預設顯示 WebAuthn
                showWebAuthnSection();
                
                // 如果是桌面設備
                if (deviceType === 'desktop') {
                    // 檢查是否有手機憑證
                    try {
                        const checkResponse = await fetch('check_credentials.php');
                        const checkData = await checkResponse.json();
                        
                        console.log('憑證檢查結果:', checkData);
                        
                        if (checkData.success && checkData.credentials && checkData.credentials.length > 0) {
                            const hasMobileCredential = checkData.credentials.some(c => 
                                c.device_type === 'phone' || c.device_type === 'tablet' ||
                                (c.device_name && (
                                    c.device_name.includes('手機') || 
                                    c.device_name.includes('iPhone') || 
                                    c.device_name.includes('Android') || 
                                    c.device_name.includes('iPad')
                                ))
                            );
                            
                            if (hasMobileCredential) {
                                // 有手機憑證
                                document.getElementById('deviceHint').style.display = 'block';
                                document.getElementById('deviceHint').innerHTML = `
                                    <i class="fas fa-info-circle"></i> 
                                    <strong>桌面電腦提示：</strong>檢測到您已註冊手機生物驗證。
                                    <br>
                                    <strong>推薦方案：</strong>
                                    <br>1. 使用手機瀏覽器直接打開此頁面進行生物驗證
                                    <br>2. 或使用下方的「使用傳統簽名」按鈕
                                `;
                                document.getElementById('webauthnDescription').innerHTML = `
                                    <span style="color: #1890ff;">💡 您已註冊手機生物驗證</span>
                                    <br>建議直接使用手機瀏覽器進行認證
                                `;
                            } else {
                                // 沒有手機憑證
                                if (!hasPlatformAuth) {
                                    document.getElementById('deviceHint').style.display = 'block';
                                    document.getElementById('deviceHint').style.display = 'block';
                                    document.getElementById('deviceHint').innerHTML = `
                                        <i class="fas fa-info-circle"></i> 
                                        <strong>桌面電腦提示：</strong>您的電腦沒有內建生物驗證功能。
                                        <br>
                                        <strong>建議方案：</strong>
                                        <br>1. 使用手機瀏覽器直接打開此頁面註冊並使用手機生物驗證（推薦）
                                        <br>2. 使用 USB 安全性金鑰（如 YubiKey）
                                        <br>3. 使用下方的「使用傳統簽名」按鈕
                                    `;
                                    document.getElementById('webauthnDescription').innerHTML = `
                                        <span style="color: #f5222d;">⚠️ 桌面電腦沒有內建生物驗證</span>
                                        <br>建議使用手機瀏覽器進行生物驗證
                                    `;
                                }
                            }
                        } else {
                            // 沒有憑證，提示註冊
                            if (!hasPlatformAuth) {
                                document.getElementById('deviceHint').style.display = 'block';
                                document.getElementById('deviceHint').style.display = 'block';
                                document.getElementById('deviceHint').innerHTML = `
                                    <i class="fas fa-info-circle"></i> 
                                    <strong>桌面電腦提示：</strong>您尚未註冊任何生物驗證設備。
                                    <br>
                                    <strong>建議方案：</strong>
                                    <br>1. 使用手機瀏覽器直接打開此頁面註冊並使用手機生物驗證（推薦）
                                    <br>2. 點擊「註冊新設備」註冊 USB 安全性金鑰
                                    <br>3. 使用下方的「使用傳統簽名」按鈕
                                `;
                            }
                        }
                    } catch (e) {
                        console.error('檢查憑證失敗:', e);
                        // 顯示提示
                        document.getElementById('deviceHint').style.display = 'block';
                        document.getElementById('deviceHint').innerHTML = `
                            <i class="fas fa-info-circle"></i> 
                            <strong>桌面電腦提示：</strong>在桌面電腦上，建議使用手機瀏覽器進行生物驗證。
                            <br>
                            <strong>推薦方案：</strong>
                            <br>1. 使用手機瀏覽器直接打開此頁面進行生物驗證（推薦）
                            <br>2. 使用下方的「使用傳統簽名」按鈕
                        `;
                    }
                    
                    if (hasPlatformAuth) {
                        // 有 Windows Hello 等平台認證器
                        document.getElementById('deviceHint').style.display = 'block';
                        document.getElementById('deviceHint').style.background = '#d4edda';
                        document.getElementById('deviceHint').style.borderColor = '#28a745';
                        document.getElementById('deviceHint').style.color = '#155724';
                        document.getElementById('deviceHint').innerHTML = `
                            <i class="fas fa-check-circle"></i> 
                            <strong>檢測到生物驗證支援：</strong>您可以使用 Windows Hello（指紋/臉部辨識）進行簽名。
                        `;
                        document.getElementById('webauthnDescription').innerHTML = `
                            可以使用 Windows Hello 指紋辨識或臉部辨識進行簽名
                        `;
                    }
                } else {
                    // 手機設備
                    document.getElementById('webauthnDescription').innerHTML = `
                        可以使用手機的指紋辨識或臉部辨識進行簽名
                    `;
                }
            } else {
                showCanvasSection();
            }
        });
        
        // 顯示 WebAuthn 區域
        function showWebAuthnSection() {
            document.getElementById('webauthnSection').style.display = 'block';
            document.getElementById('canvasSection').style.display = 'none';
            currentSignatureMethod = 'webauthn';
        }
        
        // 顯示 Canvas 區域
        function showCanvasSection() {
            document.getElementById('webauthnSection').style.display = 'none';
            document.getElementById('canvasSection').style.display = 'block';
            currentSignatureMethod = 'canvas';
            
            // 初始化 Canvas
            if (!ctx && canvas) {
                ctx = canvas.getContext('2d');
                initCanvas();
            }
        }
        
        // 切換到 Canvas
        function switchToCanvas() {
            showCanvasSection();
        }
        
        // 切換到 WebAuthn
        function switchToWebAuthn() {
            showWebAuthnSection();
        }
        
        // 開始 WebAuthn 認證
        async function startWebAuthnAuth() {
            if (!checkWebAuthnSupport()) return;
            
            const btn = document.getElementById('webauthnAuthBtn');
            const statusDiv = document.getElementById('webauthnStatus');
            const errorDiv = document.getElementById('webauthnError');
            
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 準備中...';
            errorDiv.style.display = 'none';
            
            try {
                // 1. 獲取認證選項
                const startResponse = await fetch('webauthn_authenticate.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'start' })
                });
                
                const startData = await startResponse.json();
                
                if (!startData.success) {
                    throw new Error(startData.message || '獲取認證選項失敗');
                }
                
                // 2. 檢查是否在桌面環境且有手機憑證
                const deviceType = detectDeviceType();
                if (deviceType === 'desktop' && startData.has_platform_credential) {
                    // 在桌面環境且有手機憑證，提示使用手機瀏覽器
                    const useMobile = confirm(
                        '檢測到您已註冊手機生物驗證。\n\n' +
                        '在桌面電腦上，直接認證可能會要求使用 USB 金鑰。\n\n' +
                        '建議使用手機瀏覽器進行認證。\n\n' +
                        '是否要繼續使用直接認證？（可能會要求 USB 金鑰）'
                    );
                    
                    if (!useMobile) {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-fingerprint"></i> 使用生物驗證簽名';
                        errorDiv.style.display = 'block';
                        errorDiv.innerHTML = '建議使用手機瀏覽器進行生物驗證簽名。';
                        return;
                    }
                }
                
                // 3. 轉換選項中的 challenge 為 ArrayBuffer
                const options = startData.options;
                options.challenge = base64UrlToArrayBuffer(options.challenge);
                
                // 轉換 allowCredentials 中的 id 為 ArrayBuffer
                if (options.allowCredentials && Array.isArray(options.allowCredentials)) {
                    options.allowCredentials = options.allowCredentials.map(cred => {
                        const transports = cred.transports || [];
                        // 確保包含 hybrid transport，讓 Windows 可以顯示 Passkey QR code
                        const updatedTransports = transports.includes('hybrid') 
                            ? transports 
                            : [...transports, 'hybrid'];
                        
                        return {
                            ...cred,
                            id: base64UrlToArrayBuffer(cred.id),
                            transports: updatedTransports
                        };
                    });
                    
                    // 調試：記錄允許的憑證
                    console.log('允許的憑證數量:', options.allowCredentials.length);
                    console.log('允許的憑證 transports:', options.allowCredentials.map(c => c.transports));
                } else {
                    // 如果沒有 allowCredentials，Windows 會顯示所有選項（包括 Passkey QR code）
                    console.log('⚠️ 沒有指定 allowCredentials，Windows 會顯示所有認證選項（包括 Passkey QR code）');
                }
                
                // 調試：記錄認證選項
                console.log('認證選項:', {
                    rpId: options.rpId,
                    allowCredentialsCount: options.allowCredentials?.length || 0,
                    userVerification: options.userVerification,
                    has_platform_credential: startData.has_platform_credential
                });
                
                // 提示用戶 Windows 會顯示 Passkey QR code
                if (startData.has_platform_credential) {
                    console.log('✅ 檢測到平台憑證，Windows 安全性對話框會顯示：');
                    console.log('  - Windows Hello（PIN/臉部辨識/指紋）');
                    console.log('  - Passkey QR code（用手機掃描認證）');
                    console.log('  - 手機生物驗證（指紋/Face ID）');
                } else {
                    console.log('💡 提示：Windows 可能會顯示 Passkey QR code 選項，讓您用手機掃描認證');
                }
                
                // 4. 檢查是否有平台認證器
                if (startData.has_platform_credential) {
                    // 檢查平台認證器是否可用
                    try {
                        const isPlatformAvailable = await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
                        if (!isPlatformAvailable && deviceType === 'desktop') {
                            throw new Error('平台認證器不可用。在桌面電腦上，建議使用手機瀏覽器進行生物驗證。');
                        }
                    } catch (e) {
                        if (e.message && !e.message.includes('平台認證器不可用')) {
                            console.warn('無法檢查平台認證器:', e);
                        } else {
                            throw e;
                        }
                    }
                }
                
                // 5. 調用 WebAuthn API
                statusDiv.innerHTML = '<i class="fas fa-fingerprint" style="font-size: 48px; color: #1890ff; margin-bottom: 16px;"></i><p style="font-size: 16px; color: #666;">請使用您的生物驗證設備進行驗證...</p>';
                
                console.log('最終認證選項:', JSON.stringify(options, null, 2));
                
                const credential = await navigator.credentials.get({
                    publicKey: options
                });
                
                // 3. 發送認證結果到後端
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
                
                // 檢查響應狀態
                if (!authResponse.ok) {
                    const errorText = await authResponse.text();
                    console.error('認證響應錯誤:', authResponse.status, errorText);
                    throw new Error(`認證失敗 (${authResponse.status}): ${errorText || '未知錯誤'}`);
                }
                
                // 獲取響應文本，檢查是否為有效的 JSON
                const responseText = await authResponse.text();
                if (!responseText || responseText.trim() === '') {
                    throw new Error('伺服器返回空響應');
                }
                
                let authData;
                try {
                    authData = JSON.parse(responseText);
                } catch (e) {
                    console.error('JSON 解析錯誤:', e);
                    console.error('響應內容:', responseText);
                    throw new Error('伺服器返回的資料格式錯誤: ' + e.message);
                }
                
                if (!authData.success) {
                    throw new Error(authData.message || '認證失敗');
                }
                
                // 檢查是否使用了平台認證器
                if (startData.has_platform_credential && !authData.is_platform) {
                    // 雖然有平台認證器，但可能使用了 USB 金鑰
                    console.warn('檢測到可能使用了非平台認證器');
                    // 這裡可以選擇拒絕或允許，為了用戶體驗，我們先允許
                }
                
                // 4. 認證成功，儲存結果
                webauthnAuthResult = authData;
                statusDiv.innerHTML = '<i class="fas fa-check-circle" style="font-size: 48px; color: #52c41a; margin-bottom: 16px;"></i><p style="font-size: 16px; color: #52c41a;">生物驗證成功！</p>';
                
                // 自動提交簽名
                await submitWebAuthnSignature();
                
            } catch (error) {
                console.error('WebAuthn 認證錯誤:', error);
                errorDiv.textContent = error.message || '認證失敗，請重試';
                errorDiv.style.display = 'block';
                statusDiv.innerHTML = '<i class="fas fa-fingerprint" style="font-size: 48px; color: #1890ff; margin-bottom: 16px;"></i><p style="font-size: 16px; color: #666; margin-bottom: 8px;">使用手機生物驗證進行簽名</p><p style="font-size: 14px; color: #999;">支援指紋辨識、臉部辨識等</p>';
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-fingerprint"></i> 使用生物驗證簽名';
            }
        }
        
        // 提交 WebAuthn 簽名
        async function submitWebAuthnSignature() {
            if (!webauthnAuthResult) {
                showToast('請先完成生物驗證', 'error');
                return;
            }
            
            const submitBtn = document.getElementById('webauthnAuthBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 儲存中...';
            
            try {
                const response = await fetch('save_signature.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        webauthn_auth: webauthnAuthResult,
                        user_id: <?php echo $user_id; ?>,
                        document_id: <?php echo $document_id ? $document_id : 'null'; ?>,
                        document_type: '<?php echo htmlspecialchars($document_type, ENT_QUOTES); ?>',
                        timestamp: new Date().toISOString(),
                        authentication_method: 'webauthn'
                    })
                });
                
                // 檢查響應狀態
                if (!response.ok) {
                    const errorText = await response.text();
                    console.error('儲存簽名響應錯誤:', response.status, errorText);
                    throw new Error(`儲存失敗 (${response.status}): ${errorText || '未知錯誤'}`);
                }
                
                // 獲取響應文本，檢查是否為有效的 JSON
                const responseText = await response.text();
                if (!responseText || responseText.trim() === '') {
                    throw new Error('伺服器返回空響應');
                }
                
                // 檢查是否為 HTML（PHP 錯誤頁面）
                if (responseText.trim().startsWith('<')) {
                    console.error('伺服器返回 HTML 而非 JSON:', responseText.substring(0, 200));
                    throw new Error('伺服器返回錯誤頁面，請檢查伺服器日誌');
                }
                
                let data;
                try {
                    data = JSON.parse(responseText);
                } catch (e) {
                    console.error('JSON 解析錯誤:', e);
                    console.error('響應內容:', responseText);
                    throw new Error('伺服器返回的資料格式錯誤: ' + e.message);
                }
                
                if (data.success) {
                    document.getElementById('signatureStatus').style.display = 'block';
                    document.getElementById('statusMessage').textContent = '簽名已儲存成功！';
                    showToast('簽名已儲存成功！', 'success');
                    
                    setTimeout(() => {
                        if (data.redirect_url) {
                            window.location.href = data.redirect_url;
                        } else if (window.opener) {
                            window.opener.postMessage({
                                type: 'signature_saved',
                                signature_id: data.signature_id,
                                signature_url: data.signature_url
                            }, '*');
                            window.close();
                        } else if (window.parent && window.parent !== window) {
                            window.parent.postMessage({
                                type: 'signature_saved',
                                signature_id: data.signature_id,
                                signature_url: data.signature_url
                            }, '*');
                        }
                    }, 2000);
                } else {
                    throw new Error(data.message || '儲存失敗');
                }
            } catch (error) {
                console.error('儲存簽名錯誤:', error);
                showToast('儲存失敗：' + error.message, 'error');
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-fingerprint"></i> 使用生物驗證簽名';
            }
        }
        
        // 顯示註冊模態框
        function showRegisterModal() {
            document.getElementById('registerModal').style.display = 'flex';
            // 重置為第一步
            document.getElementById('step1EmailVerification').style.display = 'block';
            document.getElementById('step2WebAuthnRegister').style.display = 'none';
            document.getElementById('verificationCodeInput').style.display = 'none';
            document.getElementById('verification2FACode').value = '';
            document.getElementById('registerStatus').innerHTML = '';
            // 載入用戶 Email
            loadUserEmail();
        }
        
        // 關閉註冊模態框
        function closeRegisterModal() {
            document.getElementById('registerModal').style.display = 'none';
            document.getElementById('registerStatus').innerHTML = '';
            // 重置所有欄位
            document.getElementById('step1EmailVerification').style.display = 'block';
            document.getElementById('step2WebAuthnRegister').style.display = 'none';
            document.getElementById('verificationCodeInput').style.display = 'none';
            document.getElementById('verification2FACode').value = '';
        }
        
        // 載入用戶 Email
        async function loadUserEmail() {
            try {
                const response = await fetch('get_user_info.php', {
                    headers: { 'Accept': 'application/json' }
                });
                
                // 檢查響應內容類型
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    const text = await response.text();
                    console.error('非 JSON 響應:', text);
                    document.getElementById('emailDisplay').textContent = '載入失敗';
                    return;
                }
                
                const data = await response.json();
                if (data.success && data.user && data.user.email) {
                    document.getElementById('emailDisplay').textContent = maskEmail(data.user.email);
                } else {
                    document.getElementById('emailDisplay').textContent = '未設定 Email';
                }
            } catch (error) {
                console.error('載入 Email 失敗:', error);
                document.getElementById('emailDisplay').textContent = '載入失敗';
            }
        }
        
        // 遮罩 Email
        function maskEmail(email) {
            const parts = email.split('@');
            if (parts.length !== 2) return email;
            const local = parts[0];
            const domain = parts[1];
            const showChars = Math.min(2, Math.floor(local.length / 2));
            const masked = local.substring(0, showChars) + '*'.repeat(local.length - showChars);
            return masked + '@' + domain;
        }
        
        // 發送 2FA 驗證碼
        async function sendWebAuthn2FA() {
            const sendBtn = document.getElementById('send2FABtn');
            const statusDiv = document.getElementById('registerStatus');
            
            sendBtn.disabled = true;
            sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 發送中...';
            statusDiv.innerHTML = '';
            
            try {
                const response = await fetch('api/send_webauthn_2fa.php', {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    }
                });
                
                // 檢查響應內容類型
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    const text = await response.text();
                    console.error('非 JSON 響應:', text);
                    throw new Error('伺服器返回了無效的響應格式');
                }
                
                const data = await response.json();
                
                if (!data.success) {
                    throw new Error(data.message || '發送驗證碼失敗');
                }
                
                statusDiv.innerHTML = '<p style="color: #52c41a;"><i class="fas fa-check-circle"></i> ' + data.message + '</p>';
                showToast(data.message, 'success');
                
                // 顯示驗證碼輸入框
                document.getElementById('verificationCodeInput').style.display = 'block';
                document.getElementById('send2FABtn').style.display = 'none';
                
                // 聚焦到驗證碼輸入框
                setTimeout(() => {
                    document.getElementById('verification2FACode').focus();
                }, 100);
                
            } catch (error) {
                console.error('發送驗證碼失敗:', error);
                let errorMessage = error.message;
                if (error.message.includes('JSON')) {
                    errorMessage = '系統錯誤，請稍後再試或聯繫管理員';
                }
                statusDiv.innerHTML = '<p style="color: #f5222d;"><i class="fas fa-exclamation-circle"></i> ' + errorMessage + '</p>';
                showToast(errorMessage, 'error');
                sendBtn.disabled = false;
                sendBtn.innerHTML = '<i class="fas fa-envelope"></i> 發送驗證碼';
            }
        }
        
        // 驗證 2FA 驗證碼
        async function verifyWebAuthn2FA() {
            const code = document.getElementById('verification2FACode').value.trim();
            const verifyBtn = document.getElementById('verify2FABtn');
            const statusDiv = document.getElementById('registerStatus');
            
            if (!/^\d{6}$/.test(code)) {
                statusDiv.innerHTML = '<p style="color: #f5222d;"><i class="fas fa-exclamation-circle"></i> 請輸入 6 位數驗證碼</p>';
                return;
            }
            
            verifyBtn.disabled = true;
            verifyBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 驗證中...';
            statusDiv.innerHTML = '';
            
            try {
                const response = await fetch('api/verify_webauthn_2fa.php', {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ code: code })
                });
                
                // 檢查響應內容類型
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    const text = await response.text();
                    console.error('非 JSON 響應:', text);
                    throw new Error('伺服器返回了無效的響應格式');
                }
                
                const data = await response.json();
                
                if (!data.success) {
                    throw new Error(data.message || '驗證失敗');
                }
                
                statusDiv.innerHTML = '<p style="color: #52c41a;"><i class="fas fa-check-circle"></i> ' + data.message + '</p>';
                showToast(data.message, 'success');
                
                // 延遲後切換到第二步
                setTimeout(() => {
                    document.getElementById('step1EmailVerification').style.display = 'none';
                    document.getElementById('step2WebAuthnRegister').style.display = 'block';
                    statusDiv.innerHTML = '';
                    // 若使用 IP 存取，顯示改用 localhost 的提示（WebAuthn 不支援 IP 作為 rpId）
                    const host = (typeof location !== 'undefined' && location.hostname) ? location.hostname : '';
                    const warnEl = document.getElementById('webauthnLocalhostWarning');
                    if (warnEl && (host === '127.0.0.1' || host === '::1')) warnEl.style.display = 'block';
                }, 1000);
                
            } catch (error) {
                console.error('驗證失敗:', error);
                let errorMessage = error.message;
                if (error.message.includes('JSON')) {
                    errorMessage = '系統錯誤，請稍後再試或聯繫管理員';
                }
                statusDiv.innerHTML = '<p style="color: #f5222d;"><i class="fas fa-exclamation-circle"></i> ' + errorMessage + '</p>';
                showToast(errorMessage, 'error');
                verifyBtn.disabled = false;
                verifyBtn.innerHTML = '<i class="fas fa-check"></i> 驗證並繼續';
            }
        }
        
        // Base64 URL 解碼並轉換為 ArrayBuffer
        function base64UrlToArrayBuffer(base64url) {
            // 將 base64url 轉換為標準 base64
            let base64 = base64url.replace(/-/g, '+').replace(/_/g, '/');
            // 補齊 padding
            while (base64.length % 4) {
                base64 += '=';
            }
            // 解碼為二進制字串
            const binaryString = atob(base64);
            // 轉換為 ArrayBuffer
            const bytes = new Uint8Array(binaryString.length);
            for (let i = 0; i < binaryString.length; i++) {
                bytes[i] = binaryString.charCodeAt(i);
            }
            return bytes.buffer;
        }
        
        // 開始 WebAuthn 註冊
        async function startWebAuthnRegister() {
            if (!checkWebAuthnSupport()) return;
            
            const statusDiv = document.getElementById('registerStatus');
            const startBtn = document.getElementById('startRegisterBtn');
            
            startBtn.disabled = true;
            startBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 準備中...';
            statusDiv.innerHTML = '<p style="color: #666;">正在準備註冊流程...</p>';
            
            try {
                // 1. 獲取註冊選項
                const startResponse = await fetch('webauthn_register.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'start' })
                });
                
                const startData = await startResponse.json();
                
                if (!startData.success) {
                    throw new Error(startData.message || '獲取註冊選項失敗');
                }
                
                // 2. 轉換選項中的 challenge 和 user.id 為 ArrayBuffer
                const options = startData.options;
                
                // 驗證必要欄位
                if (!options.challenge || !options.user || !options.user.id) {
                    throw new Error('註冊選項不完整');
                }
                
                // 轉換 challenge 為 ArrayBuffer
                options.challenge = base64UrlToArrayBuffer(options.challenge);
                
                // 轉換 user.id 為 ArrayBuffer（必須是 ArrayBuffer）
                options.user.id = base64UrlToArrayBuffer(options.user.id);
                
                // 驗證 user.id 不為空
                if (!options.user.id || options.user.id.byteLength === 0) {
                    throw new Error('用戶 ID 無效');
                }
                
                // 驗證 user.id 長度（WebAuthn 規範：1-64 字節）
                if (options.user.id.byteLength > 64) {
                    throw new Error('用戶 ID 長度超過限制');
                }
                
                // 調試資訊
                console.log('註冊選項:', {
                    rpId: options.rp?.id,
                    rpName: options.rp?.name,
                    userIdLength: options.user.id.byteLength,
                    userName: options.user.name,
                    userDisplayName: options.user.displayName,
                    userVerification: options.authenticatorSelection?.userVerification,
                    authenticatorAttachment: options.authenticatorSelection?.authenticatorAttachment
                });
                
                // 3. 調用 WebAuthn API
                statusDiv.innerHTML = '<p style="color: #666;">請使用您的生物驗證設備進行註冊...</p>';
                statusDiv.innerHTML += '<p style="color: #999; font-size: 12px; margin-top: 8px;">如果是 Android 手機，請確保已安裝最新版 Chrome 並已設定生物驗證。</p>';
                
                let credential = null;
                try {
                    credential = await navigator.credentials.create({
                        publicKey: options
                    });
                    
                    if (!credential) {
                        throw new Error('使用者取消或設備不支援生物驗證');
                    }
                } catch (webauthError) {
                    console.error('WebAuthn 建立失敗詳情:', {
                        message: webauthError.message,
                        name: webauthError.name,
                        code: webauthError.code,
                        options: options,
                        platform: navigator.platform,
                        userAgent: navigator.userAgent
                    });
                    let suggestion = '建議：\n1. 確保已設定指紋或臉部辨識\n2. 更新瀏覽器到最新版本\n3. 如為 Android，請使用 Chrome 並更新到最新版本';
                    if (webauthError.message && (webauthError.message.indexOf('invalid domain') > -1 || webauthError.message.indexOf('無效的網域') > -1)) {
                        suggestion = '目前網址為 IP（127.0.0.1）時，瀏覽器不支援生物驗證。請改用 http://localhost 開啟本頁後再試。';
                    }
                    throw new Error('生物驗證失敗: ' + webauthError.message + '\n\n' + suggestion);
                }
                
                // 3. 發送註冊結果到後端
                const registerResponse = await fetch('webauthn_register.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'complete',
                        credential: {
                            id: credential.id,
                            rawId: arrayBufferToBase64(credential.rawId),
                            response: {
                                attestationObject: arrayBufferToBase64(credential.response.attestationObject),
                                clientDataJSON: arrayBufferToBase64(credential.response.clientDataJSON),
                                transports: credential.response.getTransports?.() || []
                            },
                            type: credential.type
                        }
                    })
                });
                
                const registerData = await registerResponse.json();
                
                if (!registerData.success) {
                    throw new Error(registerData.message || '註冊失敗');
                }
                
                if (registerData.email_verification_required) {
                    statusDiv.innerHTML = '<p style="color: #1890ff;"><i class="fas fa-envelope"></i> ' + (registerData.message || '已寄送驗證信，請點擊信中連結完成設備註冊。') + '</p>';
                    showToast(registerData.message || '請至信箱點擊連結完成設備註冊', 'success');
                } else {
                    statusDiv.innerHTML = '<p style="color: #52c41a;"><i class="fas fa-check-circle"></i> 設備註冊成功！</p>';
                    showToast('設備註冊成功！現在可以使用生物驗證進行簽名', 'success');
                    setTimeout(() => { closeRegisterModal(); }, 2000);
                }
                
            } catch (error) {
                console.error('WebAuthn 註冊錯誤:', error);
                statusDiv.innerHTML = '<p style="color: #f5222d;"><i class="fas fa-exclamation-circle"></i> ' + (error.message || '註冊失敗，請重試') + '</p>';
            } finally {
                startBtn.disabled = false;
                startBtn.innerHTML = '<i class="fas fa-fingerprint"></i> 開始註冊';
            }
        }
        
        // 工具函數：ArrayBuffer 轉 Base64
        function arrayBufferToBase64(buffer) {
            const bytes = new Uint8Array(buffer);
            let binary = '';
            for (let i = 0; i < bytes.byteLength; i++) {
                binary += String.fromCharCode(bytes[i]);
            }
            return btoa(binary);
        }
        
        // Canvas 初始化
        function initCanvas() {
            if (!ctx) return;

            // 設定畫筆樣式
            ctx.strokeStyle = '#000000';
            ctx.lineWidth = 2.5;
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';

            // 調整 Canvas 大小以適應螢幕
            function resizeCanvas() {
                const container = canvas.parentElement;
                const maxWidth = Math.min(800, container.clientWidth - 48);
                canvas.style.width = maxWidth + 'px';
                canvas.style.height = (maxWidth * 350 / 800) + 'px';
            }

            window.addEventListener('resize', resizeCanvas);
            resizeCanvas();

        // 獲取滑鼠/觸控在 Canvas 上的座標
        function getEventPos(e) {
            const rect = canvas.getBoundingClientRect();
            const scaleX = canvas.width / rect.width;
            const scaleY = canvas.height / rect.height;
            
            if (e.touches && e.touches.length > 0) {
                return {
                    x: (e.touches[0].clientX - rect.left) * scaleX,
                    y: (e.touches[0].clientY - rect.top) * scaleY
                };
            } else {
                return {
                    x: (e.clientX - rect.left) * scaleX,
                    y: (e.clientY - rect.top) * scaleY
                };
            }
        }

        // 滑鼠事件
        canvas.addEventListener('mousedown', (e) => {
            isDrawing = true;
            const pos = getEventPos(e);
            lastX = pos.x;
            lastY = pos.y;
        });

        canvas.addEventListener('mousemove', (e) => {
            if (!isDrawing) return;
            
            const pos = getEventPos(e);
            drawLine(lastX, lastY, pos.x, pos.y);
            lastX = pos.x;
            lastY = pos.y;
        });

        canvas.addEventListener('mouseup', stopDrawing);
        canvas.addEventListener('mouseout', stopDrawing);

        // 觸控事件（支援手機和平板）
        canvas.addEventListener('touchstart', (e) => {
            e.preventDefault();
            isDrawing = true;
            const pos = getEventPos(e);
            lastX = pos.x;
            lastY = pos.y;
        });

        canvas.addEventListener('touchmove', (e) => {
            e.preventDefault();
            if (!isDrawing) return;
            
            const pos = getEventPos(e);
            drawLine(lastX, lastY, pos.x, pos.y);
            lastX = pos.x;
            lastY = pos.y;
        });

        canvas.addEventListener('touchend', (e) => {
            e.preventDefault();
            stopDrawing();
        });

        function drawLine(x1, y1, x2, y2) {
            ctx.beginPath();
            ctx.moveTo(x1, y1);
            ctx.lineTo(x2, y2);
            ctx.stroke();
        }

        function stopDrawing() {
            isDrawing = false;
        }

        function clearSignature() {
            if (confirm('確定要清除簽名嗎？')) {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                document.getElementById('preview').classList.remove('active');
                showToast('簽名已清除', 'success');
            }
        }

        function saveAsImage() {
            const dataURL = canvas.toDataURL('image/png');
            const link = document.createElement('a');
            link.download = 'signature_' + new Date().getTime() + '.png';
            link.href = dataURL;
            link.click();
            showToast('圖片已下載', 'success');
        }

        }
        
        // 提交 Canvas 簽名
        function submitCanvasSignature() {
            if (!ctx) {
                showToast('簽名畫布未初始化', 'error');
                return;
            }
            
            // 檢查是否有簽名
            const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
            const hasSignature = imageData.data.some((channel, index) => {
                return index % 4 !== 3 && channel !== 255; // 檢查是否有非白色像素
            });

            if (!hasSignature) {
                showToast('請先進行簽名', 'error');
                return;
            }

            // 轉換為 Base64
            const signatureData = canvas.toDataURL('image/png');

            // 顯示預覽
            document.getElementById('previewImage').src = signatureData;
            document.getElementById('preview').classList.add('active');

            // 禁用提交按鈕
            const submitBtn = document.getElementById('submitCanvasBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 儲存中...';

            // 發送到後端
            fetch('save_signature.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    signature: signatureData,
                    user_id: <?php echo $user_id; ?>,
                    document_id: <?php echo $document_id ? $document_id : 'null'; ?>,
                    document_type: '<?php echo htmlspecialchars($document_type, ENT_QUOTES); ?>',
                    timestamp: new Date().toISOString(),
                    authentication_method: 'canvas'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('signatureStatus').style.display = 'block';
                    document.getElementById('statusMessage').textContent = '簽名已儲存成功！';
                    showToast('簽名已儲存成功！', 'success');
                    // 3秒後可以選擇重定向或關閉視窗
                    setTimeout(() => {
                        if (data.redirect_url) {
                            window.location.href = data.redirect_url;
                        } else if (window.opener) {
                            // 如果是彈出視窗，通知父視窗
                            window.opener.postMessage({
                                type: 'signature_saved',
                                signature_id: data.signature_id,
                                signature_url: data.signature_url
                            }, '*');
                            window.close();
                        } else if (window.parent && window.parent !== window) {
                            window.parent.postMessage({
                                type: 'signature_saved',
                                signature_id: data.signature_id,
                                signature_url: data.signature_url
                            }, '*');
                        }
                    }, 2000);
                } else {
                    showToast('儲存失敗：' + (data.message || '未知錯誤'), 'error');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fas fa-check"></i> 確認簽名';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('儲存失敗，請稍後再試', 'error');
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-check"></i> 確認簽名';
            });
        }

        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = 'toast ' + type + ' show';
            
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => {
                    toast.textContent = '';
                }, 500);
            }, 3000);
        }
    </script>
</body>
</html>

