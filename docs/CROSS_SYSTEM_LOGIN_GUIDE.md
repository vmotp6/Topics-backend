# 前後台跨系統登入指南

## 概述
本系統實現了前台（Topics-frontend）和後台（Topics-backend）之間的無縫登入狀態共享。已登入前台的授權使用者可以直接跳轉到後台系統，無需再次登入。

## 系統架構

### 前台（Topics-frontend）
- 位置：`Topics-frontend/frontend/`
- Session 名稱：`KANGNING_SESSION`
- 登入狀態標記：`$_SESSION['logged_in']`
- 支持的登入方式：
  - Google OAuth 登入
  - 傳統帳號密碼登入

### 後台（Topics-backend）
- 位置：`Topics-backend/frontend/`
- Session 名稱：`KANGNING_SESSION`（與前台相同）
- 登入狀態標記：`$_SESSION['admin_logged_in']` 或 `$_SESSION['logged_in']`

## 登入流程

### 1. 前台使用者登入後台

```
前台登入成功
    ↓
使用者點擊「前往後台」按鈕
    ↓
跳轉至: /Topics-backend/frontend/index.php?sid=[sessionId]
    ↓
後台 session_config.php 檢測到 ?sid 參數
    ↓
採用前台的 session ID
    ↓
檢測到 $_SESSION['logged_in'] = true
    ↓
自動設置 $_SESSION['admin_logged_in'] = true
    ↓
進入後台首頁
```

### 2. 後台使用者登入

```
使用者訪問後台
    ↓
檢測到未登入
    ↓
跳轉到登入頁面 (login.php)
    ↓
使用者輸入帳號密碼
    ↓
驗證成功
    ↓
設置 $_SESSION['admin_logged_in'] = true
設置 $_SESSION['logged_in'] = true（保持同步）
    ↓
進入後台首頁
```

## 角色權限

### 允許進入後台的角色

只有以下角色可以進入後台系統：

| 角色代碼 | 中文名稱 | 說明 |
|---------|--------|------|
| ADM | 管理員 | 系統管理員，擁有全部權限 |
| STA | 行政人員 | 學校行政人員，擁有管理權限 |
| DI | 主任 | 科系主任，擁有該科系的管理權限 |

### 不允許進入後台的角色

- STU（學生）：只能存取前台功能
- TEA（老師）：暫時不允許（可根據需求調整）

## 實現細節

### 前台 Header（share/header.php）

```php
// 檢查是否為允許進入後台的角色
$allowed_backend_roles = ['ADM', 'STA', 'DI', '管理員', '行政人員', '主任'];
$can_access_backend = in_array($user_role, $allowed_backend_roles);

if ($can_access_backend) {
    $sid = session_id();
    $backend_url = $base_url . '/Topics-backend/frontend/index.php' . ('?sid=' . urlencode($sid));
    echo "<a href=\"{$backend_url}\">前往後台</a>";
}
```

### 後台 Session 配置（session_config.php）

```php
session_name('KANGNING_SESSION');

// 若前台傳遞 sid，採用該 session id
if (!empty($_GET['sid'])) {
    session_id($_GET['sid']);
}

session_start();

// 若前台已登入，同步 admin_logged_in
if (!empty($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    $_SESSION['admin_logged_in'] = true;
}
```

### 後台登入檢查（login.php）

```php
// 如果是從前台傳過來且已登入，檢查角色是否可以進入後台
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    $user_role = $_SESSION['role'] ?? '';
    $allowed_roles = ['ADM', 'STA', 'DI', '管理員', '行政人員', '主任'];
    if (in_array($user_role, $allowed_roles)) {
        $_SESSION['admin_logged_in'] = true;
        // 直接進入後台
    }
}
```

### 後台首頁驗證（index.php）

