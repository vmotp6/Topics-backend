-- ============================================================================
-- WebAuthn 完整資料庫架構 SQL 腳本
-- 包含所有表、索引、外鍵約束和自動清理事件
-- ============================================================================
-- 版本: 2.0.0
-- 日期: 2026-02-10
-- 說明: 符合第三正規化(3NF)的完整資料庫架構
-- ============================================================================

-- 設定字元集
SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- ============================================================================
-- 表 1: webauthn_2fa_codes (2FA 驗證碼表)
-- ============================================================================

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
  
  -- 索引
  INDEX `idx_user_code` (`user_id`, `code`) COMMENT '用戶和驗證碼複合索引',
  INDEX `idx_expires` (`expires_at`) COMMENT '過期時間索引',
  INDEX `idx_verified` (`verified`, `created_at`) COMMENT '驗證狀態索引',
  INDEX `idx_user_created` (`user_id`, `created_at` DESC) COMMENT '用戶建立時間索引',
  
  -- 外鍵約束
  CONSTRAINT `fk_webauthn_2fa_user` 
    FOREIGN KEY (`user_id`) 
    REFERENCES `user` (`id`) 
    ON DELETE CASCADE 
    ON UPDATE CASCADE
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
  COMMENT='WebAuthn 2FA 驗證碼表';

-- ============================================================================
-- 表 2: webauthn_register_pending (待驗證設備表)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `webauthn_register_pending` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '主鍵',
  `user_id` INT NOT NULL COMMENT '用戶 ID（關聯到 user 表）',
  `credential_id` VARCHAR(512) NOT NULL COMMENT 'WebAuthn 憑證 ID（Base64 編碼）',
  `public_key` TEXT NOT NULL COMMENT '公開金鑰（Base64 編碼）',
  `device_name` VARCHAR(255) NULL DEFAULT NULL COMMENT '設備名稱',
  `device_type` VARCHAR(50) NULL DEFAULT NULL COMMENT '設備類型（phone、desktop、tablet）',
  `verify_token` VARCHAR(64) NOT NULL UNIQUE COMMENT 'Email 驗證 Token（唯一）',
  `verify_expires_at` DATETIME NOT NULL COMMENT '驗證連結過期時間',
  `ip_address` VARCHAR(45) NULL DEFAULT NULL COMMENT '註冊請求 IP 地址（支援 IPv6）',
  `user_agent` VARCHAR(500) NULL DEFAULT NULL COMMENT '瀏覽器 User Agent',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '建立時間',
  
  -- 索引
  INDEX `idx_token` (`verify_token`) COMMENT 'Token 索引',
  INDEX `idx_user` (`user_id`, `created_at` DESC) COMMENT '用戶索引',
  INDEX `idx_expires` (`verify_expires_at`) COMMENT '過期時間索引',
  INDEX `idx_credential` (`credential_id`(255)) COMMENT '憑證 ID 索引',
  
  -- 外鍵約束
  CONSTRAINT `fk_webauthn_pending_user` 
    FOREIGN KEY (`user_id`) 
    REFERENCES `user` (`id`) 
    ON DELETE CASCADE 
    ON UPDATE CASCADE
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
  COMMENT='WebAuthn 待驗證設備表';

-- ============================================================================
-- 表 3: webauthn_credentials (已驗證設備表)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `webauthn_credentials` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '主鍵',
  `user_id` INT NOT NULL COMMENT '用戶 ID（關聯到 user 表）',
  `credential_id` VARCHAR(255) NOT NULL UNIQUE COMMENT 'WebAuthn 憑證 ID（唯一）',
  `public_key` TEXT NOT NULL COMMENT '公開金鑰',
  `counter` BIGINT UNSIGNED DEFAULT 0 COMMENT '簽名計數器（防重放攻擊）',
  `device_name` VARCHAR(255) DEFAULT NULL COMMENT '設備名稱',
  `device_type` VARCHAR(50) DEFAULT NULL COMMENT '設備類型',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '建立時間',
  `last_used_at` DATETIME DEFAULT NULL COMMENT '最後使用時間',
  `is_active` TINYINT(1) DEFAULT 1 COMMENT '是否啟用',
  
  -- 索引
  INDEX `idx_user_id` (`user_id`) COMMENT '用戶索引',
  INDEX `idx_credential_id` (`credential_id`) COMMENT '憑證 ID 索引',
  INDEX `idx_is_active` (`is_active`) COMMENT '啟用狀態索引',
  
  -- 外鍵約束
  CONSTRAINT `fk_webauthn_cred_user` 
    FOREIGN KEY (`user_id`) 
    REFERENCES `user` (`id`) 
    ON DELETE CASCADE 
    ON UPDATE CASCADE
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
  COMMENT='WebAuthn 已驗證設備表';

