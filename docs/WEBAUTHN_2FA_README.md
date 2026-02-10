# WebAuthn 2FA 郵件驗證功能

## 🎯 功能概述

為了提升帳號安全性，系統在用戶註冊生物驗證設備（如指紋、臉部辨識）之前，會先要求通過郵件 2FA 驗證。此功能確保只有帳號持有者本人才能註冊新的生物驗證設備。

## ✨ 主要特色

- ✅ **雙重驗證保護** - 註冊新設備前必須通過郵件驗證
- ✅ **6位數驗證碼** - 隨機生成，10分鐘有效期
- ✅ **一次性使用** - 驗證碼使用後立即失效
- ✅ **Session 保護** - 驗證狀態有效期 5 分鐘
- ✅ **友善的使用者介面** - 清晰的步驟指示和錯誤提示
- ✅ **Email 隱私保護** - 前端顯示遮罩後的 Email

## 📋 前置需求

1. **PHP 7.4+**
2. **MySQL 5.7+** 或 **MariaDB 10.2+**（必須支援外鍵約束）
3. **已設定 SMTP 郵件服務**
4. **用戶必須設定有效的 Email**
5. **InnoDB 儲存引擎**（支援外鍵和交易）

**⚠️ 重要：** 資料庫必須使用 InnoDB 引擎，MyISAM 不支援外鍵約束。

## 🚀 快速開始

### 1. 建立資料表

執行以下 SQL 腳本建立所需的資料表：

**方式 1：完整架構安裝（推薦）**
```bash
mysql -u your_username -p your_database < scripts/database/create_webauthn_complete_schema.sql
```

**方式 2：單獨安裝**
```bash
mysql -u your_username -p your_database < scripts/database/create_webauthn_2fa_table.sql
```

**方式 3：手動執行（查看詳細的 SQL）**

請參考 [資料庫架構文件](WEBAUTHN_DATABASE_SCHEMA.md) 或 [快速參考](WEBAUTHN_DATABASE_QUICK_REF.md)

**資料庫架構特點：**
- ✅ 符合第三正規化 (3NF)
- ✅ 完整的外鍵約束（CASCADE 刪除/更新）
- ✅ 優化的索引設計（複合索引、覆蓋索引）
- ✅ 自動清理機制（事件排程器）
- ✅ 安全性追蹤（IP、User Agent）

**相關文件：**
- 📖 [完整資料庫架構設計](WEBAUTHN_DATABASE_SCHEMA.md) - 詳細的架構說明
- 📋 [資料庫快速參考](WEBAUTHN_DATABASE_QUICK_REF.md) - 常用查詢和命令

### 2. 確認 SMTP 設定

確保 `config.php` 中的 SMTP 設定正確：

```php
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'your-email@gmail.com');
define('SMTP_PASSWORD', 'your-app-password');
define('SMTP_FROM_EMAIL', 'your-email@gmail.com');
define('SMTP_FROM_NAME', '康寧大學招生系統');
```

### 3. 測試功能

訪問測試頁面：

```
https://your-domain/Topics-backend/frontend/test_webauthn_2fa.php
```

按照頁面指示依序執行各項測試。

## 📖 使用說明

### 用戶端操作流程

1. **開啟簽名頁面**
   - 訪問 `signature.php`
   - 點擊「註冊新設備」按鈕

2. **發送驗證碼**
   - 系統顯示遮罩後的 Email（如：ab***@example.com）
   - 點擊「發送驗證碼」按鈕
   - 系統發送 6 位數驗證碼到註冊信箱

3. **輸入驗證碼**
   - 檢查信箱並複製驗證碼
   - 在輸入框中輸入 6 位數驗證碼
   - 點擊「驗證並繼續」或按 Enter 鍵

4. **開始註冊設備**
   - 驗證成功後，自動進入設備註冊步驟
   - 點擊「開始註冊設備」
   - 按照瀏覽器提示完成生物驗證

### 管理員操作

#### 清理過期驗證碼

手動清理：

```sql
DELETE FROM webauthn_2fa_codes 
WHERE expires_at < DATE_SUB(NOW(), INTERVAL 24 HOUR);
```

啟用自動清理（已包含在建表腳本中）：

```sql
SET GLOBAL event_scheduler = ON;
```

#### 查看驗證碼記錄

```sql
SELECT 
    u.username,
    u.email,
    c.code,
    c.verified,
    c.created_at,
    c.expires_at
FROM webauthn_2fa_codes c
JOIN user u ON c.user_id = u.id
ORDER BY c.created_at DESC
LIMIT 20;
```

#### 重置用戶驗證狀態

如果用戶無法完成驗證：

```sql
DELETE FROM webauthn_2fa_codes 
WHERE user_id = ? AND verified = 0;
```

## 🗂️ 檔案結構

```
Topics-backend/
├── frontend/
│   ├── api/
│   │   ├── send_webauthn_2fa.php      # 發送驗證碼 API
│   │   └── verify_webauthn_2fa.php    # 驗證驗證碼 API
│   ├── get_user_info.php               # 獲取用戶資訊 API
│   ├── signature.php                   # 簽名頁面（已更新）
│   ├── webauthn_register.php           # WebAuthn 註冊 API（已更新）
│   └── test_webauthn_2fa.php          # 測試頁面
├── scripts/
│   └── database/
│       └── create_webauthn_2fa_table.sql
└── docs/
    ├── WEBAUTHN_2FA_GUIDE.md          # 詳細技術文件
    └── WEBAUTHN_2FA_README.md         # 本文件
```