```php
// 檢查登入狀態 - 支援前台和後台的登入狀態
$isLoggedIn = (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) ||
              (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true);

if (!$isLoggedIn) {
    header("Location: login.php");
    exit;
}

// 檢查角色是否被允許進入後台
$allowed_backend_roles = ['ADM', 'STA', 'DI', '管理員', '行政人員', '主任'];
if (!in_array($user_role, $allowed_backend_roles)) {
    // 角色不允許進入後台，重定向到登入頁面
    $_SESSION['admin_logged_in'] = false;
    session_destroy();
    header("Location: login.php");
    exit;
}
```

## 登出流程

### 前台登出
- 清除 `$_SESSION['logged_in']`
- 清除所有相關的 session 資料
- 銷毀 session

### 後台登出
- 清除 `$_SESSION['admin_logged_in']` 和 `$_SESSION['logged_in']`
- 清除所有相關的 session 資料
- 銷毀 session

**注意**：後台登出時會完全銷毀 session，前台登出時也會清除所有 session 資料。

## 安全考慮

### Session 安全

1. **Session Cookie 設置**
   - `session.cookie_httponly = 1`：防止 JavaScript 存取
   - `session.cookie_secure = 0`（開發環境），`= 1`（生產環境）
   - `session.use_strict_mode = 1`：防止 session 固定攻擊

2. **Session 回收機制**
   - 不活動 30 分鐘後自動重新生成 session ID
   - Session 過期時間：24 小時

3. **跨系統 Session 共享**
   - 使用相同的 session 名稱 `KANGNING_SESSION`
   - 同一網域下可安全共享
   - 通過 URL 參數 `?sid` 傳遞已認證的 session ID

### 角色驗證

- 前台登入時驗證角色
- 後台登入時驗證角色
- 從前台進入後台時再次驗證角色
- 防止權限提升攻擊

### URL 參數驗證

- Session ID 通過 `urlencode()` 編碼
- 後台驗證 session 資料的完整性
- 拒絕不完整或無效的 session

## 測試場景

### 場景 1：前台登入 → 進入後台

1. 在前台以管理員身分登入
2. 點擊「前往後台」按鈕
3. 應該直接進入後台首頁（無需重新登入）
4. 驗證後台顯示正確的使用者資訊

### 場景 2：直接訪問後台

1. 直接訪問 `/Topics-backend/frontend/index.php`
2. 若未登入，應重定向到登入頁面
3. 登入後應進入後台首頁

### 場景 3：權限限制

1. 在前台以學生身分登入
2. 嘗試點擊「前往後台」按鈕（應不存在）
3. 直接訪問後台 URL
4. 應被重定向到後台登入頁面

### 場景 4：登出

1. 在後台登出
2. 前台 session 也應被清除
3. 訪問前台應需要重新登入

## 常見問題

### Q: 為什麼點擊「前往後台」後仍需登入？

A: 這可能是由於：
1. 瀏覽器未啟用 Cookie
2. Session 已過期
3. 使用者角色不允許進入後台
4. Session ID 傳遞失敗

檢查瀏覽器控制台的 Cookie 設置，確保 `KANGNING_SESSION` cookie 存在。

### Q: 為什麼後台無法識別前台的登入狀態？

A: 檢查以下內容：
1. 兩個系統是否在同一個網域下
2. Session 名稱是否相同（都應為 `KANGNING_SESSION`）
3. Cookie Path 是否正確（都應為 `/`）
4. `session_config.php` 是否正確載入

### Q: 前台登入後，在後台登出會影響前台嗎？

A: 是的，後台登出會銷毀 session，從而影響前台。使用者需要重新登入。

## 相關文件

- 前台配置：`Topics-frontend/frontend/session_config.php`
- 前台 Header：`Topics-frontend/frontend/share/header.php`
- 後台配置：`Topics-backend/frontend/session_config.php`
- 後台登入：`Topics-backend/frontend/login.php`
- 後台首頁：`Topics-backend/frontend/index.php`
- 後台登出：`Topics-backend/frontend/logout.php`

## 更新日誌

- **2024-12-29**：初次建立跨系統登入支援
  - 後台支援前台登入狀態自動同步
  - 實現 session ID 通過 URL 安全傳遞
  - 添加角色權限驗證機制
