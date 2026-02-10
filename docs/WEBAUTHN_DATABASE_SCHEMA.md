# WebAuthn 2FA 資料庫架構設計

## 資料表關聯圖

```
┌─────────────────┐
│     user        │
│  (用戶主表)      │
├─────────────────┤
│ id (PK)         │◄──┐
│ username        │   │
│ email           │   │
│ password        │   │
│ name            │   │
│ role            │   │
│ ...             │   │
└─────────────────┘   │
                      │
                      │ FK: user_id
        ┌─────────────┼─────────────┬─────────────────┐
        │             │             │                 │
        │             │             │                 │
┌───────▼──────────┐  │  ┌──────────▼──────────┐  ┌──▼──────────────────┐
│ webauthn_2fa_    │  │  │ webauthn_register_  │  │ webauthn_           │
│ codes            │  │  │ pending             │  │ credentials         │
│ (2FA驗證碼表)     │  │  │ (待驗證設備表)       │  │ (已驗證設備表)       │
├──────────────────┤  │  ├─────────────────────┤  ├─────────────────────┤
│ id (PK)          │  │  │ id (PK)             │  │ id (PK)             │
│ user_id (FK)     │──┘  │ user_id (FK)        │──┘ │ user_id (FK)        │
│ code             │     │ credential_id       │    │ credential_id (UK)  │
│ expires_at       │     │ public_key          │    │ public_key          │
│ verified         │     │ device_name         │    │ counter             │
│ verified_at      │     │ device_type         │    │ device_name         │
│ ip_address       │     │ verify_token (UK)   │    │ device_type         │
│ user_agent       │     │ verify_expires_at   │    │ created_at          │
│ created_at       │     │ ip_address          │    │ last_used_at        │
└──────────────────┘     │ user_agent          │    │ is_active           │
                         │ created_at          │    └─────────────────────┘
                         └─────────────────────┘
                                 │
                                 │ 驗證成功後
                                 │ 移動至 →→→→→→→→→→→┘
```

## 正規化設計

### 第一正規化 (1NF)
✅ **所有表都符合 1NF**
- 每個欄位都是原子性的（不可再分）
- 每個欄位都有唯一的名稱
- 資料以行的形式儲存
- 沒有重複的群組

### 第二正規化 (2NF)
✅ **所有表都符合 2NF**
- 符合 1NF
- 所有非鍵屬性完全依賴於主鍵
- 沒有部分依賴（所有表都使用單一主鍵 `id`）

### 第三正規化 (3NF)
✅ **所有表都符合 3NF**
- 符合 2NF
- 沒有遞移依賴
- 所有非鍵屬性僅依賴於主鍵

## 資料表詳細設計

### 1. webauthn_2fa_codes (2FA 驗證碼表)

**用途：** 儲存設備註冊前的郵件雙因素驗證碼

**欄位說明：**
| 欄位名 | 類型 | 約束 | 說明 |
|--------|------|------|------|
| id | INT UNSIGNED | PK, AUTO_INCREMENT | 主鍵 |
| user_id | INT | FK, NOT NULL | 用戶 ID（關聯 user.id） |
| code | CHAR(6) | NOT NULL | 6 位數驗證碼 |
| expires_at | DATETIME | NOT NULL | 過期時間 |
| verified | TINYINT(1) | NOT NULL, DEFAULT 0 | 是否已驗證 |
| verified_at | DATETIME | NULL | 驗證時間 |
| ip_address | VARCHAR(45) | NULL | 請求 IP（支援 IPv6） |
| user_agent | VARCHAR(500) | NULL | 瀏覽器 User Agent |
| created_at | DATETIME | NOT NULL, DEFAULT NOW | 建立時間 |

**索引設計：**
- `PRIMARY KEY (id)` - 主鍵索引
- `INDEX idx_user_code (user_id, code)` - 複合索引，加速驗證查詢
- `INDEX idx_expires (expires_at)` - 過期時間索引，用於定期清理
- `INDEX idx_verified (verified, created_at)` - 驗證狀態索引，用於統計
- `INDEX idx_user_created (user_id, created_at DESC)` - 查詢用戶最新記錄

**外鍵約束：**
```sql
FOREIGN KEY (user_id) REFERENCES user(id) 
  ON DELETE CASCADE 
  ON UPDATE CASCADE
```

**生命週期：**
1. 用戶請求註冊設備
2. 系統生成驗證碼，插入記錄
3. 發送郵件到用戶信箱
4. 用戶輸入驗證碼
5. 驗證成功，更新 `verified = 1`, `verified_at = NOW()`
6. Session 記錄驗證狀態（5分鐘有效）
7. 定期清理過期記錄（每天凌晨 2 點）

---

### 2. webauthn_register_pending (待驗證設備表)

**用途：** 暫存註冊流程中的設備資訊，等待 Email 驗證後才正式寫入 webauthn_credentials

