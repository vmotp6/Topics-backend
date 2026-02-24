<?php
// 後台 Session 設定 - 與前台保持一致以共享登入狀態
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_lifetime', 86400);
    ini_set('session.gc_maxlifetime', 86400);
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', 0);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_path', '/');
    ini_set('session.cookie_domain', '');
    session_name('KANGNING_SESSION');

    // 若前台傳遞 sid，採用該 session id
    if (!empty($_GET['sid'])) {
        session_id($_GET['sid']);
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 初始化跨設備認證 sessions 陣列（如果不存在）
if (!isset($_SESSION['cross_device_sessions'])) {
    $_SESSION['cross_device_sessions'] = [];
}

// 更新最後活動時間
if (isset($_SESSION['last_activity'])) {
    if (time() - $_SESSION['last_activity'] > 1800) {
        session_regenerate_id(true);
    }
}
$_SESSION['last_activity'] = time();

// 設定預設值
if (!isset($_SESSION['logged_in'])) {
    $_SESSION['logged_in'] = false;
}

// 後台相容處理：若前台已登入，同步 admin_logged_in
if (!empty($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    $_SESSION['admin_logged_in'] = true;
}

/**
 * 檢查後台登入狀態
 * 如果未登入或角色不允許進入後台，直接重定向到登入頁面
 */
function checkBackendLogin() {
    // 檢查登入狀態 - 支援前台和後台的登入狀態
    $isLoggedIn = (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) ||
                  (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true);

    if (!$isLoggedIn) {
        // 檢測是否為 API 請求（根據請求路徑或 Content-Type）
        $isApiRequest = (
            strpos($_SERVER['REQUEST_URI'], '/api/') !== false ||
            strpos($_SERVER['SCRIPT_NAME'], '/api/') !== false ||
            (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
        );
        
        if ($isApiRequest) {
            // API 請求返回 JSON 錯誤
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => '未登入或登入已過期'
            ]);
            exit;
        } else {
            // 一般頁面請求重定向到登入頁
            header("Location: login.php");
            exit;
        }
    }

    // 驗證角色
    $user_role = $_SESSION['role'] ?? '';
    // 向後相容：部分舊系統可能用 admin/staff/director/teacher 等字串作為 role
    $allowed_backend_roles = [
        'ADM', 'STA', 'DI', 'TEA', 'IM', 'AS', 'STAM',
        '管理員', '行政人員', '學校行政人員', '主任', '老師', '招生中心組員', '資管科主任', '科助',
        'admin', 'staff', 'director', 'teacher', 'assistant'
    ];
    
    if (!in_array($user_role, $allowed_backend_roles)) {
        $_SESSION['admin_logged_in'] = false;
        session_destroy();
        
        // 檢測是否為 API 請求
        $isApiRequest = (
            strpos($_SERVER['REQUEST_URI'], '/api/') !== false ||
            strpos($_SERVER['SCRIPT_NAME'], '/api/') !== false ||
            (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
        );
        
        if ($isApiRequest) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => '權限不足'
            ]);
            exit;
        } else {
            header("Location: login.php");
            exit;
        }
    }
}

/**
 * 檢查是否為特定角色
 * @param string|array $roles 單一角色或角色陣列
 * @return bool
 */
function hasRole($roles) {
    $user_role = $_SESSION['role'] ?? '';
    if (is_array($roles)) {
        return in_array($user_role, $roles);
    }
    return $user_role === $roles;
}

/**
 * 檢查是否為管理員
 * @return bool
 */
function isAdmin() {
    $user_role = $_SESSION['role'] ?? '';
    return in_array($user_role, ['ADM', '管理員']);
}

/**
 * 檢查是否為行政人員
 * @return bool
 */
function isStaff() {
    $user_role = $_SESSION['role'] ?? '';
    return in_array($user_role, ['STA', '行政人員']);
}

/**
 * 檢查是否為主任
 * @return bool
 */
function isDirector() {
    $user_role = $_SESSION['role'] ?? '';
    return in_array($user_role, ['DI', '主任']);
}

/**
 * 檢查是否為老師
 * @return bool
 */
function isTeacher() {
    $user_role = $_SESSION['role'] ?? '';
    return in_array($user_role, ['TEA', '老師']);
}

/**
 * 檢查是否為超級使用者（管理員或行政人員）
 * @return bool
 */
function isSuperUser() {
    return isAdmin() || isStaff();
}
