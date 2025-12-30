<?php
require_once __DIR__ . '/session_config.php';

// 檢查登入狀態和角色權限
checkBackendLogin();

// 處理登出
if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_destroy();
    header("Location: login.php");
    exit;
}

// 設置頁面標題
$page_title = '編輯使用者';

// 引入資料庫設定
require_once '../../Topics-frontend/frontend/config.php';

// 獲取用戶ID
$userId = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$userId) {
    header("Location: index.php");
    exit;
}

// 處理表單提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update') {
        // 更新用戶資料
        $username = $_POST['username'] ?? '';
        $name = $_POST['name'] ?? '';
        $email = $_POST['email'] ?? '';
        $role = $_POST['role'] ?? '';
        $status = $_POST['status'] ?? '1';
        
        // 角色代碼映射：將舊代碼轉換為新代碼
        $roleMap = [
            'student' => 'STU',
            'teacher' => 'TEA',
            'admin' => 'ADM',
            'staff' => 'STA',
            'director' => 'DI',
            // 如果已經是正確代碼，保持不變
            'STU' => 'STU',
            'TEA' => 'TEA',
            'ADM' => 'ADM',
            'STA' => 'STA',
            'DI' => 'DI'
        ];
        $roleCode = $roleMap[$role] ?? $role;
        
        try {
            $conn = getDatabaseConnection();
            
            // 驗證角色代碼是否存在於 role_types 表
            $checkRole = $conn->prepare("SELECT code FROM role_types WHERE code = ?");
            $checkRole->bind_param("s", $roleCode);
            $checkRole->execute();
            $roleResult = $checkRole->get_result();
            
            if ($roleResult->num_rows === 0) {
                throw new Exception("無效的角色代碼：{$roleCode}。請選擇有效的角色。");
            }
            
            // 更新用戶資料
            $stmt = $conn->prepare("UPDATE user SET name = ?, email = ?, role = ?, status = ? WHERE id = ?");
            $stmt->bind_param("sssii", $name, $email, $roleCode, $status, $userId);
            $stmt->execute();
            
            $success_message = "用戶資料更新成功！";
            
            // 重新獲取更新後的用戶資料
            $stmt = $conn->prepare("SELECT id, username, name, email, role, status FROM user WHERE id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            
        } catch(Exception $e) {
            $error_message = "資料庫更新失敗：" . $e->getMessage();
        } catch(mysqli_sql_exception $e) {
            $error_message = "資料庫更新失敗：" . $e->getMessage();
        }
        
    } elseif ($action === 'reset_password') {
        // 重置密碼
        try {
            $conn = getDatabaseConnection();
            
            // 重置密碼為 123456
            $new_password = '123456';
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT); // 密碼雜湊
            $stmt = $conn->prepare("UPDATE user SET password = ? WHERE id = ?");
            $stmt->bind_param("si", $hashed_password, $userId);
            $stmt->execute();
            
            $success_message = "密碼已重置為 '123456'！";
            
} catch(Exception $e) {
    $error_message = "密碼重置失敗：" . $e->getMessage();
} catch(mysqli_sql_exception $e) {
    $error_message = "密碼重置失敗：" . $e->getMessage();
}
    }
}

