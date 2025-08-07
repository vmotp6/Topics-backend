<?php
session_start();

// 如果已經登入，直接跳轉到管理介面
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in']) {
    header("Location: index.php");
    exit;
}

// 處理登入
if (isset($_POST['action']) && $_POST['action'] == 'login') {
    $admin_username = $_POST['username'];
    $admin_password = $_POST['password'];
    
    // 呼叫Python API進行登入驗證
    $api_url = 'http://localhost:5001/admin/login';
    $post_data = http_build_query([
        'username' => $admin_username,
        'password' => $admin_password
    ]);
    
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => $post_data
        ]
    ]);
    
    $response = file_get_contents($api_url, false, $context);
    $result = json_decode($response, true);
    
    if ($result && isset($result['message']) && $result['message'] === '登入成功') {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $admin_username;
        header("Location: index.php");
        exit;
    } else {
        $error_message = $result['message'] ?? "登入失敗，請稍後再試";
    }
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Topics 後台管理系統 - 登入</title>
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
        <div class="login-logo">Topics</div>
        <div class="login-subtitle">後台管理系統</div>
        
        <?php if (isset($error_message)): ?>
            <div class="message error"><?php echo $error_message; ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="hidden" name="action" value="login">
            
            <div class="form-group">
                <label for="username">管理員帳號</label>
                <input type="text" id="username" name="username" required>
            </div>
            
            <div class="form-group">
                <label for="password">管理員密碼</label>
                <input type="password" id="password" name="password" required>
            </div>
            
            <button type="submit" class="btn-login">登入系統</button>
        </form>
        
        <div class="login-info">
            預設管理員帳號：admin<br>
            預設管理員密碼：admin123
        </div>
    </div>
</body>
</html> 