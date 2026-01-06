<?php
require_once __DIR__ . '/session_config.php';

// 檢查是否已登入
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: login.php");
    exit;
}

// 引入資料庫設定
require_once '../../Topics-frontend/frontend/config.php';

// 設置頁面標題
$page_title = '影片管理';

// 建立資料庫連接
$conn = getDatabaseConnection();

$message = "";
$messageType = "";

// 處理刪除操作
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_video') {
    try {
        $video_id = intval($_POST['video_id']);
        
        // 先獲取影片資訊以刪除文件
        $stmt = $conn->prepare("SELECT video_url, thumbnail_url FROM videos WHERE id = ?");
        $stmt->bind_param("i", $video_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $video = $result->fetch_assoc();
        $stmt->close();
        
        if ($video) {
            // 刪除影片文件
            if (!empty($video['video_url'])) {
                $backend_frontend = dirname(__FILE__);
                $backend_dir = dirname($backend_frontend);
                $topics_root = dirname($backend_dir);
                $frontend_root = $topics_root . DIRECTORY_SEPARATOR . 'Topics-frontend' . DIRECTORY_SEPARATOR . 'frontend';
                $video_file = $frontend_root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $video['video_url']);
                if (file_exists($video_file)) {
                    @unlink($video_file);
                }
            }
            
            // 刪除縮圖文件
            if (!empty($video['thumbnail_url'])) {
                $backend_frontend = dirname(__FILE__);
                $backend_dir = dirname($backend_frontend);
                $topics_root = dirname($backend_dir);
                $frontend_root = $topics_root . DIRECTORY_SEPARATOR . 'Topics-frontend' . DIRECTORY_SEPARATOR . 'frontend';
                $thumb_file = $frontend_root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $video['thumbnail_url']);
                if (file_exists($thumb_file)) {
                    @unlink($thumb_file);
                }
            }
            
            // 刪除資料庫記錄
            $stmt = $conn->prepare("DELETE FROM videos WHERE id = ?");
            $stmt->bind_param("i", $video_id);
            if ($stmt->execute()) {
                $message = "影片已成功刪除！";
                $messageType = "success";
            }
            $stmt->close();
        }
    } catch (Exception $e) {
        $message = "刪除失敗：" . $e->getMessage();
        $messageType = "error";
    }
}

// 搜尋和篩選參數
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_filter = isset($_GET['category']) ? intval($_GET['category']) : 0;
$published_filter = isset($_GET['published']) ? intval($_GET['published']) : -1;
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

// 構建 WHERE 條件
$where = "1=1";
$params = [];
$types = "";

if ($search !== '') {
    $where .= " AND (title LIKE ? OR description LIKE ?)";
    $like = '%' . $search . '%';
    // 這裡我們直接存入變數，稍後再建立引用
    $params[] = $like;
    $params[] = $like;
    $types .= "ss";
}

if ($category_filter > 0) {
    $where .= " AND category_id = ?";
    $params[] = $category_filter;
    $types .= "i";
}

if ($published_filter >= 0) {
    $where .= " AND published = ?";
    $params[] = $published_filter;
    $types .= "i";
}

// 計算總數
$countSql = "SELECT COUNT(*) as total FROM videos WHERE $where";
$stmt = $conn->prepare($countSql);
if (!empty($params)) {
    // 修正：建立引用陣列傳遞給 bind_param
    $bindParams = [];
    $bindParams[] = &$types;
    // 必須使用迴圈將參數作為引用加入
    for ($i = 0; $i < count($params); $i++) {
        $bindParams[] = &$params[$i];
    }
    call_user_func_array([$stmt, 'bind_param'], $bindParams);
}
$stmt->execute();
$countResult = $stmt->get_result()->fetch_assoc();
$total = intval($countResult['total']);
$stmt->close();

// 獲取影片列表
$videos = [];
$listSql = "SELECT v.*, vc.name as category_name FROM videos v 
            LEFT JOIN video_categories vc ON v.category_id = vc.id 
            WHERE $where 
            ORDER BY v.created_at DESC 
            LIMIT ? OFFSET ?";
