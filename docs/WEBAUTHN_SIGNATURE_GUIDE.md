# WebAuthn + FIDO2 電子簽章系統使用指南

## 概述

本系統已整合 WebAuthn + FIDO2 標準，支援使用手機生物驗證（指紋辨識、臉部辨識等）進行電子簽章。系統同時保留傳統 Canvas 簽名方式作為備選方案。

## 功能特點

- ✅ 支援 WebAuthn/FIDO2 標準
- ✅ 支援手機生物驗證（指紋、臉部辨識）
- ✅ 支援多設備註冊
- ✅ 向後兼容傳統 Canvas 簽名
- ✅ 安全的憑證儲存和驗證

## 安裝步驟

### 1. 資料庫初始化

執行資料庫初始化腳本來建立必要的表結構：

```bash
# 方法 1：使用 PHP 腳本（推薦）
php Topics-backend/scripts/database/init_webauthn.php

# 方法 2：手動執行 SQL
mysql -u root -p topics_good < Topics-backend/scripts/database/create_webauthn_tables.sql
```

### 2. 檢查檔案權限

確保以下目錄有寫入權限：
- `Topics-backend/frontend/uploads/signatures/`

### 3. HTTPS 要求

**重要**：WebAuthn 要求使用 HTTPS 連線（localhost 除外）。在生產環境中必須配置 SSL 憑證。

## 使用說明

### 用戶端使用

#### 首次使用：註冊設備

1. 進入簽名頁面
2. 點擊「註冊新設備」按鈕
3. 按照瀏覽器提示完成生物驗證註冊
4. 註冊成功後即可使用該設備進行簽名

#### 使用生物驗證簽名

1. 進入需要簽名的頁面
2. 點擊「使用生物驗證簽名」按鈕
3. 使用已註冊設備的生物驗證（指紋/臉部辨識）
4. 驗證成功後簽名自動儲存

#### 使用傳統簽名

如果瀏覽器不支援 WebAuthn 或用戶偏好使用傳統方式：
1. 點擊「使用傳統簽名」按鈕
2. 在畫布上繪製簽名
3. 點擊「確認簽名」完成

### 開發者說明

#### API 端點

**註冊憑證**
- URL: `webauthn_register.php`
- Method: POST
- Action: `start` - 開始註冊流程
- Action: `complete` - 完成註冊流程

**認證憑證**
- URL: `webauthn_authenticate.php`
- Method: POST
- Action: `start` - 開始認證流程
- Action: `complete` - 完成認證流程

**儲存簽名**
- URL: `save_signature.php`
- Method: POST
- 支援兩種模式：
  - `authentication_method: 'webauthn'` - WebAuthn 簽名
  - `authentication_method: 'canvas'` - Canvas 簽名

#### 資料庫結構

**webauthn_credentials 表**
- `id` - 主鍵
- `user_id` - 用戶 ID
- `credential_id` - 憑證 ID（唯一）
- `public_key` - 公開金鑰
- `counter` - 計數器（防重放攻擊）
- `device_name` - 設備名稱
- `device_type` - 設備類型
- `created_at` - 建立時間
- `last_used_at` - 最後使用時間
- `is_active` - 是否啟用

**signatures 表擴展欄位**
- `webauthn_credential_id` - WebAuthn 憑證 ID
- `webauthn_authenticator_data` - 認證器資料
- `webauthn_signature` - 簽名資料
- `webauthn_client_data` - 客戶端資料
- `authentication_method` - 認證方法（'canvas' 或 'webauthn'）

#### JavaScript 組件

使用 `WebAuthnSignature` 類別來整合 WebAuthn 功能：

```javascript
const webauthnSignature = new WebAuthnSignature({
    userId: 123,
    documentId: 456,
    documentType: 'general',
    onSuccess: function(data) {
        console.log('簽名成功', data);
    },
    onError: function(error) {
        console.error('簽名失敗', error);
    }
});

// 開始認證
await webauthnSignature.authenticate();

// 註冊設備
await webauthnSignature.registerDevice();
```

## 安全注意事項

1. **HTTPS 要求**：生產環境必須使用 HTTPS
2. **憑證驗證**：系統會驗證 challenge、origin 和簽名
3. **計數器檢查**：防止重放攻擊
4. **Session 管理**：Challenge 儲存在 session 中，5分鐘後過期

## 瀏覽器支援

- Chrome 67+
- Firefox 60+
- Edge 18+
- Safari 13+（macOS）
- iOS Safari 13.3+
- Android Chrome 67+

## 故障排除

### 問題：無法註冊設備

**可能原因：**
1. 瀏覽器不支援 WebAuthn
2. 未使用 HTTPS（localhost 除外）
3. 設備不支援生物驗證

**解決方法：**
- 檢查瀏覽器版本
- 確認使用 HTTPS 或 localhost
- 使用支援生物驗證的設備

### 問題：認證失敗

**可能原因：**
1. 設備未註冊
2. Challenge 過期
3. 憑證已停用

**解決方法：**
- 重新註冊設備
- 重新開始認證流程
- 檢查憑證狀態

### 問題：資料庫錯誤

**可能原因：**
1. 表結構未建立
2. 欄位缺失

**解決方法：**
- 執行資料庫初始化腳本
- 檢查 SQL 執行日誌

## 未來改進

- [ ] 完整的 CBOR 解析和驗證
- [ ] 使用專業的 WebAuthn PHP 庫
- [ ] 支援更多認證器類型
- [ ] 憑證管理介面
- [ ] 簽名審計日誌

## 相關檔案

- `Topics-backend/frontend/webauthn_register.php` - 註冊 API
- `Topics-backend/frontend/webauthn_authenticate.php` - 認證 API
- `Topics-backend/frontend/includes/webauthn_helpers.php` - 輔助函數
- `Topics-backend/frontend/js/webauthn-signature.js` - JavaScript 組件
- `Topics-backend/frontend/signature.php` - 簽名頁面
- `Topics-backend/frontend/save_signature.php` - 儲存簽名 API

## 技術參考

- [WebAuthn Specification](https://www.w3.org/TR/webauthn/)
- [FIDO2 Documentation](https://fidoalliance.org/fido2/)
- [MDN WebAuthn Guide](https://developer.mozilla.org/en-US/docs/Web/API/Web_Authentication_API)

