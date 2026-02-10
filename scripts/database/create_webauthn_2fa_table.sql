-- ============================================================================
-- WebAuthn 2FA 驗證碼資料表
-- 用於儲存設備註冊前的郵件雙因素驗證碼
-- ============================================================================

-- 建立資料表
CREATE TABLE IF NOT EXISTS `webauthn_2fa_codes` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '主鍵',
  `user_id` INT NOT NULL COMMENT '用戶 ID（關聯到 user 表）',
  `code` CHAR(6) NOT NULL COMMENT '6 位數驗證碼',
  `expires_at` DATETIME NOT NULL COMMENT '過期時間',
  `verified` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否已驗證（0=未驗證，1=已驗證）',
  `verified_at` DATETIME NULL DEFAULT NULL COMMENT '驗證時間',
  `ip_address` VARCHAR(45) NULL DEFAULT NULL COMMENT '請求 IP 地址（支援 IPv6）',
  `user_agent` VARCHAR(500) NULL DEFAULT NULL COMMENT '瀏覽器 User Agent',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '建立時間',
  
  -- 索引設計
  INDEX `idx_user_code` (`user_id`, `code`) COMMENT '用戶和驗證碼複合索引（加速查詢）',
  INDEX `idx_expires` (`expires_at`) COMMENT '過期時間索引（用於定期清理）',
  INDEX `idx_verified` (`verified`, `created_at`) COMMENT '驗證狀態索引（用於統計）',
  INDEX `idx_user_created` (`user_id`, `created_at` DESC) COMMENT '用戶建立時間索引（查詢最新記錄）',
  
  -- 外鍵約束
  CONSTRAINT `fk_webauthn_2fa_user` 
    FOREIGN KEY (`user_id`) 
    REFERENCES `user` (`id`) 
    ON DELETE CASCADE 
    ON UPDATE CASCADE
    COMMENT '關聯到用戶表，用戶刪除時級聯刪除驗證碼'
    
) ENGINE=InnoDB 
  DEFAULT CHARSET=utf8mb4 
  COLLATE=utf8mb4_unicode_ci 
  COMMENT='WebAuthn 註冊前 2FA 驗證碼表';

-- ============================================================================
-- 建立定期清理過期驗證碼的事件（可選）
-- 每天凌晨 2 點清理 24 小時前的已過期驗證碼
-- ============================================================================

-- 先刪除已存在的事件
DROP EVENT IF EXISTS `evt_clean_expired_webauthn_2fa_codes`;

-- 建立新事件
DELIMITER $$
CREATE EVENT IF NOT EXISTS `evt_clean_expired_webauthn_2fa_codes`
ON SCHEDULE EVERY 1 DAY
STARTS TIMESTAMP(CURRENT_DATE, '02:00:00')
COMMENT '每天凌晨 2 點清理過期的 WebAuthn 2FA 驗證碼'
DO
BEGIN
  -- 刪除 24 小時前已過期的驗證碼
  DELETE FROM `webauthn_2fa_codes` 
  WHERE `expires_at` < DATE_SUB(NOW(), INTERVAL 24 HOUR);
  
  -- 記錄清理日誌（可選，需要有日誌表）
  -- INSERT INTO system_logs (event_type, message, created_at) 
  -- VALUES ('cleanup', CONCAT('Cleaned expired 2FA codes: ', ROW_COUNT(), ' rows'), NOW());
END$$
DELIMITER ;

-- ============================================================================
-- 啟用事件排程器（如果尚未啟用）
-- 注意：需要 SUPER 或 EVENT 權限
-- ============================================================================

-- 檢查事件排程器狀態
-- SHOW VARIABLES LIKE 'event_scheduler';

-- 啟用事件排程器（全域設定，重啟後需重新設定）
-- SET GLOBAL event_scheduler = ON;

-- 永久啟用：在 my.cnf 或 my.ini 中加入：
-- [mysqld]
-- event_scheduler = ON

-- ============================================================================
-- 使用範例查詢
-- ============================================================================

-- 1. 查詢用戶最新的驗證碼
-- SELECT * FROM webauthn_2fa_codes 
-- WHERE user_id = ? 
-- ORDER BY created_at DESC 
-- LIMIT 1;

-- 2. 查詢未驗證的有效驗證碼
-- SELECT * FROM webauthn_2fa_codes 
-- WHERE user_id = ? 
--   AND verified = 0 
--   AND expires_at > NOW()
-- ORDER BY created_at DESC;

-- 3. 統計驗證成功率
-- SELECT 
--   COUNT(*) as total,
--   SUM(verified) as verified_count,
--   ROUND(SUM(verified) * 100.0 / COUNT(*), 2) as success_rate
-- FROM webauthn_2fa_codes
-- WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY);

-- 4. 查詢頻繁請求的 IP（防止濫用）
-- SELECT 
--   ip_address,
--   COUNT(*) as request_count,
--   MIN(created_at) as first_request,
--   MAX(created_at) as last_request
-- FROM webauthn_2fa_codes
-- WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
-- GROUP BY ip_address
-- HAVING COUNT(*) > 10
-- ORDER BY request_count DESC;

-- ============================================================================
-- 維護操作
-- ============================================================================

-- 手動清理過期驗證碼
-- DELETE FROM webauthn_2fa_codes 
-- WHERE expires_at < DATE_SUB(NOW(), INTERVAL 24 HOUR);

-- 清理所有已驗證的驗證碼（謹慎使用）
-- DELETE FROM webauthn_2fa_codes 
-- WHERE verified = 1 AND verified_at < DATE_SUB(NOW(), INTERVAL 7 DAY);

-- 重置用戶的所有驗證碼
-- DELETE FROM webauthn_2fa_codes WHERE user_id = ?;

-- 查看表資訊
-- SELECT * FROM INFORMATION_SCHEMA.TABLES 
-- WHERE TABLE_NAME = 'webauthn_2fa_codes';

-- 查看索引資訊
-- SHOW INDEX FROM webauthn_2fa_codes;

-- 查看外鍵約束
-- SELECT 
--   CONSTRAINT_NAME, 
--   TABLE_NAME, 
--   COLUMN_NAME, 
--   REFERENCED_TABLE_NAME, 
--   REFERENCED_COLUMN_NAME
-- FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
-- WHERE TABLE_NAME = 'webauthn_2fa_codes' 
--   AND CONSTRAINT_NAME LIKE 'fk_%';

-- 分析表效能
-- ANALYZE TABLE webauthn_2fa_codes;

-- 優化表
-- OPTIMIZE TABLE webauthn_2fa_codes;
