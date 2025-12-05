<?php
session_start();

// 檢查是否已登入
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: login.php");
    exit;
}

// 檢查是否為管理員
$user_role = $_SESSION['role'] ?? '';
$is_admin = ($user_role === 'ADM');

if (!$is_admin) {
    header("Location: index.php");
    exit;
}

// 引入資料庫設定
require_once '../../Topics-frontend/frontend/config.php';

// 設置頁面標題
$page_title = '學長姐留言管理';

// 建立資料庫連接
$conn = getDatabaseConnection();

// 檢查表是否存在（不創建表，只檢查）
function tableExists($conn, $tableName) {
    $result = $conn->query("SHOW TABLES LIKE '$tableName'");
    return $result && $result->num_rows > 0;
}

// 檢查欄位是否存在
function columnExists($conn, $tableName, $columnName) {
    $result = $conn->query("SHOW COLUMNS FROM $tableName LIKE '$columnName'");
    return $result && $result->num_rows > 0;
}

// 獲取表的所有欄位
function getTableColumns($conn, $tableName) {
    $columns = [];
    $result = $conn->query("SHOW COLUMNS FROM $tableName");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $columns[] = $row['Field'];
        }
    }
    return $columns;
}

// 檢查必要的表是否存在（檢查多個可能的表名）
$senior_messages_exists = tableExists($conn, 'senior_messages');
// 檢查可能的留言表名稱
$comments_table_name = null;
$possible_comment_tables = ['senior_message_comments', 'senior_comments', 'message_comments'];
foreach ($possible_comment_tables as $table_name) {
    if (tableExists($conn, $table_name)) {
        $comments_table_name = $table_name;
        break;
    }
}
$senior_comments_exists = ($comments_table_name !== null);

// 如果主要表不存在，顯示錯誤並停止執行
if (!$senior_messages_exists) {
    die("錯誤：資料庫中不存在 senior_messages 表。請先確認資料庫結構。");
}

// 獲取 senior_messages 表的實際欄位
$messages_columns = getTableColumns($conn, 'senior_messages');
$has_is_hidden = in_array('is_hidden', $messages_columns);
$has_is_published = in_array('is_published', $messages_columns);
// 如果沒有 is_hidden 但有 is_published，使用 is_published 來實現隱藏功能
$use_published_for_hide = (!$has_is_hidden && $has_is_published);
$has_is_system_announcement = in_array('is_system_announcement', $messages_columns);
$has_admin_edited = in_array('admin_edited', $messages_columns);
$has_edited_at = in_array('edited_at', $messages_columns);

// 獲取留言表的實際欄位（如果表存在）
$comments_foreign_key = null; // 用於關聯到 senior_messages 的欄位名稱
if ($senior_comments_exists && $comments_table_name) {
    $comments_columns = getTableColumns($conn, $comments_table_name);
    // 檢查可能的關聯欄位名稱（優先檢查 message_id）
    $possible_keys = ['message_id', 'post_id', 'senior_message_id', 'parent_id'];
    foreach ($possible_keys as $key) {
        if (in_array($key, $comments_columns)) {
            $comments_foreign_key = $key;
            break;
        }
    }
    // 如果找不到常見的關聯欄位，嘗試查找包含 'id' 或 'message' 的欄位
    if (!$comments_foreign_key) {
        foreach ($comments_columns as $col) {
            if (stripos($col, 'message') !== false || (stripos($col, 'post') !== false && stripos($col, 'id') !== false)) {
                $comments_foreign_key = $col;
                break;
            }
        }
    }
}

// 處理各種操作
$message = '';
$message_type = '';

