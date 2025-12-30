<?php
require_once __DIR__ . '/session_config.php';

// 如果已經登入，直接跳轉到管理介面
// 支援後台登入（admin_logged_in）和前台登入轉過來的情況（logged_in + 驗證角色）
$isAlreadyLoggedIn = (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in']);

// 如果是從前台傳過來且已登入，檢查角色是否可以進入後台
if (!$isAlreadyLoggedIn && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    $user_role = $_SESSION['role'] ?? '';
    // 檢查角色是否允許進入後台（管理員、行政人員、主任）
    $allowed_roles = ['ADM', 'STA', 'DI', '管理員', '行政人員', '主任'];
    if (in_array($user_role, $allowed_roles)) {
        $_SESSION['admin_logged_in'] = true;
        $isAlreadyLoggedIn = true;
    }
}

if ($isAlreadyLoggedIn) {
    header("Location: index.php");
    exit;
}

// 處理登入
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'login') {
    $username = $_POST['username'];
    $password = $_POST['password'];
    
    // 使用統一的資料庫配置
    require_once '../../Topics-frontend/frontend/config.php';

    try {
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USERNAME, DB_PASSWORD);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // 查詢用戶
        $stmt = $pdo->prepare("SELECT * FROM user WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $login_successful = false;

        // 1. 驗證使用者是否存在
        if (!$user) {
            $error_message = "帳號或密碼錯誤。";
        }
        // 2. 驗證密碼
        else {
            // 優先使用 password_verify 驗證已雜湊的密碼
            if (password_verify($password, $user['password'])) {
                $login_successful = true;
            }
            // 兼容舊的明文密碼
            elseif ($password === $user['password']) {
                $login_successful = true;
            } else {
                $error_message = "帳號或密碼錯誤。";
            }

            // 如果密碼驗證成功，繼續檢查帳號狀態和角色
            if ($login_successful) {
                // 優先檢查角色是否不允許登入後台（只允許管理員、行政人員、主任）
                // 使用角色代碼：'ADM'=管理員, 'STA'=行政人員, 'DI'=主任
                $allowed_roles = ['ADM', 'STA', 'DI'];
                // 向後兼容：也檢查舊的中文角色名稱
                $allowed_role_names = ['管理員', '行政人員', '主任'];
                
                if (!in_array($user['role'], $allowed_roles) && !in_array($user['role'], $allowed_role_names)) {
                    $error_message = "帳號或密碼錯誤。"; // 對不允許的角色顯示一樣的錯誤訊息，避免透露帳號存在
                    $login_successful = false;
                } elseif ($user['status'] != 1) { // 然後才檢查帳號是否被停用
                    $error_message = "您的帳號已被停用，請聯繫管理員。";
                    $login_successful = false;
                }
            }
        }
        
        // 3. 登入成功
        if ($login_successful && !isset($error_message)) {
            // 根據角色設定 Session
            $_SESSION['admin_logged_in'] = true; // 後台登入狀態
            $_SESSION['logged_in'] = true;        // 前台登入狀態（保持同步）
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role']; // 儲存角色
            $_SESSION['name'] = $user['name']; // 儲存使用者姓名

            // 統一跳轉到首頁
            header("Location: index.php");
            exit;
        }
    } catch (PDOException $e) {
        $error_message = "資料庫連接失敗: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>招生平台 後台系統 - 登入</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Microsoft JhengHei', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #495057 0%, #6c757d 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
            padding: 40px;
            width: 100%;
            max-width: 400px;
            text-align: center;
            border: 1px solid #e9ecef;
        }
        
        .login-logo {
            font-size: 2.2em;
            font-weight: bold;
            color: #495057;
            margin-bottom: 10px;
        }
        
        .login-subtitle {
            color: #6c757d;
            margin-bottom: 30px;
            font-size: 1.1em;
        }
        
        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s;
            background: #f8f9fa;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #495057;
            box-shadow: 0 0 0 3px rgba(73, 80, 87, 0.1);
            background: white;
        }
        
        .btn-login {
            background: linear-gradient(135deg, #495057 0%, #6c757d 100%);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s;
            width: 100%;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
        }
        
        .login-info {
            margin-top: 20px;
            color: #6c757d;
            font-size: 14px;
            line-height: 1.6;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e9ecef;
        }
        
        .message {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-logo">招生平台</div>
        <div class="login-subtitle">後台管理系統</div>
        
        <?php if (isset($error_message)): ?>
            <div class="message error"><?php echo $error_message; ?></div>
        <?php endif; ?>
        
        <form method="POST" autocomplete="on">
            <input type="hidden" name="action" value="login">
            
            <div class="form-group">
                <label for="username">帳號</label>
                <input type="text" id="username" name="username" autocomplete="username" required>
            </div>
            
            <div class="form-group">
                <label for="password">密碼</label>
                <input type="password" id="password" name="password" autocomplete="current-password" required>
            </div>
            
            <button type="submit" class="btn-login">登入系統</button>
        </form>
        
        <div style="margin-top: 15px; text-align: right;">
    <a href="forgot_password.php" style="color: #6c757d; text-decoration: none; font-size: 14px; transition: color 0.3s;" onmouseover="this.style.color='#495057'" onmouseout="this.style.color='#6c757d'">
        忘記密碼？
    </a>
</div>
    </div>
</body>
</html> 