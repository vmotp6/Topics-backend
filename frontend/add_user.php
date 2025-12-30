<?php
require_once __DIR__ . '/session_config.php';

// 檢查登入狀態和角色權限
checkBackendLogin();

// 檢查是否為管理員
if (!isAdmin()) {
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
$status = 1; // 預設為啟用
$emails_input = trim($_POST['email'] ?? '');

// 處理表單提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 簡單的後端驗證
    if (empty($role)) {
        $error_message = "請選擇要建立的帳號角色。";
    } elseif (empty($emails_input)) {
        $error_message = "請輸入使用者的 Email（建議使用 Gmail 以確保可收到通知）。";
    } else {
        // 解析多個 Email（支援逗號和換行分隔）
        $emails_raw = preg_split('/[,\n\r]+/', $emails_input);
        $emails = [];
        $email_validation_error = false;
        
        foreach ($emails_raw as $email_raw) {
            $email = trim($email_raw);
            if (!empty($email)) {
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $emails[] = $email;
                } else {
                    $error_message = "Email 格式不正確：{$email}。請重新確認。";
                    $email_validation_error = true;
                    break;
                }
            }
        }
        
        if ($email_validation_error) {
            // 如果有 Email 格式錯誤，不繼續處理
            // 不處理，直接跳過
        } elseif (empty($emails)) {
            $error_message = "請輸入至少一個有效的 Email。";
        } else {
            try {
                $conn = getDatabaseConnection();
                
                // 角色代碼映射：將中文名稱或舊代碼轉換為新代碼
                $roleMap = [
                '老師' => 'TEA',
                '學校行政人員' => 'STA',
                'admin' => 'ADM',
                '學生' => 'STU',
                '管理員' => 'ADM',
                '行政人員' => 'STA',
                '主任' => 'DI',
                // 如果已經是正確代碼，保持不變
                'STU' => 'STU',
                'TEA' => 'TEA',
                'ADM' => 'ADM',
                'STA' => 'STA',
                    'DI' => 'DI'
                ];
                $roleCode = $roleMap[$role] ?? $role;
                
                // 驗證角色代碼是否存在於 role_types 表
                $checkRole = $conn->prepare("SELECT code FROM role_types WHERE code = ?");
                $checkRole->bind_param("s", $roleCode);
                $checkRole->execute();
                $roleResult = $checkRole->get_result();
                
                if ($roleResult->num_rows === 0) {
                    throw new Exception("無效的角色代碼：{$roleCode}。請選擇有效的角色。");
                }
                $checkRole->close();
                
                // 根據角色決定帳號前綴
                $prefix = '';
                if ($roleCode === 'STA') {
                    $prefix = 'staff_';
                } elseif ($roleCode === 'ADM') {
                    $prefix = 'admin_';
                } else {
                    $prefix = 'user_';
                }

                // 處理多個 Email
                $created_accounts = [];
                $failed_accounts = [];
                $skipped_accounts = [];
                
                foreach ($emails as $email) {
                    // 確認 Email 是否已存在
                    $email_check_stmt = $conn->prepare("SELECT id FROM user WHERE email = ? LIMIT 1");
                    $email_check_stmt->bind_param("s", $email);
                    $email_check_stmt->execute();
                    $email_result = $email_check_stmt->get_result();
                    $email_exists = $email_result->num_rows > 0;
                    $email_check_stmt->close();

                    if ($email_exists) {
                        $skipped_accounts[] = $email;
                        continue;
                    }

                    // 產生一個唯一的帳號
                    $username = '';
                    do {
                        $username = $prefix . rand(1000, 9999);
                        $stmt = $conn->prepare("SELECT id FROM user WHERE username = ?");
                        $stmt->bind_param("s", $username);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $is_username_taken = $result->num_rows > 0;
                        $stmt->close();
                    } while ($is_username_taken);

                    // 產生隨機密碼
                    $password = bin2hex(random_bytes(4)); // 產生一個8個字元的隨機密碼
                    // 密碼雜湊
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                    // 插入新用戶資料，姓名留空、Email 為使用者輸入
                    // username_changed 設為 0，表示這是系統生成的帳號，尚未修改過
                    // email_verified 設為 1，後台建立的帳號自動驗證
                    $stmt = $conn->prepare("INSERT INTO user (username, password, role, status, name, email, username_changed, email_verified) VALUES (?, ?, ?, ?, '', ?, 0, 1)");
                    $status_value = (int)$status;
                    $stmt->bind_param("sssis", $username, $hashed_password, $roleCode, $status_value, $email);
                    
                    if ($stmt->execute()) {
                        $created_accounts[] = [
                            'email' => $email,
                            'username' => $username,
                            'password' => $password
                        ];
                        
                        // 發送郵件
                        sendNewAccountEmail($email, $username, $password);
                    } else {
                        $failed_accounts[] = [
                            'email' => $email,
                            'error' => $stmt->error
                        ];
                    }
                    $stmt->close();
                }
                
                // 生成結果訊息
                if (!empty($created_accounts)) {
                    $success_message = "成功建立 " . count($created_accounts) . " 個帳號！";
                }
                if (!empty($failed_accounts)) {
                    $error_message = "部分帳號建立失敗：" . implode(', ', array_column($failed_accounts, 'email'));
                }
                if (!empty($skipped_accounts)) {
                    $warning_message = "以下 Email 已存在，已跳過：" . implode(', ', $skipped_accounts);
                }
                $conn->close();
        } catch (Exception $e) {
            $error_message = "資料庫操作失敗：" . $e->getMessage();
        }
        }
    }
}