// 處理刪除貼文
if (isset($_POST['action']) && $_POST['action'] === 'delete_post') {
    $post_id = intval($_POST['post_id'] ?? 0);
    if ($post_id > 0) {
        try {
            // 先刪除相關留言（如果表存在且有關聯欄位）
            if ($senior_comments_exists && $comments_table_name && $comments_foreign_key) {
                $stmt = $conn->prepare("DELETE FROM $comments_table_name WHERE $comments_foreign_key = ?");
                $stmt->bind_param("i", $post_id);
                $stmt->execute();
            }
            
            // 再刪除貼文
            $stmt = $conn->prepare("DELETE FROM senior_messages WHERE id = ?");
            $stmt->bind_param("i", $post_id);
            if ($stmt->execute()) {
                $message = '貼文已成功刪除';
                $message_type = 'success';
            } else {
                $message = '刪除失敗：' . $conn->error;
                $message_type = 'error';
            }
        } catch (Exception $e) {
            $message = '刪除失敗：' . $e->getMessage();
            $message_type = 'error';
        }
    }
}

// 處理隱藏/顯示貼文
if (isset($_POST['action']) && $_POST['action'] === 'toggle_hide') {
    $post_id = intval($_POST['post_id'] ?? 0);
    $is_hidden = intval($_POST['is_hidden'] ?? 0);
    
    if ($post_id > 0) {
        try {
            // 如果有 is_hidden 欄位，直接使用
            if ($has_is_hidden) {
                $stmt = $conn->prepare("UPDATE senior_messages SET is_hidden = ? WHERE id = ?");
                $stmt->bind_param("ii", $is_hidden, $post_id);
            } 
            // 如果沒有 is_hidden 但有 is_published，使用 is_published（0=隱藏，1=顯示）
            elseif ($use_published_for_hide) {
                $published_value = $is_hidden ? 0 : 1; // 隱藏時設為0，顯示時設為1
                $stmt = $conn->prepare("UPDATE senior_messages SET is_published = ? WHERE id = ?");
                $stmt->bind_param("ii", $published_value, $post_id);
            } else {
                $message = '此資料表不支援隱藏功能';
                $message_type = 'error';
                $post_id = 0; // 阻止執行
            }
            
            if ($post_id > 0 && $stmt->execute()) {
                // 確認更新是否成功
                $affected_rows = $stmt->affected_rows;
                if ($affected_rows > 0) {
                    // 確認更新後的值
                    $check_stmt = $conn->prepare("SELECT is_published FROM senior_messages WHERE id = ?");
                    $check_stmt->bind_param("i", $post_id);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    $check_row = $check_result->fetch_assoc();
                    $current_value = $check_row['is_published'] ?? 'NULL';
                    
                    if ($use_published_for_hide) {
                        $message = $is_hidden 
                            ? "貼文已隱藏（is_published 已設為 0，當前值：$current_value）" 
                            : "貼文已顯示（is_published 已設為 1，當前值：$current_value）";
                    } else {
                        $message = $is_hidden ? '貼文已隱藏' : '貼文已顯示';
                    }
                    $message_type = 'success';
                    
                    // 重要提示：前台頁面需要過濾隱藏的貼文
                    if ($is_hidden && $use_published_for_hide) {
                        $message .= '。注意：前台頁面需要在查詢時添加 WHERE is_published = 1 條件才能隱藏貼文。';
                    }
                } else {
                    $message = '操作失敗：沒有資料被更新（可能 ID 不存在或值沒有改變）';
                    $message_type = 'error';
                }
            } elseif ($post_id > 0) {
                $message = '操作失敗：' . $conn->error;
                $message_type = 'error';
            }
        } catch (Exception $e) {
            $message = '操作失敗：' . $e->getMessage();
            $message_type = 'error';
            error_log("隱藏貼文錯誤: " . $e->getMessage());
        }
    }
}

