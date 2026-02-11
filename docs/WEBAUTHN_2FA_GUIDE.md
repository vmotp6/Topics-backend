# WebAuthn 註冊前 2FA 郵件驗證功能說明

## 概述

為了提升帳號安全性，在用戶註冊生物驗證設備之前，系統會先要求通過郵件 2FA 驗證。此功能確保只有帳號持有者本人才能註冊新的生物驗證設備。

## 功能流程

### 1. 用戶點擊「註冊新設備」
- 彈出註冊模態框
- 顯示用戶的註冊信箱（遮罩格式，如：ab***@example.com）

### 2. 發送驗證碼
- 用戶點擊「發送驗證碼」按鈕
- 系統生成 6 位數隨機驗證碼
- 驗證碼儲存到資料庫，有效期 10 分鐘
- 發送包含驗證碼的郵件到用戶信箱

### 3. 輸入驗證碼
- 用戶收到郵件後，輸入 6 位數驗證碼
- 系統驗證驗證碼的有效性：
  - 檢查驗證碼是否正確
  - 檢查是否已使用過
  - 檢查是否已過期

### 4. 驗證成功
- 驗證通過後，標記驗證碼為已使用
- 在 session 中記錄驗證狀態（5 分鐘有效）
- 切換到 WebAuthn 註冊步驟

### 5. WebAuthn 註冊
- 檢查 2FA 驗證狀態
- 只有通過 2FA 驗證的用戶才能開始 WebAuthn 註冊流程
- 完成註冊後清除 session 中的驗證標記

## 技術實現

### 資料庫表結構