**欄位說明：**
| 欄位名 | 類型 | 約束 | 說明 |
|--------|------|------|------|
| id | INT UNSIGNED | PK, AUTO_INCREMENT | 主鍵 |
| user_id | INT | FK, NOT NULL | 用戶 ID（關聯 user.id） |
| credential_id | VARCHAR(512) | NOT NULL | WebAuthn 憑證 ID |
| public_key | TEXT | NOT NULL | 公開金鑰 |
| device_name | VARCHAR(255) | NULL | 設備名稱 |
| device_type | VARCHAR(50) | NULL | 設備類型 |
| verify_token | VARCHAR(64) | UNIQUE, NOT NULL | Email 驗證 Token |
| verify_expires_at | DATETIME | NOT NULL | 驗證連結過期時間 |
| ip_address | VARCHAR(45) | NULL | 註冊請求 IP |
| user_agent | VARCHAR(500) | NULL | 瀏覽器 User Agent |
| created_at | DATETIME | NOT NULL, DEFAULT NOW | 建立時間 |

**索引設計：**
- `PRIMARY KEY (id)` - 主鍵索引
- `UNIQUE INDEX idx_token (verify_token)` - Token 唯一索引
- `INDEX idx_user (user_id, created_at DESC)` - 用戶複合索引
- `INDEX idx_expires (verify_expires_at)` - 過期時間索引
- `INDEX idx_credential (credential_id(255))` - 憑證 ID 索引（前綴索引）

**外鍵約束：**
```sql
FOREIGN KEY (user_id) REFERENCES user(id) 
  ON DELETE CASCADE 
  ON UPDATE CASCADE
```

**生命週期：**
1. 用戶通過 2FA 驗證
2. 開始 WebAuthn 註冊流程
3. 系統暫存設備資訊到此表
4. 發送驗證郵件（包含 verify_token）
5. 用戶點擊郵件連結
6. 驗證成功，將資料移動到 `webauthn_credentials`
7. 刪除此表中的記錄
8. 定期清理過期記錄（每天凌晨 3 點）

---

### 3. webauthn_credentials (已驗證設備表)

**用途：** 儲存已驗證的 WebAuthn 設備憑證

**欄位說明：**
| 欄位名 | 類型 | 約束 | 說明 |
|--------|------|------|------|
| id | INT | PK, AUTO_INCREMENT | 主鍵 |
| user_id | INT | FK, NOT NULL | 用戶 ID（關聯 user.id） |
| credential_id | VARCHAR(255) | UNIQUE, NOT NULL | WebAuthn 憑證 ID（唯一） |
| public_key | TEXT | NOT NULL | 公開金鑰 |
| counter | BIGINT UNSIGNED | DEFAULT 0 | 簽名計數器（防重放攻擊） |
| device_name | VARCHAR(255) | NULL | 設備名稱 |
| device_type | VARCHAR(50) | NULL | 設備類型 |
| created_at | DATETIME | NOT NULL, DEFAULT NOW | 建立時間 |
| last_used_at | DATETIME | NULL | 最後使用時間 |
| is_active | TINYINT(1) | DEFAULT 1 | 是否啟用 |

**索引設計：**
- `PRIMARY KEY (id)` - 主鍵索引
- `UNIQUE INDEX idx_credential_id (credential_id)` - 憑證 ID 唯一索引
- `INDEX idx_user_id (user_id)` - 用戶索引
- `INDEX idx_is_active (is_active)` - 啟用狀態索引

**外鍵約束：**
```sql
FOREIGN KEY (user_id) REFERENCES user(id) 
  ON DELETE CASCADE 
  ON UPDATE CASCADE
```

**生命週期：**
1. 從 `webauthn_register_pending` 移動過來（Email 驗證後）
2. 用戶使用設備進行生物驗證簽名
3. 每次使用更新 `last_used_at` 和 `counter`
4. 可停用設備（設置 `is_active = 0`）
5. 用戶刪除時，級聯刪除所有憑證

---

## 資料流程圖

### 完整註冊流程

```
用戶操作                      系統處理                      資料庫操作
─────────                    ─────────                    ─────────

1. 點擊「註冊新設備」
                         ──► 顯示註冊模態框
                         
2. 點擊「發送驗證碼」
                         ──► 生成 6 位數驗證碼        ──► INSERT INTO webauthn_2fa_codes
                         ──► 發送郵件
                         
3. 輸入驗證碼
                         ──► 驗證驗證碼              ──► SELECT + UPDATE webauthn_2fa_codes
                         ──► Session 記錄驗證狀態
                         
4. 點擊「開始註冊設備」
                         ──► 檢查 Session 驗證狀態
                         ──► 生成 WebAuthn Challenge
                         
5. 完成生物驗證
                         ──► 接收憑證資訊
                         ──► 生成 verify_token       ──► INSERT INTO webauthn_register_pending
                         ──► 發送驗證郵件
                         
6. 點擊郵件連結
                         ──► 驗證 Token              ──► SELECT FROM webauthn_register_pending
                         ──► 儲存憑證                ──► INSERT INTO webauthn_credentials
                         ──► 刪除待驗證記錄          ──► DELETE FROM webauthn_register_pending
                         ──► 清除 Session 驗證狀態    ──► UPDATE webauthn_2fa_codes (verified=1)
                         
7. 註冊完成
```

