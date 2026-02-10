<?php
/**
 * 驗證 WebAuthn 註冊前的 2FA 驗證碼
 */

// 清理輸出緩衝區，確保只輸出 JSON
if (ob_get_level()) {
    ob_end_clean();
}
ob_start();

require_once __DIR__ . '/../session_config.php';
require_once __DIR__ . '/../../../Topics-frontend/frontend/config.php';

// 清理可能的輸出
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

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
    $code = trim($input['code'] ?? '');
    
    if ($code === '' || !preg_match('/^\d{6}$/', $code)) {
        throw new Exception('請輸入 6 位數驗證碼');
    }
    
    $conn = getDatabaseConnection();
    
    // 確保表存在並有必要的欄位
    $ensure_table = $conn->query("
        CREATE TABLE IF NOT EXISTS webauthn_2fa_codes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            code VARCHAR(6) NOT NULL,
            expires_at DATETIME NOT NULL,
            verified TINYINT(1) DEFAULT 0,
            verified_at DATETIME NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_code (user_id, code),
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    if (!$ensure_table) {
        throw new Exception('資料庫表建立失敗: ' . $conn->error);
    }
    
    // 添加缺失的欄位（如果表已存在）
    $conn->query("ALTER TABLE webauthn_2fa_codes ADD COLUMN IF NOT EXISTS verified_at DATETIME NULL");
    $conn->query("ALTER TABLE webauthn_2fa_codes ADD COLUMN IF NOT EXISTS ip_address VARCHAR(45) NULL");
    $conn->query("ALTER TABLE webauthn_2fa_codes ADD COLUMN IF NOT EXISTS user_agent TEXT NULL");
    
    // 查詢驗證碼
    $stmt = $conn->prepare("
        SELECT id, code, expires_at, verified
        FROM webauthn_2fa_codes
        WHERE user_id = ? AND code = ?
        ORDER BY created_at DESC
        LIMIT 1
    ");
    
    if (!$stmt) {
        throw new Exception('SQL 準備失敗: ' . $conn->error);
    }
    
    $stmt->bind_param("is", $user_id, $code);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    if (!$row) {
        throw new Exception('驗證碼錯誤，請重新輸入');
    }
    
    if ($row['verified'] == 1) {
        throw new Exception('此驗證碼已使用過，請重新發送驗證碼');
    }
    
    // 檢查是否過期
    $expires_at = strtotime($row['expires_at']);
    if (time() > $expires_at) {
        throw new Exception('驗證碼已過期，請重新發送驗證碼');
    }
    
    // 標記為已驗證
    $verified_at = date('Y-m-d H:i:s');
    $update_stmt = $conn->prepare("
        UPDATE webauthn_2fa_codes
        SET verified = 1, verified_at = ?
        WHERE id = ?
    ");
    
    if (!$update_stmt) {
        throw new Exception('SQL 準備失敗: ' . $conn->error);
    }
    
    $update_stmt->bind_param("si", $verified_at, $row['id']);
    if (!$update_stmt->execute()) {
        throw new Exception('驗證碼更新失敗: ' . $update_stmt->error);
    }
    $update_stmt->close();
    
    // 將驗證結果存入 session，供後續註冊使用
    $_SESSION['webauthn_2fa_verified'] = true;
    $_SESSION['webauthn_2fa_verified_time'] = time();
    
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'message' => '驗證成功！現在可以開始註冊設備'
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