$stmt = $conn->prepare($listSql);

if (!empty($params)) {
    // 複製參數並加入分頁參數
    $allParams = $params;
    $allParams[] = $perPage;
    $allParams[] = $offset;
    
    $allTypes = $types . "ii";
    
    // 建立引用陣列
    $bindParams = [];
    $bindParams[] = &$allTypes;
    
    // 將所有參數作為引用加入
    for ($i = 0; $i < count($allParams); $i++) {
        $bindParams[] = &$allParams[$i];
    }
    
    call_user_func_array([$stmt, 'bind_param'], $bindParams);
} else {
    $stmt->bind_param("ii", $perPage, $offset);
}

$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $videos[] = $row;
}
$stmt->close();

// 獲取所有分類
$categories = [];
$catSql = "SELECT id, name FROM video_categories ORDER BY name";
if ($result = $conn->query($catSql)) {
    while ($row = $result->fetch_assoc()) {
        $categories[] = $row;
    }
    $result->free();
}

$totalPages = max(1, ceil($total / $perPage));

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
        
        /* 核心佈局樣式 */
        .dashboard { display: flex; min-height: 100vh; }
        .main-content { 
            flex: 1; 
            margin-left: 250px; 
            background: #f0f2f5; 
            transition: all 0.3s ease; 
        }
        /* 當 sidebar.php 切換 collapsed 時會用到這個 class */
        .main-content.expanded { margin-left: 60px; }
        .content { padding: 24px; }
        
        /* 頂部控制列與麵包屑 */
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

        /* 篩選欄位樣式 */
        .filter-bar {
            background: var(--card-background-color);
            border-radius: 8px;
            border: 1px solid var(--border-color);
            padding: 16px 24px;
            margin-bottom: 16px;
            display: flex;
            align-items: flex-end; /* 讓按鈕與輸入框底部對齊 */
            gap: 16px;
            flex-wrap: wrap;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        
        .filter-group label {
            font-size: 14px;
            color: var(--text-color);
            font-weight: 500;
        }

        .filter-bar input[type="text"],
        .filter-bar select {
            padding: 8px 12px;
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            font-size: 14px;
            background: #fff;
            min-width: 150px;
            transition: all 0.3s;
        }

        .filter-bar input:focus,
        .filter-bar select:focus {
            outline: none;
            border-color: #1890ff;
            box-shadow: 0 0 0 2px rgba(24,144,255,0.2);
        }

        /* 按鈕樣式 */
        .btn { padding: 8px 16px; border: 1px solid #d9d9d9; border-radius: 6px; cursor: pointer; font-size: 14px; transition: all 0.3s; background: #fff; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 6px; }
        .btn-primary { background: var(--primary-color); color: white; border-color: var(--primary-color); }
        .btn-primary:hover { background: #40a9ff; border-color: #40a9ff; }
        .btn-reset { color: var(--text-secondary-color); border: 1px solid #d9d9d9; }
        .btn-reset:hover { border-color: var(--text-secondary-color); color: var(--text-color); }

        /* 表格容器與樣式 */
        .table-wrapper {
            background: var(--card-background-color);
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
            display: flex;
            flex-direction: column;
        }

        .table-container { 
            overflow-x: auto;
            flex: 1;
        }
        .table { 
            width: 100%; 
            border-collapse: collapse; 
        }

        .table th, .table td { 
            padding: 16px 5px; 
            text-align: left; 
            border-bottom: 1px solid var(--border-color); 
            font-size: 17px;
         }
        .table th { 
            background: #fafafa; 
            font-weight: 600; 
            color: #262626; 
            white-space: nowrap;
        }
        .table tr:hover { background: #fafafa; }
        
        /* 縮圖 */
        .thumbnail-preview {
            width: 180px;
            height: 100px;
            object-fit: cover;
            border-radius: 4px;
            background: #eee;
            border: 1px solid #f0f0f0;
        }
        
        /* 狀態標籤 */
        .status-badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500; white-space: nowrap; }
        .status-badge.published { background: #f6ffed; color: var(--success-color); border: 1px solid #b7eb8f; }
        .status-badge.draft { background: #fff7e6; color: #faad14; border: 1px solid #ffd591; }

        /* 操作按鈕 */
        .action-buttons { display: flex; gap: 8px; }
        .btn-action {
            padding: 4px 12px; border-radius: 4px; font-size: 14px;
            text-decoration: none; display: inline-block; transition: all 0.3s;
            background: #fff;
            cursor: pointer;
        }
        .btn-edit { color: var(--success-color); border: 1px solid var(--success-color); }
        .btn-edit:hover { background: var(--success-color); color: white; }
        .btn-delete { color: var(--danger-color); border: 1px solid var(--danger-color); }
        .btn-delete:hover { background: var(--danger-color); color: white; }
        
        /* 分頁 */
        .pagination {
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 1px solid var(--border-color);
            background: #fafafa;
        }
        .pagination-info {
            font-size: 14px;
            color: var(--text-secondary-color);
        }
        .pagination-controls {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .pagination a, .pagination span {
            padding: 6px 12px;
            border: 1px solid #d9d9d9;
            background: #fff;
            color: #595959;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
            transition: all 0.3s;
        }
        .pagination a:hover {
            border-color: #1890ff;
            color: #1890ff;
        }
        .pagination span.active {
            background: #1890ff;
            color: white;
            border-color: #1890ff;
        }
        .pagination span.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            background: #f5f5f5;
        }

        /* 訊息提示 */
        .message { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; font-weight: 500; }
        .message.success { background: #f6ffed; border: 1px solid #b7eb8f; color: var(--success-color); }
        .message.error { background: #fff2f0; border: 1px solid #ffccc7; color: var(--danger-color); }

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
        
        /* RWD */
        @media (max-width: 768px) {
            .main-content { margin-left: 0; }
            .page-controls { flex-direction: column; align-items: flex-start; }
            .filter-bar { flex-direction: column; align-items: stretch; }
            .filter-bar > div { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include 'sidebar.php'; ?>
        
        <div class="main-content" id="mainContent">
            <?php include 'header.php'; ?>
            
            <div class="content">
                <?php if (!empty($message)): ?>
                    <div class="message <?php echo $messageType; ?>">
                        <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <div class="page-controls">
                    <div class="breadcrumb">
                        <a href="index.php">首頁</a> / <?php echo $page_title; ?>
                    </div>
                    <a href="edit_video.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> 新增影片
                    </a>
                </div>

                <form method="GET" class="filter-bar">
                    <div class="filter-group">
                        <label for="search">搜尋</label>
                        <input type="text" id="search" name="search" placeholder="影片標題或描述" value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="filter-group">
                        <label for="category">分類</label>
                        <select id="category" name="category">
                            <option value="0">全部分類</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>" <?php echo $category_filter == $cat['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="published">狀態</label>
                        <select id="published" name="published">
                            <option value="-1">全部狀態</option>
                            <option value="1" <?php echo $published_filter === 1 ? 'selected' : ''; ?>>已發布</option>
                            <option value="0" <?php echo $published_filter === 0 ? 'selected' : ''; ?>>草稿</option>
                        </select>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button type="submit" class="btn btn-primary">搜尋</button>
                        <a href="video_management.php" class="btn btn-reset">重置</a>
                    </div>
                </form>

                <div class="table-wrapper">
                    <?php if (empty($videos)): ?>
                        <div class="empty-state">
                            <i class="fas fa-video"></i>
                            <h3>目前沒有影片</h3>
                            <p>還沒有新增任何影片，請點擊「新增影片」開始建立。</p>
                        </div>
                    <?php else: ?>
                        <div class="table-container">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th style="width: 100px;">縮圖</th>
                                        <th>標題</th>
                                        <th style="width: 120px;">分類</th>
                                        <th style="width: 80px;">時長</th>
                                        <th style="width: 100px;">狀態</th>
                                        <th style="width: 80px;">瀏覽</th>
                                        <th style="width: 80px;">按讚</th>
                                        <th style="width: 160px;">操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($videos as $video): ?>
                                        <tr>
                                            <td>
                                                <?php if (!empty($video['thumbnail_url'])): ?>
                                                    <?php
                                                        $thumbSrc = preg_match('/^https?:\/\//i', $video['thumbnail_url'])
                                                            ? $video['thumbnail_url']
                                                            : '/Topics-frontend/frontend/' . ltrim($video['thumbnail_url'], '/');
                                                    ?>
                                                    <img src="<?php echo htmlspecialchars($thumbSrc); ?>" alt="縮圖" class="thumbnail-preview">
                                                <?php else: ?>
                                                    <div class="thumbnail-preview" style="display: flex; align-items: center; justify-content: center; background: #eee; color: #999;">
                                                        <i class="fas fa-image"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div style="font-weight: 500; margin-bottom: 4px;"><?php echo htmlspecialchars($video['title']); ?></div>
                                                <div style="font-size: 12px; color: #888;"><?php echo mb_strimwidth(htmlspecialchars($video['description'] ?? ''), 0, 50, '...'); ?></div>
                                            </td>
                                            <td><?php echo htmlspecialchars($video['category_name'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($video['duration'] ?? '-'); ?></td>
                                            <td>
                                                <span class="status-badge <?php echo $video['published'] == 1 ? 'published' : 'draft'; ?>">
                                                    <?php echo $video['published'] == 1 ? '已發布' : '草稿'; ?>
                                                </span>
                                            </td>
                                            <td><?php echo intval($video['view_count']); ?></td>
                                            <td><?php echo intval($video['like_count']); ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <a href="edit_video.php?id=<?php echo $video['id']; ?>" class="btn-action btn-edit">
                                                        <i class="fas fa-edit"></i> 編輯
                                                    </a>
                                                    <form method="POST" style="display: inline;" onsubmit="return confirm('確定要刪除此影片嗎？');">
                                                        <input type="hidden" name="action" value="delete_video">
                                                        <input type="hidden" name="video_id" value="<?php echo $video['id']; ?>">
                                                        <button type="submit" class="btn-action btn-delete">
                                                            <i class="fas fa-trash"></i> 刪除
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="pagination">
                            <div class="pagination-info">
                                顯示第 <?php echo ($page - 1) * $perPage + 1; ?>-<?php echo min($page * $perPage, $total); ?> 筆，共 <?php echo $total; ?> 筆
                            </div>
                            <div class="pagination-controls">
                                    <?php if ($page > 1): ?>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">首頁</a>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">上一頁</a>
                                    <?php else: ?>
                                        <span class="disabled">首頁</span>
                                        <span class="disabled">上一頁</span>
                                    <?php endif; ?>

                                <?php
                                $startPage = max(1, $page - 2);
                                $endPage = min($totalPages, $page + 2);
                                
                                if ($startPage > 1) {
                                    echo '<span>...</span>';
                                }
                                
                                for ($i = $startPage; $i <= $endPage; $i++) {
                                    if ($i == $page) {
                                        echo '<span class="active">' . $i . '</span>';
                                    } else {
                                        echo '<a href="?' . http_build_query(array_merge($_GET, ['page' => $i])) . '">' . $i . '</a>';
                                    }
                                }
                                
                                if ($endPage < $totalPages) {
                                    echo '<span>...</span>';
                                }
                                ?>

                                    <?php if ($page < $totalPages): ?>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">下一頁</a>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $totalPages])); ?>">末頁</a>
                                    <?php else: ?>
                                        <span class="disabled">下一頁</span>
                                        <span class="disabled">末頁</span>
                                    <?php endif; ?>
                                </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // 刪除後自動隱藏訊息
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                const message = document.querySelector('.message');
                if (message && message.classList.contains('success')) {
                    message.style.transition = 'opacity 0.3s';
                    message.style.opacity = '0';
                    setTimeout(() => message.remove(), 300);
                }
            }, 3000);
        });
    </script>
</body>
</html>