<?php
session_start();

// 檢查是否已登入
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: login.php");
    exit;
}

// 檢查角色權限 - 僅允許資管科主任(IM)訪問
// 資管科主任可能是role=IM，也可能是role=DI且部門代碼=IM
$user_role = $_SESSION['role'] ?? '';
$user_id = $_SESSION['user_id'] ?? null;
$is_im_director = false;

// 檢查是否為資管科主任
if ($user_role === 'IM' || $user_role === '資管科主任') {
    $is_im_director = true;
} elseif ($user_role === 'DI' && $user_id) {
    // 如果role是DI，檢查部門代碼是否為IM
    require_once '../../Topics-frontend/frontend/config.php';
    $conn_check = getDatabaseConnection();
    try {
        $table_check = $conn_check->query("SHOW TABLES LIKE 'director'");
        if ($table_check && $table_check->num_rows > 0) {
            $stmt_dept = $conn_check->prepare("SELECT department FROM director WHERE user_id = ?");
        } else {
            $stmt_dept = $conn_check->prepare("SELECT department FROM teacher WHERE user_id = ?");
        }
        $stmt_dept->bind_param("i", $user_id);
        $stmt_dept->execute();
        $result_dept = $stmt_dept->get_result();
        if ($row = $result_dept->fetch_assoc()) {
            if ($row['department'] === 'IM') {
                $is_im_director = true;
            }
        }
        $stmt_dept->close();
    } catch (Exception $e) {
        error_log('Error checking IM director in department_permission_management: ' . $e->getMessage());
    }
    $conn_check->close();
}

if (!$is_im_director) {
    header("Location: index.php");
    exit;
}

// 引入資料庫設定
require_once '../../Topics-frontend/frontend/config.php';

// 設置頁面標題
$page_title = '科助權限管理';

// 建立資料庫連接
$conn = getDatabaseConnection();

// 確保AS角色存在於role_types表中
$check_as_role = $conn->prepare("SELECT code FROM role_types WHERE code = 'AS'");
$check_as_role->execute();
$as_role_result = $check_as_role->get_result();
if ($as_role_result->num_rows === 0) {
    // 檢查表是否有description欄位
    $desc_check = $conn->query("SHOW COLUMNS FROM role_types LIKE 'description'");
    if ($desc_check && $desc_check->num_rows > 0) {
        $insert_as_role = $conn->prepare("INSERT INTO role_types (code, name, description) VALUES ('AS', '科助', '科助，可被分配特定權限')");
    } else {
        $insert_as_role = $conn->prepare("INSERT INTO role_types (code, name) VALUES ('AS', '科助')");
    }
    $insert_as_role->execute();
    $insert_as_role->close();
}
$check_as_role->close();

// 確保IM角色存在於role_types表中
$check_im_role = $conn->prepare("SELECT code FROM role_types WHERE code = 'IM'");
$check_im_role->execute();
$im_role_result = $check_im_role->get_result();
if ($im_role_result->num_rows === 0) {
    // 檢查表是否有description欄位
    $desc_check = $conn->query("SHOW COLUMNS FROM role_types LIKE 'description'");
    if ($desc_check && $desc_check->num_rows > 0) {
        $insert_im_role = $conn->prepare("INSERT INTO role_types (code, name, description) VALUES ('IM', '資管科主任', '資管科主任，可分配權限給科助')");
    } else {
        $insert_im_role = $conn->prepare("INSERT INTO role_types (code, name) VALUES ('IM', '資管科主任')");
    }
    $insert_im_role->execute();
    $insert_im_role->close();
}
$check_im_role->close();