// 處理強制編輯
if (isset($_POST['action']) && $_POST['action'] === 'force_edit') {
    $post_id = intval($_POST['post_id'] ?? 0);
    $content = $_POST['content'] ?? '';
    if ($post_id > 0 && !empty($content)) {
        try {
            // 根據實際存在的欄位構建 UPDATE 語句
            $update_fields = ["content = ?"];
            $params = [$content];
            $param_types = "s";
            
            if ($has_admin_edited) {
                $update_fields[] = "admin_edited = 1";
            }
            if ($has_edited_at) {
                $update_fields[] = "edited_at = NOW()";
            }
            
            $sql = "UPDATE senior_messages SET " . implode(", ", $update_fields) . " WHERE id = ?";
            $params[] = $post_id;
            $param_types .= "i";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($param_types, ...$params);
            if ($stmt->execute()) {
                $message = '貼文已成功編輯';
                $message_type = 'success';
            } else {
                $message = '編輯失敗：' . $conn->error;
                $message_type = 'error';
            }
        } catch (Exception $e) {
            $message = '編輯失敗：' . $e->getMessage();
            $message_type = 'error';
        }
    }
}


// 處理系統公告推送
if (isset($_POST['action']) && $_POST['action'] === 'send_announcement') {
    $announcement_content = $_POST['announcement_content'] ?? '';
    $announcement_title = $_POST['announcement_title'] ?? '系統公告';
    if (!empty($announcement_content)) {
        try {
            $admin_user_id = $_SESSION['user_id'] ?? 0;
            
            // 根據實際存在的欄位構建 INSERT 語句
            $fields = ["user_id", "content", "created_at"];
            $values = ["?", "?", "NOW()"];
            $params = [$admin_user_id, $announcement_content];
            $param_types = "is";
            
            // 檢查是否有 title 欄位
            if (in_array('title', $messages_columns)) {
                $fields[] = "title";
                $values[] = "?";
                $params[] = $announcement_title;
                $param_types .= "s";
            }
            
            // 檢查是否有 is_system_announcement 欄位
            if ($has_is_system_announcement) {
                $fields[] = "is_system_announcement";
                $values[] = "1";
            }
            
            $sql = "INSERT INTO senior_messages (" . implode(", ", $fields) . ") VALUES (" . implode(", ", $values) . ")";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($param_types, ...$params);
            if ($stmt->execute()) {
                $message = '系統公告已成功推送';
                $message_type = 'success';
            } else {
                $message = '推送失敗：' . $conn->error;
                $message_type = 'error';
            }
        } catch (Exception $e) {
            $message = '推送失敗：' . $e->getMessage();
            $message_type = 'error';
        }
    }
}