```sql
CREATE TABLE IF NOT EXISTS webauthn_2fa_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    code VARCHAR(6) NOT NULL,
    expires_at DATETIME NOT NULL,
    verified TINYINT(1) DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_code (user_id, code),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 新增檔案

#### 1. `/frontend/api/send_webauthn_2fa.php`
- 發送 2FA 驗證碼的 API
- 生成 6 位數隨機驗證碼
- 儲存到資料庫並發送郵件
- 返回遮罩後的 Email 地址

**請求方式：** POST

**回應格式：**
```json
{
  "success": true,
  "message": "驗證碼已發送至 ab***@example.com",
  "email_masked": "ab***@example.com"
}
```

#### 2. `/frontend/api/verify_webauthn_2fa.php`
- 驗證 2FA 驗證碼的 API
- 檢查驗證碼是否正確、未使用、未過期
- 標記驗證碼為已使用
- 在 session 中記錄驗證狀態

**請求方式：** POST

**請求參數：**
```json
{
  "code": "123456"
}
```

**回應格式：**
```json
{
  "success": true,
  "message": "驗證成功！現在可以開始註冊設備"
}
```

#### 3. `/frontend/get_user_info.php`
- 獲取當前登入用戶的基本資訊
- 返回用戶名、姓名、Email 等資訊

**請求方式：** GET

**回應格式：**
```json
{
  "success": true,
  "username": "user123",
  "name": "張三",
  "email": "user@example.com"
}
```

### 修改檔案

#### 1. `/frontend/webauthn_register.php`
- 在 `action === 'start'` 處新增 2FA 驗證檢查
- 檢查 session 中的 `webauthn_2fa_verified` 標記
- 驗證時間戳記在 5 分鐘內有效
- 完成註冊後清除 2FA 驗證標記

**修改位置：** 約第 30-35 行

```php
if ($action === 'start') {
    // 檢查是否已通過 2FA 驗證（5分鐘內有效）
    if (!isset($_SESSION['webauthn_2fa_verified']) || 
        !isset($_SESSION['webauthn_2fa_verified_time']) ||
        (time() - $_SESSION['webauthn_2fa_verified_time']) > 300) {
        throw new Exception('請先完成郵件驗證碼驗證');
    }
    // ...
}
```

#### 2. `/frontend/signature.php`
- 重新設計註冊模態框，分為兩個步驟
- 步驟 1：郵件驗證（發送並輸入驗證碼）
- 步驟 2：WebAuthn 註冊
- 新增相關 JavaScript 函數

**新增 JavaScript 函數：**
- `loadUserEmail()` - 載入用戶 Email
- `maskEmail(email)` - 遮罩 Email 顯示
- `sendWebAuthn2FA()` - 發送 2FA 驗證碼
- `verifyWebAuthn2FA()` - 驗證 2FA 驗證碼

## 安全特性

### 1. 驗證碼安全
- 6 位數隨機驗證碼
- 10 分鐘有效期
- 一次性使用（驗證後標記為已使用）
- 每次發送新驗證碼時，清除舊的未使用驗證碼

### 2. Session 安全
- 驗證成功後在 session 中記錄標記
- 標記有效期 5 分鐘
- 完成註冊後立即清除標記
- 防止重放攻擊

### 3. Email 隱私
- 前端顯示遮罩後的 Email
- 僅顯示前 1-2 個字符，其餘用 * 代替
- 完整 Email 僅用於後端發送郵件

### 4. 錯誤提示
- 清晰的錯誤訊息提示
- 區分不同的錯誤情況（驗證碼錯誤、已使用、已過期等）
- 不洩露敏感資訊

## 使用者體驗

### 視覺設計
- 兩步驟的清晰流程指示
- 色彩編碼的狀態提示（藍色=資訊、綠色=成功、紅色=錯誤）
- 大字體驗證碼輸入框，方便輸入
- 等寬字體和字母間距，提升可讀性

### 互動設計
- 發送驗證碼後自動顯示輸入框
- 驗證碼輸入框自動聚焦
- 支援按 Enter 鍵快速驗證
- 提供「重新發送」功能
- 倒數計時器（可選）

### 錯誤處理
- 友善的錯誤訊息
- 自動重置按鈕狀態
- 保持用戶輸入（除非驗證成功）

## 測試建議

### 功能測試
1. ✅ 發送驗證碼成功
2. ✅ 驗證碼郵件正確送達
3. ✅ 輸入正確驗證碼通過驗證
4. ✅ 輸入錯誤驗證碼顯示錯誤
5. ✅ 驗證碼過期後無法使用
6. ✅ 已使用的驗證碼無法重複使用
7. ✅ 驗證通過後可以正常註冊設備
8. ✅ 未驗證時無法開始 WebAuthn 註冊

### 安全測試
1. ✅ 驗證 session 有效期限制
2. ✅ 驗證驗證碼一次性使用
3. ✅ 驗證郵件發送限制（防止濫用）
4. ✅ 驗證 SQL 注入防護
5. ✅ 驗證 XSS 防護

### 使用者體驗測試
1. ✅ 在不同瀏覽器測試
2. ✅ 在行動裝置測試
3. ✅ 測試郵件送達時間
4. ✅ 測試錯誤訊息清晰度

## 常見問題

### Q1: 用戶沒有設定 Email 怎麼辦？
**A:** 系統會顯示錯誤訊息：「請先在『個人資料』設定有效的 Email，才能註冊生物驗證設備。」引導用戶先設定 Email。

### Q2: 郵件發送失敗怎麼辦？
**A:** 系統會顯示錯誤訊息，用戶可以點擊「重新發送」按鈕重試。建議檢查 SMTP 設定。

### Q3: 驗證碼過期了怎麼辦？
**A:** 用戶可以點擊「重新發送」按鈕獲取新的驗證碼。舊驗證碼會被清除。

### Q4: 可以跳過 2FA 驗證嗎？
**A:** 不可以。這是強制性的安全措施，所有用戶都必須通過 2FA 驗證才能註冊新設備。

### Q5: 2FA 驗證會影響已註冊設備嗎？
**A:** 不會。已註冊的設備可以正常使用，2FA 驗證只在註冊新設備時需要。

## 維護建議

### 定期清理
建議建立定期任務清理過期的驗證碼：

```sql
-- 清理 24 小時前的已過期驗證碼
DELETE FROM webauthn_2fa_codes 
WHERE expires_at < DATE_SUB(NOW(), INTERVAL 24 HOUR);
```

### 監控
- 監控驗證碼發送頻率
- 監控驗證失敗次數
- 記錄異常行為（如短時間內多次請求）

### 郵件服務
- 確保 SMTP 服務穩定
- 監控郵件送達率
- 設定合理的發送頻率限制

## 版本資訊

- **版本：** 1.0.0
- **更新日期：** 2026-02-10
- **作者：** GitHub Copilot
- **相容性：** 需要 PHP 7.4+、MySQL 5.7+

## 相關檔案

- `/frontend/api/send_webauthn_2fa.php` - 發送驗證碼 API
- `/frontend/api/verify_webauthn_2fa.php` - 驗證驗證碼 API
- `/frontend/get_user_info.php` - 獲取用戶資訊 API
- `/frontend/webauthn_register.php` - WebAuthn 註冊 API
- `/frontend/signature.php` - 簽名頁面
- `WEBAUTHN_DEVICE_GUIDE.md` - WebAuthn 設備管理指南
- `WEBAUTHN_SIGNATURE_GUIDE.md` - WebAuthn 簽章使用指南
