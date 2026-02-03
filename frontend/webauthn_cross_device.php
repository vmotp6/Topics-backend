<?php
/**
 * WebAuthn 跨設備認證 API
 * 允許在電腦上顯示 QR 碼，用戶用手機掃描後完成生物驗證
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
    $action = $input['action'] ?? $_GET['action'] ?? '';
    
    if ($action === 'create_session') {
        // 創建跨設備認證 session
        $session_id = bin2hex(random_bytes(16));
        $challenge = random_bytes(32);
        $expires_at = time() + 300; // 5分鐘過期
        
        // 獲取用戶資訊用於驗證
        $username = $_SESSION['username'] ?? '';
        $user_name = $_SESSION['name'] ?? $username;
        
        // 儲存到 session（包含用戶資訊用於驗證）
        $_SESSION['cross_device_sessions'][$session_id] = [
            'user_id' => $user_id,
            'username' => $username,
            'user_name' => $user_name,
            'challenge' => $challenge,
            'expires_at' => $expires_at,
            'status' => 'pending',
            'created_at' => time(),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '' // 記錄 IP 用於安全日誌
        ];
        
        // 生成 QR 碼 URL（包含 session_id）
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $qr_url = $protocol . '://' . $host . '/Topics-backend/frontend/webauthn_mobile_auth.php?session_id=' . $session_id;
        
        echo json_encode([
            'success' => true,
            'session_id' => $session_id,
            'qr_url' => $qr_url,
            'expires_at' => $expires_at,
            'user_name' => $user_name // 返回用戶名稱用於顯示
        ], JSON_UNESCAPED_UNICODE);
        
    } else if ($action === 'check_status') {
        // 檢查認證狀態
        $session_id = $input['session_id'] ?? $_GET['session_id'] ?? '';
        
        if (empty($session_id)) {
            throw new Exception('Session ID 缺失');
        }
        
        $session = $_SESSION['cross_device_sessions'][$session_id] ?? null;
        
        if (!$session) {
            echo json_encode([
                'success' => false,
                'status' => 'not_found',
                'message' => 'Session 不存在或已過期'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        if (time() > $session['expires_at']) {
            unset($_SESSION['cross_device_sessions'][$session_id]);
            echo json_encode([
                'success' => false,
                'status' => 'expired',
                'message' => 'Session 已過期'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        echo json_encode([
            'success' => true,
            'status' => $session['status'],
            'result' => $session['result'] ?? null
        ], JSON_UNESCAPED_UNICODE);
        
    } else if ($action === 'complete') {
        // 手機端完成認證後調用
        $session_id = $input['session_id'] ?? '';
        $auth_result = $input['auth_result'] ?? null;
        
        if (empty($session_id) || !$auth_result) {
            throw new Exception('參數缺失');
        }
        
        $session = $_SESSION['cross_device_sessions'][$session_id] ?? null;
        
        if (!$session) {
            throw new Exception('Session 不存在或已過期');
        }
        
        if (time() > $session['expires_at']) {
            unset($_SESSION['cross_device_sessions'][$session_id]);
            throw new Exception('Session 已過期');
        }
        
        // 安全性檢查：驗證手機端的用戶 ID
        $mobile_user_id = $_SESSION['user_id'] ?? 0;
        if ($mobile_user_id != $session['user_id']) {
            error_log("跨設備認證安全錯誤: Session user_id={$session['user_id']}, Mobile user_id={$mobile_user_id}");
            throw new Exception('安全錯誤：用戶身份驗證失敗。您登入的帳號與認證請求不符。');
        }
        
        // 額外安全檢查：驗證認證結果中的憑證是否屬於正確的用戶
        // 從 auth_result 中提取 credential_id，驗證它是否屬於 session 中的 user_id
        if (isset($auth_result['credential_id'])) {
            require_once '../../Topics-frontend/frontend/config.php';
            $conn = getDatabaseConnection();
            $check_stmt = $conn->prepare("SELECT user_id FROM webauthn_credentials WHERE credential_id = ?");
            $credential_id = $auth_result['credential_id'];
            $check_stmt->bind_param("s", $credential_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $cred = $check_result->fetch_assoc();
                if ($cred['user_id'] != $session['user_id']) {
                    $check_stmt->close();
                    $conn->close();
                    error_log("跨設備認證安全錯誤: 憑證屬於其他用戶。Session user_id={$session['user_id']}, 憑證用戶 ID={$cred['user_id']}, Mobile user_id={$mobile_user_id}");
                    throw new Exception('安全錯誤：此憑證屬於其他用戶。請使用您自己的設備進行認證。');
                }
            } else {
                // 憑證不在資料庫中（可能是 Passkey 跨設備）
                // 但我們需要確保這個認證是從正確的用戶發起的
                // 這個檢查已經在 webauthn_authenticate.php 中完成（通過 userHandle 驗證）
                // 但我們再次確認 mobile_user_id 匹配
                if ($mobile_user_id != $session['user_id']) {
                    $check_stmt->close();
                    $conn->close();
                    error_log("跨設備認證安全錯誤: Passkey 憑證，但用戶 ID 不匹配。Session user_id={$session['user_id']}, Mobile user_id={$mobile_user_id}");
                    throw new Exception('安全錯誤：用戶身份驗證失敗。請使用您自己的設備進行認證。');
                }
            }
            $check_stmt->close();
            $conn->close();
        } else {
            // 沒有 credential_id，這不應該發生
            error_log("跨設備認證安全錯誤: 認證結果中沒有 credential_id");
            throw new Exception('安全錯誤：認證結果無效');
        }
        
        // 更新 session 狀態
        $_SESSION['cross_device_sessions'][$session_id]['status'] = 'completed';
        $_SESSION['cross_device_sessions'][$session_id]['result'] = $auth_result;
        $_SESSION['cross_device_sessions'][$session_id]['completed_at'] = time();
        $_SESSION['cross_device_sessions'][$session_id]['mobile_user_id'] = $mobile_user_id; // 記錄手機端用戶 ID
        
        echo json_encode([
            'success' => true,
            'message' => '認證結果已儲存'
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
    
    error_log('跨設備認證錯誤: ' . $e->getMessage());
}

