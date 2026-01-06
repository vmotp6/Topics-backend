<?php
require_once __DIR__ . '/session_config.php';
// 檢查權限
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: login.php");
    exit;
}

$user_role = $_SESSION['role'] ?? '';
// 標準化角色字串，避免大小寫或前後空白造成判斷失敗
$user_role = strtoupper(trim($user_role));
// 兼容中文角色名稱
if ($user_role === '管理員') $user_role = 'ADM';
if (in_array($user_role, ['行政人員', '學校行政人員'])) $user_role = 'STA';

$allowed_roles = ['ADM', 'STA', 'DI', 'TEA', 'STAM', 'IM', 'AS'];
if (!in_array($user_role, $allowed_roles)) {
    header("Location: index.php");
    exit;
}

require_once '../../Topics-frontend/frontend/config.php';
$conn = getDatabaseConnection();

// 處理刪除
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $id = intval($_POST['id']);
    
    // 刪除前先移除實體檔案
    $stmt = $conn->prepare("SELECT file_path FROM bulletin_files WHERE bulletin_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($file = $res->fetch_assoc()) {
        if (file_exists($file['file_path'])) {
            unlink($file['file_path']);
        }
    }
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM bulletin_board WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    header("Location: bulletin_board.php?msg=deleted");
    exit;
}

// 篩選參數
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

// 構建查詢
$where = "1=1";
$params = [];
$types = "";

if ($search) {
    $where .= " AND (title LIKE ? OR content LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $types .= "ss";
}

