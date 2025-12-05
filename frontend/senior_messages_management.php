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

// 處理各種操作
$message = '';
$message_type = '';

// 處理刪除貼文
if (isset($_POST['action']) && $_POST['action'] === 'delete_post') {
    $post_id = intval($_POST['post_id'] ?? 0);
    if ($post_id > 0) {
        try {
            // 先刪除相關留言
            $stmt = $conn->prepare("DELETE FROM senior_comments WHERE post_id = ?");
            $stmt->bind_param("i", $post_id);
            $stmt->execute();
            
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
            $stmt = $conn->prepare("UPDATE senior_messages SET is_hidden = ? WHERE id = ?");
            $stmt->bind_param("ii", $is_hidden, $post_id);
            if ($stmt->execute()) {
                $message = $is_hidden ? '貼文已隱藏' : '貼文已顯示';
                $message_type = 'success';
            } else {
                $message = '操作失敗：' . $conn->error;
                $message_type = 'error';
            }
        } catch (Exception $e) {
            $message = '操作失敗：' . $e->getMessage();
            $message_type = 'error';
        }
    }
}

// 處理強制編輯
if (isset($_POST['action']) && $_POST['action'] === 'force_edit') {
    $post_id = intval($_POST['post_id'] ?? 0);
    $content = $_POST['content'] ?? '';
    if ($post_id > 0 && !empty($content)) {
        try {
            $stmt = $conn->prepare("UPDATE senior_messages SET content = ?, admin_edited = 1, edited_at = NOW() WHERE id = ?");
            $stmt->bind_param("si", $content, $post_id);
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

// 處理標記敏感內容
if (isset($_POST['action']) && $_POST['action'] === 'mark_sensitive') {
    $post_id = intval($_POST['post_id'] ?? 0);
    $sensitive_type = $_POST['sensitive_type'] ?? '';
    if ($post_id > 0 && !empty($sensitive_type)) {
        try {
            $stmt = $conn->prepare("UPDATE senior_messages SET sensitive_type = ?, sensitive_marked_at = NOW() WHERE id = ?");
            $stmt->bind_param("si", $sensitive_type, $post_id);
            if ($stmt->execute()) {
                $message = '貼文已標記為敏感內容';
                $message_type = 'success';
            } else {
                $message = '標記失敗：' . $conn->error;
                $message_type = 'error';
            }
        } catch (Exception $e) {
            $message = '標記失敗：' . $e->getMessage();
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
            // 假設有系統公告表，如果沒有則插入到 senior_messages 表作為系統貼文
            $admin_user_id = $_SESSION['user_id'] ?? 0;
            $stmt = $conn->prepare("INSERT INTO senior_messages (user_id, title, content, is_system_announcement, created_at) VALUES (?, ?, ?, 1, NOW())");
            $stmt->bind_param("iss", $admin_user_id, $announcement_title, $announcement_content);
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
$sql = "SELECT sm.*, 
               u.username, u.name as user_name,
               (SELECT COUNT(*) FROM senior_comments sc WHERE sc.post_id = sm.id) as comment_count
        FROM senior_messages sm 
        LEFT JOIN user u ON sm.user_id = u.id 
        $where_clause
        ORDER BY sm.created_at DESC 
        LIMIT ? OFFSET ?";

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
while ($row = $posts_result->fetch_assoc()) {
    $posts[] = $row;
}

// 獲取統計數據
$stats_sql = "SELECT 
    COUNT(*) as total_posts,
    SUM((SELECT COUNT(*) FROM senior_comments sc WHERE sc.post_id = sm.id)) as total_comments,
    COUNT(CASE WHEN sm.is_hidden = 1 THEN 1 END) as hidden_posts,
    COUNT(CASE WHEN sm.sensitive_type IS NOT NULL THEN 1 END) as sensitive_posts
FROM senior_messages sm";
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
        
        .badge-sensitive {
            background: #fff7e6;
            color: #fa8c16;
        }
        
        .badge-system {
            background: #e6f7ff;
            color: #1890ff;
        }
        
        .badge-violence {
            background: #ff4d4f;
            color: white;
        }
        
        .badge-pornography {
            background: #eb2f96;
            color: white;
        }
        
        .badge-hate {
            background: #722ed1;
            color: white;
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
                    <div class="stat-card">
                        <div class="stat-card-icon"><i class="fas fa-exclamation-triangle"></i></div>
                        <div class="stat-card-title">敏感內容</div>
                        <div class="stat-card-value"><?php echo number_format($stats['sensitive_posts'] ?? 0); ?></div>
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
                                    <?php if ($post['is_hidden'] ?? 0): ?>
                                        <span class="badge badge-hidden">已隱藏</span>
                                    <?php endif; ?>
                                    <?php if ($post['is_system_announcement'] ?? 0): ?>
                                        <span class="badge badge-system">系統公告</span>
                                    <?php endif; ?>
                                    <?php if (!empty($post['sensitive_type'])): ?>
                                        <?php
                                        $sensitive_badge_class = 'badge-sensitive';
                                        if ($post['sensitive_type'] === 'violence') $sensitive_badge_class = 'badge-violence';
                                        elseif ($post['sensitive_type'] === 'pornography') $sensitive_badge_class = 'badge-pornography';
                                        elseif ($post['sensitive_type'] === 'hate') $sensitive_badge_class = 'badge-hate';
                                        ?>
                                        <span class="badge <?php echo $sensitive_badge_class; ?>">
                                            <?php
                                            $sensitive_labels = [
                                                'violence' => '暴力',
                                                'pornography' => '色情',
                                                'hate' => '仇恨'
                                            ];
                                            echo $sensitive_labels[$post['sensitive_type']] ?? '敏感';
                                            ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('Y-m-d H:i', strtotime($post['created_at'])); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn btn-small btn-secondary" onclick="viewPost(<?php echo $post['id']; ?>)">
                                            <i class="fas fa-eye"></i> 查看
                                        </button>
                                        <?php if ($post['is_hidden'] ?? 0): ?>
                                            <button class="btn btn-small btn-primary" onclick="toggleHide(<?php echo $post['id']; ?>, 0)">
                                                <i class="fas fa-eye"></i> 顯示
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-small btn-warning" onclick="toggleHide(<?php echo $post['id']; ?>, 1)">
                                                <i class="fas fa-eye-slash"></i> 隱藏
                                            </button>
                                        <?php endif; ?>
                                        <button class="btn btn-small btn-secondary" onclick="openEditModal(<?php echo $post['id']; ?>, '<?php echo htmlspecialchars(addslashes($post['content'])); ?>')">
                                            <i class="fas fa-edit"></i> 編輯
                                        </button>
                                        <button class="btn btn-small btn-warning" onclick="openSensitiveModal(<?php echo $post['id']; ?>)">
                                            <i class="fas fa-exclamation-triangle"></i> 標記
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
    
    <!-- 標記敏感內容模態對話框 -->
    <div id="sensitiveModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title">標記敏感內容</div>
                <button class="modal-close" onclick="closeSensitiveModal()">&times;</button>
            </div>
            <form method="POST" id="sensitiveForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="mark_sensitive">
                    <input type="hidden" name="post_id" id="sensitive_post_id">
                    <div class="form-group">
                        <label>敏感內容類型</label>
                        <select name="sensitive_type" id="sensitive_type" required>
                            <option value="">請選擇</option>
                            <option value="violence">暴力</option>
                            <option value="pornography">色情</option>
                            <option value="hate">仇恨</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeSensitiveModal()">取消</button>
                    <button type="submit" class="btn btn-warning">確認標記</button>
                </div>
            </form>
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
        
        function openSensitiveModal(postId) {
            document.getElementById('sensitive_post_id').value = postId;
            document.getElementById('sensitive_type').value = '';
            document.getElementById('sensitiveModal').style.display = 'block';
        }
        
        function closeSensitiveModal() {
            document.getElementById('sensitiveModal').style.display = 'none';
        }
        
        function openAnnouncementModal() {
            document.getElementById('announcementForm').reset();
            document.getElementById('announcementModal').style.display = 'block';
        }
        
        function closeAnnouncementModal() {
            document.getElementById('announcementModal').style.display = 'none';
        }
        
        function viewPost(postId) {
            // 這裡可以打開一個新視窗或跳轉到前台查看
            window.open('http://localhost/Topics-frontend/frontend/senior_messages.php?post_id=' + postId, '_blank');
        }
        
        // 點擊模態對話框外部關閉
        window.onclick = function(event) {
            const editModal = document.getElementById('editModal');
            const sensitiveModal = document.getElementById('sensitiveModal');
            const announcementModal = document.getElementById('announcementModal');
            
            if (event.target === editModal) {
                closeEditModal();
            }
            if (event.target === sensitiveModal) {
                closeSensitiveModal();
            }
            if (event.target === announcementModal) {
                closeAnnouncementModal();
            }
        }
    </script>
</body>
</html>

