<?php
/**
 * WebAuthn 憑證註冊 API
 * 處理用戶註冊新的 WebAuthn 憑證（如手機生物驗證）
 */

require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/includes/webauthn_helpers.php';

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
                // 不限制 authenticatorAttachment，允許平台和跨平台認證器
                // 平台認證器：手機內建的指紋/臉部辨識、Windows Hello
                // 跨平台認證器：USB 金鑰等外部設備
                'userVerification' => 'preferred', // preferred 允許更多選項（PIN、生物驗證等）
                'requireResidentKey' => true, // 改為 true，Passkey 需要 resident key 才能跨設備同步
                // 不設置 authenticatorAttachment，讓用戶選擇
                // 如果設置為 'platform'，會強制使用平台認證器（Passkey）
            ],
            'timeout' => 120000, // 增加到 120 秒，給用戶更多時間
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
        
        // 儲存憑證
        $success = saveWebAuthnCredential(
            $user_id,
            base64UrlEncode($raw_id),
            $public_key,
            $device_name,
            $device_type
        );
        
        if (!$success) {
            throw new Exception('憑證儲存失敗');
        }
        
        // 清除 session 中的 challenge
        unset($_SESSION['webauthn_register_challenge']);
        unset($_SESSION['webauthn_register_timestamp']);
        
        echo json_encode([
            'success' => true,
            'message' => '憑證註冊成功',
            'credential_id' => base64UrlEncode($raw_id),
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

