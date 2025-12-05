<?php
session_start();

// 檢查是否已登入
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: login.php");
    exit;
}

// 引入資料庫設定
require_once '../../Topics-frontend/frontend/config.php';

// 設置頁面標題
$page_title = '招生問答管理';

// 建立資料庫連接
$conn = getDatabaseConnection();

$message = "";
$messageType = "";

// 處理表單提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            // 新增問答
            case 'add_qa':
                $sql = "INSERT INTO qa (question, answer, is_active) VALUES (?, ?, 1)";
                $stmt = $conn->prepare($sql);
                $question = $_POST['question'];
                $answer = $_POST['answer'];
                $stmt->bind_param("ss", $question, $answer);
                if ($stmt->execute()) {
                    $message = "問答新增成功！"; 
                    $messageType = "success";
                }
                break;

            // 更新問答
            case 'update_qa':
                $sql = "UPDATE qa SET question = ?, answer = ?, is_active = ? WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $question = $_POST['question'];
                $answer = $_POST['answer'];
                $is_active = intval($_POST['is_active']);
                $qa_id = intval($_POST['qa_id']);
                $stmt->bind_param("ssii", $question, $answer, $is_active, $qa_id);
                if ($stmt->execute()) {
                    $message = "問答更新成功！"; 
                    $messageType = "success";
                }
                break;

            // 刪除問答
            case 'delete_qa':
                $sql = "DELETE FROM qa WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $_POST['qa_id']);
                if ($stmt->execute()) {
                    $message = "問答刪除成功！"; 
                    $messageType = "success";
                }
                break;

        }
        if (isset($stmt) && $stmt->error) {
            throw new Exception($stmt->error);
        }
    } catch (Exception $e) {
        $message = "操作失敗：" . $e->getMessage();
        $messageType = "error";
    }
}

// 排序參數
$sortBy = $_GET['sort_by'] ?? 'id';
$sortOrder = $_GET['sort_order'] ?? 'desc';

// 驗證排序參數，防止 SQL 注入
$allowed_columns = ['id', 'question', 'answer', 'is_active'];
if (!in_array($sortBy, $allowed_columns)) {
    $sortBy = 'id';
}
if (!in_array(strtolower($sortOrder), ['asc', 'desc'])) {
    $sortOrder = 'desc';
}

