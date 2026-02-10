# WebAuthn 2FA 郵件驗證 - 安裝指南

## 快速安裝（5 分鐘完成）

### 步驟 1: 建立資料表 (1 分鐘)

連接到你的 MySQL 資料庫並執行：

```sql
-- 建立 2FA 驗證碼表（包含外鍵約束）
CREATE TABLE IF NOT EXISTS `webauthn_2fa_codes` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '主鍵',
  `user_id` INT NOT NULL COMMENT '用戶 ID（關聯到 user 表）',
  `code` CHAR(6) NOT NULL COMMENT '6 位數驗證碼',
  `expires_at` DATETIME NOT NULL COMMENT '過期時間',
  `verified` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否已驗證',
  `verified_at` DATETIME NULL DEFAULT NULL COMMENT '驗證時間',
  `ip_address` VARCHAR(45) NULL DEFAULT NULL COMMENT '請求 IP 地址',
  `user_agent` VARCHAR(500) NULL DEFAULT NULL COMMENT '瀏覽器 User Agent',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '建立時間',
  
  INDEX `idx_user_code` (`user_id`, `code`),
  INDEX `idx_expires` (`expires_at`),
  INDEX `idx_verified` (`verified`, `created_at`),
  INDEX `idx_user_created` (`user_id`, `created_at` DESC),
  
  CONSTRAINT `fk_webauthn_2fa_user` 
    FOREIGN KEY (`user_id`) 
    REFERENCES `user` (`id`) 
    ON DELETE CASCADE 
    ON UPDATE CASCADE
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**或使用命令行：**

```bash
mysql -u root -p your_database < scripts/database/create_webauthn_2fa_table.sql
```

**⚠️ 注意事項：**
- 確保 `user` 表已存在且有 `id` 主鍵
- 如果外鍵建立失敗，請檢查用戶表名稱（可能是 `users` 而非 `user`）
- 外鍵約束確保資料完整性，用戶刪除時會自動清理相關記錄

### 步驟 2: 驗證檔案 (1 分鐘)

確認以下檔案已經存在：

```
✅ frontend/api/send_webauthn_2fa.php
✅ frontend/api/verify_webauthn_2fa.php
✅ frontend/get_user_info.php
✅ frontend/signature.php (已更新)
✅ frontend/webauthn_register.php (已更新)
✅ frontend/test_webauthn_2fa.php
```

### 步驟 3: 檢查 SMTP 設定 (1 分鐘)

確認 `Topics-frontend/frontend/config.php` 中的 SMTP 設定：

```php
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'your-email@gmail.com');
define('SMTP_PASSWORD', 'your-app-password');
define('SMTP_FROM_EMAIL', 'your-email@gmail.com');
define('SMTP_FROM_NAME', '康寧大學招生系統');
```

### 步驟 4: 測試功能 (2 分鐘)

1. **訪問測試頁面：**
   ```
   https://your-domain/Topics-backend/frontend/test_webauthn_2fa.php
   ```

2. **依序執行測試：**
   - 測試 1: 獲取用戶資訊 ✓
   - 測試 2: 發送 2FA 驗證碼 ✓
   - 測試 3: 驗證 2FA 驗證碼 ✓
   - 測試 4: WebAuthn 註冊檢查 ✓

3. **所有測試通過 = 安裝成功！** 🎉

### 步驟 5: 實際使用

訪問簽名頁面並測試完整流程：

```
https://your-domain/Topics-backend/frontend/signature.php
```

點擊「註冊新設備」→ 發送驗證碼 → 輸入驗證碼 → 開始註冊設備

---

## 檢查清單

安裝前請確認：

- [ ] PHP 版本 >= 7.4
- [ ] MySQL 版本 >= 5.7 或 MariaDB >= 10.2
- [ ] SMTP 郵件服務已設定並可用
- [ ] 用戶資料表包含 `email` 欄位
- [ ] `email_functions.php` 檔案存在且功能正常
- [ ] SSL 憑證已安裝（WebAuthn 需要 HTTPS）

---

## 常見安裝問題

### ❌ 資料表建立失敗

**錯誤：** `Table already exists`

**解決：** 資料表已存在，可以跳過此步驟或先刪除舊表：
```sql
DROP TABLE IF EXISTS webauthn_2fa_codes;
```

### ❌ 郵件發送失敗

**錯誤：** `SMTP connect() failed`

**解決：**
1. 檢查 SMTP_HOST、SMTP_PORT 是否正確
2. 檢查 SMTP_USERNAME、SMTP_PASSWORD 是否正確
3. 確認防火牆允許 SMTP 連線
4. 使用 Gmail 時需要開啟「低安全性應用程式存取」或使用應用程式密碼

### ❌ 找不到 email_functions.php

**錯誤：** `Failed opening required 'email_functions.php'`

**解決：**
```bash
# 檢查檔案是否存在
ls -l Topics-frontend/frontend/includes/email_functions.php

# 如果不存在，請確認路徑或從備份還原
```

### ❌ Session 無法儲存驗證狀態

**錯誤：** 驗證成功但無法開始註冊

**解決：**
```php
// 檢查 session 設定
session_save_path(); // 確認路徑存在且可寫入
ini_get('session.save_handler'); // 確認使用 files
```

---

## 升級指南

如果你已經有舊版的 WebAuthn 註冊功能，請按以下步驟升級：

### 1. 備份現有檔案

```bash
cp frontend/signature.php frontend/signature.php.backup
cp frontend/webauthn_register.php frontend/webauthn_register.php.backup
```

### 2. 建立資料表

執行步驟 1 的 SQL 語句

### 3. 新增檔案

複製以下新檔案：
- `frontend/api/send_webauthn_2fa.php`
- `frontend/api/verify_webauthn_2fa.php`
- `frontend/get_user_info.php`

### 4. 更新現有檔案

更新：
- `frontend/signature.php`
- `frontend/webauthn_register.php`

### 5. 測試

執行測試頁面確認功能正常

---

## 完成！

✅ 安裝完成後，所有新的設備註冊都需要通過郵件 2FA 驗證

✅ 已註冊的設備不受影響，可以繼續正常使用

✅ 查看詳細文件：`docs/WEBAUTHN_2FA_README.md`

---

**需要協助？**

- 📖 查看完整技術文件：`docs/WEBAUTHN_2FA_GUIDE.md`
- 🧪 執行測試頁面：`frontend/test_webauthn_2fa.php`
- 💬 聯繫系統管理員