// 從資料庫獲取用戶資料
try {
    $conn = getDatabaseConnection();
    $stmt = $conn->prepare("SELECT id, username, name, email, role, status FROM user WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    
    if (!$user) {
        header("Location: index.php");
        exit;
    }
    
    // 獲取所有可用的角色列表
    $roles_stmt = $conn->query("SELECT code, name FROM role_types ORDER BY code");
    $available_roles = [];
    if ($roles_stmt) {
        $available_roles = $roles_stmt->fetch_all(MYSQLI_ASSOC);
    }
} catch(Exception $e) {
    $error_message = "讀取使用者資料失敗：" . $e->getMessage();
    // 發生錯誤時，顯示錯誤訊息，而不是模擬資料
    $user = [];
    $available_roles = [];
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>編輯用戶 - Topics 後台管理系統</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f0f2f5;
            color: #262626;
            overflow-x: hidden;
        }
        
        /* 主介面樣式 */
        .dashboard {
            display: flex;
            min-height: 100vh;
        }
        

        
        /* 主要內容 */
        .content {
            padding: 24px;
        }
        
        /* 麵包屑 */
        .breadcrumb {
    margin-bottom: 16px;
    font-size: 16px;
    color: #8c8c8c;
}
        
        .breadcrumb a {
            color: #1890ff;
            text-decoration: none;
        }
        
        .breadcrumb a:hover {
            text-decoration: underline;
        }
        
        .form-container {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            overflow: hidden;
            border: 1px solid #f0f0f0;
        }
        
        .form-section {
            padding: 24px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .form-section:last-child {
            border-bottom: none;
        }
        
        .section-title {
    font-size: 18px;
    font-weight: 600;
    color: #262626;
    margin-bottom: 20px;
    padding-bottom: 8px;
    border-bottom: 1px solid #f0f0f0;
}
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 16px;
        }
        
        .form-group {
            margin-bottom: 16px;
        }
        
        .form-group.full-width {
            grid-column: 1 / -1;
        }
        
        .form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 500;
    color: #262626;
    font-size: 16px;
}
        
        .required::after {
            content: " *";
            color: #ff4d4f;
        }
        
        .form-control {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #d9d9d9;
    border-radius: 6px;
    font-size: 16px;
    transition: all 0.3s;
}
        
        .form-control:focus {
            outline: none;
            border-color: #1890ff;
            box-shadow: 0 0 0 2px rgba(24,144,255,0.2);
        }
        
        .form-control[readonly] {
            background: #f5f5f5;
            color: #8c8c8c;
        }
        
        .password-link {
    color: #1890ff;
    text-decoration: none;
    font-size: 16px;
    margin-left: 8px;
    transition: color 0.3s;
}
        
        .password-link:hover {
            color: #40a9ff;
            text-decoration: underline;
        }
        
        .message {
    padding: 12px 16px;
    border-radius: 6px;
    margin-bottom: 16px;
    font-weight: 500;
    font-size: 16px;
}
        
        .message.success {
            background: #f6ffed;
            color: #52c41a;
            border: 1px solid #b7eb8f;
        }
        
        .message.error {
            background: #fff2f0;
            color: #ff4d4f;
            border: 1px solid #ffccc7;
        }
        
        .btn {
    padding: 8px 16px;
    border: 1px solid #d9d9d9;
    border-radius: 6px;
    cursor: pointer;
    font-size: 16px;
    transition: all 0.3s;
    background: #fff;
}
        
        .btn-primary {
            background: #1890ff;
            color: white;
            border-color: #1890ff;
        }
        
        .btn-primary:hover {
            background: #40a9ff;
            border-color: #40a9ff;
        }
        
        .btn-secondary {
            background: #fff;
            color: #595959;
            border-color: #d9d9d9;
        }
        
        .btn-secondary:hover {
            background: #f5f5f5;
            border-color: #40a9ff;
            color: #40a9ff;
        }
        
        /* 響應式設計 */
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- 主介面 -->
    <div class="dashboard">
        <!-- 引入側邊欄 -->
        <?php include 'sidebar.php'; ?>
        
        <!-- 主內容區 -->
        <div class="main-content" id="mainContent">
            <!-- 引入標題欄 -->
            <?php include 'header.php'; ?>
            
            <!-- 內容區域 -->
            <div class="content">
                <!-- 麵包屑 -->
                <div class="breadcrumb">
                    <a href="index.php">首頁</a> / <a href="users.php">使用者管理</a> / 編輯用戶
                </div>
                
                <?php if (isset($success_message)): ?>
                    <div class="message success"><?php echo htmlspecialchars($success_message); ?></div>
                <?php endif; ?>
                
                <?php if (isset($error_message)): ?>
                    <div class="message error"><?php echo htmlspecialchars($error_message); ?></div>
                <?php endif; ?>
                
                <form method="POST" class="form-container">
                    <input type="hidden" name="action" id="formAction" value="update">
                    
                    <!-- 基本信息 -->
                    <div class="form-section">
                        <div class="section-title">基本信息</div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="required">用戶編號</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['id']); ?>" readonly>
                            </div>
                            
                            <div class="form-group">
                                <label class="required">帳號</label>
                                <input type="text" class="form-control"  value="<?php echo htmlspecialchars($user['username']); ?>" readonly>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="required">姓名</label>
                                <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($user['name']); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label class="required">電子郵件</label>
                                <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="required">角色</label>
                                <select name="role" class="form-control" required>
                                    <?php if (!empty($available_roles)): ?>
                                        <?php foreach ($available_roles as $role_type): ?>
                                            <option value="<?php echo htmlspecialchars($role_type['code']); ?>" <?php echo $user['role'] === $role_type['code'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($role_type['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <!-- 如果無法從資料庫讀取，使用預設選項 -->
                                        <option value="STU" <?php echo ($user['role'] === 'STU' || $user['role'] === 'student') ? 'selected' : ''; ?>>學生</option>
                                        <option value="TEA" <?php echo ($user['role'] === 'TEA' || $user['role'] === 'teacher') ? 'selected' : ''; ?>>老師</option>
                                        <option value="ADM" <?php echo ($user['role'] === 'ADM' || $user['role'] === 'admin') ? 'selected' : ''; ?>>管理員</option>
                                        <option value="STA" <?php echo ($user['role'] === 'STA' || $user['role'] === 'staff') ? 'selected' : ''; ?>>行政人員</option>
                                        <option value="DI" <?php echo ($user['role'] === 'DI' || $user['role'] === 'director') ? 'selected' : ''; ?>>主任</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="required">狀態</label>
                                <select name="status" class="form-control" required>
                                    <option value="0" <?php echo $user['status'] == 0 ? 'selected' : ''; ?>>停用</option>
                                    <option value="1" <?php echo $user['status'] == 1 ? 'selected' : ''; ?>>啟用</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label class="required">密碼</label>
                                <div style="display: flex; align-items: center;">
                                    <input type="password" class="form-control" value="••••••••" readonly style="flex: 1;">
                                    <a href="#" class="password-link" onclick="resetPassword()">修改密碼</a>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 操作按鈕 -->
                    <div class="form-section">
                        <div style="display: flex; justify-content: flex-end; gap: 8px;">
                            <button type="button" class="btn btn-secondary" onclick="history.back()">取消</button>
                            <button type="button" class="btn btn-primary" onclick="submitForm('update')">儲存</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        function submitForm(action) {
            document.getElementById('formAction').value = action;
            document.querySelector('form').submit();
        }
        
        function resetPassword() {
            if (confirm('確定要將此用戶的密碼重置為 "123456" 嗎？')) {
                submitForm('reset_password');
            }
        }
    </script>
</body>
</html>
