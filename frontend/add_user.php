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

// 處理表單提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role = $_POST['role'] ?? '';
    $status = $_POST['status'] ?? '1';

    // 簡單的後端驗證
    if (empty($role)) {
        $error_message = "請選擇要建立的帳號角色。";
    } else {
        try {
            $conn = getDatabaseConnection();
            $username = '';
            $password = bin2hex(random_bytes(4)); // 產生一個8個字元的隨機密碼

            // 根據角色決定帳號前綴
            $prefix = '';
            if ($role === '老師') {
                $prefix = 'teacher_';
            } elseif ($role === '學校行政人員') {
                $prefix = 'staff_';
            } elseif ($role === 'admin') {
                $prefix = 'admin_';
            } else {
                $prefix = 'user_';
            }

            // 產生一個唯一的帳號
            do {
                $username = $prefix . rand(1000, 9999);
                $stmt = $conn->prepare("SELECT id FROM user WHERE username = ?");
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $result = $stmt->get_result();
            } while ($result->num_rows > 0);

            // 密碼雜湊
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            // 插入新用戶資料，姓名和Email留空
            $stmt = $conn->prepare("INSERT INTO user (username, password, role, status, name, email) VALUES (?, ?, ?, ?, '', '')");
            $stmt->bind_param("sssi", $username, $hashed_password, $role, $status);
            
            if ($stmt->execute()) {
                $success_message = "帳號建立成功！";
                $generated_username = $username;
                $generated_password = $password;
            } else {
                $error_message = "建立使用者失敗：" . $stmt->error;
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
                            <label for="role">角色 <span class="required">*</span></label>
                            <select id="role" name="role" class="form-control" required>
                                <option value="">請選擇角色</option>
                                <option value="老師">老師</option>
                                <option value="學校行政人員">學校行政人員</option>
                                <option value="admin">管理員</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="status">狀態 <span class="required">*</span></label>
                            <select id="status" name="status" class="form-control" required>
                                <option value="1" selected>啟用</option>
                                <option value="0">停用</option>
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