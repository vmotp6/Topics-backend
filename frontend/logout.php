<?php
require_once __DIR__ . '/session_config.php';

// 清除所有 session 資料
$_SESSION = array();

// 清除所有可能的 session 變數
unset($_SESSION['admin_logged_in']);
unset($_SESSION['logged_in']);
unset($_SESSION['username']);
unset($_SESSION['role']);
unset($_SESSION['user_id']);
unset($_SESSION['id']);
unset($_SESSION['name']);
unset($_SESSION['last_activity']);
unset($_SESSION['expire_time']);
unset($_SESSION['initiated']);

// 清除 session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 銷毀 session
session_destroy();

// 重新啟動一個乾淨的 session
session_start();
session_regenerate_id(true);

// 防止快取
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// 跳轉到前台首頁
header("Location: /Topics-frontend/frontend/index.php");
exit;
?>