## 🔧 API 文件

### 1. 發送驗證碼

**端點：** `POST /api/send_webauthn_2fa.php`

**請求：** 無需參數（使用 session 中的用戶資訊）

**成功回應：**
```json
{
  "success": true,
  "message": "驗證碼已發送至 ab***@example.com",
  "email_masked": "ab***@example.com"
}
```

**錯誤回應：**
```json
{
  "success": false,
  "message": "請先在「個人資料」設定有效的 Email"
}
```

### 2. 驗證驗證碼

**端點：** `POST /api/verify_webauthn_2fa.php`

**請求參數：**
```json
{
  "code": "123456"
}
```

**成功回應：**
```json
{
  "success": true,
  "message": "驗證成功！現在可以開始註冊設備"
}
```

**錯誤回應：**
```json
{
  "success": false,
  "message": "驗證碼錯誤，請重新輸入"
}
```

### 3. 獲取用戶資訊

**端點：** `GET /get_user_info.php`

**成功回應：**
```json
{
  "success": true,
  "username": "user123",
  "name": "張三",
  "email": "user@example.com"
}
```

## 🔒 安全機制

### 驗證碼安全
- 6 位數隨機生成（000000-999999）
- 10 分鐘有效期
- 一次性使用
- 每次發送新驗證碼時，清除舊的未使用驗證碼

### Session 安全
- 驗證成功後在 session 中記錄標記
- 標記有效期 5 分鐘
- 完成註冊後立即清除標記
- 防止重放攻擊

### Email 隱私
- 前端顯示遮罩後的 Email
- 僅顯示前 1-2 個字符，其餘用 * 代替
- 完整 Email 僅用於後端發送郵件

## ❓ 常見問題

### Q: 用戶沒有設定 Email 怎麼辦？

**A:** 系統會顯示錯誤訊息：「請先在『個人資料』設定有效的 Email，才能註冊生物驗證設備。」引導用戶先設定 Email。

### Q: 郵件發送失敗怎麼辦？

**A:** 
1. 檢查 SMTP 設定是否正確
2. 確認 SMTP 服務是否可用
3. 檢查郵件伺服器連線狀態
4. 查看 PHP error log

### Q: 驗證碼過期了怎麼辦？

**A:** 用戶可以點擊「重新發送」按鈕獲取新的驗證碼。舊驗證碼會被自動清除。

### Q: 可以跳過 2FA 驗證嗎？

**A:** 不可以。這是強制性的安全措施，所有用戶都必須通過 2FA 驗證才能註冊新設備。

### Q: 2FA 驗證會影響已註冊設備嗎？

**A:** 不會。已註冊的設備可以正常使用，2FA 驗證只在註冊新設備時需要。

### Q: 如何調整驗證碼有效期？

**A:** 修改 `api/send_webauthn_2fa.php` 中的以下行：

```php
// 預設 10 分鐘（600 秒）
$expires_at = date('Y-m-d H:i:s', time() + 600);

// 改為 15 分鐘
$expires_at = date('Y-m-d H:i:s', time() + 900);
```

### Q: 如何調整 Session 驗證有效期？

**A:** 修改 `webauthn_register.php` 中的以下行：

```php
// 預設 5 分鐘（300 秒）
if ((time() - $_SESSION['webauthn_2fa_verified_time']) > 300) {
    throw new Exception('請先完成郵件驗證碼驗證');
}

// 改為 10 分鐘
if ((time() - $_SESSION['webauthn_2fa_verified_time']) > 600) {
    throw new Exception('請先完成郵件驗證碼驗證');
}
```

## 🐛 故障排除

### 問題 1: 收不到驗證碼郵件

**可能原因：**
- SMTP 設定錯誤
- 郵件被標記為垃圾郵件
- Email 地址無效
- 郵件伺服器連線失敗

**解決方法：**
1. 檢查垃圾郵件資料夾
2. 驗證 SMTP 設定
3. 確認 Email 地址有效
4. 查看 PHP error log

### 問題 2: 驗證碼總是提示錯誤

**可能原因：**
- 輸入了錯誤的驗證碼
- 驗證碼已過期
- 驗證碼已被使用

**解決方法：**
1. 仔細核對驗證碼
2. 重新發送新的驗證碼
3. 檢查系統時間是否正確

### 問題 3: 通過驗證後仍無法開始註冊

**可能原因：**
- Session 已過期
- Session 設定問題

**解決方法：**
1. 重新執行驗證流程
2. 檢查 PHP session 設定
3. 確認 session.save_path 可寫入

## 📝 更新日誌

### Version 1.0.0 (2026-02-10)
- ✨ 初始版本發布
- ✅ 實現郵件 2FA 驗證功能
- ✅ 整合到 WebAuthn 註冊流程
- ✅ 建立測試頁面
- ✅ 完成技術文件

## 📞 技術支援

如有問題或建議，請：
1. 查看詳細技術文件：`docs/WEBAUTHN_2FA_GUIDE.md`
2. 執行測試頁面：`frontend/test_webauthn_2fa.php`
3. 聯繫系統管理員

## 📄 授權

© 2026 康寧大學招生系統
