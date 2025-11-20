<?php
session_start();

// 檢查是否為管理員，如果不是則跳轉
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in'] || !in_array($_SESSION['role'], ['admin', '管理員'])) {
    header("Location: index.php");
    exit;
}

// 設置頁面標題
$page_title = '新增使用者';

// 引入資料庫設定
require_once '../../Topics-frontend/frontend/config.php';
require_once '../../Topics-frontend/frontend/includes/email_functions.php';

function sendNewAccountEmail($email, $username, $password) {
    $subject = "招生系統帳號建立通知";
    $body = '
        <p>您好：</p>
        <p>已為您建立招生系統帳號，請使用以下資訊登入：</p>
        <ul>
            <li><strong>帳號：</strong> ' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '</li>
            <li><strong>初始密碼：</strong> ' . htmlspecialchars($password, ENT_QUOTES, 'UTF-8') . '</li>
        </ul>
        <p>首次登入後請立即前往「個人資料」頁面設定姓名與 Email，並修改密碼以確保帳號安全。</p>
        <p>謝謝！</p>
    ';
    $altBody = "您好：\n\n已為您建立招生系統帳號。\n帳號：{$username}\n初始密碼：{$password}\n\n請登入後盡快更新個人資料與密碼。\n";
    return sendEmail($email, $subject, $body, $altBody);
}

$role = $_POST['role'] ?? '';
$status = $_POST['status'] ?? '1';
$email = trim($_POST['email'] ?? '');

