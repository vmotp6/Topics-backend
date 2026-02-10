-- ============================================================================
-- WebAuthn 設備註冊待驗證表
-- 用於暫存註冊流程中的設備資訊，等待 Email 驗證後才正式寫入 webauthn_credentials
-- ============================================================================

CREATE TABLE IF NOT EXISTS `webauthn_register_pending` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '主鍵',
  `user_id` INT NOT NULL COMMENT '用戶 ID（關聯到 user 表）',
  `credential_id` VARCHAR(512) NOT NULL COMMENT 'WebAuthn 憑證 ID（Base64 編碼）',
  `public_key` TEXT NOT NULL COMMENT '公開金鑰（Base64 編碼）',
  `device_name` VARCHAR(255) NULL DEFAULT NULL COMMENT '設備名稱（如：iPhone 13、Windows 設備）',
  `device_type` VARCHAR(50) NULL DEFAULT NULL COMMENT '設備類型（phone、desktop、tablet）',
  `verify_token` VARCHAR(64) NOT NULL UNIQUE COMMENT 'Email 驗證 Token（唯一）',
  `verify_expires_at` DATETIME NOT NULL COMMENT '驗證連結過期時間',
  `ip_address` VARCHAR(45) NULL DEFAULT NULL COMMENT '註冊請求 IP 地址（支援 IPv6）',
  `user_agent` VARCHAR(500) NULL DEFAULT NULL COMMENT '瀏覽器 User Agent',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '建立時間',
  
  -- 索引設計
  INDEX `idx_token` (`verify_token`) COMMENT 'Token 索引（加速驗證查詢）',
  INDEX `idx_user` (`user_id`, `created_at` DESC) COMMENT '用戶索引（查詢用戶的待驗證設備）',
  INDEX `idx_expires` (`verify_expires_at`) COMMENT '過期時間索引（用於定期清理）',
  INDEX `idx_credential` (`credential_id`(255)) COMMENT '憑證 ID 索引（防止重複註冊）',
  
  -- 外鍵約束
  CONSTRAINT `fk_webauthn_pending_user` 
    FOREIGN KEY (`user_id`) 
    REFERENCES `user` (`id`) 
    ON DELETE CASCADE 
    ON UPDATE CASCADE
    COMMENT '關聯到用戶表，用戶刪除時級聯刪除待驗證記錄'
    
) ENGINE=InnoDB 
  DEFAULT CHARSET=utf8mb4 
  COLLATE=utf8mb4_unicode_ci 
  COMMENT='WebAuthn 設備註冊待驗證表';

-- ============================================================================
-- 建立定期清理過期待驗證記錄的事件（可選）
-- 每天凌晨 3 點清理 24 小時前的已過期記錄
-- ============================================================================

DROP EVENT IF EXISTS `evt_clean_expired_webauthn_pending`;

DELIMITER $$
CREATE EVENT IF NOT EXISTS `evt_clean_expired_webauthn_pending`
ON SCHEDULE EVERY 1 DAY
STARTS TIMESTAMP(CURRENT_DATE, '03:00:00')
COMMENT '每天凌晨 3 點清理過期的 WebAuthn 待驗證記錄'
DO
BEGIN
  DELETE FROM `webauthn_register_pending` 
  WHERE `verify_expires_at` < DATE_SUB(NOW(), INTERVAL 24 HOUR);
END$$
DELIMITER ;

-- ============================================================================
-- 使用範例查詢
-- ============================================================================

-- 1. 查詢用戶的待驗證設備
-- SELECT * FROM webauthn_register_pending 
-- WHERE user_id = ? 
--   AND verify_expires_at > NOW()
-- ORDER BY created_at DESC;

-- 2. 驗證 Token 並獲取設備資訊
-- SELECT * FROM webauthn_register_pending 
-- WHERE verify_token = ? 
--   AND verify_expires_at > NOW()
-- LIMIT 1;

-- 3. 統計待驗證設備數量
-- SELECT 
--   COUNT(*) as total_pending,
--   COUNT(CASE WHEN verify_expires_at > NOW() THEN 1 END) as valid_pending,
--   COUNT(CASE WHEN verify_expires_at <= NOW() THEN 1 END) as expired_pending
-- FROM webauthn_register_pending;

-- ============================================================================
-- 維護操作
-- ============================================================================

-- 手動清理過期記錄
-- DELETE FROM webauthn_register_pending 
-- WHERE verify_expires_at < DATE_SUB(NOW(), INTERVAL 24 HOUR);

-- 清理用戶的所有待驗證記錄
-- DELETE FROM webauthn_register_pending WHERE user_id = ?;

-- 查看表資訊
-- SHOW INDEX FROM webauthn_register_pending;
