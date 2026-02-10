<?php
/**
 * WebAuthn 憑證註冊 API
 * 處理用戶註冊新的 WebAuthn 憑證（如手機生物驗證）
 */

require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/includes/webauthn_helpers.php';
require_once __DIR__ . '/../../Topics-frontend/frontend/includes/email_functions.php';

header('Content-Type: application/json; charset=utf-8');

// 檢查登入狀態
checkBackendLogin();

$user_id = $_SESSION['user_id'] ?? 0;
if ($user_id <= 0) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => '未登入'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    if ($action === 'start') {
        // 檢查是否已通過 2FA 驗證（5分鐘內有效）
        if (!isset($_SESSION['webauthn_2fa_verified']) || 
            !isset($_SESSION['webauthn_2fa_verified_time']) ||
            (time() - $_SESSION['webauthn_2fa_verified_time']) > 300) {
            throw new Exception('請先完成郵件驗證碼驗證');
        }
        
        // 開始註冊流程：生成 challenge 和註冊選項
        // 生成 32 字節的隨機 challenge
        $challenge = random_bytes(32);
        
        // 獲取用戶資訊
        $user_id = $_SESSION['user_id'] ?? 0;
        $username = $_SESSION['username'] ?? 'user';
        $user_name = $_SESSION['name'] ?? $username;
        
        // 將 user_id 轉換為二進制數據（WebAuthn 要求 user.id 是 ArrayBuffer）
        // 使用 64 位整數格式（8 字節），確保唯一性
        if ($user_id > 0) {
            $user_id_bytes = pack('J', $user_id); // J = 64-bit unsigned integer (little-endian)
        } else {
            // 如果 user_id 為 0 或無效，使用隨機數據確保唯一性
            $user_id_bytes = random_bytes(64); // 使用 64 字節確保唯一性
        }
        
        // Base64 URL 編碼用於傳輸
        $user_id_base64 = base64UrlEncode($user_id_bytes);
        
        // 獲取 RP ID（域名）
        $rp_id = getRelyingPartyId();
        
        // 儲存 challenge 到 session
        $_SESSION['webauthn_register_challenge'] = $challenge;
        $_SESSION['webauthn_register_timestamp'] = time();
        
        $publicKeyCredentialCreationOptions = [
            'challenge' => base64UrlEncode($challenge),
            'rp' => [
                'name' => 'Topics 電子簽章系統',
                'id' => $rp_id
            ],
            'user' => [
                'id' => $user_id_base64, // 前端會轉換為 ArrayBuffer
                'name' => $username,
                'displayName' => $user_name
            ],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],  // ES256
                ['type' => 'public-key', 'alg' => -257] // RS256
            ],
            'authenticatorSelection' => [
                // 支持 Android 設備上的生物驗證
                // Android 支持：平台認證器（通過 Google Play 服務）、跨平台認證器
                'authenticatorAttachment' => 'platform', // 優先使用平台認證器（手機內建生物驗證）
                'userVerification' => 'preferred',      // 允許生物驗證、PIN、密碼等驗證方式
                'requireResidentKey' => false,          // 不要求 resident key，提高 Android 兼容性
            ],
            'timeout' => 120000, // 120 秒，給用戶充足時間
            'attestation' => 'none' // 不需要 attestation
        ];
        
        // 調試資訊
        error_log("WebAuthn 註冊 - 用戶 ID: $user_id, RP ID: $rp_id, Username: $username, User ID bytes length: " . strlen($user_id_bytes));
        
        echo json_encode([
            'success' => true,
            'options' => $publicKeyCredentialCreationOptions
        ], JSON_UNESCAPED_UNICODE);
        
    } else if ($action === 'complete') {
        // 完成註冊流程：驗證並儲存憑證
        if (!isset($_SESSION['webauthn_register_challenge'])) {
            throw new Exception('註冊流程未開始或已過期');
        }
        
        // 檢查 challenge 是否過期（5分鐘）
        if (time() - $_SESSION['webauthn_register_timestamp'] > 300) {
            unset($_SESSION['webauthn_register_challenge']);
            throw new Exception('註冊流程已過期，請重新開始');
        }
        
        $challenge = $_SESSION['webauthn_register_challenge'];
        $credential = $input['credential'] ?? null;
        
        if (!$credential) {
            throw new Exception('憑證資料缺失');
        }
        
        $credential_id = $credential['id'] ?? '';
        $raw_id = $credential['rawId'] ?? '';
        $response = $credential['response'] ?? [];
        
        // 解析憑證資料
        $attestation_object = base64UrlDecode($response['attestationObject'] ?? '');
        $client_data_json = base64UrlDecode($response['clientDataJSON'] ?? '');
        
        // 驗證 clientDataJSON
        $client_data = json_decode($client_data_json, true);
        if (!$client_data) {
            throw new Exception('ClientDataJSON 解析失敗');
        }
        
        // 驗證 challenge
        $received_challenge = base64UrlDecode($client_data['challenge'] ?? '');
        if ($received_challenge !== $challenge) {
            throw new Exception('Challenge 驗證失敗');
        }
        
        // 驗證 origin
        $expected_origin = getRelyingPartyOrigin();
        if ($client_data['origin'] !== $expected_origin) {
            throw new Exception('Origin 驗證失敗');
        }
        
        // 驗證 type
        if ($client_data['type'] !== 'webauthn.create') {
            throw new Exception('註冊類型錯誤');
        }
        
        // 解析 attestationObject（簡化版本）
        // 注意：完整的實現需要使用 CBOR 解析器來解析 attestationObject
        // 這裡我們先儲存原始資料，實際驗證可以在後續改進
        
        // 從 attestationObject 中提取公開金鑰（簡化處理）
        // 實際應該使用 CBOR 解析器解析並驗證
        $public_key = base64_encode($attestation_object); // 暫時儲存原始資料
        
        // 獲取設備資訊
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $device_name = '未知設備';
        $device_type = 'unknown';
        
        // 檢測是否為移動設備
        $is_mobile = preg_match('/Mobile|Android|iPhone|iPad|iPod|BlackBerry|Windows Phone/', $user_agent);
        
        if ($is_mobile) {
            if (preg_match('/iPhone|iPod/', $user_agent)) {
                $device_name = 'iPhone';
                $device_type = 'phone';
            } else if (preg_match('/iPad/', $user_agent)) {
                $device_name = 'iPad';
                $device_type = 'tablet';
            } else if (preg_match('/Android/', $user_agent)) {
                // 嘗試區分手機和平板
                if (preg_match('/Mobile/', $user_agent)) {
                    $device_name = 'Android 手機';
                    $device_type = 'phone';
                } else {
                    $device_name = 'Android 平板';
                    $device_type = 'tablet';
                }
            } else {
                $device_name = '行動設備';
                $device_type = 'phone';
            }
        } else {
            // 桌面設備，但可能是 Windows Hello 等平台認證器
            $device_type = 'desktop';
            if (preg_match('/Windows/', $user_agent)) {
                $device_name = 'Windows 設備';
            } else if (preg_match('/Mac/', $user_agent)) {
                $device_name = 'Mac 設備';
        } else {
            $device_name = '桌面設備';
            }
        }
        
        // 直接保存設備到 webauthn_credentials 表（跳過郵件確認）
        $conn = getDatabaseConnection();
        
        // 確保 webauthn_credentials 表存在
        $ensure_table = $conn->query("
            CREATE TABLE IF NOT EXISTS webauthn_credentials (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                credential_id VARCHAR(512) NOT NULL UNIQUE,
                public_key TEXT NOT NULL,
                sign_count INT DEFAULT 0,
                device_name VARCHAR(255) NULL,
                device_type VARCHAR(50) NULL,
                transports JSON NULL,
                ip_address VARCHAR(45) NULL,
                user_agent TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_used_at DATETIME NULL,
                INDEX idx_user_id (user_id),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        // 添加缺失的欄位（如果表已存在）
        $conn->query("ALTER TABLE webauthn_credentials ADD COLUMN IF NOT EXISTS sign_count INT DEFAULT 0");
        $conn->query("ALTER TABLE webauthn_credentials ADD COLUMN IF NOT EXISTS ip_address VARCHAR(45) NULL");
        $conn->query("ALTER TABLE webauthn_credentials ADD COLUMN IF NOT EXISTS user_agent TEXT NULL");
        $conn->query("ALTER TABLE webauthn_credentials ADD COLUMN IF NOT EXISTS transports JSON NULL");
        $conn->query("ALTER TABLE webauthn_credentials ADD COLUMN IF NOT EXISTS last_used_at DATETIME NULL");
        
        $credential_id_encoded = base64UrlEncode($raw_id);
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $transports = isset($input['response']['transports']) ? json_encode($input['response']['transports']) : null;
        
        // 檢查是否已存在此設備
        $check_stmt = $conn->prepare("SELECT id FROM webauthn_credentials WHERE user_id = ? AND credential_id = ? LIMIT 1");
        $check_stmt->bind_param("is", $user_id, $credential_id_encoded);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        if ($check_result->num_rows > 0) {
            $conn->close();
            throw new Exception('此設備已註冊過，無需重複註冊。');
        }
        $check_stmt->close();
        
        // 直接保存設備到 webauthn_credentials 表（不指定 sign_count，讓 DEFAULT 值自動設置）
        $ins = $conn->prepare("
            INSERT INTO webauthn_credentials (user_id, credential_id, public_key, device_name, device_type, ip_address, user_agent, transports)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->bind_param("isssssss", $user_id, $credential_id_encoded, $public_key, $device_name, $device_type, $ip_address, $user_agent, $transports);
        if (!$ins->execute()) {
            $conn->close();
            throw new Exception('設備註冊失敗，請重試。');
        }
        $ins->close();
        
        $conn->close();
        
        // 清除 session 中的 2FA 驗證標誌
        unset($_SESSION['webauthn_register_challenge']);
        unset($_SESSION['webauthn_register_timestamp']);
        unset($_SESSION['webauthn_2fa_verified']);
        unset($_SESSION['webauthn_2fa_verified_time']);
        
        echo json_encode([
            'success' => true,
            'email_verification_required' => false,
            'message' => '設備註冊成功！',
            'credential_id' => $credential_id_encoded,
            'device_name' => $device_name
        ], JSON_UNESCAPED_UNICODE);
        
    } else {
        throw new Exception('無效的操作');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    
    error_log('WebAuthn 註冊錯誤: ' . $e->getMessage());
}

