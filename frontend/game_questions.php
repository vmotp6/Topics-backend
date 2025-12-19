<?php
session_start();
// 檢查權限
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: login.php");
    exit;
}

require_once '../../Topics-frontend/frontend/config.php';
$conn = getDatabaseConnection();

// 獲取並驗證分類
$category = isset($_GET['category']) ? $_GET['category'] : '';
$validCategories = [
    'fight' => '格鬥問答',
    'nursing' => '護理科互動'
];

if (!array_key_exists($category, $validCategories)) {
    header("Location: game_management.php");
    exit;
}

$categoryName = $validCategories[$category];

// 處理刪除
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $id = intval($_POST['id']);
    $stmt = $conn->prepare("DELETE FROM game_questions WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    header("Location: game_questions.php?category=" . urlencode($category) . "&msg=deleted");
    exit;
}

// 篩選參數
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

// 構建查詢
$where = "category = ?";
$params = [$category];
$types = "s";

if ($search) {
    $where .= " AND (question LIKE ? OR explanation LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $types .= "ss";
}

// 取得總數
$countSql = "SELECT COUNT(*) as total FROM game_questions WHERE $where";
$stmt = $conn->prepare($countSql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$total = $stmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($total / $perPage);
$stmt->close();

// 取得列表
$sql = "SELECT * FROM game_questions WHERE $where ORDER BY created_at DESC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$params[] = $perPage;
$params[] = $offset;
$types .= "ii";
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// 準備將資料輸出給 JS 使用的陣列
$questionsData = [];
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($categoryName) ?>題目管理</title>
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
        .main-content { flex: 1; overflow-x: hidden; }
        .content { padding: 24px; width: 100%; }

        /* 麵包屑與控制區 */
        .page-controls { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; gap: 16px; }
        .breadcrumb { margin-bottom: 0; font-size: 16px; color: var(--text-secondary-color); }
        .breadcrumb a { color: var(--primary-color); text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }

        /* 搜尋區 */
        .search-group { display: flex; gap: 8px; align-items: center; }
        .search-input {
            padding: 8px 12px; border: 1px solid #d9d9d9; border-radius: 6px; font-size: 14px; width: 250px; transition: all 0.3s;
        }
        .search-input:focus { outline: none; border-color: #1890ff; box-shadow: 0 0 0 2px rgba(24,144,255,0.2); }
        
        .btn {
            padding: 8px 16px; border: 1px solid #d9d9d9; border-radius: 6px; cursor: pointer;
            font-size: 14px; background: #fff; color: #595959; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: all 0.3s;
        }
        .btn:hover { border-color: var(--primary-color); color: var(--primary-color); }
        .btn-primary { background: var(--primary-color); color: white; border-color: var(--primary-color); }
        .btn-primary:hover { background: #40a9ff; border-color: #40a9ff; color: white; }

        /* 表格區 */
        .table-wrapper {
            background: var(--card-background-color); border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.03); border: 1px solid var(--border-color); margin-bottom: 24px;
        }
        .table-container { overflow-x: auto; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 16px 24px; text-align: left; border-bottom: 1px solid var(--border-color); font-size: 16px; white-space: nowrap; }
        .table th { background: #fafafa; font-weight: 600; color: #262626; }
        .table td { color: #595959; }
        .table tr:hover { background: #fafafa; }

        /* 狀態與按鈕 */
        .status-badge { padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600; }
        .status-active { background: #f6ffed; color: #52c41a; border: 1px solid #b7eb8f; }
        .status-inactive { background: #fff2f0; color: #ff4d4f; border: 1px solid #ffccc7; }

        .btn-action { padding: 4px 12px; border-radius: 4px; border: 1px solid; background: #fff; font-size: 13px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; }
        .btn-view { border-color: #1890ff; color: #1890ff; }
        .btn-view:hover { background: #1890ff; color: white; }
        .btn-edit { border-color: #52c41a; color: #52c41a; }
        .btn-edit:hover { background: #52c41a; color: white; }
        .btn-delete { border-color: #ff4d4f; color: #ff4d4f; }
        .btn-delete:hover { background: #ff4d4f; color: white; }

        /* 分頁 */
        .pagination { padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--border-color); background: #fafafa; }
        .page-link { width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border: 1px solid #d9d9d9; background: #fff; color: #595959; border-radius: 6px; text-decoration: none; }
        .page-link.active { background: #1890ff; color: white; border-color: #1890ff; }

        /* Modal 樣式 (模仿 users.php 但調整內容) */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
        .modal-content { background-color: #fff; margin: 5% auto; padding: 0; border-radius: 8px; width: 90%; max-width: 600px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); animation: slideIn 0.3s; }
        @keyframes slideIn { from {transform: translateY(-20px); opacity: 0;} to {transform: translateY(0); opacity: 1;} }
        
        .modal-header { padding: 16px 24px; border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center; }
        .modal-title { font-size: 18px; font-weight: 600; color: #262626; }
        .close { color: #8c8c8c; font-size: 24px; font-weight: bold; cursor: pointer; }
        .close:hover { color: #262626; }
        
        .modal-body { padding: 24px; max-height: 70vh; overflow-y: auto; }
        .detail-item { margin-bottom: 20px; }
        .detail-label { font-size: 14px; color: #8c8c8c; margin-bottom: 8px; font-weight: 500; }
        .detail-value { font-size: 16px; color: #262626; line-height: 1.6; background: #fafafa; padding: 12px; border-radius: 6px; border: 1px solid #f0f0f0; }
        .detail-value.correct { border: 1px solid #b7eb8f; background: #f6ffed; color: #52c41a; font-weight: 600; }
        .option-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        
        .modal-footer { padding: 16px 24px; border-top: 1px solid #f0f0f0; display: flex; justify-content: flex-end; background: #fafafa; border-radius: 0 0 8px 8px; }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include 'sidebar.php'; ?>
        <div class="main-content">
            <?php include 'header.php'; ?>
            
            <div class="content">
                <div class="page-controls">
                    <div class="breadcrumb">
                        <a href="index.php">首頁</a> / 
                        <a href="game_management.php">遊戲管理</a> / 
                        <?= htmlspecialchars($categoryName) ?>
                    </div>
                    
                    <form method="GET" class="search-group">
                        <input type="hidden" name="category" value="<?= htmlspecialchars($category) ?>">
                        <input type="text" name="search" class="search-input" placeholder="搜尋題目..." value="<?= htmlspecialchars($search) ?>">
                        <button type="submit" class="btn btn-primary">搜尋</button>
                        <a href="edit_game_question.php?category=<?= $category ?>" class="btn btn-primary">
                            <i class="fas fa-plus"></i> 新增題目
                        </a>
                    </form>
                </div>

                <div class="table-wrapper">
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>題目</th>
                                    <th width="100" style="text-align: center;">狀態</th>
                                    <th width="200" style="text-align: center;">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($result->num_rows == 0): ?>
                                <tr><td colspan="3" style="text-align:center; padding: 40px; color: #999;">目前沒有題目資料</td></tr>
                                <?php endif; ?>

                                <?php 
                                while($row = $result->fetch_assoc()): 
                                    // 儲存資料供 JS Modal 使用
                                    $questionsData[] = $row;
                                ?>
                                <tr>
                                    <td style="white-space: normal; min-width: 300px;">
                                        <?= htmlspecialchars(mb_strimwidth($row['question'], 0, 60, "...")) ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <?= $row['is_active'] ? '<span class="status-badge status-active">啟用</span>' : '<span class="status-badge status-inactive">停用</span>' ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <div style="display: flex; gap: 8px; justify-content: center;">
                                            <button type="button" class="btn-action btn-view" onclick="viewQuestion(<?= $row['id'] ?>)">
                                                <i class="fas fa-eye"></i> 查看
                                            </button>
                                            <a href="edit_game_question.php?id=<?= $row['id'] ?>&return_category=<?= $category ?>" class="btn-action btn-edit">
                                                <i class="fas fa-edit"></i> 編輯
                                            </a>
                                            <form method="POST" onsubmit="return confirm('確定要刪除此題目嗎？');" style="margin:0;">
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
                    
                    <?php if($totalPages > 1): ?>
                    <div class="pagination">
                        <div style="color:#8c8c8c; font-size:14px;">
                            顯示第 <?= $offset + 1 ?>-<?= min($offset + $perPage, $total) ?> 筆，共 <?= $total ?> 筆
                        </div>
                        <div style="display:flex; gap:5px;">
                            <?php if($page > 1): ?>
                                <a href="?category=<?= $category ?>&page=<?= $page-1 ?>&search=<?= $search ?>" class="page-link"><i class="fas fa-chevron-left"></i></a>
                            <?php endif; ?>
                            
                            <?php for($i=1; $i<=$totalPages; $i++): ?>
                                <a href="?category=<?= $category ?>&page=<?= $i ?>&search=<?= $search ?>" class="page-link <?= $page==$i ? 'active' : '' ?>"><?= $i ?></a>
                            <?php endfor; ?>
                            
                            <?php if($page < $totalPages): ?>
                                <a href="?category=<?= $category ?>&page=<?= $page+1 ?>&search=<?= $search ?>" class="page-link"><i class="fas fa-chevron-right"></i></a>
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
                <span class="modal-title">題目詳情</span>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="detail-item">
                    <div class="detail-label">題目內容</div>
                    <div class="detail-value" id="modalQuestion" style="font-weight: 500;"></div>
                </div>
                
                <div class="detail-item">
                    <div class="detail-label">選項 (綠色為正確答案)</div>
                    <div class="option-grid">
                        <div class="detail-value" id="modalOptionA">A: <span></span></div>
                        <div class="detail-value" id="modalOptionB">B: <span></span></div>
                        <div class="detail-value" id="modalOptionC">C: <span></span></div>
                        <div class="detail-value" id="modalOptionD">D: <span></span></div>
                    </div>
                </div>

                <div class="detail-item">
                    <div class="detail-label">解析</div>
                    <div class="detail-value" id="modalExplanation" style="color: #666; font-size: 15px;"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn" onclick="closeModal()">關閉</button>
            </div>
        </div>
    </div>

    <script>
    // 將 PHP 資料傳遞給 JS
    const questions = <?= json_encode($questionsData) ?>;

    function viewQuestion(id) {
        const data = questions.find(q => q.id == id);
        if (!data) return;

        // 填充資料
        document.getElementById('modalQuestion').textContent = data.question;
        
        // 設定選項內容與樣式
        const options = ['A', 'B', 'C', 'D'];
        options.forEach(opt => {
            const el = document.getElementById('modalOption' + opt);
            // 安全地處理 option_a/b/c/d
            const key = 'option_' + opt.toLowerCase();
            el.innerHTML = opt + ': ' + escapeHtml(data[key]);
            
            // 標記正確答案
            if (opt === data.correct_option) {
                el.className = 'detail-value correct';
            } else {
                el.className = 'detail-value';
            }
        });

        document.getElementById('modalExplanation').textContent = data.explanation || "無解析";
        
        // 顯示 Modal
        document.getElementById('viewModal').style.display = 'block';
    }

    function closeModal() {
        document.getElementById('viewModal').style.display = 'none';
    }

    // 點擊外部關閉
    window.onclick = function(event) {
        if (event.target == document.getElementById('viewModal')) {
            closeModal();
        }
    }

    // HTML 轉義防止 XSS
    function escapeHtml(text) {
        if (text == null) return "";
        return text
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }
    </script>
</body>
</html>