// 獲取所有資料
$qa_sql = "SELECT * FROM qa ORDER BY $sortBy $sortOrder";
$qa_list = $conn->query($qa_sql)->fetch_all(MYSQLI_ASSOC);

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
            cursor: pointer; 
            user-select: none; 
            position: relative; 
        }
        .table th:hover { 
            background: #f0f0f0; 
        }
        .sort-icon {
            margin-left: 8px;
            font-size: 12px;
            color: #8c8c8c;
        }
        .sort-icon.active {
            color: #1890ff;
        }
        .sort-icon.asc::after {
            content: "↑";
        }
        .sort-icon.desc::after {
            content: "↓";
        }
        .table td { color: #595959; }
        .table tr:hover { background: #fafafa; }
        
        .table-search {
            display: flex;
            gap: 8px;
        }
        
        .table-search input {
            padding: 8px 12px;
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            width: 240px;
            background: #fff;
            font-size: 16px;
            transition: all 0.3s;
        }
        
        .table-search input:focus {
            outline: none;
            border-color: #1890ff;
            box-shadow: 0 0 0 2px rgba(24,144,255,0.2);
        }
        .table a.btn { text-decoration: none; }

        .btn { padding: 8px 16px; border: 1px solid #d9d9d9; border-radius: 6px; cursor: pointer; font-size: 14px; transition: all 0.3s; background: #fff; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 6px; }
        .btn-primary { background: var(--primary-color); color: white; border-color: var(--primary-color); }
        .btn-primary:hover { background: #40a9ff; border-color: #40a9ff; }

        /* 表格內操作按鈕樣式 */
        .action-buttons { display: flex; gap: 8px; }
        .btn-action {
            padding: 4px 12px; border-radius: 4px; font-size: 14px;
            text-decoration: none; display: inline-block; transition: all 0.3s;
            background: #fff;
        }
        .btn-edit { color: var(--success-color); border: 1px solid var(--success-color); }
        .btn-edit:hover { background: var(--success-color); color: white; }
        .btn-delete { color: var(--danger-color); border: 1px solid var(--danger-color); }
        .btn-delete:hover { background: var(--danger-color); color: white; }
        
        .form-group { margin-bottom: 20px; }
        .form-label { display: block; margin-bottom: 8px; font-weight: 500; }
        .form-control { width: 100%; padding: 10px 12px; border: 1px solid #d9d9d9; border-radius: 6px; font-size: 14px; transition: border-color 0.3s; }
        .form-control:focus { outline: none; border-color: var(--primary-color); box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.2); }
        textarea.form-control {
            min-height: 120px;
            resize: vertical;
        }

        .message { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; font-weight: 500; }
        .message.success { background: #f6ffed; border: 1px solid #b7eb8f; color: var(--success-color); }
        .message.error { background: #fff2f0; border: 1px solid #ffccc7; color: var(--danger-color); }

        .status-badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500; }
        .status-active { background: #f6ffed; color: var(--success-color); border: 1px solid #b7eb8f; }
        .status-inactive { background: #fff2f0; color: var(--danger-color); border: 1px solid #ffccc7; }

        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.45); }
        .modal-content { background-color: #fff; margin: 5% auto; padding: 0; border-radius: 8px; width: 90%; max-width: 600px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .modal-header { padding: 16px 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }
        .modal-title { font-size: 16px; font-weight: 600; }
        .close { color: var(--text-secondary-color); font-size: 20px; font-weight: bold; cursor: pointer; }
        .close:hover { color: var(--text-color); }
        .modal-body { padding: 24px; }
        .modal-footer { padding: 16px 24px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 8px; background: #fafafa; }
        .required-asterisk { color: var(--danger-color); margin-right: 4px; }
        
        .question-preview, .answer-preview {
            max-height: 100px;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
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
                    <div class="message <?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
                <?php endif; ?>

                <div class="page-controls">
                    <div class="breadcrumb">
                        <a href="index.php">首頁</a> / <?php echo $page_title; ?>
                    </div>
                    <div class="table-search">
                        <input type="text" id="tableSearchInput" placeholder="搜尋問題或答案..." onkeyup="filterTable()">
                        <button class="btn btn-primary" onclick="showModal('addQAModal')"><i class="fas fa-plus"></i> 新增問答</button>
                    </div>
                </div>

                <div class="table-wrapper">
                    <div class="table-container">
                        <table class="table" id="qaTable">
                            <thead>
                                <tr>
                                    <th onclick="sortTable('id')">ID <span class="sort-icon" id="sort-id"></span></th>
                                    <th onclick="sortTable('question')">問題 <span class="sort-icon" id="sort-question"></span></th>
                                    <th onclick="sortTable('answer')">答案 <span class="sort-icon" id="sort-answer"></span></th>
                                    <th onclick="sortTable('is_active')">狀態 <span class="sort-icon" id="sort-is_active"></span></th>
                                    <th>操作</th>                                       
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($qa_list as $item): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($item['id']); ?></td>
                                    <td>
                                        <div class="question-preview"><?php echo htmlspecialchars($item['question']); ?></div>
                                    </td>
                                    <td>
                                        <div class="answer-preview"><?php echo htmlspecialchars($item['answer']); ?></div>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $item['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                            <?php echo $item['is_active'] ? '啟用' : '停用'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn-action btn-edit" onclick='editQA(<?php echo json_encode($item); ?>)'>編輯</button>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('確定要刪除此問答嗎？');">
                                                <input type="hidden" name="action" value="delete_qa">
                                                <input type="hidden" name="qa_id" value="<?php echo $item['id']; ?>">
                                                <button type="submit" class="btn-action btn-delete">刪除</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- 新增問答 Modal -->
    <div id="addQAModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">新增問答</h3>
                <span class="close" onclick="closeModal('addQAModal')">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_qa">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label"><span class="required-asterisk">*</span>問題</label>
                        <textarea name="question" class="form-control" required placeholder="請輸入問題..."></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><span class="required-asterisk">*</span>答案</label>
                        <textarea name="answer" class="form-control" required placeholder="請輸入答案..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal('addQAModal')">取消</button>
                    <button type="submit" class="btn btn-primary">新增</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 編輯問答 Modal -->
    <div id="editQAModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">編輯問答</h3>
                <span class="close" onclick="closeModal('editQAModal')">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_qa">
                <input type="hidden" name="qa_id" id="edit_qa_id">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label"><span class="required-asterisk">*</span>問題</label>
                        <textarea name="question" id="edit_question" class="form-control" required></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><span class="required-asterisk">*</span>答案</label>
                        <textarea name="answer" id="edit_answer" class="form-control" required></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><span class="required-asterisk">*</span>狀態</label>
                        <select name="is_active" id="edit_is_active" class="form-control" required>
                            <option value="1">啟用</option>
                            <option value="0">停用</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal('editQAModal')">取消</button>
                    <button type="submit" class="btn btn-primary">儲存</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // 排序表格
        function sortTable(field) {
            let newSortOrder = 'asc';
            
            // 如果點擊的是當前排序欄位，則切換排序方向
            const urlParams = new URLSearchParams(window.location.search);
            const currentSortBy = urlParams.get('sort_by') || 'id';
            const currentSortOrder = urlParams.get('sort_order') || 'desc';
            
            if (currentSortBy === field) {
                newSortOrder = currentSortOrder === 'asc' ? 'desc' : 'asc';
            }
            
            window.location.href = `qa_management.php?sort_by=${field}&sort_order=${newSortOrder}`;
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
            const currentSortBy = urlParams.get('sort_by') || 'id';
            const currentSortOrder = urlParams.get('sort_order') || 'desc';
            
            // 設置當前排序欄位的圖標
            const currentIcon = document.getElementById(`sort-${currentSortBy}`);
            if (currentIcon) {
                currentIcon.className = `sort-icon active ${currentSortOrder}`;
            }
        }
        
        // 表格搜尋功能
        function filterTable() {
            const input = document.getElementById('tableSearchInput');
            const filter = input.value.toLowerCase();
            const table = document.getElementById('qaTable');
            
            if (!table) return;
            
            const tbody = table.getElementsByTagName('tbody')[0];
            if (!tbody) return;
            
            const rows = tbody.getElementsByTagName('tr');
            
            for (let i = 0; i < rows.length; i++) {
                const row = rows[i];
                const cells = row.getElementsByTagName('td');
                let found = false;
                
                // 搜尋問題和答案欄位（第2和第3欄）
                for (let j = 1; j < 3; j++) {
                    if (cells[j]) {
                        const cellText = cells[j].textContent || cells[j].innerText;
                        if (cellText.toLowerCase().indexOf(filter) > -1) {
                            found = true;
                            break;
                        }
                    }
                }
                
                row.style.display = found ? '' : 'none';
            }
        }

        function showModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
        
        // 頁面載入時更新排序圖標
        document.addEventListener('DOMContentLoaded', function() {
            updateSortIcons();
        });

        function editQA(item) {
            document.getElementById('edit_qa_id').value = item.id;
            document.getElementById('edit_question').value = item.question;
            document.getElementById('edit_answer').value = item.answer;
            document.getElementById('edit_is_active').value = item.is_active;
            showModal('editQAModal');
        }

    </script>
</body>
</html>

