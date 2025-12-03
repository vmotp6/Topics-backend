<?php
session_start();

// 檢查是否已登入，如果沒有登入則跳轉到登入頁面
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: login.php");
    exit;
}

// 處理登出
if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_destroy();
    header("Location: login.php");
    exit;
}

// 設置頁面標題
$page_title = '使用者管理';

// 引入資料庫設定
require_once '../../Topics-frontend/frontend/config.php';

$users = [];
$error_message = '';

try {
    $conn = getDatabaseConnection();

    // 獲取所有使用者資料 (移除後端排序，改用前端排序)
    $sql = "SELECT u.id, u.username, u.name, u.email, u.role, u.status, rt.name as role_name 
            FROM user u 
            LEFT JOIN role_types rt ON u.role = rt.code 
            ORDER BY u.id DESC";
    $result = $conn->query($sql);

    if ($result) {
        $users = $result->fetch_all(MYSQLI_ASSOC);
        // 如果沒有從 role_types 表獲取到名稱，使用預設映射
        foreach ($users as &$user) {
            if (empty($user['role_name'])) {
                $user['role_name'] = getRoleName($user['role']);
            }
        }
        unset($user);
    }
} catch (Exception $e) {
    $error_message = "讀取使用者資料失敗：" . $e->getMessage();
}

