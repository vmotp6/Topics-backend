# 報名階段時間與 Gmail 發送設定位置

## 一、每個階段的報名時間在哪裡設定

報名時間是依「月份」判斷（完全免試 4 月、優先免試 5 月、聯合免試 6–7 月；續招依科系名額管理時間），**需在以下 3 個檔案保持一致**：

### 1. enrollment_list.php（名單頁面顯示的階段）

**檔案：** `Topics-backend/frontend/enrollment_list.php`  
**位置：** 約第 **196–206 行**，函數 `getCurrentRegistrationStage()`

```php
// 判斷當前報名階段（與 send_registration_stage_reminders.php、registration_reminder_api.php 需一致）
// 完全免試 4 月、優先免試 5 月、聯合免試 6–7 月；續招依科系名額管理設定的時間區間
function getCurrentRegistrationStage($conn) {
    $current_month = (int)date('m');
    // 續招：依 department_quotas 時間區間
    if ($current_month >= 4 && $current_month < 5) return 'full_exempt';   // 4月：完全免試
    if ($current_month >= 5 && $current_month < 6) return 'priority_exam';  // 5月：優先免試
    if ($current_month >= 6 && $current_month < 8) return 'joint_exam';      // 6-7月：聯合免試
    return null; // 非報名期間
}
```

- 改這裡：名單頁「當前報名階段」、誰可點「已提醒/已報名」會跟著變。

### 2. send_registration_stage_reminders.php（自動發 Gmail 的腳本）

**檔案：** `Topics-backend/frontend/send_registration_stage_reminders.php`  
**位置：** 約第 **72–82 行**，同樣是 `getCurrentRegistrationStage()`

- 改這裡：**自動發送階段提醒 Gmail** 會依新的月份判斷發送。

### 3. registration_reminder_api.php（已提醒/已報名 API）

**檔案：** `Topics-frontend/frontend/api/registration_reminder_api.php`  
**位置：** 約第 **56–66 行**，也是 `getCurrentRegistrationStage()`

- 改這裡：老師在名單頁按「已提醒」「已報名」時，API 認定的階段會變。

**建議：** 調整報名時間時，上述 3 個檔案的 `getCurrentRegistrationStage()` 請一起改，邏輯保持一致。

---

## 二、每個階段都會發送 Gmail 的設定在哪裡

### 1. Gmail 帳號與 SMTP（發信用）

**檔案：** `Topics-frontend/frontend/config.php`  
**位置：** 約第 **34–41 行**

```php
// SMTP 郵件設定
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'your-email@gmail.com');   // Gmail 帳號
define('SMTP_PASSWORD', 'your-app-password');     // Gmail 應用程式密碼
define('SMTP_FROM_EMAIL', 'your-email@gmail.com'); // 發信人
define('SMTP_FROM_NAME', '康寧大學招生系統');      // 發信人名稱
define('SMTP_SECURE', 'tls');
```

- 改這裡：換發信 Gmail、密碼、顯示名稱。

### 2. 實際發送「階段提醒 Gmail」的程式

**檔案：** `Topics-backend/frontend/send_registration_stage_reminders.php`

- **誰會被發信：** 約第 **262–268 行** 的 SQL 條件（有 email、未報名、該階段未提醒、當年度國三、未結案）。
- **信件內容：** 約第 **108–203 行**（`sendRegistrationStageReminderEmail`：主旨、HTML、純文字）。
- **階段對應的月份：** 同上，第 **72–82 行** `getCurrentRegistrationStage()`。

要改「每個階段都會發 Gmail」的邏輯或內容，就是在這個檔案改。

### 3. 自動「每天跑一次」發送（排程）

- **腳本路徑：** `Topics-backend/frontend/send_registration_stage_reminders.php`
- **排程設定：**
  - **Windows：** 工作排程器，每天指定時間執行上述 PHP 腳本。
  - **Linux：** crontab，例如每天 9:00：
    ```bash
    0 9 * * * /usr/bin/php /path/to/Topics-backend/frontend/send_registration_stage_reminders.php
    ```

排程只負責「何時執行」；要改發信對象、階段時間、信件內容，仍是改 `send_registration_stage_reminders.php` 和上面列的 3 個 `getCurrentRegistrationStage()`。

---

## 快速對照

| 項目 | 檔案 | 大約行數 |
|------|------|----------|
| 報名階段月份（名單頁） | `enrollment_list.php` | 196–206 |
| 報名階段月份（自動發信） | `send_registration_stage_reminders.php` | 72–82 |
| 報名階段月份（已提醒/已報名 API） | `registration_reminder_api.php` | 56–66 |
| Gmail 帳號、密碼、發信人 | `Topics-frontend/frontend/config.php` | 34–41 |
| 階段提醒信內容、發信對象條件 | `send_registration_stage_reminders.php` | 108–203、262–268 |
