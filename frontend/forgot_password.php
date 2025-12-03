<?php
session_start();
require_once '../../Topics-frontend/frontend/config.php'; // 引用原本的設定檔

$message = '';
$message_type = ''; // success 或 error

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email)) {
        $message = "請輸入 Email 地址。";
        $message_type = "error";
    } else {
        try {
            // 使用 MySQLi 連接（與其他文件保持一致）
            $conn = getDatabaseConnection();

            // 檢查 Email 是否存在於資料庫
            $stmt = $conn->prepare("SELECT id, username, email FROM user WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();

            if ($user) {
                // 將密碼重設為 123456
                $new_password = '123456';
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                
                // 更新密碼
                $update_stmt = $conn->prepare("UPDATE user SET password = ? WHERE email = ?");
                $update_stmt->bind_param("ss", $hashed_password, $email);
                
                if ($update_stmt->execute()) {
                    $message = "密碼已重設為 123456，請使用此密碼登入，登入後請盡快修改密碼。";
                    $message_type = "success";
                } else {
                    $message = "密碼重設失敗，請稍後再試。";
                    $message_type = "error";
                }
                
                $update_stmt->close();
            } else {
                // 為了安全，通常即使 Email 錯誤也不要明講「查無此人」，但內部測試可以先顯示錯誤
                $message = "找不到此 Email 對應的帳號。";
                $message_type = "error";
            }

            $stmt->close();
            $conn->close();

        } catch (Exception $e) {
            $message = "系統錯誤: " . $e->getMessage();
            $message_type = "error";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>招生平台 - 忘記密碼</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* 繼承您原本 login.php 的 CSS */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Microsoft JhengHei', sans-serif;
            background: linear-gradient(135deg, #495057 0%, #6c757d 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
            padding: 40px;
            width: 100%;
            max-width: 400px;
            text-align: center;
        }
        .title { font-size: 1.8em; font-weight: bold; color: #495057; margin-bottom: 10px; }
        .subtitle { color: #6c757d; margin-bottom: 30px; font-size: 1em; }
        .form-group { margin-bottom: 20px; text-align: left; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #333; }
        .form-group input {
            width: 100%; padding: 12px 15px; border: 2px solid #e9ecef;
            border-radius: 8px; font-size: 16px; background: #f8f9fa;
        }
        .btn-submit {
            background: linear-gradient(135deg, #495057 0%, #6c757d 100%);
            color: white; padding: 12px 30px; border: none; border-radius: 8px;
            font-size: 16px; font-weight: 600; cursor: pointer; width: 100%;
        }
        .btn-submit:hover { transform: translateY(-2px); }
        .back-link {
            display: block; margin-top: 20px; color: #6c757d; text-decoration: none; font-size: 14px;
        }
        .back-link:hover { color: #495057; }
        
        /* 訊息提示樣式 */
        .message { padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: left; font-size: 14px; }
        .message.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .message.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
    </style>
</head>
<body>
    <div class="container">
        <div class="title">重設密碼</div>
        <div class="subtitle">請輸入您註冊時使用的 Email</div>
        
        <?php if (!empty($message)): ?>
            <div class="message <?php echo $message_type; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label for="email">電子信箱 (Email)</label>
                <input type="email" id="email" name="email" required placeholder="name@example.com">
            </div>
            
            <button type="submit" class="btn-submit">重設密碼</button>
        </form>
        
        <a href="login.php" class="back-link">
            <i class="fas fa-arrow-left"></i> 返回登入頁面
        </a>
    </div>
</body>
</html>