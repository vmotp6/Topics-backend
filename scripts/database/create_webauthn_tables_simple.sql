-- WebAuthn 憑證表（簡化版本，不包含外鍵約束）
-- 如果遇到外鍵約束錯誤，請使用此版本

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

-- 如果 users 表存在且需要外鍵約束，請手動執行：
-- ALTER TABLE webauthn_credentials 
-- ADD CONSTRAINT fk_webauthn_user_id 
-- FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- WebAuthn 簽名記錄表擴展（signatures 表）
-- 注意：請確保 signatures 表已存在

-- 添加 webauthn_credential_id 欄位
ALTER TABLE signatures 
ADD COLUMN IF NOT EXISTS webauthn_credential_id VARCHAR(255) DEFAULT NULL;

-- 添加 webauthn_authenticator_data 欄位
ALTER TABLE signatures 
ADD COLUMN IF NOT EXISTS webauthn_authenticator_data TEXT DEFAULT NULL;

-- 添加 webauthn_signature 欄位
ALTER TABLE signatures 
ADD COLUMN IF NOT EXISTS webauthn_signature TEXT DEFAULT NULL;

-- 添加 webauthn_client_data 欄位
ALTER TABLE signatures 
ADD COLUMN IF NOT EXISTS webauthn_client_data TEXT DEFAULT NULL;

-- 添加 authentication_method 欄位
ALTER TABLE signatures 
ADD COLUMN IF NOT EXISTS authentication_method ENUM('canvas', 'webauthn') DEFAULT 'canvas';

-- 添加索引
CREATE INDEX IF NOT EXISTS idx_webauthn_credential_id ON signatures(webauthn_credential_id);
CREATE INDEX IF NOT EXISTS idx_authentication_method ON signatures(authentication_method);