// 角色代碼到中文名稱的映射函數
function getRoleName($roleCode) {
    $roleMap = [
        'STU' => '學生', 'TEA' => '老師', 'ADM' => '管理員', 'STA' => '行政人員', 'DI' => '主任',
        'student' => '學生', 'teacher' => '老師', 'admin' => '管理員', 'staff' => '行政人員', 'director' => '主任'
    ];
    return $roleMap[$roleCode] ?? $roleCode;
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>使用者管理 - Topics 後台管理系統</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* 基礎樣式 */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background: #f0f2f5; color: #262626; overflow-x: hidden; }
        .dashboard { display: flex; min-height: 100vh; }
        .content { padding: 24px; }
        
        /* 頁面控制 */
        .page-controls { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; gap: 16px; }
        .breadcrumb { margin-bottom: 0; font-size: 16px; color: #8c8c8c; }
        .breadcrumb a { color: #1890ff; text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }
        
        /* 搜尋框 */
        .table-search { display: flex; gap: 8px; align-items: center; }
        .table-search input { padding: 8px 12px; border: 1px solid #d9d9d9; border-radius: 6px; width: 240px; background: #fff; font-size: 16px; transition: all 0.3s; }
        .table-search input:focus { outline: none; border-color: #1890ff; box-shadow: 0 0 0 2px rgba(24,144,255,0.2); }
        
        /* 表格容器 */
        .table-container { background: #fff; border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.03); overflow: hidden; border: 1px solid #f0f0f0; }
        .user-table { width: 100%; border-collapse: collapse; }
        .user-table th { background: #fafafa; padding: 16px 24px; text-align: left; font-weight: 600; color: #262626; border-bottom: 1px solid #f0f0f0; font-size: 16px; cursor: pointer; user-select: none; position: relative; }
        .user-table th:hover { background: #f0f0f0; }
        .user-table td { padding: 16px 24px; border-bottom: 1px solid #f0f0f0; font-size: 16px; color: #595959; }
        .user-table tr:hover { background: #fafafa; }
        
        /* 排序圖示 */
        .sort-icon { margin-left: 8px; font-size: 12px; color: #8c8c8c; }
        .sort-icon.active { color: #1890ff; }
        .sort-icon.asc::after { content: "↑"; }
        .sort-icon.desc::after { content: "↓"; }

        /* 徽章樣式 */
        .role-badge, .status-badge { padding: 4px 8px; border-radius: 4px; font-size: 14px; font-weight: 500; }
        .role-student { background: #f6ffed; color: #52c41a; border: 1px solid #b7eb8f; }
        .role-teacher { background: #e6f7ff; color: #1890ff; border: 1px solid #91d5ff; }
        .role-admin { background: #fff2e8; color: #fa8c16; border: 1px solid #ffd591; }
        .status-0 { background: #fff2f0; color: #ff4d4f; border: 1px solid #ffccc7; }
        .status-1 { background: #f6ffed; color: #52c41a; border: 1px solid #b7eb8f; }

        /* 按鈕樣式 */
        .action-buttons { display: flex; gap: 8px; }
        .btn-view, .btn-edit, .btn { padding: 4px 12px; border: 1px solid #d9d9d9; border-radius: 4px; cursor: pointer; font-size: 14px; text-decoration: none; display: inline-block; transition: all 0.3s; background: #fff; }
        .btn-view { color: #1890ff; border-color: #1890ff; }
        .btn-view:hover { background: #1890ff; color: white; }
        .btn-edit { color: #52c41a; border-color: #52c41a; }
        .btn-edit:hover { background: #52c41a; color: white; }
        .btn-primary { background: #1890ff; color: white; border-color: #1890ff; padding: 8px 12px; display: inline-flex; align-items: center; }
        .btn-primary:hover { background: #40a9ff; border-color: #40a9ff; }
        .btn-secondary { background: #fff; color: #595959; }
        .btn-secondary:hover { background: #f5f5f5; border-color: #40a9ff; color: #40a9ff; }

        /* 分頁控制樣式 (新增) */
        .pagination { padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #f0f0f0; background: #fafafa; }
        .pagination-info { display: flex; align-items: center; gap: 16px; color: #8c8c8c; font-size: 14px; }
        .pagination-controls { display: flex; align-items: center; gap: 8px; }
        .pagination select { padding: 6px 12px; border: 1px solid #d9d9d9; border-radius: 6px; font-size: 14px; background: #fff; cursor: pointer; }
        .pagination select:focus { outline: none; border-color: #1890ff; box-shadow: 0 0 0 2px rgba(24,144,255,0.2); }
        .pagination button { padding: 6px 12px; border: 1px solid #d9d9d9; background: #fff; color: #595959; border-radius: 6px; cursor: pointer; font-size: 14px; transition: all 0.3s; }
        .pagination button:hover:not(:disabled) { border-color: #1890ff; color: #1890ff; }
        .pagination button:disabled { opacity: 0.5; cursor: not-allowed; }
        .pagination button.active { background: #1890ff; color: white; border-color: #1890ff; }

        /* 模態框樣式 */
        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.45); }
        .modal-content { background-color: #fff; margin: 5% auto; padding: 0; border-radius: 8px; width: 90%; max-width: 520px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .modal-header { padding: 16px 24px; border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center; background: #fff; border-radius: 8px 8px 0 0; }
        .modal-body { padding: 24px; }
        .modal-footer { padding: 16px 24px; border-top: 1px solid #f0f0f0; display: flex; justify-content: flex-end; gap: 8px; background: #fafafa; border-radius: 0 0 8px 8px; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; }
        .form-group input { width: 100%; padding: 8px 12px; border: 1px solid #d9d9d9; border-radius: 6px; }
        .close { cursor: pointer; font-size: 20px; font-weight: bold; color: #8c8c8c; }
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
                        <a href="index.php">首頁</a> / 使用者管理
                    </div>
                    <div class="table-search">
                        <input type="text" id="searchInput" placeholder="搜尋使用者..." onkeyup="filterTable()">
                        <a href="add_user.php" class="btn btn-primary">
                            <i class="fas fa-plus" style="margin-right: 6px;"></i> 新增使用者
                        </a>
                    </div>
                </div>
                
                <div id="messageContainer"></div>
                
                <div class="table-container">
                    <table class="user-table" id="userTable">
                        <thead>
                            <tr>
                                <th onclick="sortTable(0)">ID <span class="sort-icon" id="sort-0"></span></th>
                                <th onclick="sortTable(1)">帳號 <span class="sort-icon" id="sort-1"></span></th>
                                <th onclick="sortTable(2)">姓名 <span class="sort-icon" id="sort-2"></span></th>
                                <th onclick="sortTable(3)">電子郵件 <span class="sort-icon" id="sort-3"></span></th>
                                <th onclick="sortTable(4)">角色 <span class="sort-icon" id="sort-4"></span></th>
                                <th onclick="sortTable(5)">狀態 <span class="sort-icon" id="sort-5"></span></th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody id="tableBody">
                            </tbody>
                    </table>
                    <div class="pagination">
                        <div class="pagination-info">
                            <span>每頁顯示：</span>
                            <select id="itemsPerPage" onchange="changeItemsPerPage()">
                                <option value="10" selected>10</option>
                                <option value="20" >20</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                                <option value="all">全部</option>
                            </select>
                            <span id="pageInfo">顯示第 <span id="currentRange">0-0</span> 筆，共 0 筆</span>
                        </div>
                        <div class="pagination-controls">
                            <button id="prevPage" onclick="changePage(-1)" disabled>上一頁</button>
                            <span id="pageNumbers"></span>
                            <button id="nextPage" onclick="changePage(1)">下一頁</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <span class="modal-title">查看用戶資料</span>
                <span class="close" onclick="closeModal('viewModal')">&times;</span>
            </div>
            <div class="modal-body">
                <div class="form-group"><label>ID:</label><input type="text" id="viewUserId" readonly></div>
                <div class="form-group"><label>帳號:</label><input type="text" id="viewUsername" readonly></div>
                <div class="form-group"><label>姓名:</label><input type="text" id="viewName" readonly></div>
                <div class="form-group"><label>電子郵件:</label><input type="email" id="viewEmail" readonly></div>
                <div class="form-group"><label>角色:</label><input type="text" id="viewRole" readonly></div>
                <div class="form-group"><label>狀態:</label><input type="text" id="viewStatus" readonly></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('viewModal')">關閉</button>
            </div>
        </div>
    </div>
    
    <script>
    // 原始使用者資料
    const users = <?php echo json_encode($users); ?>;
    
    // 分頁相關變數
    let currentPage = 1;
    let itemsPerPage = 10;
    let filteredRows = [...users]; // 初始為所有使用者
    
    // 排序相關變數
    let currentSortColumn = -1;
    let currentSortOrder = 'desc'; // 預設降序

    // 頁面載入初始化
    document.addEventListener('DOMContentLoaded', function() {
        updatePagination();
    });

    // 渲染表格
    function renderTable(data) {
        const tbody = document.getElementById('tableBody');
        tbody.innerHTML = '';
        
        if (data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding:20px;">沒有找到資料</td></tr>';
            return;
        }

        data.forEach(user => {
            const roleClass = getRoleClass(user.role);
            const userStatus = parseInt(user.status);
            const statusClass = userStatus === 1 ? 'status-1' : 'status-0';
            const statusText = userStatus === 1 ? '啟用' : '停用';
            
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${user.id}</td>
                <td>${escapeHtml(user.username)}</td>
                <td>${escapeHtml(user.name)}</td>
                <td>${escapeHtml(user.email)}</td>
                <td><span class="role-badge ${roleClass}">${escapeHtml(user.role_name || user.role)}</span></td>
                <td><span class="status-badge ${statusClass}">${statusText}</span></td>
                <td>
                    <div class="action-buttons">
                        <button onclick='viewUser(${JSON.stringify(user)})' class="btn-view">查看</button>
                        <button onclick="editUser(${user.id})" class="btn-edit">編輯</button>
                    </div>
                </td>
            `;
            tbody.appendChild(tr);
        });
    }

    // 搜尋功能
    function filterTable() {
        const input = document.getElementById('searchInput');
        const filter = input.value.toLowerCase();
        
        filteredRows = users.filter(user => {
            return (
                String(user.id).toLowerCase().includes(filter) ||
                (user.username && user.username.toLowerCase().includes(filter)) ||
                (user.name && user.name.toLowerCase().includes(filter)) ||
                (user.email && user.email.toLowerCase().includes(filter)) ||
                (user.role_name && user.role_name.toLowerCase().includes(filter))
            );
        });
        
        currentPage = 1; // 重置到第一頁
        updatePagination();
    }

    // 分頁邏輯
    function changeItemsPerPage() {
        itemsPerPage = document.getElementById('itemsPerPage').value === 'all' ? 
                      filteredRows.length : 
                      parseInt(document.getElementById('itemsPerPage').value);
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
        
        // 計算當前頁的資料
        let currentData = [];
        if (itemsPerPage === 'all' || itemsPerPage >= totalItems) {
            currentData = filteredRows;
            document.getElementById('currentRange').textContent = totalItems > 0 ? `1-${totalItems}` : '0-0';
        } else {
            const start = (currentPage - 1) * itemsPerPage;
            const end = Math.min(start + itemsPerPage, totalItems);
            currentData = filteredRows.slice(start, end);
            document.getElementById('currentRange').textContent = totalItems > 0 ? `${start + 1}-${end}` : '0-0';
        }
        
        renderTable(currentData);
        
        // 更新資訊
        document.getElementById('pageInfo').innerHTML = 
            `顯示第 <span id="currentRange">${document.getElementById('currentRange').textContent}</span> 筆，共 ${totalItems} 筆`;
        
        // 更新按鈕
        document.getElementById('prevPage').disabled = currentPage === 1;
        document.getElementById('nextPage').disabled = currentPage >= totalPages;
        
        updatePageNumbers(totalPages);
    }

    function updatePageNumbers(totalPages) {
        const pageNumbers = document.getElementById('pageNumbers');
        pageNumbers.innerHTML = '';
        if (totalPages <= 1) return;
        
        let startPage = Math.max(1, currentPage - 2);
        let endPage = Math.min(totalPages, currentPage + 2);
        
        if (currentPage <= 3) { startPage = 1; endPage = Math.min(5, totalPages); }
        if (currentPage >= totalPages - 2) { startPage = Math.max(1, totalPages - 4); endPage = totalPages; }
        
        if (startPage > 1) {
            addPageButton(1);
            if (startPage > 2) pageNumbers.appendChild(createEllipsis());
        }
        
        for (let i = startPage; i <= endPage; i++) {
            addPageButton(i);
        }
        
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) pageNumbers.appendChild(createEllipsis());
            addPageButton(totalPages);
        }
    }

    function addPageButton(page) {
        const btn = document.createElement('button');
        btn.textContent = page;
        btn.onclick = () => goToPage(page);
        if (page === currentPage) btn.classList.add('active');
        document.getElementById('pageNumbers').appendChild(btn);
    }

    function createEllipsis() {
        const span = document.createElement('span');
        span.textContent = '...';
        span.style.padding = '0 8px';
        return span;
    }

    // 排序功能
    function sortTable(colIndex) {
        // 對照索引與欄位名稱
        const keys = ['id', 'username', 'name', 'email', 'role_name', 'status'];
        const key = keys[colIndex];
        
        if (currentSortColumn === colIndex) {
            currentSortOrder = currentSortOrder === 'asc' ? 'desc' : 'asc';
        } else {
            currentSortColumn = colIndex;
            currentSortOrder = 'asc';
        }
        
        filteredRows.sort((a, b) => {
            let valA = a[key];
            let valB = b[key];
            
            // 處理數字
            if (key === 'id' || key === 'status') {
                valA = parseInt(valA);
                valB = parseInt(valB);
            } else {
                valA = String(valA || '').toLowerCase();
                valB = String(valB || '').toLowerCase();
            }
            
            if (valA < valB) return currentSortOrder === 'asc' ? -1 : 1;
            if (valA > valB) return currentSortOrder === 'asc' ? 1 : -1;
            return 0;
        });
        
        updateSortIcons(colIndex);
        currentPage = 1; // 排序後回到第一頁
        updatePagination();
    }

    function updateSortIcons(activeIndex) {
        for (let i = 0; i < 6; i++) {
            const icon = document.getElementById(`sort-${i}`);
            if (icon) icon.className = 'sort-icon';
        }
        const activeIcon = document.getElementById(`sort-${activeIndex}`);
        if (activeIcon) activeIcon.className = `sort-icon active ${currentSortOrder}`;
    }

    // 輔助函數
    function getRoleClass(role) {
        const r = String(role).toUpperCase();
        if (['STU', 'STUDENT'].includes(r)) return 'role-student';
        if (['TEA', 'TEACHER', 'STA', 'STAFF'].includes(r)) return 'role-teacher';
        if (['ADM', 'ADMIN', 'DI', 'DIRECTOR'].includes(r)) return 'role-admin';
        return 'role-student';
    }

    function escapeHtml(text) {
        if (text == null) return '';
        return String(text)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    // 動作函數
    function viewUser(user) { // 從物件直接讀取，不重新 fetch
        document.getElementById('viewUserId').value = user.id;
        document.getElementById('viewUsername').value = user.username;
        document.getElementById('viewName').value = user.name;
        document.getElementById('viewEmail').value = user.email;
        document.getElementById('viewRole').value = user.role_name || user.role;
        document.getElementById('viewStatus').value = user.status == 1 ? '啟用' : '停用';
        document.getElementById('viewModal').style.display = 'block';
    }

    function editUser(id) {
        window.location.href = `edit_user.php?id=${id}`;
    }

    function closeModal(id) {
        document.getElementById(id).style.display = 'none';
    }

    window.onclick = function(event) {
        const modal = document.getElementById('viewModal');
        if (event.target === modal) modal.style.display = 'none';
    }
    </script>
</body>
</html>