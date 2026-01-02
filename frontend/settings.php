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
$page_title = '場次設定';

// 建立資料庫連接
$conn = getDatabaseConnection();

$message = "";
$messageType = "";

// 處理表單提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            // 場次管理
            case 'add_session':
                $sql = "INSERT INTO admission_sessions (session_name, session_date, session_type, max_participants, is_active) VALUES (?, ?, ?, ?, 1)";
                $stmt = $conn->prepare($sql);
                // session_type: 1=線上, 2=實體
                $session_type = ($_POST['session_type'] === '線上' || $_POST['session_type'] === '1') ? 1 : 2;
                // 處理日期格式：從 datetime-local 轉換為 date
                $session_date = date('Y-m-d', strtotime($_POST['session_date']));
                // 處理 max_participants：如果為空則設為 null（使用變數引用以支持 null）
                $max_participants = !empty($_POST['max_participants']) ? intval($_POST['max_participants']) : null;
                // bind_param: session_name(s), session_date(s), session_type(i), max_participants(i/null)
                // 使用變數引用來處理 null 值
                $session_name = $_POST['session_name'];
                $stmt->bind_param("ssii", $session_name, $session_date, $session_type, $max_participants);
                if ($stmt->execute()) {
                    $message = "場次新增成功！"; $messageType = "success";
                }
                break;

            case 'update_session':
                $sql = "UPDATE admission_sessions SET session_name = ?, session_date = ?, session_type = ?, max_participants = ?, is_active = ? WHERE id = ?";
                $stmt = $conn->prepare($sql);
                // session_type: 1=線上, 2=實體
                $session_type = ($_POST['session_type'] === '線上' || $_POST['session_type'] === '1') ? 1 : 2;
                $is_active = intval($_POST['is_active']);
                $session_id = intval($_POST['session_id']);
                // 處理日期格式：從 datetime-local 轉換為 date
                $session_date = date('Y-m-d', strtotime($_POST['session_date']));
                // 處理 max_participants：如果為空則設為 null（使用變數引用以支持 null）
                $max_participants = !empty($_POST['max_participants']) ? intval($_POST['max_participants']) : null;
                // bind_param: session_name(s), session_date(s), session_type(i), max_participants(i/null), is_active(i), session_id(i)
                // 使用變數引用來處理 null 值
                $session_name = $_POST['session_name'];
                $stmt->bind_param("ssiiii", $session_name, $session_date, $session_type, $max_participants, $is_active, $session_id);
                if ($stmt->execute()) {
                    $message = "場次更新成功！"; $messageType = "success";
                }
                break;

            case 'delete_session':
                $sql = "DELETE FROM admission_sessions WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $_POST['session_id']);
                if ($stmt->execute()) {
                    $message = "場次刪除成功！"; $messageType = "success";
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
$sortBy = $_GET['sort_by'] ?? 'session_date';
$sortOrder = $_GET['sort_order'] ?? 'desc';

// 驗證排序參數，防止 SQL 注入
$allowed_columns = ['id', 'session_name', 'session_date', 'session_type', 'max_participants', 'is_active'];
if (!in_array($sortBy, $allowed_columns)) {
    $sortBy = 'session_date';
}
if (!in_array(strtolower($sortOrder), ['asc', 'desc'])) {
    $sortOrder = 'desc';
}

// 獲取所有資料
$sessions_sql = "
    SELECT s.*, 
           COUNT(a.id) as registration_count,
           (s.max_participants - COUNT(a.id)) as remaining_slots
    FROM admission_sessions s
    LEFT JOIN admission_applications a ON s.id = a.session_id 
    GROUP BY s.id 
    ORDER BY s.$sortBy $sortOrder";
$sessions = $conn->query($sessions_sql)->fetch_all(MYSQLI_ASSOC);

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

        /* 表格內操作按鈕樣式 (與 users.php 統一) */
        .action-buttons { display: flex; gap: 8px; }
        .btn-action {
            padding: 4px 12px; border-radius: 4px; font-size: 14px;
            text-decoration: none; display: inline-block; transition: all 0.3s;
            background: #fff;
        }
        .btn-edit { color: var(--success-color); border: 1px solid var(--success-color); }
        .btn-edit:hover { background: var(--success-color); color: white; }
        .btn-view-list { color: var(--primary-color); border: 1px solid var(--primary-color); }
        .btn-view-list:hover { background: var(--primary-color); color: white; }
        .btn-delete { color: var(--danger-color); border: 1px solid var(--danger-color); }
        .btn-delete:hover { background: var(--danger-color); color: white; }
        
        /* 移除 .btn-sm, .btn-danger, .btn-info 等舊樣式，改用新的 .btn-action 系列 */


        .form-group { margin-bottom: 20px; }
        .form-label { display: block; margin-bottom: 8px; font-weight: 500; }
        .form-control { width: 100%; padding: 10px 12px; border: 1px solid #d9d9d9; border-radius: 6px; font-size: 14px; transition: border-color 0.3s; }
        .form-control:focus { outline: none; border-color: var(--primary-color); box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.2); }
        .form-row { display: flex; gap: 16px; }
        .form-row .form-group { flex: 1; }

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
                        <input type="text" id="tableSearchInput" placeholder="搜尋場次..." onkeyup="filterTable()">
                        <button class="btn btn-primary" onclick="showModal('addSessionModal')"><i class="fas fa-plus"></i> 新增場次</button>
                    </div>
                </div>

                <div class="table-wrapper">
                    <div class="table-container">
                        <table class="table" id="sessionTable">
                            <thead>
                                <tr>
                                    <th onclick="sortTable('session_name')">場次名稱 <span class="sort-icon" id="sort-session_name"></span></th>
                                    <th onclick="sortTable('session_date')">日期時間 <span class="sort-icon" id="sort-session_date"></span></th>
                                    <th onclick="sortTable('session_type')">類型 <span class="sort-icon" id="sort-session_type"></span></th>
                                    <th onclick="sortTable('max_participants')">報名/上限 <span class="sort-icon" id="sort-max_participants"></span></th>
                                    <th>剩餘名額</th>
                                    <th onclick="sortTable('is_active')">狀態 <span class="sort-icon" id="sort-is_active"></span></th>
                                    <th>操作</th>                                       
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sessions as $item): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($item['session_name']); ?></td>
                                    <td><?php echo date('Y/m/d H:i', strtotime($item['session_date'])); ?></td>
                                    <td><?php echo ($item['session_type'] == 1) ? '線上' : '實體'; ?></td>
                                    <td><?php echo $item['registration_count']; ?> / <?php echo $item['max_participants'] ?: '無限'; ?></td>
                                    <td>
                                        <?php 
                                        if ($item['max_participants']) {
                                            if ($item['remaining_slots'] <= 0) {
                                                echo ' <span style="color: var(--danger-color);">(已額滿)</span>';
                                            } else {
                                                echo '剩餘 ' . $item['remaining_slots'] . ' 位';
                                            }
                                        } else {
                                            echo '無限';
                                        }
                                        ?>
                                    </td>
                                    <td><span class="status-badge <?php echo $item['is_active'] ? 'status-active' : 'status-inactive'; ?>"><?php echo $item['is_active'] ? '啟用' : '停用'; ?></span></td>
                                    <td>
                                        <div class="action-buttons">
                                        <button class="btn-action btn-edit" onclick='editSession(<?php echo json_encode($item); ?>)'>編輯</button>
                                        <a href="view_registrations.php?session_id=<?php echo $item['id']; ?>" class="btn-action btn-view-list">查看名單</a>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('確定要刪除此場次嗎？');">
                                            <input type="hidden" name="action" value="delete_session">
                                            <input type="hidden" name="session_id" value="<?php echo $item['id']; ?>">
                                            <button type="submit" class="btn-action btn-delete">刪除</button>
                                        </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- 分頁控制 -->
                    <?php if (!empty($sessions)): ?>
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
                            <span id="pageInfo">顯示第 <span id="currentRange">1-<?php echo min(10, count($sessions)); ?></span> 筆，共 <?php echo count($sessions); ?> 筆</span>
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
        </div>
    </div>

    <!-- 新增場次 Modal -->
    <div id="addSessionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">新增場次</h3>
                <span class="close" onclick="closeModal('addSessionModal')">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_session">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label"><span class="required-asterisk">*</span>場次名稱</label>
                        <input type="text" name="session_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><span class="required-asterisk">*</span>場次日期</label>
                        <input type="datetime-local" name="session_date" id="add_session_date" class="form-control" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label"><span class="required-asterisk">*</span>場次類型</label>
                            <select name="session_type" class="form-control" required>
                                <option value="實體">實體</option>
                                <option value="線上">線上</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">人數上限 (選填)</label>
                            <input type="number" name="max_participants" class="form-control" min="1">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal('addSessionModal')">取消</button>
                    <button type="submit" class="btn btn-primary">新增</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 編輯場次 Modal -->
    <div id="editSessionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">編輯場次</h3>
                <span class="close" onclick="closeModal('editSessionModal')">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_session">
                <input type="hidden" name="session_id" id="edit_session_id">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label"><span class="required-asterisk">*</span> 場次名稱</label>
                        <input type="text" name="session_name" id="edit_session_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><span class="required-asterisk">*</span> 場次日期</label>
                        <input type="datetime-local" name="session_date" id="edit_session_date" class="form-control" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label"><span class="required-asterisk">*</span> 場次類型</label>
                            <select name="session_type" id="edit_session_type" class="form-control" required>
                                <option value="實體">實體</option>
                                <option value="線上">線上</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">人數上限 (選填)</label>
                            <input type="number" name="max_participants" id="edit_max_participants" class="form-control" min="1">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><span class="required-asterisk">*</span> 狀態</label>
                        <select name="is_active" id="edit_session_is_active" class="form-control" required>
                            <option value="1">啟用</option>
                            <option value="0">停用</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal('editSessionModal')">取消</button>
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
            const currentSortBy = urlParams.get('sort_by') || 'session_date';
            const currentSortOrder = urlParams.get('sort_order') || 'desc';
            
            if (currentSortBy === field) {
                newSortOrder = currentSortOrder === 'asc' ? 'desc' : 'asc';
            }
            
            window.location.href = `settings.php?sort_by=${field}&sort_order=${newSortOrder}`;
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
            const currentSortBy = urlParams.get('sort_by') || 'session_date';
            const currentSortOrder = urlParams.get('sort_order') || 'desc';
            
            // 設置當前排序欄位的圖標
            const currentIcon = document.getElementById(`sort-${currentSortBy}`);
            if (currentIcon) {
                currentIcon.className = `sort-icon active ${currentSortOrder}`;
            }
        }
        
        // 分頁相關變數
        let currentPage = 1;
        let itemsPerPage = 10;
        let allRows = [];
        let filteredRows = [];
        
        // 初始化分頁
        function initPagination() {
            const table = document.getElementById('sessionTable');
            if (!table) return;
            
            const tbody = table.getElementsByTagName('tbody')[0];
            if (!tbody) return;
            
            allRows = Array.from(tbody.getElementsByTagName('tr'));
            filteredRows = allRows;
            
            updatePagination();
        }
        
        function changeItemsPerPage() {
            const select = document.getElementById('itemsPerPage');
            itemsPerPage = select.value === 'all' ? 
                          filteredRows.length : 
                          parseInt(select.value);
            currentPage = 1;
            updatePagination();
        }

        function changePage(direction) {
            const totalPages = Math.ceil(filteredRows.length / itemsPerPage);
            currentPage += direction;
            
            if (currentPage < 1) currentPage = 1;
            if (currentPage > totalPages) currentPage = totalPages;
            
            updatePagination();
        }

        function goToPage(page) {
            currentPage = page;
            updatePagination();
        }

        function updatePagination() {
            const totalItems = filteredRows.length;
            const totalPages = itemsPerPage === 'all' ? 1 : Math.ceil(totalItems / itemsPerPage);
            
            // 隱藏所有行
            allRows.forEach(row => row.style.display = 'none');
            
            if (itemsPerPage === 'all' || itemsPerPage >= totalItems) {
                // 顯示所有過濾後的行
                filteredRows.forEach(row => row.style.display = '');
                
                // 更新分頁資訊
                document.getElementById('currentRange').textContent = 
                    totalItems > 0 ? `1-${totalItems}` : '0-0';
            } else {
                // 計算當前頁的範圍
                const start = (currentPage - 1) * itemsPerPage;
                const end = Math.min(start + itemsPerPage, totalItems);
                
                // 顯示當前頁的行
                for (let i = start; i < end; i++) {
                    if (filteredRows[i]) {
                        filteredRows[i].style.display = '';
                    }
                }
                
                // 更新分頁資訊
                document.getElementById('currentRange').textContent = 
                    totalItems > 0 ? `${start + 1}-${end}` : '0-0';
            }
            
            // 更新總數
            document.getElementById('pageInfo').innerHTML = 
                `顯示第 <span id="currentRange">${document.getElementById('currentRange').textContent}</span> 筆，共 ${totalItems} 筆`;
            
            // 更新上一頁/下一頁按鈕
            document.getElementById('prevPage').disabled = currentPage === 1;
            document.getElementById('nextPage').disabled = currentPage >= totalPages || totalPages <= 1;
            
            // 更新頁碼按鈕
            updatePageNumbers(totalPages);
        }

        function updatePageNumbers(totalPages) {
            const pageNumbers = document.getElementById('pageNumbers');
            pageNumbers.innerHTML = '';
            
            // 總是顯示頁碼按鈕（即使只有1頁）
            if (totalPages >= 1) {
                // 如果只有1頁，只顯示"1"
                // 如果有多頁，顯示所有頁碼
                const pagesToShow = totalPages === 1 ? [1] : Array.from({length: totalPages}, (_, i) => i + 1);
                
                for (let i of pagesToShow) {
                    const btn = document.createElement('button');
                    btn.textContent = i;
                    btn.onclick = () => goToPage(i);
                    if (i === currentPage) btn.classList.add('active');
                    pageNumbers.appendChild(btn);
                }
            }
        }
        
        // 表格搜尋功能
        function filterTable() {
            const input = document.getElementById('tableSearchInput');
            const filter = input.value.toLowerCase();
            const table = document.getElementById('sessionTable');
            
            if (!table) return;
            
            const tbody = table.getElementsByTagName('tbody')[0];
            if (!tbody) return;
            
            allRows = Array.from(tbody.getElementsByTagName('tr'));
            
            filteredRows = allRows.filter(row => {
                const cells = row.getElementsByTagName('td');
                for (let j = 0; j < cells.length; j++) {
                    const cell = cells[j];
                    if (cell) {
                        const txtValue = cell.textContent || cell.innerText;
                        if (txtValue.toLowerCase().indexOf(filter) > -1) {
                            return true;
                        }
                    }
                }
                return false;
            });
            
            currentPage = 1;
            updatePagination();
        }

        function showModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
            
            // 如果是新增場次模態框，設置日期最小值為今天
            if (modalId === 'addSessionModal') {
                const dateInput = document.getElementById('add_session_date');
                if (dateInput) {
                    // 獲取今天的日期時間，格式為 YYYY-MM-DDTHH:mm
                    const now = new Date();
                    const year = now.getFullYear();
                    const month = String(now.getMonth() + 1).padStart(2, '0');
                    const day = String(now.getDate()).padStart(2, '0');
                    const hours = String(now.getHours()).padStart(2, '0');
                    const minutes = String(now.getMinutes()).padStart(2, '0');
                    const minDateTime = `${year}-${month}-${day}T${hours}:${minutes}`;
                    dateInput.setAttribute('min', minDateTime);
                    dateInput.value = ''; // 清空之前的值
                }
            }
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
        
        // 頁面載入時更新排序圖標和初始化分頁
        document.addEventListener('DOMContentLoaded', function() {
            updateSortIcons();
            initPagination();
        });

        function editSession(item) {
            document.getElementById('edit_session_id').value = item.id;
            document.getElementById('edit_session_name').value = item.session_name;
            // 格式化日期以符合 datetime-local input
            const date = new Date(item.session_date.replace(' ', 'T'));
            const formattedDate = date.toISOString().slice(0, 16);
            document.getElementById('edit_session_date').value = formattedDate;
            // session_type 在資料庫中是數字 (1=線上, 2=實體)，但表單需要顯示文字
            const sessionTypeMap = {1: '線上', 2: '實體'};
            document.getElementById('edit_session_type').value = sessionTypeMap[item.session_type] || item.session_type;
            document.getElementById('edit_max_participants').value = item.max_participants || '';
            document.getElementById('edit_session_is_active').value = item.is_active;
            showModal('editSessionModal');
        }

    </script>
</body>
</html>