// 處理表單提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 簡單的後端驗證
    if (empty($role)) {
        $error_message = "請選擇要建立的帳號角色。";
    } elseif (empty($email)) {
        $error_message = "請輸入使用者的 Email（建議使用 Gmail 以確保可收到通知）。";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Email 格式不正確，請重新確認。";
    } else {
        try {
            $conn = getDatabaseConnection();
            $username = '';
            $password = bin2hex(random_bytes(4)); // 產生一個8個字元的隨機密碼

            // 根據角色決定帳號前綴
            $prefix = '';
            if ($role === '學校行政人員') {
                $prefix = 'staff_';
            } elseif ($role === 'admin') {
                $prefix = 'admin_';
            } else {
                $prefix = 'user_';
            }

            // 確認 Email 是否已存在
            $email_check_stmt = $conn->prepare("SELECT id FROM user WHERE email = ? LIMIT 1");
            $email_check_stmt->bind_param("s", $email);
            $email_check_stmt->execute();
            $email_result = $email_check_stmt->get_result();
            $email_exists = $email_result->num_rows > 0;
            $email_check_stmt->close();

            if ($email_exists) {
                $error_message = "此 Email 已被使用，請改用其他 Email。";
            } else {
                // 產生一個唯一的帳號
                do {
                    $username = $prefix . rand(1000, 9999);
                    $stmt = $conn->prepare("SELECT id FROM user WHERE username = ?");
                    $stmt->bind_param("s", $username);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $is_username_taken = $result->num_rows > 0;
                    $stmt->close();
                } while ($is_username_taken);

                // 密碼雜湊
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                // 插入新用戶資料，姓名留空、Email 為使用者輸入
                $stmt = $conn->prepare("INSERT INTO user (username, password, role, status, name, email) VALUES (?, ?, ?, ?, '', ?)");
                $status_value = (int)$status;
                $stmt->bind_param("sssis", $username, $hashed_password, $role, $status_value, $email);
                
                if ($stmt->execute()) {
                    $success_message = "帳號建立成功！";
                    $generated_username = $username;
                    $generated_password = $password;
                    $generated_email = $email;
    
                    $email_sent = sendNewAccountEmail($email, $username, $password);
                } else {
                    $error_message = "建立使用者失敗：" . $stmt->error;
                }
                $stmt->close();
            }
            $conn->close();
        } catch (Exception $e) {
            $error_message = "資料庫操作失敗：" . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Topics 後台管理系統</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1890ff; --text-color: #262626; --text-secondary-color: #8c8c8c;
            --border-color: #f0f0f0; --background-color: #f0f2f5; --card-background-color: #fff;
            --success-color: #52c41a; --danger-color: #f5222d;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background: var(--background-color); color: var(--text-color); overflow-x: hidden; }
        .dashboard { display: flex; min-height: 100vh; }
        .content { padding: 24px; width: 100%; }
        .breadcrumb { margin-bottom: 16px; font-size: 16px; color: var(--text-secondary-color); }
        .breadcrumb a { color: var(--primary-color); text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }
        
        .form-container { background: var(--card-background-color); border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.03); border: 1px solid var(--border-color); }
        .form-header { padding: 16px 24px; border-bottom: 1px solid var(--border-color); background: #fafafa; }
        .form-header h3 { font-size: 18px; font-weight: 600; margin: 0; }
        .form-body { padding: 24px; }
        
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; font-size: 14px; }
        .form-group label .required { color: var(--danger-color); }
        .form-control {
            width: 100%; padding: 8px 12px; border: 1px solid #d9d9d9; border-radius: 6px; font-size: 14px;
            transition: all 0.3s;
        }
        .form-control:focus { outline: none; border-color: var(--primary-color); box-shadow: 0 0 0 2px rgba(24,144,255,0.2); }
        
        .form-actions { display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px; }
        .btn {
            padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 14px;
            transition: all 0.3s; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-primary { background: var(--primary-color); color: white; border: 1px solid var(--primary-color); }
        .btn-primary:hover { background: #40a9ff; border-color: #40a9ff; }
        .btn-secondary { background: #fff; color: #595959; border: 1px solid #d9d9d9; }
        .btn-secondary:hover { background: #f5f5f5; border-color: #40a9ff; color: #40a9ff; }

        .message { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; font-weight: 500; }
        .message.success { background: #f6ffed; color: var(--success-color); border: 1px solid #b7eb8f; }
        .message.error { background: #fff2f0; color: var(--danger-color); border: 1px solid #ffccc7; }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include 'sidebar.php'; ?>
        <div class="main-content" id="mainContent">
            <?php include 'header.php'; ?>
            <div class="content">
                <div class="breadcrumb">
                    <a href="index.php">首頁</a> / <a href="users.php">使用者管理</a> / <?php echo $page_title; ?>
                </div>

                <?php if (isset($success_message)): ?>
                    <div class="message success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?></div>
                    <div class="message" style="background: #e6f7ff; border: 1px solid #91d5ff; color: #1890ff;">
                        <p style="margin-bottom: 8px;">請將以下資訊提供給使用者：</p>
                        <div style="font-size: 16px;">
                            <p><strong>帳號：</strong> <code style="background: #d9edff; padding: 2px 6px; border-radius: 4px;"><?php echo htmlspecialchars($generated_username); ?></code></p>
                            <p style="margin-top: 4px;"><strong>密碼：</strong> <code style="background: #d9edff; padding: 2px 6px; border-radius: 4px;"><?php echo htmlspecialchars($generated_password); ?></code></p>
                        </div>
                        <p style="margin-top: 12px; font-size: 12px; color: #8c8c8c;">
                            使用者首次登入後，應引導其至個人資料頁面填寫姓名與Email。
                        </p>
                    </div>
                    <?php if (isset($email_sent) && isset($generated_email)): ?>
                        <div class="message <?php echo $email_sent ? 'success' : 'error'; ?>">
                            <?php if ($email_sent): ?>
                                <i class="fas fa-envelope-circle-check"></i> 已將預設帳密寄送至 <?php echo htmlspecialchars($generated_email, ENT_QUOTES, 'UTF-8'); ?>，請提醒使用者登入後盡速修改。
                            <?php else: ?>
                                <i class="fas fa-envelope-open-text"></i> 帳號已建立，但郵件寄送失敗，請自行轉告使用者帳密。
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php if (isset($error_message)): ?>
                    <div class="message error"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error_message); ?></div>
                <?php endif; ?>

                <div class="form-container">
                    <div class="form-header">
                        <h3>建立新使用者</h3>
                    </div>
                    <form method="POST" class="form-body">
                        <div class="form-group">
                            <label for="email">Email <span class="required">*</span></label>
                            <input type="email" id="email" name="email" class="form-control" placeholder="example@gmail.com" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>" required>
                            <small style="display:block;margin-top:6px;color:#8c8c8c;">系統將寄送預設帳密至此信箱，建議填寫 Gmail 以確保可收到通知。</small>
                        </div>

                        <div class="form-group">
                            <label for="role">角色 <span class="required">*</span></label>
                            <select id="role" name="role" class="form-control" required>
                                <option value="">請選擇角色</option>
                                <option value="學校行政人員" <?php echo $role === '學校行政人員' ? 'selected' : ''; ?>>學校行政人員</option>
                                <option value="admin" <?php echo $role === 'admin' ? 'selected' : ''; ?>>管理員</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="status">狀態 <span class="required">*</span></label>
                            <select id="status" name="status" class="form-control" required>
                                <option value="1" <?php echo $status === '1' ? 'selected' : ''; ?>>啟用</option>
                                <option value="0" <?php echo $status === '0' ? 'selected' : ''; ?>>停用</option>
                            </select>
                        </div>

                        <div class="form-actions">
                            <a href="users.php" class="btn btn-secondary">取消</a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-plus"></i> 建立使用者
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</body>
</html>