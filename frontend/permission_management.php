<?php
session_start();

// 檢查是否已登入
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: login.php");
    exit;
}

// 檢查角色權限 - 僅允許管理員和學校行政訪問
$user_role = $_SESSION['role'] ?? '';
$allowed_roles = ['ADM', 'STA', '管理員', '行政人員'];

if (!in_array($user_role, $allowed_roles)) {
    header("Location: index.php");
    exit;
}

// 引入資料庫設定
require_once '../../Topics-frontend/frontend/config.php';

// 設置頁面標題
$page_title = '權限管理';

// 建立資料庫連接
$conn = getDatabaseConnection();

// 確保STAM角色存在於role_types表中
$check_stam_role = $conn->prepare("SELECT code FROM role_types WHERE code = 'STAM'");
$check_stam_role->execute();
$stam_role_result = $check_stam_role->get_result();
if ($stam_role_result->num_rows === 0) {
    // 檢查表是否有description欄位
    $desc_check = $conn->query("SHOW COLUMNS FROM role_types LIKE 'description'");
    if ($desc_check && $desc_check->num_rows > 0) {
        $insert_stam_role = $conn->prepare("INSERT INTO role_types (code, name, description) VALUES ('STAM', '招生中心組員', '招生中心組員，可被分配特定權限')");
    } else {
        $insert_stam_role = $conn->prepare("INSERT INTO role_types (code, name) VALUES ('STAM', '招生中心組員')");
    }
    $insert_stam_role->execute();
    $insert_stam_role->close();
}
$check_stam_role->close();

// 創建權限表（如果不存在）
$create_permission_table_sql = "
CREATE TABLE IF NOT EXISTS staff_member_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL COMMENT '用戶ID（role=STAM的用戶）',
    permission_code VARCHAR(50) NOT NULL COMMENT '權限代碼',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_permission (user_id, permission_code),
    INDEX idx_user_id (user_id),
    INDEX idx_permission_code (permission_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='招生中心組員權限表';
";

// 嘗試創建表，如果外鍵約束失敗則不添加外鍵
$conn->query($create_permission_table_sql);

// 檢查表是否存在，如果不存在則嘗試不帶外鍵的版本
$check_table = $conn->query("SHOW TABLES LIKE 'staff_member_permissions'");
if ($check_table->num_rows == 0) {
    // 如果表不存在，嘗試創建不帶外鍵的版本
    $create_permission_table_sql_no_fk = " 
    CREATE TABLE IF NOT EXISTS staff_member_permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL COMMENT '用戶ID（role=STAM的用戶）',
        permission_code VARCHAR(50) NOT NULL COMMENT '權限代碼',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_user_permission (user_id, permission_code),
        INDEX idx_user_id (user_id),
        INDEX idx_permission_code (permission_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='招生中心組員權限表';
    ";
    $conn->query($create_permission_table_sql_no_fk);
}

// 定義可分配的權限
$available_permissions = [
    'enrollment_list' => '查看就讀意願',
    'continued_admission_list' => '續招',
    'admission_recommend_list' => '招生推薦',
    'activity_records' => '統計分析',
    'teacher_activity_records' => '教師活動紀錄',
    'settings' => '入學說明會',
    'mobile_junior_B' => '國中招生申請名單'
];

$message = "";
$messageType = "";

// 檢查是否有成功訊息
if (isset($_GET['msg']) && $_GET['msg'] === 'success') {
    $message = "權限更新成功！";
    $messageType = "success";
}

// 處理表單提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            case 'update_permissions':
                $user_id = intval($_POST['user_id']);
                
                if ($user_id <= 0) {
                    throw new Exception("無效的用戶ID。");
                }
                
                // 開始事務
                $conn->begin_transaction();
                
                // 驗證用戶是否存在且角色為STAM
                $check_user = $conn->prepare("SELECT id, role FROM user WHERE id = ? AND role = 'STAM'");
                $check_user->bind_param("i", $user_id);
                $check_user->execute();
                $user_result = $check_user->get_result();
                
                if ($user_result->num_rows === 0) {
                    $check_user->close();
                    $conn->rollback();
                    throw new Exception("找不到該用戶或該用戶不是招生中心組員（STAM）。");
                }
                $check_user->close();
                
                // 刪除該用戶的所有現有權限
                $delete_stmt = $conn->prepare("DELETE FROM staff_member_permissions WHERE user_id = ?");
                $delete_stmt->bind_param("i", $user_id);
                if (!$delete_stmt->execute()) {
                    $delete_stmt->close();
                    $conn->rollback();
                    throw new Exception("刪除舊權限失敗：" . $delete_stmt->error);
                }
                $delete_stmt->close();
                
                // 插入新權限
                if (isset($_POST['permissions']) && is_array($_POST['permissions']) && count($_POST['permissions']) > 0) {
                    $insert_stmt = $conn->prepare("INSERT INTO staff_member_permissions (user_id, permission_code) VALUES (?, ?)");
                    foreach ($_POST['permissions'] as $permission_code) {
                        if (array_key_exists($permission_code, $available_permissions)) {
                            $insert_stmt->bind_param("is", $user_id, $permission_code);
                            if (!$insert_stmt->execute()) {
                                $insert_stmt->close();
                                $conn->rollback();
                                throw new Exception("插入權限失敗：" . $insert_stmt->error);
                            }
                        }
                    }
                    $insert_stmt->close();
                }
                
                // 提交事務
                $conn->commit();
                
                // 重定向到當前頁面，避免重複提交並刷新數據
                header("Location: permission_management.php?msg=success");
                exit;
                break;
        }
    } catch (Exception $e) {
        if (isset($conn) && $conn->in_transaction) {
            $conn->rollback();
        }
        $message = "操作失敗：" . $e->getMessage();
        $messageType = "error";
    } catch (mysqli_sql_exception $e) {
        if (isset($conn) && $conn->in_transaction) {
            $conn->rollback();
        }
        $message = "資料庫錯誤：" . $e->getMessage();
        $messageType = "error";
    }
}

