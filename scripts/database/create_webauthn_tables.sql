-- WebAuthn 憑證表
CREATE TABLE IF NOT EXISTS webauthn_credentials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    credential_id VARCHAR(255) NOT NULL UNIQUE,
    public_key TEXT NOT NULL,
    counter BIGINT UNSIGNED DEFAULT 0,
    device_name VARCHAR(255) DEFAULT NULL,
    device_type VARCHAR(50) DEFAULT NULL COMMENT 'phone, desktop, tablet, etc.',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    INDEX idx_user_id (user_id),
    INDEX idx_credential_id (credential_id),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 添加外鍵約束（如果 users 表存在）
-- 注意：如果 users 表不存在或結構不同，請手動調整或移除此約束
SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'webauthn_credentials' 
    AND CONSTRAINT_NAME = 'fk_webauthn_user_id'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @users_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'users');
SET @sql = IF(@fk_exists = 0 AND @users_exists > 0, 
    'ALTER TABLE webauthn_credentials ADD CONSTRAINT fk_webauthn_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
    'SELECT "Foreign key constraint already exists or users table does not exist" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- WebAuthn 簽名記錄表（擴展原有的 signatures 表）
-- 注意：MySQL 不支援 IF NOT EXISTS，請先檢查欄位是否存在
-- 如果欄位已存在，請跳過對應的 ALTER TABLE 語句

-- 檢查並添加 webauthn_credential_id 欄位
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'signatures' 
    AND COLUMN_NAME = 'webauthn_credential_id');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE signatures ADD COLUMN webauthn_credential_id VARCHAR(255) DEFAULT NULL',
    'SELECT "Column webauthn_credential_id already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 檢查並添加 webauthn_authenticator_data 欄位
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'signatures' 
    AND COLUMN_NAME = 'webauthn_authenticator_data');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE signatures ADD COLUMN webauthn_authenticator_data TEXT DEFAULT NULL',
    'SELECT "Column webauthn_authenticator_data already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 檢查並添加 webauthn_signature 欄位
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'signatures' 
    AND COLUMN_NAME = 'webauthn_signature');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE signatures ADD COLUMN webauthn_signature TEXT DEFAULT NULL',
    'SELECT "Column webauthn_signature already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 檢查並添加 webauthn_client_data 欄位
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'signatures' 
    AND COLUMN_NAME = 'webauthn_client_data');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE signatures ADD COLUMN webauthn_client_data TEXT DEFAULT NULL',
    'SELECT "Column webauthn_client_data already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 檢查並添加 authentication_method 欄位
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'signatures' 
    AND COLUMN_NAME = 'authentication_method');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE signatures ADD COLUMN authentication_method ENUM(\'canvas\', \'webauthn\') DEFAULT \'canvas\'',
    'SELECT "Column authentication_method already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 添加索引（如果不存在）
-- 檢查並添加 idx_webauthn_credential_id 索引
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'signatures' 
    AND INDEX_NAME = 'idx_webauthn_credential_id');
SET @sql = IF(@idx_exists = 0, 
    'CREATE INDEX idx_webauthn_credential_id ON signatures(webauthn_credential_id)',
    'SELECT "Index idx_webauthn_credential_id already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 檢查並添加 idx_authentication_method 索引
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'signatures' 
    AND INDEX_NAME = 'idx_authentication_method');
SET @sql = IF(@idx_exists = 0, 
    'CREATE INDEX idx_authentication_method ON signatures(authentication_method)',
    'SELECT "Index idx_authentication_method already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