---

## 安全性設計

### 1. 資料完整性
- ✅ 所有必填欄位都設置 NOT NULL
- ✅ 主鍵使用 AUTO_INCREMENT 確保唯一性
- ✅ 外鍵約束確保引用完整性
- ✅ UNIQUE 約束防止重複資料

### 2. 級聯操作
```sql
ON DELETE CASCADE  -- 用戶刪除時，自動刪除相關記錄
ON UPDATE CASCADE  -- 用戶 ID 更新時，自動更新相關記錄
```

### 3. 時效性控制
- `webauthn_2fa_codes.expires_at` - 驗證碼 10 分鐘過期
- `webauthn_register_pending.verify_expires_at` - 驗證連結 1 小時過期
- Session 驗證狀態 - 5 分鐘過期

### 4. 防濫用機制
- IP 地址記錄，可追蹤異常請求
- User Agent 記錄，可分析請求來源
- 驗證碼一次性使用
- 定期清理過期資料

---

## 效能優化

### 1. 索引策略
- **複合索引：** `(user_id, code)` 加速驗證查詢
- **覆蓋索引：** `(verified, created_at)` 用於統計查詢
- **前綴索引：** `credential_id(255)` 節省空間

### 2. 查詢優化
```sql
-- 使用索引的高效查詢
SELECT * FROM webauthn_2fa_codes 
WHERE user_id = ? AND code = ?;  -- 使用 idx_user_code

-- 避免全表掃描
SELECT * FROM webauthn_2fa_codes 
WHERE expires_at < NOW();  -- 使用 idx_expires
```

### 3. 定期維護
```sql
-- 分析表
ANALYZE TABLE webauthn_2fa_codes;

-- 優化表
OPTIMIZE TABLE webauthn_2fa_codes;
```

---

## 監控與統計

### 1. 驗證成功率
```sql
SELECT 
  DATE(created_at) as date,
  COUNT(*) as total,
  SUM(verified) as verified,
  ROUND(SUM(verified) * 100.0 / COUNT(*), 2) as success_rate
FROM webauthn_2fa_codes
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY DATE(created_at)
ORDER BY date DESC;
```

### 2. 異常 IP 檢測
```sql
SELECT 
  ip_address,
  COUNT(*) as attempts,
  SUM(verified) as success,
  COUNT(*) - SUM(verified) as failed
FROM webauthn_2fa_codes
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
GROUP BY ip_address
HAVING COUNT(*) > 10
ORDER BY attempts DESC;
```

### 3. 設備註冊統計
```sql
SELECT 
  device_type,
  COUNT(*) as count,
  ROUND(COUNT(*) * 100.0 / SUM(COUNT(*)) OVER(), 2) as percentage
FROM webauthn_credentials
WHERE is_active = 1
GROUP BY device_type
ORDER BY count DESC;
```

---

## 資料庫版本控制

### Migration 腳本順序
1. `create_webauthn_tables.sql` - 建立基礎 WebAuthn 表
2. `create_webauthn_register_pending.sql` - 建立待驗證表
3. `create_webauthn_2fa_table.sql` - 建立 2FA 驗證碼表

### Rollback 順序（反向）
1. DROP `webauthn_2fa_codes`
2. DROP `webauthn_register_pending`
3. DROP `webauthn_credentials`

---

## 備份策略

### 1. 完整備份（每日）
```bash
mysqldump -u root -p database_name \
  webauthn_2fa_codes \
  webauthn_register_pending \
  webauthn_credentials \
  > webauthn_backup_$(date +%Y%m%d).sql
```

### 2. 增量備份（每小時）
```sql
-- 備份最近 1 小時的記錄
SELECT * INTO OUTFILE '/backup/2fa_codes_incremental.csv'
FROM webauthn_2fa_codes
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR);
```

---

## 故障恢復

### 1. 恢復驗證碼表
```bash
mysql -u root -p database_name < webauthn_backup_20260210.sql
```

### 2. 清除損壞資料
```sql
-- 清除孤立記錄（user_id 不存在）
DELETE w FROM webauthn_2fa_codes w
LEFT JOIN user u ON w.user_id = u.id
WHERE u.id IS NULL;
```

---

## 相容性說明

- **MySQL 版本：** 5.7+
- **MariaDB 版本：** 10.2+
- **字元集：** UTF8MB4（完整 Unicode 支援）
- **引擎：** InnoDB（支援交易和外鍵）

---

## 版本資訊

- **版本：** 2.0.0
- **更新日期：** 2026-02-10
- **變更說明：** 
  - 加入外鍵約束
  - 優化索引設計
  - 新增 IP 和 User Agent 欄位
  - 完整的正規化設計