// 創建科助權限表（如果不存在）
$create_permission_table_sql = "
CREATE TABLE IF NOT EXISTS assistant_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL COMMENT '用戶ID（role=AS的用戶）',
    permission_code VARCHAR(50) NOT NULL COMMENT '權限代碼',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_permission (user_id, permission_code),
    INDEX idx_user_id (user_id),
    INDEX idx_permission_code (permission_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='科助權限表';
";

// 嘗試創建表
$conn->query($create_permission_table_sql);

// 檢查表是否存在，如果不存在則嘗試不帶外鍵的版本
$check_table = $conn->query("SHOW TABLES LIKE 'assistant_permissions'");
if ($check_table->num_rows == 0) {
    // 如果表不存在，嘗試創建不帶外鍵的版本
    $create_permission_table_sql_no_fk = "
    CREATE TABLE IF NOT EXISTS assistant_permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL COMMENT '用戶ID（role=AS的用戶）',
        permission_code VARCHAR(50) NOT NULL COMMENT '權限代碼',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_user_permission (user_id, permission_code),
        INDEX idx_user_id (user_id),
        INDEX idx_permission_code (permission_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='科助權限表';
    ";
    $conn->query($create_permission_table_sql_no_fk);
}

// 定義可分配的權限
$available_permissions = [
    'enrollment_list' => '查看就讀意願',
    'continued_admission_list' => '續招',
    'admission_recommend_list' => '招生推薦',
    'activity_records' => '統計分析',
    'teacher_activity_records' => '教師活動紀錄'
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
                
                // 驗證用戶是否存在且角色為AS
                $check_user = $conn->prepare("SELECT id, role FROM user WHERE id = ? AND role = 'AS'");
                $check_user->bind_param("i", $user_id);
                $check_user->execute();
                $user_result = $check_user->get_result();
                
                if ($user_result->num_rows === 0) {
                    $check_user->close();
                    $conn->rollback();
                    throw new Exception("找不到該用戶或該用戶不是科助（AS）。");
                }
                $check_user->close();
                
                // 刪除該用戶的所有現有權限
                $delete_stmt = $conn->prepare("DELETE FROM assistant_permissions WHERE user_id = ?");
                $delete_stmt->bind_param("i", $user_id);
                if (!$delete_stmt->execute()) {
                    $delete_stmt->close();
                    $conn->rollback();
                    throw new Exception("刪除舊權限失敗：" . $delete_stmt->error);
                }
                $delete_stmt->close();
                
                // 插入新權限
                if (isset($_POST['permissions']) && is_array($_POST['permissions']) && count($_POST['permissions']) > 0) {
                    $insert_stmt = $conn->prepare("INSERT INTO assistant_permissions (user_id, permission_code) VALUES (?, ?)");
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
                header("Location: department_permission_management.php?msg=success");
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

// 獲取所有AS角色的用戶
$as_users_sql = "
    SELECT u.id, u.username, u.name, u.email, u.role
    FROM user u
    WHERE u.role = 'AS'
    ORDER BY u.id DESC
";
$as_users_result = $conn->query($as_users_sql);
$as_users = [];
if ($as_users_result) {
    $as_users = $as_users_result->fetch_all(MYSQLI_ASSOC);
    
    // 為每個用戶獲取權限詳情（包括分配時間）
    foreach ($as_users as &$user) {
        $user_id = $user['id'];
        $perm_sql = "SELECT permission_code, updated_at FROM assistant_permissions WHERE user_id = ? ORDER BY permission_code";
        $perm_stmt = $conn->prepare($perm_sql);
        $perm_stmt->bind_param("i", $user_id);
        $perm_stmt->execute();
        $perm_result = $perm_stmt->get_result();
        
        $user['permissions'] = [];
        $user['permission_times'] = [];
        while ($perm_row = $perm_result->fetch_assoc()) {
            $user['permissions'][] = $perm_row['permission_code'];
            $user['permission_times'][$perm_row['permission_code']] = $perm_row['updated_at'];
        }
        $perm_stmt->close();
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
            padding: 6px 12px; 
            margin: 2px; 
            background: #e6f7ff; 
            color: var(--primary-color); 
            border-radius: 4px; 
            font-size: 16px; 
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
                                <?php if (empty($as_users)): ?>
                                    <tr>
                                        <td colspan="5" class="empty-state">
                                            <i class="fas fa-users"></i>
                                            <p>目前沒有科助（AS）用戶</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($as_users as $user): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($user['name'] ?? $user['username']); ?></td>
                                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                                            <td><?php echo htmlspecialchars($user['email'] ?? '-'); ?></td>
                                            <td>
                                                <?php 
                                                if (!empty($user['permissions']) && is_array($user['permissions'])) {
                                                    $permission_times = $user['permission_times'] ?? [];
                                                    
                                                    // 按照$available_permissions的順序顯示權限
                                                    $displayed_codes = [];
                                                    
                                                    // 先顯示已定義的權限（按順序）
                                                    foreach ($available_permissions as $code => $name) {
                                                        if (in_array($code, $user['permissions'], true)) {
                                                            $displayed_codes[] = $code;
                                                            $assigned_time = isset($permission_times[$code]) ? $permission_times[$code] : null;
                                                            
                                                            echo '<div style="margin-bottom: 8px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">';
                                                            echo '<span class="permissions-badge">' . htmlspecialchars($name) . '</span>';
                                                            if ($assigned_time) {
                                                                $time_obj = new DateTime($assigned_time);
                                                                echo '<span style="font-size: 14px; color: #8c8c8c;">';
                                                                echo '<i class="fas fa-clock" style="margin-right: 4px;"></i>';
                                                                echo $time_obj->format('Y-m-d H:i:s');
                                                                echo '</span>';
                                                            }
                                                            echo '</div>';
                                                        }
                                                    }
                                                    
                                                    // 再顯示未定義的權限代碼（以防萬一）
                                                    foreach ($user['permissions'] as $perm_code) {
                                                        if (!in_array($perm_code, $displayed_codes, true)) {
                                                            $assigned_time = isset($permission_times[$perm_code]) ? $permission_times[$perm_code] : null;
                                                            
                                                            echo '<div style="margin-bottom: 8px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">';
                                                            if (isset($available_permissions[$perm_code])) {
                                                                echo '<span class="permissions-badge">' . htmlspecialchars($available_permissions[$perm_code]) . '</span>';
                                                            } else {
                                                                echo '<span class="permissions-badge" style="background: #ffccc7; color: #a8071a;">' . htmlspecialchars($perm_code) . ' (未定義)</span>';
                                                            }
                                                            if ($assigned_time) {
                                                                $time_obj = new DateTime($assigned_time);
                                                                echo '<span style="font-size: 14px; color: #8c8c8c;">';
                                                                echo '<i class="fas fa-clock" style="margin-right: 4px;"></i>';
                                                                echo $time_obj->format('Y-m-d H:i:s');
                                                                echo '</span>';
                                                            }
                                                            echo '</div>';
                                                            $displayed_codes[] = $perm_code;
                                                        }
                                                    }
                                                } else {
                                                    echo '<span class="empty-permissions">尚未分配權限</span>';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <button class="btn btn-edit assign-permission-btn" 
                                                        data-user-id="<?php echo intval($user['id']); ?>"
                                                        data-user-name="<?php echo htmlspecialchars($user['name'] ?? $user['username'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-permissions="<?php echo htmlspecialchars(implode(',', $user['permissions'] ?? []), ENT_QUOTES, 'UTF-8'); ?>">
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