-- ============================================================================
-- 自動清理事件
-- ============================================================================

-- 刪除舊事件
DROP EVENT IF EXISTS `evt_clean_expired_webauthn_2fa_codes`;
DROP EVENT IF EXISTS `evt_clean_expired_webauthn_pending`;

-- 事件 1: 清理過期的 2FA 驗證碼（每天凌晨 2 點）
DELIMITER $$
CREATE EVENT IF NOT EXISTS `evt_clean_expired_webauthn_2fa_codes`
ON SCHEDULE EVERY 1 DAY
STARTS TIMESTAMP(CURRENT_DATE, '02:00:00')
COMMENT '清理過期的 2FA 驗證碼'
DO
BEGIN
  DELETE FROM `webauthn_2fa_codes` 
  WHERE `expires_at` < DATE_SUB(NOW(), INTERVAL 24 HOUR);
END$$
DELIMITER ;

-- 事件 2: 清理過期的待驗證設備（每天凌晨 3 點）
DELIMITER $$
CREATE EVENT IF NOT EXISTS `evt_clean_expired_webauthn_pending`
ON SCHEDULE EVERY 1 DAY
STARTS TIMESTAMP(CURRENT_DATE, '03:00:00')
COMMENT '清理過期的待驗證設備'
DO
BEGIN
  DELETE FROM `webauthn_register_pending` 
  WHERE `verify_expires_at` < DATE_SUB(NOW(), INTERVAL 24 HOUR);
END$$
DELIMITER ;

-- ============================================================================
-- 啟用事件排程器
-- ============================================================================

-- 檢查事件排程器狀態
SHOW VARIABLES LIKE 'event_scheduler';

-- 啟用事件排程器（需要重啟後在 my.cnf 中設定）
-- SET GLOBAL event_scheduler = ON;

-- ============================================================================
-- 驗證安裝
-- ============================================================================

-- 查看所有 WebAuthn 相關表
SELECT 
  TABLE_NAME,
  TABLE_ROWS,
  DATA_LENGTH,
  INDEX_LENGTH,
  TABLE_COMMENT
FROM INFORMATION_SCHEMA.TABLES 
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME LIKE 'webauthn%'
ORDER BY TABLE_NAME;

-- 查看外鍵約束
SELECT 
  CONSTRAINT_NAME,
  TABLE_NAME,
  COLUMN_NAME,
  REFERENCED_TABLE_NAME,
  REFERENCED_COLUMN_NAME,
  DELETE_RULE,
  UPDATE_RULE
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME LIKE 'webauthn%'
  AND CONSTRAINT_NAME LIKE 'fk_%'
ORDER BY TABLE_NAME, CONSTRAINT_NAME;

-- 查看索引
SELECT 
  TABLE_NAME,
  INDEX_NAME,
  COLUMN_NAME,
  INDEX_TYPE
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME LIKE 'webauthn%'
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

-- 查看事件
SELECT 
  EVENT_NAME,
  EVENT_DEFINITION,
  INTERVAL_VALUE,
  INTERVAL_FIELD,
  STATUS,
  EVENT_COMMENT
FROM INFORMATION_SCHEMA.EVENTS
WHERE EVENT_SCHEMA = DATABASE() 
  AND EVENT_NAME LIKE 'evt_clean_expired_webauthn%';

-- ============================================================================
-- 完成
-- ============================================================================

SELECT '✅ WebAuthn 資料庫架構建立完成！' AS message;
SELECT '📊 請執行上述驗證查詢確認所有表、索引、外鍵和事件都已正確建立。' AS next_step;
