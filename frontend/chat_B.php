<?php
require_once __DIR__ . '/session_config.php';
checkBackendLogin();

// 引入資料庫設定
$config_path = '../../Topics-frontend/frontend/config.php';
if (!file_exists($config_path)) {
    $alt_paths = [
        '../../../Topics-frontend/frontend/config.php',
        __DIR__ . '/../../Topics-frontend/frontend/config.php',
        dirname(__DIR__) . '/../Topics-frontend/frontend/config.php'
    ];
    
    $found = false;
    foreach ($alt_paths as $alt_path) {
        if (file_exists($alt_path)) {
            $config_path = $alt_path;
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        die('錯誤：找不到資料庫設定檔案 (config.php)');
    }
}

require_once $config_path;

if (!function_exists('getDatabaseConnection')) {
    die('錯誤：資料庫連接函數未定義');
}

// 初始化變數
$user_contacts = [];
$group_chat_members = [];
$error_message = '';
$user_id = $_SESSION['user_id'] ?? 0;
$username = isset($_SESSION['username']) ? $_SESSION['username'] : '';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';

// 權限檢查：只允許 ADM 角色訪問
if ($user_role !== 'ADM') {
    header("Location: index.php");
    exit;
}

// 設置頁面標題
$page_title = '聯絡人與群組管理';

// 排序參數
$sortBy = $_GET['sort_by'] ?? '';
$sortOrder = $_GET['sort_order'] ?? 'desc';
$active_tab = $_GET['tab'] ?? 'contacts'; // contacts 或 groups

// 驗證排序參數，防止 SQL 注入
$allowed_columns = ['id', 'user_id', 'contact_user_id', 'group_id', 'created_at', 'updated_at', 'joined_at', 'role'];
if (!in_array($sortBy, $allowed_columns)) {
    $sortBy = ''; // 不设置默认值，让查询根据实际表结构决定
}
if (!in_array(strtolower($sortOrder), ['asc', 'desc'])) {
    $sortOrder = 'desc';
}

// 獲取表結構的函數
function getTableColumns($conn, $table_name) {
    $columns = [];
    $result = $conn->query("DESCRIBE $table_name");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $columns[] = $row['Field'];
        }
    }
    return $columns;
}

