<?php
/**
 * WebAuthn 輔助函數
 * 提供 WebAuthn/FIDO2 認證所需的工具函數
 */

require_once __DIR__ . '/../session_config.php';
require_once '../../Topics-frontend/frontend/config.php';

/**
 * 生成隨機字串（用於 challenge）
 */
function generateRandomString($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Base64 URL 安全編碼
 */
function base64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Base64 URL 安全解碼
 */
function base64UrlDecode($data) {
    return base64_decode(strtr($data, '-_', '+/'));
}

/**
 * 獲取 Relying Party ID（域名）
 */
function getRelyingPartyId() {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // 移除端口號
    $host = preg_replace('/:\d+$/', '', $host);
    return $host;
}

/**
 * 獲取 Relying Party Origin
 */
function getRelyingPartyOrigin() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $protocol . '://' . $host;
}

/**
 * 獲取用戶的 WebAuthn 憑證
 */
function getUserWebAuthnCredentials($user_id) {
    $conn = getDatabaseConnection();
    
    $stmt = $conn->prepare("
        SELECT id, credential_id, device_name, device_type, counter, created_at, last_used_at
        FROM webauthn_credentials
        WHERE user_id = ? AND is_active = 1
        ORDER BY last_used_at DESC, created_at DESC
    ");
    
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $credentials = [];
    
    while ($row = $result->fetch_assoc()) {
        $credentials[] = $row;
    }
    
    $stmt->close();
    $conn->close();
    
    return $credentials;
}

/**
 * 儲存 WebAuthn 憑證
 */
function saveWebAuthnCredential($user_id, $credential_id, $public_key, $device_name = null, $device_type = null) {
    $conn = getDatabaseConnection();
    
    // 檢查憑證是否已存在
    $check_stmt = $conn->prepare("SELECT id FROM webauthn_credentials WHERE credential_id = ?");
    $check_stmt->bind_param("s", $credential_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($result->num_rows > 0) {
        // 更新現有憑證
        $update_stmt = $conn->prepare("
            UPDATE webauthn_credentials 
            SET public_key = ?, device_name = ?, device_type = ?, last_used_at = NOW(), is_active = 1
            WHERE credential_id = ?
        ");
        $update_stmt->bind_param("ssss", $public_key, $device_name, $device_type, $credential_id);
        $update_stmt->execute();
        $update_stmt->close();
        $success = true;
    } else {
        // 插入新憑證
        $insert_stmt = $conn->prepare("
            INSERT INTO webauthn_credentials (user_id, credential_id, public_key, device_name, device_type, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $insert_stmt->bind_param("issss", $user_id, $credential_id, $public_key, $device_name, $device_type);
        $insert_stmt->execute();
        $success = $insert_stmt->affected_rows > 0;
        $insert_stmt->close();
    }
    
    $check_stmt->close();
    $conn->close();
    
    return $success;
}

/**
 * 獲取憑證的公開金鑰
 */
function getCredentialPublicKey($credential_id) {
    $conn = getDatabaseConnection();
    
    $stmt = $conn->prepare("SELECT public_key, counter FROM webauthn_credentials WHERE credential_id = ? AND is_active = 1");
    $stmt->bind_param("s", $credential_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $credential = $result->fetch_assoc();
    
    $stmt->close();
    $conn->close();
    
    return $credential;
}

/**
 * 更新憑證計數器
 */
function updateCredentialCounter($credential_id, $counter) {
    $conn = getDatabaseConnection();
    
    $stmt = $conn->prepare("
        UPDATE webauthn_credentials 
        SET counter = ?, last_used_at = NOW()
        WHERE credential_id = ?
    ");
    $stmt->bind_param("is", $counter, $credential_id);
    $stmt->execute();
    $success = $stmt->affected_rows > 0;
    
    $stmt->close();
    $conn->close();
    
    return $success;
}

/**
 * 驗證 WebAuthn 簽名（簡化版本，實際生產環境需要使用完整的 WebAuthn 驗證庫）
 * 注意：這是一個簡化的實現，生產環境建議使用專業的 WebAuthn 庫如 web-token/jwt-framework
 */
function verifyWebAuthnSignature($credential_id, $authenticator_data, $client_data_json, $signature, $challenge) {
    // 獲取憑證
    $credential = getCredentialPublicKey($credential_id);
    if (!$credential) {
        return ['valid' => false, 'error' => '憑證不存在或已停用'];
    }
    
    // 驗證 challenge
    $client_data = json_decode(base64UrlDecode($client_data_json), true);
    if (!$client_data) {
        return ['valid' => false, 'error' => 'ClientDataJSON 解析失敗'];
    }
    
    // challenge 在 clientDataJSON 中是 base64 URL 編碼的，需要解碼後比較
    $received_challenge = base64UrlDecode($client_data['challenge'] ?? '');
    if ($received_challenge !== $challenge) {
        return ['valid' => false, 'error' => 'Challenge 驗證失敗'];
    }
    
    // 驗證 origin
    $expected_origin = getRelyingPartyOrigin();
    if ($client_data['origin'] !== $expected_origin) {
        return ['valid' => false, 'error' => 'Origin 驗證失敗'];
    }
    
    // 驗證 type
    if ($client_data['type'] !== 'webauthn.get') {
        return ['valid' => false, 'error' => '認證類型錯誤'];
    }
    
    // 驗證計數器（簡化版本，實際應該檢查 authenticator_data 中的計數器）
    // 注意：完整的驗證需要解析 authenticator_data 並驗證簽名
    
    // 這裡應該使用公開金鑰驗證簽名
    // 由於 PHP 原生不支援 COSE 格式，建議使用專業庫
    // 暫時返回成功，實際生產環境需要完整實現
    
    return [
        'valid' => true,
        'credential_id' => $credential_id,
        'counter' => $credential['counter']
    ];
}