// 從資料庫獲取所有可用的角色列表
try {
    $conn = getDatabaseConnection();
    $roles_stmt = $conn->query("SELECT code, name FROM role_types ORDER BY code");
    $available_roles = [];
    if ($roles_stmt) {
        $available_roles = $roles_stmt->fetch_all(MYSQLI_ASSOC);
    }
    if (isset($conn) && !isset($conn->close_called)) {
        $conn->close();
    }
} catch (Exception $e) {
    $available_roles = [];
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
        .message.warning { background: #fff7e6; color: #fa8c16; border: 1px solid #ffd591; }
        .accounts-list { margin-top: 12px; }
        .account-item { padding: 8px; margin: 4px 0; background: #f0f2f5; border-radius: 4px; font-family: monospace; }
        .account-item strong { color: var(--primary-color); }
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
                    <?php if (isset($created_accounts) && !empty($created_accounts)): ?>
                        <div class="message" style="background: #e6f7ff; border: 1px solid #91d5ff; color: #1890ff;">
                            <p style="margin-bottom: 8px;"><strong>已建立的帳號 Email：</strong></p>
                            <div class="accounts-list">
                                <?php foreach ($created_accounts as $account): ?>
                                    <div class="account-item">
                                        <div><strong>Email:</strong> <?php echo htmlspecialchars($account['email']); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <p style="margin-top: 12px; font-size: 12px; color: #8c8c8c;">
                                <i class="fas fa-envelope-circle-check"></i> 已將預設帳密寄送至各使用者信箱，請提醒使用者登入後盡速修改。
                            </p>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php if (isset($warning_message)): ?>
                    <div class="message warning"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($warning_message); ?></div>
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
                            <label for="email"><span class="required">*</span> Email</label>
                            <textarea id="email" name="email" class="form-control" rows="5" placeholder="example1@gmail.com&#10;example2@gmail.com&#10;或使用逗號分隔：example1@gmail.com, example2@gmail.com" required><?php echo htmlspecialchars($emails_input, ENT_QUOTES, 'UTF-8'); ?></textarea>
                            <small style="display:block;margin-top:6px;color:#8c8c8c;">可一次輸入多個 Email，以換行或逗號分隔。系統將為每個 Email 建立一個帳號並寄送預設帳密，建議填寫 Gmail 以確保可收到通知。</small>
                        </div>

                        <div class="form-group">
                            <label for="role"><span class="required">*</span> 角色</label>
                            <select id="role" name="role" class="form-control" required>
                                <option value="">請選擇角色</option>
                                <?php if (!empty($available_roles)): ?>
                                    <?php foreach ($available_roles as $role_type): ?>
                                        <option value="<?php echo htmlspecialchars($role_type['code']); ?>" <?php echo $role === $role_type['code'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($role_type['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <!-- 如果無法從資料庫讀取，使用預設選項 -->
                                    <option value="STU" <?php echo ($role === 'STU' || $role === '學生') ? 'selected' : ''; ?>>學生</option>
                                    <option value="TEA" <?php echo ($role === 'TEA' || $role === '老師') ? 'selected' : ''; ?>>老師</option>
                                    <option value="ADM" <?php echo ($role === 'ADM' || $role === 'admin' || $role === '管理員') ? 'selected' : ''; ?>>管理員</option>
                                    <option value="STA" <?php echo ($role === 'STA' || $role === '學校行政人員' || $role === '行政人員') ? 'selected' : ''; ?>>行政人員</option>
                                    <option value="DI" <?php echo ($role === 'DI' || $role === '主任') ? 'selected' : ''; ?>>主任</option>
                                <?php endif; ?>
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