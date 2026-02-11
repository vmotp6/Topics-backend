<?php
/**
 * 發送 WebAuthn 註冊前的 2FA 驗證碼
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
    // 獲取用戶資料
    $conn = getDatabaseConnection();
    
    $stmt = $conn->prepare("SELECT username, name, email FROM user WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    
    if (!$user) {
        throw new Exception('找不到用戶資料');
    }
    
    $email = trim($user['email'] ?? '');
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('請先在「個人資料」設定有效的 Email，才能註冊生物驗證設備。');
    }
    
    // 生成 6 位數驗證碼
    $verification_code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    
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
    
    // 刪除該用戶之前未使用的驗證碼
    $delete_old = $conn->prepare("DELETE FROM webauthn_2fa_codes WHERE user_id = ? AND verified = 0");
    $delete_old->bind_param("i", $user_id);
    $delete_old->execute();
    $delete_old->close();
    
    // 插入新驗證碼（10分鐘有效期）
    $expires_at = date('Y-m-d H:i:s', time() + 600);
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    $insert_stmt = $conn->prepare("
        INSERT INTO webauthn_2fa_codes (user_id, code, expires_at, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?)
    ");
    
    if (!$insert_stmt) {
        throw new Exception('SQL 準備失敗: ' . $conn->error);
    }
    
    $insert_stmt->bind_param("issss", $user_id, $verification_code, $expires_at, $ip_address, $user_agent);
    if (!$insert_stmt->execute()) {
        throw new Exception('儲存驗證碼失敗: ' . $insert_stmt->error);
    }
    $insert_stmt->close();
    
    // 發送郵件
    $user_name = $user['name'] ?: $user['username'];
    $subject = "【招生系統】生物驗證設備註冊－2FA 驗證碼";
    
    $body = '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
            <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
                <h1 style="color: white; margin: 0; font-size: 28px;">
                    <i style="font-size: 36px;">🔐</i><br>
                    生物驗證設備註冊
                </h1>
            </div>
            <div style="background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px;">
                <h2 style="color: #333;">親愛的 ' . htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') . '，您好！</h2>
                <p style="color: #666; font-size: 16px; line-height: 1.6;">
                    您正在嘗試註冊新的生物驗證設備。為了確保您的帳號安全，請使用以下驗證碼完成身份驗證：
                </p>
                <div style="background: white; border: 2px dashed #667eea; border-radius: 10px; padding: 20px; text-align: center; margin: 30px 0;">
                    <div style="font-size: 14px; color: #999; margin-bottom: 10px;">驗證碼</div>
                    <div style="font-size: 36px; font-weight: bold; color: #667eea; letter-spacing: 8px; font-family: monospace;">
                        ' . $verification_code . '
                    </div>
                </div>
                <p style="color: #999; font-size: 14px; text-align: center;">
                    ⏱️ 此驗證碼將在 <strong style="color: #f56c6c;">10 分鐘</strong>後過期
                </p>
                <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin-top: 20px; border-radius: 4px;">
                    <p style="margin: 0; color: #856404; font-size: 14px;">
                        ⚠️ <strong>安全提示：</strong>如果您沒有嘗試註冊設備，請忽略此郵件並立即修改您的密碼。
                    </p>
                </div>
                <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; text-align: center; color: #999; font-size: 12px;">
                    <p>此郵件由系統自動發送，請勿直接回覆</p>
                    <p>© ' . date('Y') . ' 康寧大學招生系統</p>
                </div>
            </div>
        </div>
    ';
    
    $altBody = "您好 {$user_name}，\n\n您正在嘗試註冊新的生物驗證設備。請使用以下驗證碼完成身份驗證：\n\n{$verification_code}\n\n此驗證碼將在 10 分鐘後過期。\n\n如果您沒有嘗試註冊設備，請忽略此郵件。";
    
    // 使用 PHPMailer 發送郵件
    require_once __DIR__ . '/../../../Topics-frontend/frontend/includes/email_functions.php';
    
    $email_sent = sendEmail($email, $subject, $body, $altBody);
    
    if (!$email_sent) {
        throw new Exception('驗證碼郵件發送失敗，請稍後再試。');
    }
    
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'message' => '驗證碼已發送至 ' . maskEmail($email),
        'email_masked' => maskEmail($email)
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 遮罩 Email 顯示
 */
function maskEmail($email) {
    $parts = explode('@', $email);
    if (count($parts) !== 2) {
        return $email;
    }
    
    $local = $parts[0];
    $domain = $parts[1];
    
    $local_len = mb_strlen($local);
    if ($local_len <= 2) {
        $masked_local = str_repeat('*', $local_len);
    } else {
        $show_chars = min(2, floor($local_len / 2));
        $masked_local = mb_substr($local, 0, $show_chars) . str_repeat('*', $local_len - $show_chars);
    }
    
    return $masked_local . '@' . $domain;
}