// 取得總數
$countSql = "SELECT COUNT(*) as total FROM bulletin_board WHERE $where";
$stmt = $conn->prepare($countSql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$total = $stmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($total / $perPage);
$stmt->close();

// 取得列表 (優先顯示置頂，再依日期排序)
$sql = "SELECT * FROM bulletin_board WHERE $where ORDER BY is_pinned DESC, created_at DESC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$params[] = $perPage;
$params[] = $offset;
$types .= "ii";
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$bulletinsData = [];
// 狀態對照表
$statusMap = [
    'published' => ['label' => '已發布', 'class' => 'status-active'],
    'draft'     => ['label' => '草稿',   'class' => 'status-inactive'],
    'archived'  => ['label' => '已歸檔', 'class' => 'status-inactive']
];
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>招生公告管理 - Topics 後台管理系統</title>
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
        
        /* Dashboard 佈局設定 (與 settings.php 一致) */
        .dashboard { display: flex; min-height: 100vh; }
        .content { padding: 24px; }
        /* 注意：不要在此定義 .main-content，讓 sidebar.php 負責 */

        /* 頁面控制區 */
        .page-controls { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; gap: 16px; }
        .breadcrumb { margin-bottom: 0; font-size: 16px; color: var(--text-secondary-color); }
        .breadcrumb a { color: var(--primary-color); text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }
        
        /* 搜尋區 */
        .table-search { display: flex; gap: 8px; }
        .table-search input { padding: 8px 12px; border: 1px solid #d9d9d9; border-radius: 6px; width: 240px; background: #fff; font-size: 16px; transition: all 0.3s; }
        .table-search input:focus { outline: none; border-color: #1890ff; box-shadow: 0 0 0 2px rgba(24,144,255,0.2); }
        
        /* 表格樣式 */
        .table-wrapper { background: var(--card-background-color); border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.03); border: 1px solid var(--border-color); margin-bottom: 24px; }
        .table-container { overflow-x: auto; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 16px 24px; text-align: left; border-bottom: 1px solid var(--border-color); font-size: 16px; }
        .table th { background: #fafafa; font-weight: 600; color: #262626; white-space: nowrap; }
        .table tr:hover { background: #fafafa; }
        
        /* 按鈕樣式 */
        .btn { padding: 8px 16px; border: 1px solid #d9d9d9; border-radius: 6px; cursor: pointer; font-size: 14px; transition: all 0.3s; background: #fff; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 6px; color: #595959; }
        .btn-primary { background: var(--primary-color); color: white; border-color: var(--primary-color); }
        .btn-primary:hover { background: #40a9ff; border-color: #40a9ff; color: white; }
        
        .action-buttons { display: flex; gap: 8px; }
        .btn-action { padding: 4px 12px; border-radius: 4px; font-size: 14px; text-decoration: none; display: inline-block; transition: all 0.3s; background: #fff; cursor: pointer;}
        .btn-view-list { color: var(--primary-color); border: 1px solid var(--primary-color); }
        .btn-view-list:hover { background: var(--primary-color); color: white; }
        .btn-edit { color: var(--success-color); border: 1px solid var(--success-color); }
        .btn-edit:hover { background: var(--success-color); color: white; }
        .btn-delete { color: var(--danger-color); border: 1px solid var(--danger-color); }
        .btn-delete:hover { background: var(--danger-color); color: white; }

        /* 狀態標籤 */
        .status-badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500; }
        .status-active { background: #f6ffed; color: var(--success-color); border: 1px solid #b7eb8f; }
        .status-inactive { background: #fff2f0; color: var(--danger-color); border: 1px solid #ffccc7; }

        /* 分頁樣式 */
        .pagination { padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--border-color); background: #fafafa; }
        .pagination-info { color: var(--text-secondary-color); font-size: 14px; }
        .pagination-controls { display: flex; gap: 8px; }
        .pagination-btn { padding: 6px 12px; border: 1px solid #d9d9d9; background: #fff; color: #595959; border-radius: 6px; cursor: pointer; font-size: 14px; text-decoration: none; display: flex; align-items: center; justify-content: center; }
        .pagination-btn:hover { border-color: #1890ff; color: #1890ff; }
        .pagination-btn.active { background: #1890ff; color: white; border-color: #1890ff; }
        .pagination-btn.disabled { opacity: 0.5; cursor: not-allowed; pointer-events: none; }

        /* Modal */
        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.45); }
        .modal-content { background-color: #fff; margin: 5% auto; padding: 0; border-radius: 8px; width: 90%; max-width: 700px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .modal-header { padding: 16px 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }
        .modal-title { font-size: 16px; font-weight: 600; }
        .close { color: var(--text-secondary-color); font-size: 20px; font-weight: bold; cursor: pointer; }
        .close:hover { color: var(--text-color); }
        .modal-body { padding: 24px; max-height: 70vh; overflow-y: auto; }
        .modal-footer { padding: 16px 24px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 8px; background: #fafafa; border-radius: 0 0 8px 8px; }

        /* 其他 */
        .file-list, .link-list { list-style: none; padding: 0; margin: 0; }
        .file-list li, .link-list li { padding: 8px 0; border-bottom: 1px dashed #eee; display: flex; align-items: center; }
        .file-list li i, .link-list li i { margin-right: 8px; color: #888; }
        .file-link { text-decoration: none; color: var(--primary-color); }
        .file-link:hover { text-decoration: underline; }
        .section-title { font-weight: 600; margin: 20px 0 10px; padding-bottom: 5px; border-bottom: 2px solid #f0f0f0; color: #595959; font-size: 15px; }
        .message { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; font-weight: 500; }
        .message.success { background: #f6ffed; border: 1px solid #b7eb8f; color: var(--success-color); }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include 'sidebar.php'; ?>
        <div class="main-content" id="mainContent">
            <?php include 'header.php'; ?>
            
            <div class="content">
                <?php if (isset($_GET['msg']) && $_GET['msg'] == 'saved'): ?>
                    <div class="message success">公告儲存成功！</div>
                <?php elseif (isset($_GET['msg']) && $_GET['msg'] == 'deleted'): ?>
                    <div class="message success">公告已刪除！</div>
                <?php endif; ?>

                <div class="page-controls">
                    <div class="breadcrumb">
                        <a href="index.php">首頁</a> / 招生公告管理
                    </div>
                    <form method="GET" class="table-search">
                        <input type="text" name="search" placeholder="搜尋公告..." value="<?= htmlspecialchars($search) ?>">
                        <button type="submit" class="btn btn-primary">搜尋</button>
                        <a href="edit_bulletin.php" class="btn btn-primary" style="margin-left: 8px;">
                            <i class="fas fa-plus"></i> 新增公告
                        </a>
                    </form>
                </div>

                <div class="table-wrapper">
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>標題</th>
                                    <th>發布日期</th>
                                    <th>狀態</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($result->num_rows == 0): ?>
                                <tr><td colspan="4" style="text-align:center; padding: 30px; color: #999;">目前沒有公告資料</td></tr>
                                <?php endif; ?>

                                <?php 
                                while($row = $result->fetch_assoc()): 
                                    // 查詢該公告的檔案與連結
                                    $b_id = $row['id'];
                                    
                                    $u_stmt = $conn->prepare("SELECT * FROM bulletin_urls WHERE bulletin_id = ? ORDER BY display_order ASC");
                                    $u_stmt->bind_param("i", $b_id);
                                    $u_stmt->execute();
                                    $row['urls'] = $u_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                                    $u_stmt->close();
                                    
                                    $f_stmt = $conn->prepare("SELECT * FROM bulletin_files WHERE bulletin_id = ? ORDER BY display_order ASC");
                                    $f_stmt->bind_param("i", $b_id);
                                    $f_stmt->execute();
                                    $row['files'] = $f_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                                    $f_stmt->close();

                                    $bulletinsData[] = $row;
                                    
                                    // 狀態判斷
                                    $statusCode = $row['status_code'] ?? ($row['is_active'] ? 'published' : 'draft');
                                    $status = $statusMap[$statusCode] ?? ['label' => $statusCode, 'class' => 'status-inactive'];
                                ?>
                                <tr>
                                    <td>
                                        <?php if($row['is_pinned']): ?>
                                            <i class="fas fa-thumbtack" style="color: var(--warning-color); margin-right:5px;" title="置頂"></i>
                                        <?php endif; ?>
                                        <?= htmlspecialchars($row['title']) ?>
                                        <?php if(count($row['files']) > 0): ?>
                                            <i class="fas fa-paperclip" title="有附件" style="color: #999; font-size: 12px; margin-left: 5px;"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= date('Y/m/d H:i', strtotime($row['created_at'])) ?></td>
                                    <td><span class="status-badge <?= $status['class'] ?>"><?= $status['label'] ?></span></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button type="button" class="btn-action btn-view-list" onclick="viewBulletin(<?= $row['id'] ?>)">
                                                <i class="fas fa-eye"></i> 查看
                                            </button>
                                            <a href="edit_bulletin.php?id=<?= $row['id'] ?>" class="btn-action btn-edit">
                                                <i class="fas fa-edit"></i> 編輯
                                            </a>
                                            <form method="POST" onsubmit="return confirm('確定要刪除此公告嗎？');" style="margin:0;">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                                <button class="btn-action btn-delete"><i class="fas fa-trash"></i> 刪除</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?php if ($totalPages > 0): ?>
                    <div class="pagination">
                        <div class="pagination-info">
                            顯示第 <?= ($total > 0) ? ($offset + 1) . '-' . min($offset + $perPage, $total) : '0-0' ?> 筆，共 <?= $total ?> 筆
                        </div>
                        <div class="pagination-controls">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>" class="pagination-btn">上一頁</a>
                            <?php else: ?>
                                <span class="pagination-btn disabled">上一頁</span>
                            <?php endif; ?>

                            <?php 
                            $range = 2; 
                            for ($i = 1; $i <= $totalPages; $i++): 
                                if ($i == 1 || $i == $totalPages || ($i >= $page - $range && $i <= $page + $range)):
                            ?>
                                <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>" class="pagination-btn <?= $page == $i ? 'active' : '' ?>"><?= $i ?></a>
                            <?php elseif (($i == $page - $range - 1) || ($i == $page + $range + 1)): ?>
                                <span class="pagination-btn disabled">...</span>
                            <?php endif; endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>" class="pagination-btn">下一頁</a>
                            <?php else: ?>
                                <span class="pagination-btn disabled">下一頁</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="modalTitle">公告詳情</h3>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div style="margin-bottom: 15px; color: var(--text-secondary-color); font-size: 14px;">
                    發布時間：<span id="modalDate"></span>
                </div>
                
                <div id="modalContent" style="background: #fafafa; padding: 16px; border-radius: 6px; border: 1px solid #f0f0f0; line-height: 1.6; white-space: pre-wrap; margin-bottom: 20px; color: #595959;"></div>
                
                <div id="modalLinksSection" style="display: none;">
                    <div class="section-title">相關連結</div>
                    <ul id="modalLinks" class="link-list"></ul>
                </div>

                <div id="modalFilesSection" style="display: none;">
                    <div class="section-title">附件下載</div>
                    <ul id="modalFiles" class="file-list"></ul>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn" onclick="closeModal()">關閉</button>
            </div>
        </div>
    </div>

    <script>
    const bulletins = <?= json_encode($bulletinsData) ?>;

    function viewBulletin(id) {
        const data = bulletins.find(b => b.id == id);
        if (!data) return;

        document.getElementById('modalTitle').textContent = data.title;
        document.getElementById('modalDate').textContent = data.created_at;
        document.getElementById('modalContent').textContent = data.content;
        
        // 處理連結
        const linkList = document.getElementById('modalLinks');
        const linkSection = document.getElementById('modalLinksSection');
        linkList.innerHTML = '';
        if (data.urls && data.urls.length > 0) {
            linkSection.style.display = 'block';
            data.urls.forEach(url => {
                const li = document.createElement('li');
                li.innerHTML = `<i class="fas fa-link"></i> <a href="${url.url}" target="_blank" class="file-link">${url.title || url.url}</a>`;
                linkList.appendChild(li);
            });
        } else {
            linkSection.style.display = 'none';
        }

        // 處理檔案
        const fileList = document.getElementById('modalFiles');
        const fileSection = document.getElementById('modalFilesSection');
        fileList.innerHTML = '';
        if (data.files && data.files.length > 0) {
            fileSection.style.display = 'block';
            data.files.forEach(file => {
                // 使用原始路徑，讓瀏覽器處理 ../../
                let displayPath = file.file_path;
                
                const li = document.createElement('li');
                // 移除 download 屬性，保留 target="_blank" 以便在新分頁開啟預覽
                li.innerHTML = `<i class="fas fa-file-alt"></i> <a href="${displayPath}" target="_blank" class="file-link">${file.original_filename}</a> <span style="color:#999; font-size:12px; margin-left:5px;">(${formatBytes(file.file_size)})</span>`;
                fileList.appendChild(li);
            });
        } else {
            fileSection.style.display = 'none';
        }

        document.getElementById('viewModal').style.display = 'block';
    }

    function closeModal() {
        document.getElementById('viewModal').style.display = 'none';
    }
    
    function formatBytes(bytes, decimals = 2) {
        if (!+bytes) return '0 Bytes';
        const k = 1024;
        const dm = decimals < 0 ? 0 : decimals;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return `${parseFloat((bytes / Math.pow(k, i)).toFixed(dm))} ${sizes[i]}`;
    }

    window.onclick = function(event) {
        if (event.target == document.getElementById('viewModal')) {
            closeModal();
        }
    }
    </script>
</body>
</html>