try {
    // 建立資料庫連接
    $conn = getDatabaseConnection();

    // 輔助函數：格式化日期
    function formatDate($date) {
        if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
            return '未提供';
        }
        return date('Y/m/d', strtotime($date));
    }

    function formatDateTime($datetime) {
        if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
            return '未提供';
        }
        return date('Y/m/d H:i', strtotime($datetime));
    }

    // 查詢 user_contacts 表
    $table_check = $conn->query("SHOW TABLES LIKE 'user_contacts'");
    if ($table_check && $table_check->num_rows > 0) {
        // 獲取表結構
        $user_contacts_columns = getTableColumns($conn, 'user_contacts');
        
        // 動態構建 SELECT 語句
        $select_fields = implode(', ', array_map(function($col) {
            return "uc.$col";
        }, $user_contacts_columns));
        
        // 嘗試 JOIN user 表獲取用戶名稱
        $sql = "SELECT $select_fields";
        if (in_array('user_id', $user_contacts_columns)) {
            $sql .= ", u1.name as user_name, u1.username as user_username";
        }
        if (in_array('contact_user_id', $user_contacts_columns)) {
            $sql .= ", u2.name as contact_name, u2.username as contact_username";
        }
        $sql .= " FROM user_contacts uc";
        if (in_array('user_id', $user_contacts_columns)) {
            $sql .= " LEFT JOIN user u1 ON uc.user_id = u1.id";
        }
        if (in_array('contact_user_id', $user_contacts_columns)) {
            $sql .= " LEFT JOIN user u2 ON uc.contact_user_id = u2.id";
        }
        // 檢查排序欄位是否存在
        if (!empty($sortBy) && in_array($sortBy, $user_contacts_columns)) {
            $sql .= " ORDER BY uc.$sortBy $sortOrder";
        } else {
            // 使用第一個欄位作為默認排序
            $first_col = !empty($user_contacts_columns) ? $user_contacts_columns[0] : 'id';
            $sql .= " ORDER BY uc.$first_col $sortOrder";
        }

        $result = $conn->query($sql);
        if ($result) {
            $all_contacts = $result->fetch_all(MYSQLI_ASSOC);
            // 按用戶ID分組
            $user_contacts_grouped = [];
            foreach ($all_contacts as $contact) {
                $user_id = $contact['user_id'] ?? 0;
                if (!isset($user_contacts_grouped[$user_id])) {
                    $user_contacts_grouped[$user_id] = [
                        'user_id' => $user_id,
                        'user_name' => $contact['user_name'] ?? $contact['user_username'] ?? '未知用戶',
                        'user_username' => $contact['user_username'] ?? '',
                        'contacts' => []
                    ];
                }
                $user_contacts_grouped[$user_id]['contacts'][] = $contact;
            }
            // 轉換為數組格式以便在模板中使用
            $user_contacts = array_values($user_contacts_grouped);
        } else {
            $error_message = '查詢 user_contacts 失敗: ' . $conn->error;
        }
    } else {
        $error_message = '找不到 user_contacts 表';
    }

    // 查詢 group_chat_members 表
    $table_check2 = $conn->query("SHOW TABLES LIKE 'group_chat_members'");
    if ($table_check2 && $table_check2->num_rows > 0) {
        // 獲取表結構
        $group_members_columns = getTableColumns($conn, 'group_chat_members');
        
        // 動態構建 SELECT 語句
        $select_fields = implode(', ', array_map(function($col) {
            return "gcm.$col";
        }, $group_members_columns));
        
        // 嘗試 JOIN user 表獲取用戶完整資訊
        // 檢查可能的用戶ID字段名：user_id 或 user
        $user_field = null;
        if (in_array('user_id', $group_members_columns)) {
            $user_field = 'user_id';
        } elseif (in_array('user', $group_members_columns)) {
            $user_field = 'user';
        }
        
        $sql = "SELECT $select_fields";
        if ($user_field) {
            $sql .= ", u.id as user_table_id, u.name as user_name, u.username as user_username, u.email as user_email, u.role as user_role, u.status as user_status";
        }
        
        // 檢查 group_info 表是否存在（優先使用）
        $group_info_check = $conn->query("SHOW TABLES LIKE 'group_info'");
        $group_info_exists = $group_info_check && $group_info_check->num_rows > 0;
        
        // 檢查 group_chats 表是否存在（備用）
        $group_chats_check = $conn->query("SHOW TABLES LIKE 'group_chats'");
        $group_chats_exists = $group_chats_check && $group_chats_check->num_rows > 0;
        
        // 優先使用 group_info，如果不存在則使用 group_chats
        if ($group_info_exists && in_array('group_id', $group_members_columns)) {
            // 檢查 group_info 表的欄位結構
            $group_info_columns = getTableColumns($conn, 'group_info');
            // 嘗試獲取群組名稱欄位（可能是 group_name, name, title 等）
            $group_name_field = null;
            if (in_array('group_name', $group_info_columns)) {
                $group_name_field = 'group_name';
            } elseif (in_array('name', $group_info_columns)) {
                $group_name_field = 'name';
            } elseif (in_array('title', $group_info_columns)) {
                $group_name_field = 'title';
            }
            
            if ($group_name_field) {
                $sql .= ", gi.$group_name_field as group_name";
            }
            
            // 嘗試獲取描述欄位
            if (in_array('description', $group_info_columns)) {
                $sql .= ", gi.description as group_description";
            } elseif (in_array('desc', $group_info_columns)) {
                $sql .= ", gi.desc as group_description";
            }
        } elseif ($group_chats_exists && in_array('group_id', $group_members_columns)) {
            $sql .= ", gc.group_name, gc.description as group_description";
        }
        
        $sql .= " FROM group_chat_members gcm";
        if ($user_field) {
            $sql .= " LEFT JOIN user u ON gcm.$user_field = u.id";
        }
        
        // 優先 JOIN group_info，如果不存在則 JOIN group_chats
        if ($group_info_exists && in_array('group_id', $group_members_columns)) {
            // 檢查 group_info 表的主鍵欄位（可能是 id 或 group_id）
            $group_info_columns = getTableColumns($conn, 'group_info');
            $group_info_key = 'id';
            if (in_array('group_id', $group_info_columns)) {
                $group_info_key = 'group_id';
            } elseif (in_array('id', $group_info_columns)) {
                $group_info_key = 'id';
            }
            $sql .= " LEFT JOIN group_info gi ON gcm.group_id = gi.$group_info_key";
        } elseif ($group_chats_exists && in_array('group_id', $group_members_columns)) {
            $sql .= " LEFT JOIN group_chats gc ON gcm.group_id = gc.id";
        }
        // 檢查排序欄位是否存在
        if (!empty($sortBy) && in_array($sortBy, $group_members_columns)) {
            $sql .= " ORDER BY gcm.$sortBy $sortOrder";
        } else {
            // 使用第一個欄位作為默認排序
            $first_col = !empty($group_members_columns) ? $group_members_columns[0] : 'id';
            $sql .= " ORDER BY gcm.$first_col $sortOrder";
        }

        $result = $conn->query($sql);
        if ($result) {
            $all_members = $result->fetch_all(MYSQLI_ASSOC);
            // 按群組ID分組
            $group_members_grouped = [];
            foreach ($all_members as $member) {
                $group_id = $member['group_id'] ?? 0;
                // 優先使用從 group_info 或 group_chats 獲取的群組名稱
                $group_name = $member['group_name'] ?? null;
                // 如果沒有群組名稱，嘗試其他可能的欄位
                if (empty($group_name)) {
                    $group_name = $member['name'] ?? $member['title'] ?? null;
                }
                // 如果還是沒有，使用默認值
                if (empty($group_name)) {
                    $group_name = '群組 ' . $group_id;
                }
                
                if (!isset($group_members_grouped[$group_id])) {
                    $group_members_grouped[$group_id] = [
                        'group_id' => $group_id,
                        'group_name' => $group_name,
                        'group_description' => $member['group_description'] ?? $member['desc'] ?? '',
                        'members' => []
                    ];
                }
                $group_members_grouped[$group_id]['members'][] = $member;
            }
            // 轉換為數組格式以便在模板中使用
            $group_chat_members = array_values($group_members_grouped);
        } else {
            $error_message .= ($error_message ? '; ' : '') . '查詢 group_chat_members 失敗: ' . $conn->error;
        }
    } else {
        $error_message .= ($error_message ? '; ' : '') . '找不到 group_chat_members 表';
    }

    $conn->close();
    
} catch (Exception $e) {
    $error_message = '資料庫操作失敗，請稍後再試: ' . $e->getMessage();
    error_log('chat_B.php 錯誤: ' . $e->getMessage());
    if (isset($conn)) {
        $conn->close();
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
            --primary-color: #1890ff;
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
            /* 防止內部過寬的元素撐開主內容區，影響 header */
            overflow-x: hidden;
        }
        .content { padding: 24px; width: 100%; }

        .page-controls { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; gap: 16px; }
        .breadcrumb { margin-bottom: 0; font-size: 16px; color: var(--text-secondary-color); }
        .breadcrumb a { color: var(--primary-color); text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }
        
        .table-wrapper {
            background: var(--card-background-color);
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
        }

        .table-container {
            overflow-x: auto;
        }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 16px 24px; text-align: left; border-bottom: 1px solid var(--border-color); font-size: 16px; white-space: nowrap; }
        .table th:first-child, .table td:first-child {
            padding-left: 60px;
        }
        .table th { background: #fafafa; font-weight: 600; color: #262626; cursor: pointer; user-select: none; position: relative; }
        .table td {
            color: #595959;
        }
        .table th:hover { background: #f0f0f0; }
        .sort-icon { margin-left: 8px; font-size: 12px; color: #8c8c8c; }
        .sort-icon.active { color: #1890ff; }
        .sort-icon.asc::after { content: "↑"; }
        .sort-icon.desc::after { content: "↓"; }
        .table tr:hover { background: #fafafa; }
        
        /* 分頁樣式 */
        .pagination {
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 1px solid var(--border-color);
            background: #fafafa;
        }

        .pagination-info {
            display: flex;
            align-items: center;
            gap: 16px;
            color: var(--text-secondary-color);
            font-size: 14px;
        }

        .pagination-controls {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .pagination select {
            padding: 6px 12px;
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            font-size: 14px;
            background: #fff;
            cursor: pointer;
        }

        .pagination select:focus {
            outline: none;
            border-color: #1890ff;
            box-shadow: 0 0 0 2px rgba(24,144,255,0.2);
        }

        .pagination button {
            padding: 6px 12px;
            border: 1px solid #d9d9d9;
            background: #fff;
            color: #595959;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
        }

        .pagination button:hover:not(:disabled) {
            border-color: #1890ff;
            color: #1890ff;
        }

        .pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .pagination button.active {
            background: #1890ff;
            color: white;
            border-color: #1890ff;
        }
        
        .detail-row {
            background: #f9f9f9;
        }
        
        .info-row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 4px;
        }
        .info-label {
            font-weight: 600;
            color: var(--text-secondary-color);
            min-width: 80px;
        }
        .info-value {
            color: var(--text-color);
        }
        
        .table-row-clickable {
            cursor: pointer;
        }
        
        /* 用戶分組行樣式 */
        .user-group-row {
            background: #f0f7ff;
            font-weight: 600;
        }
        .user-group-row:hover {
            background: #e6f2ff;
        }
        .user-group-row .expand-icon {
            display: inline-block;
            width: 20px;
            text-align: center;
            margin-right: 8px;
            transition: transform 0.3s;
        }
        .user-group-row.expanded .expand-icon {
            transform: rotate(90deg);
        }
        
        /* 聯絡人子行樣式 */
        .contact-child-row {
            background: #fafafa;
            display: none;
        }
        .contact-child-row.show {
            display: table-row;
        }
        .contact-child-row td:first-child {
            padding-left: 80px;
        }
        .contact-child-row:hover {
            background: #f0f0f0;
        }
        
        /* 群組分組行樣式 */
        .group-group-row {
            background: #f0f7ff;
            font-weight: 600;
        }
        .group-group-row:hover {
            background: #e6f2ff;
        }
        .group-group-row .expand-icon {
            display: inline-block;
            width: 20px;
            text-align: center;
            margin-right: 8px;
            transition: transform 0.3s;
        }
        .group-group-row.expanded .expand-icon {
            transform: rotate(90deg);
        }
        
        /* 群組成員子行樣式 */
        .member-child-row {
            background: #fafafa;
            display: none;
        }
        .member-child-row.show {
            display: table-row;
        }
        .member-child-row td:first-child {
            padding-left: 80px;
        }
        .member-child-row:hover {
            background: #f0f0f0;
        }

        .search-input {
            padding: 8px 12px;
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            font-size: 14px;
            width: 250px;
            transition: all 0.3s;
        }
        .search-input:focus {
            outline: none;
            border-color: #1890ff;
            box-shadow: 0 0 0 2px rgba(24,144,255,0.2);
        }

        .empty-state { text-align: center; padding: 40px; color: var(--text-secondary-color); }

        .status-badge { 
            padding: 4px 8px; 
            border-radius: 4px; 
            font-size: 12px; 
            font-weight: 500; 
            border: 1px solid; 
            display: inline-block;
        }
        .status-approved { 
            background: #f6ffed; 
            color: #52c41a; 
            border-color: #b7eb8f; 
        }
        .status-rejected { 
            background: #fff2f0; 
            color: #ff4d4f; 
            border-color: #ffccc7; 
        }
        .status-waitlist { 
            background: #fff7e6; 
            color: #fa8c16; 
            border-color: #ffd591; 
        }
        .status-pending { 
            background: #e6f7ff; 
            color: #1890ff; 
            border-color: #91d5ff; 
        }

        .status-select {
            padding: 4px 8px;
            border: 1px solid #d9d9d9;
            border-radius: 4px;
            font-size: 12px;
            background-color: #fff;
            cursor: pointer;
            margin-right: 8px;
        }
        .status-select:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        .btn-update {
            padding: 4px 12px;
            border: 1px solid #1890ff;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            background: #1890ff;
            color: white;
            transition: all 0.3s;
        }
        .btn-update:hover {
            background: #40a9ff;
            border-color: #40a9ff;
        }
        .btn-update:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .btn-edit {
            padding: 6px 12px;
            border: 1px solid #1890ff;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            background: #1890ff;
            color: white;
            transition: all 0.3s;
        }
        .btn-edit:hover:not(:disabled) {
            background: #40a9ff;
            border-color: #40a9ff;
        }
        .btn-edit:disabled,
        .btn-edit.disabled {
            background: #d9d9d9;
            border-color: #d9d9d9;
            color: #8c8c8c;
            cursor: not-allowed;
            opacity: 0.6;
        }
        .btn-edit i {
            margin-right: 4px;
        }

        .btn-delete {
            padding: 6px 12px;
            border: 1px solid #ff4d4f;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            background: #ff4d4f;
            color: white;
            transition: all 0.3s;
        }
        .btn-delete:hover {
            background: #ff7875;
            border-color: #ff7875;
        }
        .btn-delete i {
            margin-right: 4px;
        }

        /* 按鈕樣式（參考 settings.php） */
        .btn {
            padding: 8px 16px;
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
            background: #fff;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        .btn-primary {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        .btn-primary:hover {
            background: #40a9ff;
            border-color: #40a9ff;
        }

        /* 編輯模態視窗樣式 */
        .edit-modal {
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            display: none;
            align-items: center;
            justify-content: center;
        }
        .edit-modal-content {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
        }
        .edit-modal-header {
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: white;
            z-index: 10;
        }
        .edit-modal-header h3 {
            margin: 0;
            color: var(--text-color);
        }
        .edit-modal-body {
            padding: 20px;
        }
        .edit-form-row {
            display: flex;
            gap: 16px;
            margin-bottom: 16px;
        }
        .edit-form-group {
            flex: 1;
        }
        .edit-form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: var(--text-color);
            font-size: 14px;
        }
        .edit-form-group input,
        .edit-form-group select,
        .edit-form-group textarea {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
        }
        .edit-form-group textarea {
            min-height: 80px;
            resize: vertical;
        }
        .edit-form-group input:disabled,
        .edit-form-group select:disabled,
        .edit-form-group textarea:disabled {
            background-color: #f5f5f5;
            cursor: not-allowed;
        }
        .edit-form-group.full-width {
            flex: 1 1 100%;
        }
        .edit-modal-footer {
            padding: 20px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            position: sticky;
            bottom: 0;
            background: white;
            z-index: 10;
        }

        /* 標籤頁樣式 */
        .tabs {
            display: flex;
            gap: 0;
            margin-bottom: 24px;
        }
        .tab-button {
            padding: 12px 24px;
            border: none;
            background: transparent;
            color: var(--text-secondary-color);
            font-size: 16px;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            transition: all 0.3s;
            position: relative;
            top: 2px;
        }
        .tab-button:hover {
            color: var(--primary-color);
        }
        .tab-button.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
            font-weight: 600;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include 'sidebar.php'; ?>
        <div class="main-content" id="mainContent">
            <?php include 'header.php'; ?>
            <div class="content">
                <div class="page-controls">
                    <div class="breadcrumb">
                        <a href="index.php">首頁</a> / <?php echo $page_title; ?>
                    </div>
                    <div class="table-search">
                        <input type="text" id="searchInput" class="search-input" placeholder="搜尋...">
                    </div>
                </div>

                <!-- 標籤頁 -->
                <div class="tabs" style="margin-bottom: 24px; border-bottom: 2px solid var(--border-color);">
                    <button class="tab-button <?php echo $active_tab === 'contacts' ? 'active' : ''; ?>" onclick="switchTab('contacts')">
                        聯絡人 (<?php echo count($user_contacts); ?>)
                    </button>
                    <button class="tab-button <?php echo $active_tab === 'groups' ? 'active' : ''; ?>" onclick="switchTab('groups')">
                        群組成員 (<?php echo count($group_chat_members); ?>)
                    </button>
                </div>

                <?php if (!empty($error_message)): ?>
                <div style="background: #fff2f0; border: 1px solid #ffccc7; border-radius: 8px; padding: 16px; margin-bottom: 24px; color: #cf1322;">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
                </div>
                <?php endif; ?>

                <!-- 聯絡人標籤頁內容 -->
                <div class="tab-content <?php echo $active_tab === 'contacts' ? 'active' : ''; ?>" id="tab-contacts">
                    <div class="table-wrapper">
                        <div class="table-container">
                            <?php if (empty($user_contacts)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-inbox fa-3x" style="margin-bottom: 16px;"></i>
                                    <p>目前尚無任何聯絡人資料。</p>
                                </div>
                            <?php else: 
                                // 獲取第一個聯絡人的欄位結構（用於表頭）
                                $sample_contact = null;
                                foreach ($user_contacts as $group) {
                                    if (!empty($group['contacts'])) {
                                        $sample_contact = $group['contacts'][0];
                                        break;
                                    }
                                }
                            ?>
                                <table class="table" id="contactsTable">
                                    <thead>
                                        <tr>
                                            <th>用戶ID</th>
                                            <th>用戶名稱</th>
                                            <th>聯絡人數量</th>
                                            <?php if ($sample_contact): 
                                                foreach ($sample_contact as $key => $value): 
                                                    if (in_array($key, ['user_id', 'user_name', 'user_username', 'contact_name', 'contact_username'])) continue;
                                            ?>
                                                <th>
                                                    <?php 
                                                    $column_names = [
                                                        'id' => 'ID',
                                                        'contact_user_id' => '聯絡人ID',
                                                        'email' => 'Email',
                                                        'phone' => '電話',
                                                        'created_at' => '建立時間',
                                                        'updated_at' => '更新時間'
                                                    ];
                                                    echo $column_names[$key] ?? $key; 
                                                    ?>
                                                </th>
                                            <?php endforeach; endif; ?>
                                            <th>聯絡人名稱</th>
                                            <th>操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($user_contacts as $group): 
                                            $user_id = $group['user_id'];
                                            $contacts_count = count($group['contacts']);
                                        ?>
                                        <!-- 用戶分組行 -->
                                        <tr class="table-row-clickable user-group-row" data-user-id="<?php echo $user_id; ?>" onclick="toggleUserContacts(<?php echo $user_id; ?>)">
                                            <td>
                                                <span class="expand-icon">▶</span>
                                                <?php echo htmlspecialchars($user_id); ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($group['user_name']); ?></td>
                                            <td>
                                                <span style="background: #1890ff; color: white; padding: 2px 8px; border-radius: 12px; font-size: 12px;">
                                                    <?php echo $contacts_count; ?> 位聯絡人
                                                </span>
                                            </td>
                                            <?php if ($sample_contact): 
                                                // 填充空欄位以對齊表頭
                                                foreach ($sample_contact as $key => $value): 
                                                    if (in_array($key, ['user_id', 'user_name', 'user_username', 'contact_name', 'contact_username'])) continue;
                                            ?>
                                                <td>-</td>
                                            <?php endforeach; endif; ?>
                                            <td>-</td>
                                            <td onclick="event.stopPropagation();">
                                                <!-- 只显示删除按钮，移除编辑按钮 -->
                                            </td>
                                        </tr>
                                        
                                        <!-- 聯絡人子行 -->
                                        <?php foreach ($group['contacts'] as $contact): 
                                            $contact_id = $contact['id'] ?? $contact['contact_user_id'] ?? 0;
                                        ?>
                                        <tr class="contact-child-row" data-user-id="<?php echo $user_id; ?>">
                                            <td></td>
                                            <td></td>
                                            <td></td>
                                            <?php foreach ($contact as $key => $value): 
                                                if (in_array($key, ['user_id', 'user_name', 'user_username', 'contact_name', 'contact_username'])) continue;
                                            ?>
                                                <td>
                                                    <?php 
                                                    if (in_array($key, ['created_at', 'updated_at'])) {
                                                        echo formatDateTime($value);
                                                    } else {
                                                        echo htmlspecialchars($value ?? '未提供');
                                                    }
                                                    ?>
                                                </td>
                                            <?php endforeach; ?>
                                            <td><?php echo htmlspecialchars($contact['contact_name'] ?? $contact['contact_username'] ?? '未提供'); ?></td>
                                            <td onclick="event.stopPropagation();">
                                                <button class="btn-delete" onclick="deleteRecord('contact', <?php echo $contact_id; ?>)">
                                                    <i class="fas fa-trash"></i> 刪除
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                        <!-- 分頁控制 -->
                        <?php if (!empty($user_contacts)): ?>
                        <div class="pagination">
                            <div class="pagination-info">
                                <span>每頁顯示：</span>
                                <select id="itemsPerPage" onchange="changeItemsPerPage()">
                                    <option value="10" selected>10</option>
                                    <option value="20">20</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                    <option value="all">全部</option>
                                </select>
                                <span id="pageInfo">顯示第 <span id="currentRange">1-<?php echo min(10, count($user_contacts)); ?></span> 筆，共 <?php echo count($user_contacts); ?> 位用戶</span>
                            </div>
                            <div class="pagination-controls">
                                <button id="prevPage" onclick="changePage(-1)" disabled>上一頁</button>
                                <span id="pageNumbers"></span>
                                <button id="nextPage" onclick="changePage(1)">下一頁</button>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 群組成員標籤頁內容 -->
                <div class="tab-content <?php echo $active_tab === 'groups' ? 'active' : ''; ?>" id="tab-groups">
                    <div class="table-wrapper">
                        <div class="table-container">
                            <?php if (empty($group_chat_members)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-inbox fa-3x" style="margin-bottom: 16px;"></i>
                                    <p>目前尚無任何群組成員資料。</p>
                                </div>
                            <?php else: 
                                // 獲取第一個成員的欄位結構（用於表頭）
                                $sample_member = null;
                                foreach ($group_chat_members as $group) {
                                    if (!empty($group['members'])) {
                                        $sample_member = $group['members'][0];
                                        break;
                                    }
                                }
                            ?>
                                <table class="table" id="groupsTable">
                                    <thead>
                                        <tr>
                                            <th>群組ID</th>
                                            <th>群組名稱</th>
                                            <th>成員數量</th>
                                            <?php if ($sample_member): 
                                                foreach ($sample_member as $key => $value): 
                                                    if (in_array($key, ['group_id', 'group_name', 'group_description', 'user_name', 'user_username', 'user_email', 'user_role', 'user_status', 'user_table_id'])) continue;
                                            ?>
                                                <th>
                                                    <?php 
                                                    $column_names = [
                                                        'id' => 'ID',
                                                        'user_id' => '用戶ID',
                                                        'role' => '角色',
                                                        'joined_at' => '加入時間',
                                                        'created_at' => '建立時間',
                                                        'updated_at' => '更新時間'
                                                    ];
                                                    echo $column_names[$key] ?? $key; 
                                                    ?>
                                                </th>
                                            <?php endforeach; endif; ?>
                                            <th>用戶姓名</th>
                                            <th>用戶帳號</th>
                                            <th>用戶Email</th>
                                            <th>用戶角色</th>
                                            <th>操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($group_chat_members as $group): 
                                            $group_id = $group['group_id'];
                                            $members_count = count($group['members']);
                                        ?>
                                        <!-- 群組分組行 -->
                                        <tr class="table-row-clickable group-group-row" data-group-id="<?php echo $group_id; ?>" onclick="toggleGroupMembers(<?php echo $group_id; ?>)">
                                            <td>
                                                <span class="expand-icon">▶</span>
                                                <?php echo htmlspecialchars($group_id); ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($group['group_name']); ?></td>
                                            <td>
                                                <span style="background: #1890ff; color: white; padding: 2px 8px; border-radius: 12px; font-size: 12px;">
                                                    <?php echo $members_count; ?> 位成員
                                                </span>
                                            </td>
                                            <?php if ($sample_member): 
                                                // 填充空欄位以對齊表頭
                                                foreach ($sample_member as $key => $value): 
                                                    if (in_array($key, ['group_id', 'group_name', 'group_description', 'user_name', 'user_username', 'user_email', 'user_role', 'user_status', 'user_table_id'])) continue;
                                            ?>
                                                <td>-</td>
                                            <?php endforeach; endif; ?>
                                            <td>-</td>
                                            <td>-</td>
                                            <td>-</td>
                                            <td>-</td>
                                            <td onclick="event.stopPropagation();">
                                                <!-- 只显示删除按钮，移除编辑按钮 -->
                                            </td>
                                        </tr>
                                        
                                        <!-- 群組成員子行 -->
                                        <?php foreach ($group['members'] as $member): 
                                            $member_id = $member['id'] ?? $member['user_id'] ?? 0;
                                        ?>
                                        <tr class="member-child-row" data-group-id="<?php echo $group_id; ?>">
                                            <td></td>
                                            <td></td>
                                            <td></td>
                                            <?php foreach ($member as $key => $value): 
                                                if (in_array($key, ['group_id', 'group_name', 'group_description', 'user_name', 'user_username', 'user_email', 'user_role', 'user_status', 'user_table_id'])) continue;
                                            ?>
                                                <td>
                                                    <?php 
                                                    if (in_array($key, ['created_at', 'updated_at', 'joined_at'])) {
                                                        echo formatDateTime($value);
                                                    } else {
                                                        echo htmlspecialchars($value ?? '未提供');
                                                    }
                                                    ?>
                                                </td>
                                            <?php endforeach; ?>
                                            <td><?php echo htmlspecialchars($member['user_name'] ?? '未提供'); ?></td>
                                            <td><?php echo htmlspecialchars($member['user_username'] ?? '未提供'); ?></td>
                                            <td><?php echo htmlspecialchars($member['user_email'] ?? '未提供'); ?></td>
                                            <td><?php echo htmlspecialchars($member['user_role'] ?? '未提供'); ?></td>
                                            <td onclick="event.stopPropagation();">
                                                <button class="btn-delete" onclick="deleteRecord('group', <?php echo $member_id; ?>)">
                                                    <i class="fas fa-trash"></i> 刪除
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                        <!-- 分頁控制 -->
                        <?php if (!empty($group_chat_members)): ?>
                        <div class="pagination">
                            <div class="pagination-info">
                                <span>每頁顯示：</span>
                                <select id="itemsPerPage2" onchange="changeItemsPerPage()">
                                    <option value="10" selected>10</option>
                                    <option value="20">20</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                    <option value="all">全部</option>
                                </select>
                                <span id="pageInfo2">顯示第 <span id="currentRange2">1-<?php echo min(10, count($group_chat_members)); ?></span> 筆，共 <?php echo count($group_chat_members); ?> 個群組</span>
                            </div>
                            <div class="pagination-controls">
                                <button id="prevPage2" onclick="changePage(-1)" disabled>上一頁</button>
                                <span id="pageNumbers2"></span>
                                <button id="nextPage2" onclick="changePage(1)">下一頁</button>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 訊息提示框 -->
    <div id="toast" style="position: fixed; top: 20px; right: 20px; background-color: #333; color: white; padding: 15px 20px; border-radius: 8px; z-index: 9999; display: none; opacity: 0; transition: opacity 0.5s;"></div>

    <!-- 編輯模態視窗 -->
    <div id="editModal" class="edit-modal">
        <div class="edit-modal-content">
            <div class="edit-modal-header">
                <h3>編輯資料</h3>
                <span class="close" onclick="closeEditModal()" style="font-size: 24px; font-weight: bold; cursor: pointer; color: var(--text-secondary-color);">&times;</span>
            </div>
            <div class="edit-modal-body">
                <!-- 表單將由 JavaScript 動態生成 -->
            </div>
            <div class="edit-modal-footer">
                <button type="button" class="btn" onclick="closeEditModal()">取消</button>
                <button type="button" class="btn btn-primary" onclick="saveEdit()">儲存</button>
            </div>
        </div>
    </div>

    <script>
    // 分頁相關變數
    let currentPage = 1;
    let itemsPerPage = 10;
    let allRows = [];
    let filteredRows = [];

    // 標籤頁切換函數
    function switchTab(tab) {
        window.location.href = 'chat_B.php?tab=' + tab;
    }

    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('searchInput');
        const activeTab = '<?php echo $active_tab; ?>';
        const table = activeTab === 'contacts' ? document.getElementById('contactsTable') : document.getElementById('groupsTable');
        
        if (table) {
            const tbody = table.getElementsByTagName('tbody')[0];
            if (tbody) {
                // 根據當前標籤頁獲取對應的分組行
                const allTableRows = Array.from(tbody.getElementsByTagName('tr'));
                if (activeTab === 'contacts') {
                    // 只包含用戶分組行
                    allRows = allTableRows.filter(row => {
                        return row.classList.contains('user-group-row');
                    });
                } else {
                    // 只包含群組分組行
                    allRows = allTableRows.filter(row => {
                        return row.classList.contains('group-group-row');
                    });
                }
                // 確保 filteredRows 和 allRows 一致
                filteredRows = [...allRows];
            }
        }

        if (searchInput) {
            searchInput.addEventListener('keyup', function() {
                const filter = searchInput.value.toLowerCase();
                
                if (!table) return;
                
                const tbody = table.getElementsByTagName('tbody')[0];
                if (!tbody) return;
                
                // 根據當前標籤頁過濾
                if (activeTab === 'contacts') {
                    // 過濾用戶分組行
                    filteredRows = allRows.filter(row => {
                        if (!row.classList.contains('user-group-row')) {
                            return false;
                        }
                        const cells = row.getElementsByTagName('td');
                        for (let j = 0; j < cells.length; j++) {
                            const cellText = cells[j].textContent || cells[j].innerText;
                            if (cellText.toLowerCase().indexOf(filter) > -1) {
                                return true;
                            }
                        }
                        // 也檢查該用戶的聯絡人
                        const userId = row.getAttribute('data-user-id');
                        if (userId) {
                            const childRows = document.querySelectorAll(`tr.contact-child-row[data-user-id="${userId}"]`);
                            for (let childRow of childRows) {
                                const childCells = childRow.getElementsByTagName('td');
                                for (let j = 0; j < childCells.length; j++) {
                                    const cellText = childCells[j].textContent || childCells[j].innerText;
                                    if (cellText.toLowerCase().indexOf(filter) > -1) {
                                        return true;
                                    }
                                }
                            }
                        }
                        return false;
                    });
                } else {
                    // 過濾群組分組行
                    filteredRows = allRows.filter(row => {
                        if (!row.classList.contains('group-group-row')) {
                            return false;
                        }
                        const cells = row.getElementsByTagName('td');
                        for (let j = 0; j < cells.length; j++) {
                            const cellText = cells[j].textContent || cells[j].innerText;
                            if (cellText.toLowerCase().indexOf(filter) > -1) {
                                return true;
                            }
                        }
                        // 也檢查該群組的成員
                        const groupId = row.getAttribute('data-group-id');
                        if (groupId) {
                            const childRows = document.querySelectorAll(`tr.member-child-row[data-group-id="${groupId}"]`);
                            for (let childRow of childRows) {
                                const childCells = childRow.getElementsByTagName('td');
                                for (let j = 0; j < childCells.length; j++) {
                                    const cellText = childCells[j].textContent || childCells[j].innerText;
                                    if (cellText.toLowerCase().indexOf(filter) > -1) {
                                        return true;
                                    }
                                }
                            }
                        }
                        return false;
                    });
                }
                
                currentPage = 1;
                updatePagination();
            });
        }

        // 排序表格
        function sortTable(field) {
            let newSortOrder = 'asc';
            
            // 如果點擊的是當前排序欄位，則切換排序方向
            const urlParams = new URLSearchParams(window.location.search);
            const currentSortBy = urlParams.get('sort_by') || 'id';
            const currentSortOrder = urlParams.get('sort_order') || 'desc';
            const currentTab = urlParams.get('tab') || 'contacts';
            
            if (currentSortBy === field) {
                newSortOrder = currentSortOrder === 'asc' ? 'desc' : 'asc';
            }
            
            window.location.href = `chat_B.php?tab=${currentTab}&sort_by=${field}&sort_order=${newSortOrder}`;
        }
        
        // 更新排序圖標
        function updateSortIcons() {
            // 清除所有圖標
            const icons = document.querySelectorAll('.sort-icon');
            icons.forEach(icon => {
                icon.className = 'sort-icon';
            });
            
            // 獲取當前 URL 的排序參數
            const urlParams = new URLSearchParams(window.location.search);
            const currentSortBy = urlParams.get('sort_by') || 'created_at';
            const currentSortOrder = urlParams.get('sort_order') || 'desc';
            
            // 設置當前排序欄位的圖標
            const currentIcon = document.getElementById(`sort-${currentSortBy}`);
            if (currentIcon) {
                currentIcon.className = `sort-icon active ${currentSortOrder}`;
            }
        }
        
        // 展開/收合用戶的聯絡人（同時只允許一個展開）
        function toggleUserContacts(userId) {
            const groupRow = document.querySelector(`tr.user-group-row[data-user-id="${userId}"]`);
            if (!groupRow) return;
            
            const childRows = document.querySelectorAll(`tr.contact-child-row[data-user-id="${userId}"]`);
            const isExpanded = groupRow.classList.contains('expanded');
            
            if (isExpanded) {
                // 收起當前展開的
                groupRow.classList.remove('expanded');
                childRows.forEach(row => {
                    row.classList.remove('show');
                });
            } else {
                // 先收起所有其他展開的用戶分組
                const allGroupRows = document.querySelectorAll('tr.user-group-row.expanded');
                allGroupRows.forEach(row => {
                    const otherUserId = row.getAttribute('data-user-id');
                    if (otherUserId && otherUserId !== userId.toString()) {
                        row.classList.remove('expanded');
                        const otherChildRows = document.querySelectorAll(`tr.contact-child-row[data-user-id="${otherUserId}"]`);
                        otherChildRows.forEach(childRow => {
                            childRow.classList.remove('show');
                        });
                    }
                });
                
                // 展開當前點擊的
                groupRow.classList.add('expanded');
                childRows.forEach(row => {
                    row.classList.add('show');
                });
            }
        }
        
        // 展開/收合詳細資訊（保留原有功能）
        function toggleDetail(id) {
            const detailRow = document.getElementById('detail-' + id);
            if (detailRow) {
                if (detailRow.style.display === 'none' || detailRow.style.display === '') {
                    detailRow.style.display = 'table-row';
                } else {
                    detailRow.style.display = 'none';
                }
            }
        }
        
        // 展開/收合群組的成員（同時只允許一個展開）
        function toggleGroupMembers(groupId) {
            const groupRow = document.querySelector(`tr.group-group-row[data-group-id="${groupId}"]`);
            if (!groupRow) return;
            
            const childRows = document.querySelectorAll(`tr.member-child-row[data-group-id="${groupId}"]`);
            const isExpanded = groupRow.classList.contains('expanded');
            
            if (isExpanded) {
                // 收起當前展開的
                groupRow.classList.remove('expanded');
                childRows.forEach(row => {
                    row.classList.remove('show');
                });
            } else {
                // 先收起所有其他展開的群組分組
                const allGroupRows = document.querySelectorAll('tr.group-group-row.expanded');
                allGroupRows.forEach(row => {
                    const otherGroupId = row.getAttribute('data-group-id');
                    if (otherGroupId && otherGroupId !== groupId.toString()) {
                        row.classList.remove('expanded');
                        const otherChildRows = document.querySelectorAll(`tr.member-child-row[data-group-id="${otherGroupId}"]`);
                        otherChildRows.forEach(childRow => {
                            childRow.classList.remove('show');
                        });
                    }
                });
                
                // 展開當前點擊的
                groupRow.classList.add('expanded');
                childRows.forEach(row => {
                    row.classList.add('show');
                });
            }
        }
        
        // 將函數暴露到全局作用域
        window.sortTable = sortTable;
        window.toggleDetail = toggleDetail;
        window.toggleUserContacts = toggleUserContacts;
        window.toggleGroupMembers = toggleGroupMembers;
        
        // 更新排序圖標
        updateSortIcons();
        
        // 初始化分頁
        initPagination();
    });

    // 開啟編輯模態視窗
    function openEditModal(type, item) {
        const modal = document.getElementById('editModal');
        const modalBody = document.querySelector('.edit-modal-body');
        const modalHeader = document.querySelector('.edit-modal-header h3');
        
        // 設置標題
        modalHeader.textContent = type === 'contact' ? '編輯聯絡人資料' : '編輯群組成員資料';
        
        // 動態生成表單
        let formHTML = '<form id="editForm"><input type="hidden" id="edit_id" name="id" value="' + (item.id || '') + '">';
        formHTML += '<input type="hidden" id="edit_type" name="type" value="' + type + '">';
        
        // 根據類型生成不同的表單欄位
        if (type === 'contact') {
            formHTML += '<div class="edit-form-row"><div class="edit-form-group"><label>ID</label><input type="text" id="edit_id_display" value="' + (item.id || '') + '" disabled></div>';
            formHTML += '<div class="edit-form-group"><label>用戶ID</label><input type="number" id="edit_user_id" name="user_id" value="' + (item.user_id || '') + '"></div></div>';
            formHTML += '<div class="edit-form-row"><div class="edit-form-group"><label>聯絡人ID</label><input type="number" id="edit_contact_user_id" name="contact_user_id" value="' + (item.contact_user_id || '') + '"></div>';
            if (item.email !== undefined) {
                formHTML += '<div class="edit-form-group"><label>Email</label><input type="email" id="edit_email" name="email" value="' + (item.email || '') + '"></div></div>';
            }
            if (item.phone !== undefined) {
                formHTML += '<div class="edit-form-row"><div class="edit-form-group"><label>電話</label><input type="text" id="edit_phone" name="phone" value="' + (item.phone || '') + '"></div></div>';
            }
        } else {
            formHTML += '<div class="edit-form-row"><div class="edit-form-group"><label>ID</label><input type="text" id="edit_id_display" value="' + (item.id || '') + '" disabled></div>';
            formHTML += '<div class="edit-form-group"><label>群組ID</label><input type="number" id="edit_group_id" name="group_id" value="' + (item.group_id || '') + '"></div></div>';
            formHTML += '<div class="edit-form-row"><div class="edit-form-group"><label>用戶ID</label><input type="number" id="edit_user_id" name="user_id" value="' + (item.user_id || '') + '"></div>';
            if (item.role !== undefined) {
                formHTML += '<div class="edit-form-group"><label>角色</label><input type="text" id="edit_role" name="role" value="' + (item.role || '') + '"></div></div>';
            }
            if (item.joined_at !== undefined) {
                formHTML += '<div class="edit-form-row"><div class="edit-form-group"><label>加入時間</label><input type="datetime-local" id="edit_joined_at" name="joined_at" value="' + (item.joined_at ? item.joined_at.replace(' ', 'T').substring(0, 16) : '') + '"></div></div>';
            }
        }
        
        formHTML += '<div class="edit-form-row"><div class="edit-form-group"><label>建立時間</label><input type="text" id="edit_created_at" value="' + (item.created_at ? new Date(item.created_at).toLocaleString('zh-TW') : '') + '" disabled></div></div>';
        formHTML += '</form>';
        
        modalBody.innerHTML = formHTML;
        modal.style.display = 'flex';
    }

    // 關閉編輯模態視窗
    function closeEditModal() {
        document.getElementById('editModal').style.display = 'none';
    }

    // 刪除記錄
    function deleteRecord(type, id) {
        if (!confirm('確定要刪除此記錄嗎？此操作無法復原。')) {
            return;
        }
        
        fetch('update_chat_data.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                type: type,
                id: id,
                action: 'delete'
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('刪除成功', 'success');
                setTimeout(() => {
                    location.reload();
                }, 1000);
            } else {
                showToast('刪除失敗：' + (data.message || '未知錯誤'), 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('刪除失敗，請稍後再試', 'error');
        });
    }

    // 儲存編輯
    function saveEdit() {
        const type = document.getElementById('edit_type').value;
        const id = document.getElementById('edit_id').value;
        
        // 根據類型構建表單數據
        const formData = {
            type: type,
            id: id
        };
        
        if (type === 'contact') {
            formData.user_id = document.getElementById('edit_user_id').value;
            formData.contact_user_id = document.getElementById('edit_contact_user_id').value;
            if (document.getElementById('edit_email')) {
                formData.email = document.getElementById('edit_email').value;
            }
            if (document.getElementById('edit_phone')) {
                formData.phone = document.getElementById('edit_phone').value;
            }
        } else {
            formData.group_id = document.getElementById('edit_group_id').value;
            formData.user_id = document.getElementById('edit_user_id').value;
            if (document.getElementById('edit_role')) {
                formData.role = document.getElementById('edit_role').value;
            }
            if (document.getElementById('edit_joined_at')) {
                formData.joined_at = document.getElementById('edit_joined_at').value;
            }
        }

        // 發送更新請求
        fetch('update_chat_data.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(formData)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('更新成功', 'success');
                setTimeout(() => {
                    location.reload();
                }, 1000);
            } else {
                showToast('更新失敗：' + (data.message || '未知錯誤'), 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('更新失敗，請稍後再試', 'error');
        });
    }

    // 點擊模態視窗外部關閉
    document.getElementById('editModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeEditModal();
        }
    });

    // 顯示提示訊息
    function showToast(message, type) {
        const toast = document.getElementById('toast');
        if (!toast) {
            // 如果沒有 toast 元素，創建一個
            const toastDiv = document.createElement('div');
            toastDiv.id = 'toast';
            toastDiv.style.cssText = 'position: fixed; top: 20px; right: 20px; background-color: #333; color: white; padding: 15px 20px; border-radius: 8px; z-index: 9999; display: block; opacity: 0; transition: opacity 0.5s;';
            document.body.appendChild(toastDiv);
        }
        const toastElement = document.getElementById('toast');
        toastElement.textContent = message;
        if (type === 'success') {
            toastElement.style.backgroundColor = '#52c41a';
        } else if (type === 'info') {
            toastElement.style.backgroundColor = '#1890ff';
        } else {
            toastElement.style.backgroundColor = '#ff4d4f';
        }
        toastElement.style.opacity = '1';
        toastElement.style.display = 'block';
        
        setTimeout(() => {
            toastElement.style.opacity = '0';
            setTimeout(() => {
                toastElement.style.display = 'none';
            }, 500);
        }, 3000);
    }

    // 分頁功能
    function initPagination() {
        // 根據當前標籤頁過濾
        const activeTab = '<?php echo $active_tab; ?>';
        if (activeTab === 'contacts') {
            filteredRows = allRows.filter(row => {
                return row.classList.contains('user-group-row');
            });
        } else {
            filteredRows = allRows.filter(row => {
                return row.classList.contains('group-group-row');
            });
        }
        
        // 確保 itemsPerPage 從 select 元素獲取正確的值
        const select = document.getElementById('itemsPerPage');
        if (select) {
            if (select.value === 'all') {
                itemsPerPage = filteredRows.length;
            } else {
                itemsPerPage = parseInt(select.value) || 10;
            }
        }
        currentPage = 1;
        updatePagination();
    }

    function changeItemsPerPage() {
        const activeTab = '<?php echo $active_tab; ?>';
        const select = activeTab === 'contacts' ? document.getElementById('itemsPerPage') : document.getElementById('itemsPerPage2');
        
        // 根據當前標籤頁過濾
        if (activeTab === 'contacts') {
            filteredRows = allRows.filter(row => {
                return row.classList.contains('user-group-row');
            });
        } else {
            filteredRows = allRows.filter(row => {
                return row.classList.contains('group-group-row');
            });
        }
        
        if (select) {
            itemsPerPage = select.value === 'all' ? filteredRows.length : parseInt(select.value);
        }
        currentPage = 1;
        updatePagination();
    }

    function changePage(direction) {
        const totalItems = filteredRows.length;
        // 確保 itemsPerPage 是數字
        const itemsPerPageNum = typeof itemsPerPage === 'number' ? itemsPerPage : parseInt(itemsPerPage) || 10;
        // 只有當資料筆數大於每頁顯示筆數時，才需要多頁
        // 例如：10筆/頁，11筆資料才會有第2頁
        const totalPages = totalItems > itemsPerPageNum ? Math.ceil(totalItems / itemsPerPageNum) : 1;
        
        currentPage += direction;
        
        if (currentPage < 1) currentPage = 1;
        if (currentPage > totalPages) currentPage = totalPages;
        
        updatePagination();
    }

    function updatePagination() {
        const activeTab = '<?php echo $active_tab; ?>';
        // 根據當前標籤頁過濾
        if (activeTab === 'contacts') {
            filteredRows = allRows.filter(row => {
                return row.classList.contains('user-group-row');
            });
        } else {
            filteredRows = allRows.filter(row => {
                return row.classList.contains('group-group-row');
            });
        }
        
        const totalItems = filteredRows.length;
        
        // 確保 itemsPerPage 是數字，並從 select 元素獲取最新值（根據當前標籤頁）
        const select = activeTab === 'contacts' ? document.getElementById('itemsPerPage') : document.getElementById('itemsPerPage2');
        let itemsPerPageNum = 10;
        if (select) {
            if (select.value === 'all') {
                itemsPerPageNum = totalItems;
            } else {
                itemsPerPageNum = parseInt(select.value) || 10;
            }
        } else {
            itemsPerPageNum = typeof itemsPerPage === 'number' ? itemsPerPage : parseInt(itemsPerPage) || 10;
        }
        
        // 只有當資料筆數大於每頁顯示筆數時，才需要多頁
        // 例如：10筆/頁，11筆資料才會有第2頁
        // 如果 totalItems <= itemsPerPageNum，則只有1頁
        const totalPages = totalItems > itemsPerPageNum ? Math.ceil(totalItems / itemsPerPageNum) : 1;
        
        // 確保當前頁不超過總頁數
        if (currentPage > totalPages && totalPages > 0) {
            currentPage = totalPages;
        }
        if (currentPage < 1) {
            currentPage = 1;
        }
        
        // 更新顯示範圍 - 修正計算邏輯
        let start, end;
        if (totalItems === 0) {
            start = 0;
            end = 0;
        } else {
            // 無論如何，end 都不能超過 totalItems
            if (totalPages <= 1 || itemsPerPageNum >= totalItems) {
                // 只有一頁或選擇全部，顯示所有數據
                start = 1;
                end = totalItems; // 確保 end 等於 totalItems，不會超過
            } else {
                // 多頁情況
                start = (currentPage - 1) * itemsPerPageNum + 1;
                end = Math.min(currentPage * itemsPerPageNum, totalItems);
            }
            // 最終確保 end 不會超過 totalItems
            end = Math.min(end, totalItems);
        }
        
        // 更新顯示範圍（根據當前標籤頁）
        const currentRangeEl = activeTab === 'contacts' ? document.getElementById('currentRange') : document.getElementById('currentRange2');
        if (currentRangeEl) {
            currentRangeEl.textContent = `${start}-${end}`;
        }
        
        // 更新按鈕狀態
        const prevBtn = document.getElementById('prevPage');
        const nextBtn = document.getElementById('nextPage');
        const prevBtn2 = document.getElementById('prevPage2');
        const nextBtn2 = document.getElementById('nextPage2');
        
        const targetPrevBtn = activeTab === 'contacts' ? prevBtn : prevBtn2;
        const targetNextBtn = activeTab === 'contacts' ? nextBtn : nextBtn2;
        
        if (targetPrevBtn) targetPrevBtn.disabled = currentPage === 1;
        if (targetNextBtn) targetNextBtn.disabled = currentPage >= totalPages || totalPages <= 1;
        
        // 更新頁碼 - 先清空所有按鈕
        const pageNumbers = document.getElementById('pageNumbers');
        const pageNumbers2 = document.getElementById('pageNumbers2');
        const targetPageNumbers = activeTab === 'contacts' ? pageNumbers : pageNumbers2;
        
        if (targetPageNumbers) {
            targetPageNumbers.innerHTML = '';
            
            // 總是顯示頁碼按鈕（即使只有1頁）
            if (totalPages >= 1) {
                // 如果只有1頁，只顯示"1"
                // 如果有多頁，顯示所有頁碼
                const pagesToShow = totalPages === 1 ? [1] : Array.from({length: totalPages}, (_, i) => i + 1);
                
                for (let i of pagesToShow) {
                    const button = document.createElement('button');
                    button.textContent = i;
                    button.className = i === currentPage ? 'active' : '';
                    button.onclick = () => {
                        currentPage = i;
                        updatePagination();
                    };
                    targetPageNumbers.appendChild(button);
                }
            }
        }
        
        // 顯示/隱藏行（無論是否有分頁都要執行）
        allRows.forEach((row, index) => {
            if (filteredRows.includes(row)) {
                const rowIndex = filteredRows.indexOf(row);
                // 如果只有一頁或選擇全部，顯示所有行
                const shouldShow = (totalPages <= 1 || itemsPerPageNum >= totalItems) 
                    ? true 
                    : (rowIndex >= (currentPage - 1) * itemsPerPageNum && rowIndex < currentPage * itemsPerPageNum);
                row.style.display = shouldShow ? '' : 'none';
                
                // 根據當前標籤頁隱藏子行
                if (!shouldShow) {
                    const activeTab = '<?php echo $active_tab; ?>';
                    if (activeTab === 'contacts') {
                        const userId = row.getAttribute('data-user-id');
                        if (userId) {
                            const childRows = document.querySelectorAll(`tr.contact-child-row[data-user-id="${userId}"]`);
                            childRows.forEach(childRow => {
                                childRow.style.display = 'none';
                            });
                        }
                    } else {
                        const groupId = row.getAttribute('data-group-id');
                        if (groupId) {
                            const childRows = document.querySelectorAll(`tr.member-child-row[data-group-id="${groupId}"]`);
                            childRows.forEach(childRow => {
                                childRow.style.display = 'none';
                            });
                        }
                    }
                }
            } else {
                row.style.display = 'none';
                // 根據當前標籤頁隱藏子行
                const activeTab = '<?php echo $active_tab; ?>';
                if (activeTab === 'contacts') {
                    const userId = row.getAttribute('data-user-id');
                    if (userId) {
                        const childRows = document.querySelectorAll(`tr.contact-child-row[data-user-id="${userId}"]`);
                        childRows.forEach(childRow => {
                            childRow.style.display = 'none';
                        });
                    }
                } else {
                    const groupId = row.getAttribute('data-group-id');
                    if (groupId) {
                        const childRows = document.querySelectorAll(`tr.member-child-row[data-group-id="${groupId}"]`);
                        childRows.forEach(childRow => {
                            childRow.style.display = 'none';
                        });
                    }
                }
            }
        });
    }
    </script>
</body>
</html>