// 獲取搜尋參數
$search_keyword = $_GET['keyword'] ?? '';
$search_user = $_GET['user'] ?? '';
$search_date_from = $_GET['date_from'] ?? '';
$search_date_to = $_GET['date_to'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;

// 構建查詢條件
$where_conditions = [];
$params = [];
$param_types = '';

if (!empty($search_keyword)) {
    $where_conditions[] = "(sm.content LIKE ? OR sm.title LIKE ?)";
    $keyword_param = "%{$search_keyword}%";
    $params[] = $keyword_param;
    $params[] = $keyword_param;
    $param_types .= 'ss';
}

if (!empty($search_user)) {
    $where_conditions[] = "(u.username LIKE ? OR u.name LIKE ?)";
    $user_param = "%{$search_user}%";
    $params[] = $user_param;
    $params[] = $user_param;
    $param_types .= 'ss';
}

if (!empty($search_date_from)) {
    $where_conditions[] = "DATE(sm.created_at) >= ?";
    $params[] = $search_date_from;
    $param_types .= 's';
}

if (!empty($search_date_to)) {
    $where_conditions[] = "DATE(sm.created_at) <= ?";
    $params[] = $search_date_to;
    $param_types .= 's';
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// 獲取總數（需要複製參數，因為後面還要使用）
$count_params = $params;
$count_param_types = $param_types;
$count_sql = "SELECT COUNT(*) as total FROM senior_messages sm 
              LEFT JOIN user u ON sm.user_id = u.id 
              $where_clause";
$count_stmt = $conn->prepare($count_sql);
if (!empty($count_params)) {
    $count_stmt->bind_param($count_param_types, ...$count_params);
}
$count_stmt->execute();
$total_result = $count_stmt->get_result();
$total_posts = $total_result->fetch_assoc()['total'] ?? 0;
$total_pages = ceil($total_posts / $per_page);

// 獲取貼文列表
$offset = ($page - 1) * $per_page;
// 根據留言表是否存在且有正確的關聯欄位，決定是否查詢留言數
$comment_count_sql = ($senior_comments_exists && $comments_table_name && $comments_foreign_key) 
    ? "(SELECT COUNT(*) FROM $comments_table_name sc WHERE sc.$comments_foreign_key = sm.id)" 
    : "0";

$sql = "SELECT sm.*, 
               u.username, u.name as user_name,
               $comment_count_sql as comment_count
        FROM senior_messages sm 
        LEFT JOIN user u ON sm.user_id = u.id 
        $where_clause
        ORDER BY sm.created_at DESC 
        LIMIT ? OFFSET ?";

// 調試信息（如果需要可以取消註釋來查看實際使用的表和欄位）
// if ($senior_comments_exists) {
//     error_log("留言表名稱: " . ($comments_table_name ?? '未找到'));
//     error_log("關聯欄位: " . ($comments_foreign_key ?? '未找到'));
//     if ($comments_foreign_key && $comments_table_name) {
//         error_log("查詢留言數的 SQL: $comment_count_sql");
//     }
// }

// 為列表查詢添加 LIMIT 和 OFFSET 參數
$list_params = $params;
$list_params[] = $per_page;
$list_params[] = $offset;
$list_param_types = $param_types . 'ii';

$stmt = $conn->prepare($sql);
if (!empty($list_param_types)) {
    $stmt->bind_param($list_param_types, ...$list_params);
}
$stmt->execute();
$posts_result = $stmt->get_result();
$posts = [];
$posts_data = []; // 用於 JavaScript 的數據
while ($row = $posts_result->fetch_assoc()) {
    $posts[] = $row;
    // 準備用於 JavaScript 的數據
    $posts_data[$row['id']] = [
        'id' => $row['id'],
        'title' => $row['title'] ?? '無標題',
        'content' => $row['content'],
        'user_name' => $row['user_name'] ?? $row['username'] ?? '未知',
        'created_at' => date('Y-m-d H:i:s', strtotime($row['created_at'])),
        'comment_count' => $row['comment_count'] ?? 0
    ];
}

// 獲取統計數據
$comments_count_sql = ($senior_comments_exists && $comments_table_name && $comments_foreign_key) 
    ? "SUM((SELECT COUNT(*) FROM $comments_table_name sc WHERE sc.$comments_foreign_key = sm.id))" 
    : "0";

// 根據實際存在的欄位構建統計查詢
$stats_fields = ["COUNT(*) as total_posts", "$comments_count_sql as total_comments"];

// 統計隱藏貼文數
if ($has_is_hidden) {
    $stats_fields[] = "COUNT(CASE WHEN sm.is_hidden = 1 THEN 1 END) as hidden_posts";
} elseif ($use_published_for_hide) {
    $stats_fields[] = "COUNT(CASE WHEN sm.is_published = 0 THEN 1 END) as hidden_posts";
} else {
    $stats_fields[] = "0 as hidden_posts";
}


$stats_sql = "SELECT " . implode(", ", $stats_fields) . " FROM senior_messages sm";
$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - Topics 後台管理系統</title>
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
        
        .dashboard {
            display: flex;
            min-height: 100vh;
        }
        
        .content {
            padding: 24px;
        }
        
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
        
        /* 統計卡片 */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: #fff;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            border: 1px solid #f0f0f0;
        }
        
        .stat-card-title {
            font-size: 14px;
            color: #8c8c8c;
            margin-bottom: 8px;
        }
        
        .stat-card-value {
            font-size: 28px;
            font-weight: 600;
            color: #262626;
        }
        
        .stat-card-icon {
            font-size: 32px;
            color: #1890ff;
            margin-bottom: 8px;
        }
        
        /* 搜尋區域 */
        .search-container {
            background: #fff;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            border: 1px solid #f0f0f0;
        }
        
        .search-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            align-items: end;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-group label {
            font-size: 14px;
            color: #262626;
            margin-bottom: 8px;
            font-weight: 500;
        }
        
        .form-group input, .form-group select {
            padding: 8px 12px;
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: #1890ff;
            box-shadow: 0 0 0 2px rgba(24,144,255,0.2);
        }
        
        .search-buttons {
            display: flex;
            gap: 8px;
        }
        
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
        }
        
        .btn-danger {
            background: #ff4d4f;
            color: white;
            border-color: #ff4d4f;
        }
        
        .btn-danger:hover {
            background: #ff7875;
            border-color: #ff7875;
        }
        
        .btn-warning {
            background: #faad14;
            color: white;
            border-color: #faad14;
        }
        
        .btn-warning:hover {
            background: #ffc53d;
            border-color: #ffc53d;
        }
        
        /* 表格區域 */
        .table-container {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            overflow: hidden;
            border: 1px solid #f0f0f0;
        }
        
        .table-header {
            padding: 20px 24px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .table-title {
            font-size: 18px;
            font-weight: 600;
            color: #262626;
        }
        
        .posts-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .posts-table th {
            background: #fafafa;
            padding: 16px 24px;
            text-align: left;
            font-weight: 600;
            color: #262626;
            border-bottom: 1px solid #f0f0f0;
            font-size: 14px;
        }
        
        .posts-table td {
            padding: 16px 24px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 14px;
            color: #595959;
        }
        
        .posts-table tr:hover {
            background: #fafafa;
        }
        
        .post-content {
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .badge-hidden {
            background: #fff2f0;
            color: #ff4d4f;
        }
        
        .badge-system {
            background: #e6f7ff;
            color: #1890ff;
        }
        
        .action-buttons {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        
        .btn-small {
            padding: 4px 8px;
            font-size: 12px;
        }
        
        .message {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 16px;
            font-weight: 500;
            font-size: 14px;
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
        
        /* 分頁 */
        .pagination {
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 1px solid #f0f0f0;
            background: #fafafa;
        }
        
        .pagination-info {
            color: #8c8c8c;
            font-size: 14px;
        }
        
        .pagination-controls {
            display: flex;
            gap: 8px;
        }
        
        .pagination button {
            padding: 6px 12px;
            border: 1px solid #d9d9d9;
            background: #fff;
            color: #595959;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
        }
        
        .pagination button:hover:not(:disabled) {
            border-color: #1890ff;
            color: #1890ff;
        }
        
        .pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* 模態對話框 */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.45);
        }
        
        .modal-content {
            background-color: #fff;
            margin: 5% auto;
            padding: 0;
            border-radius: 8px;
            width: 90%;
            max-width: 600px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-title {
            font-size: 18px;
            font-weight: 600;
            color: #262626;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #8c8c8c;
        }
        
        .modal-body {
            padding: 24px;
        }
        
        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid #f0f0f0;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }
        
        .form-group textarea {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            font-size: 14px;
            min-height: 120px;
            font-family: inherit;
        }
        
        .form-group textarea:focus {
            outline: none;
            border-color: #1890ff;
            box-shadow: 0 0 0 2px rgba(24,144,255,0.2);
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include 'sidebar.php'; ?>
        <div class="main-content" id="mainContent">
            <?php include 'header.php'; ?>
            <div class="content">
                <div class="breadcrumb">
                    <a href="index.php">首頁</a> / <?php echo htmlspecialchars($page_title); ?>
                </div>
                
                <?php if ($message): ?>
                <div class="message <?php echo $message_type; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
                <?php endif; ?>
                
                <!-- 統計卡片 -->
                <div class="stats-container">
                    <div class="stat-card">
                        <div class="stat-card-icon"><i class="fas fa-comments"></i></div>
                        <div class="stat-card-title">總貼文數</div>
                        <div class="stat-card-value"><?php echo number_format($stats['total_posts'] ?? 0); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-icon"><i class="fas fa-reply"></i></div>
                        <div class="stat-card-title">總留言數</div>
                        <div class="stat-card-value"><?php echo number_format($stats['total_comments'] ?? 0); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-icon"><i class="fas fa-eye-slash"></i></div>
                        <div class="stat-card-title">已隱藏貼文</div>
                        <div class="stat-card-value"><?php echo number_format($stats['hidden_posts'] ?? 0); ?></div>
                    </div>
                </div>
                
                <!-- 搜尋區域 -->
                <div class="search-container">
                    <form method="GET" class="search-form">
                        <div class="form-group">
                            <label>關鍵字搜尋</label>
                            <input type="text" name="keyword" value="<?php echo htmlspecialchars($search_keyword); ?>" placeholder="搜尋貼文內容...">
                        </div>
                        <div class="form-group">
                            <label>使用者</label>
                            <input type="text" name="user" value="<?php echo htmlspecialchars($search_user); ?>" placeholder="搜尋使用者名稱...">
                        </div>
                        <div class="form-group">
                            <label>開始日期</label>
                            <input type="date" name="date_from" value="<?php echo htmlspecialchars($search_date_from); ?>">
                        </div>
                        <div class="form-group">
                            <label>結束日期</label>
                            <input type="date" name="date_to" value="<?php echo htmlspecialchars($search_date_to); ?>">
                        </div>
                        <div class="search-buttons">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> 搜尋
                            </button>
                            <a href="senior_messages_management.php" class="btn btn-secondary">
                                <i class="fas fa-redo"></i> 重置
                            </a>
                            <button type="button" class="btn btn-warning" onclick="openAnnouncementModal()">
                                <i class="fas fa-bullhorn"></i> 系統公告
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- 貼文列表 -->
                <div class="table-container">
                    <div class="table-header">
                        <div class="table-title">貼文列表</div>
                    </div>
                    <table class="posts-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>標題</th>
                                <th>內容</th>
                                <th>發文者</th>
                                <th>留言數</th>
                                <th>狀態</th>
                                <th>發文時間</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($posts)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 40px; color: #8c8c8c;">
                                    沒有找到貼文
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($posts as $post): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($post['id']); ?></td>
                                <td><?php echo htmlspecialchars($post['title'] ?? '無標題'); ?></td>
                                <td class="post-content" title="<?php echo htmlspecialchars($post['content']); ?>">
                                    <?php echo htmlspecialchars(mb_substr($post['content'], 0, 50)); ?>...
                                </td>
                                <td><?php echo htmlspecialchars($post['user_name'] ?? $post['username'] ?? '未知'); ?></td>
                                <td><?php echo htmlspecialchars($post['comment_count'] ?? 0); ?></td>
                                <td>
                                    <?php 
                                    // 檢查是否隱藏（支援 is_hidden 或 is_published）
                                    $is_hidden_status = false;
                                    if ($has_is_hidden && ($post['is_hidden'] ?? 0)) {
                                        $is_hidden_status = true;
                                    } elseif ($use_published_for_hide && ($post['is_published'] ?? 1) == 0) {
                                        $is_hidden_status = true;
                                    }
                                    if ($is_hidden_status): 
                                    ?>
                                        <span class="badge badge-hidden">已隱藏</span>
                                    <?php endif; ?>
                                    <?php if ($has_is_system_announcement && ($post['is_system_announcement'] ?? 0)): ?>
                                        <span class="badge badge-system">系統公告</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('Y-m-d H:i', strtotime($post['created_at'])); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn btn-small btn-secondary" onclick="viewPost(<?php echo $post['id']; ?>)">
                                            <i class="fas fa-eye"></i> 查看
                                        </button>
                                        <?php 
                                        // 顯示隱藏/顯示按鈕（支援 is_hidden 或 is_published）
                                        if ($has_is_hidden || $use_published_for_hide): 
                                            $current_hidden = false;
                                            if ($has_is_hidden && ($post['is_hidden'] ?? 0)) {
                                                $current_hidden = true;
                                            } elseif ($use_published_for_hide && ($post['is_published'] ?? 1) == 0) {
                                                $current_hidden = true;
                                            }
                                        ?>
                                            <?php if ($current_hidden): ?>
                                                <button class="btn btn-small btn-primary" onclick="toggleHide(<?php echo $post['id']; ?>, 0)">
                                                    <i class="fas fa-eye"></i> 顯示
                                                </button>
                                            <?php else: ?>
                                                <button class="btn btn-small btn-warning" onclick="toggleHide(<?php echo $post['id']; ?>, 1)">
                                                    <i class="fas fa-eye-slash"></i> 隱藏
                                                </button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <button class="btn btn-small btn-secondary" onclick="openEditModal(<?php echo $post['id']; ?>, '<?php echo htmlspecialchars(addslashes($post['content'])); ?>')">
                                            <i class="fas fa-edit"></i> 編輯
                                        </button>
                                        <button class="btn btn-small btn-danger" onclick="deletePost(<?php echo $post['id']; ?>)">
                                            <i class="fas fa-trash"></i> 刪除
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    
                    <!-- 分頁 -->
                    <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <div class="pagination-info">
                            顯示第 <?php echo ($page - 1) * $per_page + 1; ?>-<?php echo min($page * $per_page, $total_posts); ?> 筆，共 <?php echo $total_posts; ?> 筆
                        </div>
                        <div class="pagination-controls">
                            <button onclick="goToPage(<?php echo max(1, $page - 1); ?>)" <?php echo $page <= 1 ? 'disabled' : ''; ?>>
                                <i class="fas fa-chevron-left"></i> 上一頁
                            </button>
                            <span style="padding: 6px 12px;">第 <?php echo $page; ?> / <?php echo $total_pages; ?> 頁</span>
                            <button onclick="goToPage(<?php echo min($total_pages, $page + 1); ?>)" <?php echo $page >= $total_pages ? 'disabled' : ''; ?>>
                                下一頁 <i class="fas fa-chevron-right"></i>
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 編輯模態對話框 -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title">強制編輯貼文</div>
                <button class="modal-close" onclick="closeEditModal()">&times;</button>
            </div>
            <form method="POST" id="editForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="force_edit">
                    <input type="hidden" name="post_id" id="edit_post_id">
                    <div class="form-group">
                        <label>貼文內容</label>
                        <textarea name="content" id="edit_content" required></textarea>
                    </div>
                    <div style="color: #ff4d4f; font-size: 12px; margin-top: 8px;">
                        <i class="fas fa-exclamation-triangle"></i> 警告：此操作將覆蓋原始內容，請謹慎使用
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">取消</button>
                    <button type="submit" class="btn btn-primary">確認編輯</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- 查看留言詳情模態對話框 -->
    <div id="viewModal" class="modal">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header">
                <div class="modal-title">留言詳情</div>
                <button class="modal-close" onclick="closeViewModal()">&times;</button>
            </div>
            <div class="modal-body" id="viewModalBody">
                <div style="padding: 20px;">
                    <div style="margin-bottom: 16px;">
                        <strong>留言 ID：</strong> <span id="view_post_id"></span>
                    </div>
                    <div style="margin-bottom: 16px;">
                        <strong>標題：</strong> <span id="view_post_title"></span>
                    </div>
                    <div style="margin-bottom: 16px;">
                        <strong>發文者：</strong> <span id="view_post_user"></span>
                    </div>
                    <div style="margin-bottom: 16px;">
                        <strong>發文時間：</strong> <span id="view_post_time"></span>
                    </div>
                    <div style="margin-bottom: 16px;">
                        <strong>留言數：</strong> <span id="view_post_comments"></span>
                    </div>
                    <div style="margin-bottom: 16px;">
                        <strong>內容：</strong>
                    </div>
                    <div id="view_post_content" style="
                        background: #f5f5f5;
                        border: 1px solid #d9d9d9;
                        border-radius: 6px;
                        padding: 16px;
                        min-height: 100px;
                        white-space: pre-wrap;
                        word-wrap: break-word;
                        max-height: 400px;
                        overflow-y: auto;
                    "></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeViewModal()">關閉</button>
            </div>
        </div>
    </div>
    
    <!-- 系統公告模態對話框 -->
    <div id="announcementModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title">系統公告推送</div>
                <button class="modal-close" onclick="closeAnnouncementModal()">&times;</button>
            </div>
            <form method="POST" id="announcementForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="send_announcement">
                    <div class="form-group">
                        <label>公告標題</label>
                        <input type="text" name="announcement_title" value="系統公告" required>
                    </div>
                    <div class="form-group">
                        <label>公告內容</label>
                        <textarea name="announcement_content" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeAnnouncementModal()">取消</button>
                    <button type="submit" class="btn btn-primary">發送公告</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function goToPage(page) {
            const url = new URL(window.location.href);
            url.searchParams.set('page', page);
            window.location.href = url.toString();
        }
        
        function deletePost(postId) {
            if (confirm('確定要刪除這則貼文嗎？此操作無法復原，相關留言也會一併刪除。')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_post">
                    <input type="hidden" name="post_id" value="${postId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function toggleHide(postId, isHidden) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="toggle_hide">
                <input type="hidden" name="post_id" value="${postId}">
                <input type="hidden" name="is_hidden" value="${isHidden}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
        
        function openEditModal(postId, content) {
            document.getElementById('edit_post_id').value = postId;
            document.getElementById('edit_content').value = content;
            document.getElementById('editModal').style.display = 'block';
        }
        
        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }
        
        function openAnnouncementModal() {
            document.getElementById('announcementForm').reset();
            document.getElementById('announcementModal').style.display = 'block';
        }
        
        function closeAnnouncementModal() {
            document.getElementById('announcementModal').style.display = 'none';
        }
        
        // 留言數據（從 PHP 傳遞）
        const postsData = <?php echo json_encode($posts_data, JSON_UNESCAPED_UNICODE); ?>;
        
        function viewPost(postId) {
            // 在後台彈出模態視窗顯示留言詳情
            const post = postsData[postId];
            
            if (!post) {
                alert('找不到該留言的資料');
                return;
            }
            
            // 填充模態對話框內容
            document.getElementById('view_post_id').textContent = post.id;
            document.getElementById('view_post_title').textContent = post.title;
            document.getElementById('view_post_user').textContent = post.user_name;
            document.getElementById('view_post_time').textContent = post.created_at;
            document.getElementById('view_post_comments').textContent = post.comment_count + ' 則留言';
            document.getElementById('view_post_content').textContent = post.content;
            
            // 顯示模態對話框
            document.getElementById('viewModal').style.display = 'block';
        }
        
        function closeViewModal() {
            document.getElementById('viewModal').style.display = 'none';
        }
        
        // 點擊模態對話框外部關閉
        window.onclick = function(event) {
            const editModal = document.getElementById('editModal');
            const viewModal = document.getElementById('viewModal');
            const announcementModal = document.getElementById('announcementModal');
            
            if (event.target === editModal) {
                closeEditModal();
            }
            if (event.target === viewModal) {
                closeViewModal();
            }
            if (event.target === announcementModal) {
                closeAnnouncementModal();
            }
        }
    </script>
</body>
</html>