// 獲取所有STAM角色的用戶
$stam_users_sql = "
    SELECT u.id, u.username, u.name, u.email, u.role,
           GROUP_CONCAT(DISTINCT smp.permission_code ORDER BY smp.permission_code SEPARATOR ',') as permissions
    FROM user u
    LEFT JOIN staff_member_permissions smp ON u.id = smp.user_id
    WHERE u.role = 'STAM'
    GROUP BY u.id, u.username, u.name, u.email, u.role
    ORDER BY u.id DESC
";
$stam_users_result = $conn->query($stam_users_sql);
$stam_users = [];
if ($stam_users_result) {
    $stam_users = $stam_users_result->fetch_all(MYSQLI_ASSOC);
    // 調試：檢查每個用戶的權限數據
    foreach ($stam_users as &$user) {
        // 確保權限字段存在
        if (!isset($user['permissions'])) {
            $user['permissions'] = '';
        }
    }
    unset($user); // 取消引用
}

$conn->close();
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
            --primary-color: #1890ff;
            --success-color: #52c41a;
            --warning-color: #faad14;
            --danger-color: #ff4d4f;
            --text-color: #262626;
            --text-secondary-color: #8c8c8c;
            --border-color: #f0f0f0;
            --background-color: #f0f2f5;
            --card-background-color: #fff;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background: var(--background-color); color: var(--text-color); overflow-x: hidden; }
        .dashboard { display: flex; min-height: 100vh; }
        .main-content { 
            flex: 1; 
            margin-left: 250px; 
            background: #f0f2f5; 
            transition: all 0.3s ease; 
        }
        .main-content.expanded { margin-left: 60px; }
        .content { padding: 24px; }
        
        .page-controls { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-bottom: 16px; 
            gap: 16px; 
        }
        .breadcrumb { 
            margin-bottom: 0; 
            font-size: 16px; 
            color: var(--text-secondary-color); 
        }
        .breadcrumb a { 
            color: var(--primary-color); 
            text-decoration: none; 
        }
        .breadcrumb a:hover { 
            text-decoration: underline; 
        }
        
        .table-wrapper {
            background: var(--card-background-color);
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
        }

        .table-container { overflow-x: auto; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 16px 24px; text-align: left; border-bottom: 1px solid var(--border-color); font-size: 16px; }
        .table th:first-child, .table td:first-child {
            padding-left: 60px;
        }
        .table th { 
            background: #fafafa; 
            font-weight: 600; 
            color: #262626; 
        }
        .table td { color: #595959; }
        .table tr:hover { background: #fafafa; }
        
        .btn { padding: 8px 16px; border: 1px solid #d9d9d9; border-radius: 6px; cursor: pointer; font-size: 14px; transition: all 0.3s; background: #fff; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 6px; }
        .btn-primary { background: var(--primary-color); color: white; border-color: var(--primary-color); }
        .btn-primary:hover { background: #40a9ff; border-color: #40a9ff; }
        .btn-edit { color: var(--primary-color); border: 1px solid var(--primary-color); }
        .btn-edit:hover { background: var(--primary-color); color: white; }

        .message { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; font-weight: 500; }
        .message.success { background: #f6ffed; border: 1px solid #b7eb8f; color: var(--success-color); }
        .message.error { background: #fff2f0; border: 1px solid #ffccc7; color: var(--danger-color); }

        .modal { 
            display: none; 
            position: fixed; 
            z-index: 2000; 
            left: 0; 
            top: 0; 
            width: 100%; 
            height: 100%; 
            background-color: rgba(0,0,0,0.45); 
            overflow: auto;
        }
        .modal-content { background-color: #fff; margin: 5% auto; padding: 0; border-radius: 8px; width: 90%; max-width: 700px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); max-height: 90vh; overflow-y: auto; }
        .modal-header { padding: 16px 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }
        .modal-title { font-size: 18px; font-weight: 600; }
        .close { color: var(--text-secondary-color); font-size: 24px; font-weight: bold; cursor: pointer; line-height: 1; }
        .close:hover { color: var(--text-color); }
        .modal-body { padding: 24px; }
        .modal-footer { padding: 16px 24px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 8px; background: #fafafa; }
        
        .form-group { margin-bottom: 20px; }
        .form-label { display: block; margin-bottom: 12px; font-weight: 500; font-size: 14px; }
        .permission-checkboxes { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
        .permission-item { 
            display: flex; 
            align-items: center; 
            padding: 12px; 
            border: 1px solid var(--border-color); 
            border-radius: 6px; 
            transition: all 0.3s;
        }
        .permission-item:hover { 
            background: #f5f5f5; 
            border-color: var(--primary-color); 
        }
        .permission-item input[type="checkbox"] { 
            margin-right: 8px; 
            width: 18px; 
            height: 18px; 
            cursor: pointer; 
        }
        .permission-item label { 
            cursor: pointer; 
            flex: 1; 
            font-size: 14px; 
            margin: 0; 
        }
        
        
        .permissions-badge { 
            display: inline-block; 
            padding: 4px 8px; 
            margin: 2px; 
            background: #e6f7ff; 
            color: var(--primary-color); 
            border-radius: 4px; 
            font-size: 12px; 
        }
        .empty-permissions { 
            color: var(--text-secondary-color); 
            font-style: italic; 
        }
        
        .empty-state {
            text-align: center;
            padding: 48px;
            color: var(--text-secondary-color);
        }
        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            color: #d9d9d9;
        }
        
        @media (max-width: 768px) {
            .main-content { margin-left: 0; }
            .page-controls { flex-direction: column; align-items: flex-start; }
            .permission-checkboxes { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include 'sidebar.php'; ?>
        <div class="main-content" id="mainContent">
            <?php include 'header.php'; ?>
            <div class="content">
                <?php if ($message): ?>
                    <div class="message <?php echo $messageType; ?>">
                        <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <div class="page-controls">
                    <div class="breadcrumb">
                        <a href="index.php">首頁</a> / <?php echo $page_title; ?>
                    </div>
                </div>

                <div class="table-wrapper">
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>用戶名稱</th>
                                    <th>帳號</th>
                                    <th>Email</th>
                                    <th>已分配權限</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($stam_users)): ?>
                                    <tr>
                                        <td colspan="5" class="empty-state">
                                            <i class="fas fa-users"></i>
                                            <p>目前沒有招生中心組員（STAM）用戶</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($stam_users as $user): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($user['name'] ?? $user['username']); ?></td>
                                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                                            <td><?php echo htmlspecialchars($user['email'] ?? '-'); ?></td>
                                            <td>
                                                <?php 
                                                // 臨時調試：顯示原始權限數據（用於排查問題，確認後可移除）
                                                if (isset($_GET['debug']) && $_GET['debug'] == '1') {
                                                    echo '<div style="font-size: 12px; color: #999; margin-bottom: 4px;">原始數據: ' . htmlspecialchars($user['permissions'] ?? 'NULL') . '</div>';
                                                }
                                                
                                                if (!empty($user['permissions'])) {
                                                    // 先清理權限字符串，去除空格
                                                    $permissions_str = trim($user['permissions']);
                                                    $permissions = explode(',', $permissions_str);
                                                    
                                                    // 清理每個權限代碼，去除空格
                                                    $permissions = array_map('trim', $permissions);
                                                    $permissions = array_filter($permissions); // 移除空值
                                                    $permissions = array_values($permissions); // 重新索引陣列
                                                    
                                                    // 調試：檢查從資料庫獲取的權限（僅在開發時使用）
                                                    // error_log("User ID: " . $user['id'] . ", Raw permissions: " . $user['permissions']);
                                                    // error_log("Parsed permissions: " . print_r($permissions, true));
                                                    
                                                    // 按照$available_permissions的順序顯示權限
                                                    $displayed_codes = [];
                                                    
                                                    // 先顯示已定義的權限（按順序）
                                                    foreach ($available_permissions as $code => $name) {
                                                        if (in_array($code, $permissions, true)) { // 使用嚴格比較
                                                            $displayed_codes[] = $code;
                                                            echo '<span class="permissions-badge">' . htmlspecialchars($name) . '</span>';
                                                        }
                                                    }
                                                    
                                                    // 再顯示未定義的權限代碼（以防萬一）
                                                    foreach ($permissions as $perm_code) {
                                                        $perm_code = trim($perm_code);
                                                        if (!empty($perm_code) && !in_array($perm_code, $displayed_codes, true)) {
                                                            if (isset($available_permissions[$perm_code])) {
                                                                // 如果存在但之前沒顯示，再次顯示
                                                                if (!in_array($perm_code, $displayed_codes, true)) {
                                                                    echo '<span class="permissions-badge">' . htmlspecialchars($available_permissions[$perm_code]) . '</span>';
                                                                    $displayed_codes[] = $perm_code;
                                                                }
                                                            } else {
                                                                // 未定義的權限代碼
                                                                echo '<span class="permissions-badge" style="background: #ffccc7; color: #a8071a;">' . htmlspecialchars($perm_code) . ' (未定義)</span>';
                                                            }
                                                        }
                                                    }
                                                    
                                                    // 調試：如果權限數量不匹配，顯示警告（僅在開發時使用）
                                                    // if (count($permissions) > count($displayed_permissions)) {
                                                    //     error_log("警告：用戶 " . $user['id'] . " 有 " . count($permissions) . " 個權限，但只顯示了 " . count($displayed_permissions) . " 個");
                                                    //     error_log("未匹配的權限: " . print_r(array_diff($permissions, $displayed_permissions), true));
                                                    // }
                                                } else {
                                                    echo '<span class="empty-permissions">尚未分配權限</span>';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <button class="btn btn-edit assign-permission-btn" 
                                                        data-user-id="<?php echo intval($user['id']); ?>"
                                                        data-user-name="<?php echo htmlspecialchars($user['name'] ?? $user['username'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-permissions="<?php echo htmlspecialchars($user['permissions'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                                    <i class="fas fa-edit"></i> 分配權限
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 權限分配模態框 -->
    <div id="permissionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title">分配權限 - <span id="modalUserName"></span></div>
                <span class="close" onclick="closePermissionModal()">&times;</span>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_permissions">
                <input type="hidden" name="user_id" id="modalUserId">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">請選擇要分配的權限：</label>
                        <div class="permission-checkboxes" id="permissionCheckboxes">
                            <?php foreach ($available_permissions as $code => $name): ?>
                                <div class="permission-item">
                                    <input type="checkbox" name="permissions[]" value="<?php echo htmlspecialchars($code); ?>" id="perm_<?php echo htmlspecialchars($code); ?>">
                                    <label for="perm_<?php echo htmlspecialchars($code); ?>"><?php echo htmlspecialchars($name); ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closePermissionModal()">取消</button>
                    <button type="submit" class="btn btn-primary">儲存</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openPermissionModal(userId, userName, currentPermissions) {
            try {
                const modal = document.getElementById('permissionModal');
                const userIdInput = document.getElementById('modalUserId');
                const userNameSpan = document.getElementById('modalUserName');
                
                if (!modal || !userIdInput || !userNameSpan) {
                    console.error('找不到模態框元素');
                    alert('無法打開權限分配視窗，請刷新頁面後重試。');
                    return;
                }
                
                userIdInput.value = userId;
                userNameSpan.textContent = userName || '未知用戶';
                
                // 清除所有選中的複選框
                const checkboxes = document.querySelectorAll('#permissionCheckboxes input[type="checkbox"]');
                checkboxes.forEach(cb => cb.checked = false);
                
                // 如果有現有權限，則選中對應的複選框
                if (currentPermissions && currentPermissions.trim() !== '') {
                    const permissions = currentPermissions.split(',');
                    permissions.forEach(permCode => {
                        const trimmedCode = permCode.trim();
                        if (trimmedCode) {
                            const checkbox = document.getElementById('perm_' + trimmedCode);
                            if (checkbox) {
                                checkbox.checked = true;
                            }
                        }
                    });
                }
                
                modal.style.display = 'block';
            } catch (error) {
                console.error('打開模態框時發生錯誤:', error);
                alert('打開權限分配視窗時發生錯誤，請刷新頁面後重試。');
            }
        }

        function closePermissionModal() {
            const modal = document.getElementById('permissionModal');
            if (modal) {
                modal.style.display = 'none';
            }
        }

        // 點擊模態框外部關閉
        window.onclick = function(event) {
            const modal = document.getElementById('permissionModal');
            if (event.target == modal) {
                closePermissionModal();
            }
        }
        
        // 確保頁面載入時模態框是隱藏的，並綁定按鈕事件
        document.addEventListener('DOMContentLoaded', function() {
            const modal = document.getElementById('permissionModal');
            if (modal) {
                modal.style.display = 'none';
            }
            
            // 為所有分配權限按鈕綁定點擊事件
            const assignButtons = document.querySelectorAll('.assign-permission-btn');
            assignButtons.forEach(function(button) {
                button.addEventListener('click', function() {
                    const userId = this.getAttribute('data-user-id');
                    const userName = this.getAttribute('data-user-name');
                    const permissions = this.getAttribute('data-permissions') || '';
                    openPermissionModal(userId, userName, permissions);
                });
            });
        });
    </script>
</body>
</html>

