# WebAuthn 2FA 資料庫關聯快速參考

## 📊 資料表關聯圖（ASCII）

```
┌─────────────────────────────────────────────────────────────────┐
│                          user (用戶主表)                         │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │ id (PK) | username | email | password | name | role ... │   │
│  └─────────────────────────────────────────────────────────┘   │
└──────────────────────┬──────────────────────────────────────────┘
                       │
         ┌─────────────┼─────────────┬───────────────────┐
         │             │             │                   │
         │ FK          │ FK          │ FK                │
         ▼             ▼             ▼                   │
┌────────────────┐ ┌────────────────┐ ┌──────────────────▼─────┐
│ 2FA 驗證碼表    │ │ 待驗證設備表    │ │ 已驗證設備表           │
├────────────────┤ ├────────────────┤ ├────────────────────────┤
│ webauthn_2fa_  │ │ webauthn_      │ │ webauthn_credentials   │
│ codes          │ │ register_      │ │                        │
│                │ │ pending        │ │ ✓ 正式使用的設備       │
│ ⏱ 10分鐘過期   │ │ ⏱ 1小時過期    │ │ ♾️ 長期有效             │
│ 🔢 6位數驗證碼  │ │ 📧 Email驗證   │ │ 🔐 生物驗證             │
└────────────────┘ └────────────────┘ └────────────────────────┘
                           │
                           │ 驗證成功後移動 →→→→→→→→┘
```

## 🔗 外鍵關聯

| 子表 | 外鍵欄位 | 參考表 | 參考欄位 | 刪除規則 | 更新規則 |
|------|---------|--------|---------|---------|---------|
| webauthn_2fa_codes | user_id | user | id | CASCADE | CASCADE |
| webauthn_register_pending | user_id | user | id | CASCADE | CASCADE |
| webauthn_credentials | user_id | user | id | CASCADE | CASCADE |

**CASCADE 說明：**
- `ON DELETE CASCADE` - 刪除用戶時，自動刪除該用戶的所有相關記錄
- `ON UPDATE CASCADE` - 更新用戶 ID 時，自動更新所有相關記錄

## 📋 資料表快速對照

### 1️⃣ webauthn_2fa_codes
- **用途：** 郵件 2FA 驗證
- **壽命：** 10 分鐘
- **清理：** 每天凌晨 2:00
- **關鍵欄位：**
  - `code` - CHAR(6)
  - `verified` - 0/1
  - `expires_at`

### 2️⃣ webauthn_register_pending  
- **用途：** 暫存待驗證設備
- **壽命：** 1 小時
- **清理：** 每天凌晨 3:00
- **關鍵欄位：**
  - `verify_token` - VARCHAR(64) UNIQUE
  - `credential_id` - VARCHAR(512)
  - `verify_expires_at`

### 3️⃣ webauthn_credentials
- **用途：** 已驗證設備憑證
- **壽命：** 永久（可停用）
- **清理：** 手動或用戶刪除
- **關鍵欄位：**
  - `credential_id` - VARCHAR(255) UNIQUE
  - `public_key` - TEXT
  - `is_active` - 0/1

## 🔑 索引策略

### 複合索引（提升查詢效能）
```sql
-- 2FA 驗證查詢
idx_user_code (user_id, code)

-- 用戶記錄查詢
idx_user_created (user_id, created_at DESC)

-- 驗證狀態統計
idx_verified (verified, created_at)
```

### 單欄索引（加速過濾）
```sql
-- 過期清理
idx_expires (expires_at)

-- Token 驗證
idx_token (verify_token)

-- 設備查詢
idx_credential_id (credential_id)
```

## 🔄 資料流轉

```
1. 用戶請求註冊
   ↓
2. [webauthn_2fa_codes] 插入驗證碼
   ↓
3. 驗證成功 → verified = 1
   ↓
4. Session 記錄（5分鐘）
   ↓
5. [webauthn_register_pending] 插入待驗證設備
   ↓
6. Email 驗證成功
   ↓
7. [webauthn_credentials] 插入正式設備
   ↓
8. [webauthn_register_pending] 刪除記錄
   ↓
9. 完成 ✓
```

## 🛡️ 安全特性

### 資料完整性
- ✅ NOT NULL 約束
- ✅ UNIQUE 約束（防重複）
- ✅ 外鍵約束（引用完整性）
- ✅ CASCADE 規則（自動清理）

### 時效控制
- ⏱️ 驗證碼：10 分鐘
- ⏱️ Email 連結：1 小時  
- ⏱️ Session：5 分鐘
- 🗑️ 自動清理：每天執行

### 追蹤記錄
- 📍 IP 地址（IPv4/IPv6）
- 🌐 User Agent
- 📅 建立時間
- ✅ 驗證時間

## 📊 常用查詢

### 檢查用戶的有效驗證碼
```sql
SELECT * FROM webauthn_2fa_codes
WHERE user_id = ? AND verified = 0 AND expires_at > NOW()
ORDER BY created_at DESC LIMIT 1;
```

### 檢查待驗證設備
```sql
SELECT * FROM webauthn_register_pending
WHERE user_id = ? AND verify_expires_at > NOW();
```

### 查詢用戶的所有設備
```sql
SELECT * FROM webauthn_credentials
WHERE user_id = ? AND is_active = 1
ORDER BY last_used_at DESC;
```

### 驗證成功率統計
```sql
SELECT 
  COUNT(*) as total,
  SUM(verified) as success,
  ROUND(SUM(verified)*100.0/COUNT(*), 2) as rate
FROM webauthn_2fa_codes
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY);
```

## 🔧 維護命令

### 手動清理過期資料
```sql
-- 清理過期驗證碼
DELETE FROM webauthn_2fa_codes 
WHERE expires_at < NOW();

-- 清理過期待驗證設備
DELETE FROM webauthn_register_pending 
WHERE verify_expires_at < NOW();
```

### 優化表效能
```sql
ANALYZE TABLE webauthn_2fa_codes;
ANALYZE TABLE webauthn_register_pending;
ANALYZE TABLE webauthn_credentials;
```

### 檢查外鍵
```sql
SELECT * FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME LIKE 'webauthn%'
  AND CONSTRAINT_NAME LIKE 'fk_%';
```

## 📦 一鍵安裝

```bash
# 完整架構（推薦）
mysql -u root -p database_name < create_webauthn_complete_schema.sql

# 或分別安裝
mysql -u root -p database_name < create_webauthn_2fa_table.sql
mysql -u root -p database_name < create_webauthn_register_pending.sql
mysql -u root -p database_name < create_webauthn_tables.sql
```

## ⚠️ 注意事項

1. **外鍵依賴：** 必須先有 `user` 表
2. **字元集：** 使用 UTF8MB4（支援完整 Unicode）
3. **引擎：** 必須使用 InnoDB（支援外鍵）
4. **權限：** 建立事件需要 EVENT 權限
5. **事件排程：** 需啟用 `event_scheduler`

## 🆘 故障排除

### 外鍵建立失敗
```sql
-- 檢查用戶表是否存在
SHOW TABLES LIKE 'user';

-- 檢查用戶表主鍵
SHOW INDEX FROM user WHERE Key_name = 'PRIMARY';

-- 檢查資料類型是否一致
SHOW COLUMNS FROM user WHERE Field = 'id';
```

### 事件未執行
```sql
-- 檢查事件排程器
SHOW VARIABLES LIKE 'event_scheduler';

-- 啟用事件排程器
SET GLOBAL event_scheduler = ON;

-- 查看事件狀態
SHOW EVENTS WHERE Db = DATABASE();
```

---

**版本：** 2.0.0 | **更新：** 2026-02-10
