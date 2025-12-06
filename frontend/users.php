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

    // 排序參數
    $sortBy = $_GET['sort_by'] ?? 'id';
    $sortOrder = $_GET['sort_order'] ?? 'desc';

    // 驗證排序參數，防止 SQL 注入
    $allowed_columns = ['id', 'username', 'name', 'email', 'role', 'status'];
    if (!in_array($sortBy, $allowed_columns)) {
        $sortBy = 'id';
    }
    if (!in_array(strtolower($sortOrder), ['asc', 'desc'])) {
        $sortOrder = 'desc';
    }

    $sql = "SELECT u.id, u.username, u.name, u.email, u.role, u.status, rt.name as role_name 
            FROM user u 
            LEFT JOIN role_types rt ON u.role = rt.code 
            ORDER BY $sortBy $sortOrder";
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
        'STU' => '學生',
        'TEA' => '老師',
        'ADM' => '管理員',
        'STA' => '行政人員',
        'DI' => '主任',
        // 兼容舊代碼
        'student' => '學生',
        'teacher' => '老師',
        'admin' => '管理員',
        'staff' => '行政人員',
        'director' => '主任'
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
        
        .page-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            gap: 16px;
        }
        
        /* 主介面樣式 */
        .dashboard {
            display: flex;
            min-height: 100vh;
        }
        

        
        /* 內容區域 */
        .content {
            padding: 24px;
        }
        
        /* 麵包屑 */
        .breadcrumb {
            margin-bottom: 0; /* 從 page-controls 控制 */
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
        
        /* 表格區域 */
        .table-container {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            overflow: hidden;
            border: 1px solid #f0f0f0;
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
        
        .user-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .user-table th {
            background: #fafafa;
            padding: 16px 24px;
            text-align: left;
            font-weight: 600;
            color: #262626;
            border-bottom: 1px solid #f0f0f0;
            font-size: 16px;
            cursor: pointer;
            user-select: none;
            position: relative;
        }
        
        .user-table th:first-child, .user-table td:first-child {
            padding-left: 60px;
        }
        
        .user-table th:hover {
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
        
        .user-table td {
            padding: 16px 24px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 16px;
            color: #595959;
        }
        
        .user-table tr:hover {
            background: #fafafa;
        }
        
        .role-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
        }
        
        .role-student {
            background: #f6ffed;
            color: #52c41a;
            border: 1px solid #b7eb8f;
        }
        
        .role-teacher {
            background: #e6f7ff;
            color: #1890ff;
            border: 1px solid #91d5ff;
        }
        
        .role-admin {
            background: #fff2e8;
            color: #fa8c16;
            border: 1px solid #ffd591;
        }
        
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
        }
        
        .status-0 {
            background: #fff2f0;
            color: #ff4d4f;
            border: 1px solid #ffccc7;
        }
        
        .status-1 {
            background: #f6ffed;
            color: #52c41a;
            border: 1px solid #b7eb8f;
        }
        
        .status-2 {
            background: #e6f7ff;
            color: #1890ff;
            border: 1px solid #91d5ff;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .btn-view, .btn-edit {
            padding: 4px 12px;
            border: 1px solid #d9d9d9;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
            background: #fff;
        }
        
        .btn-view {
            color: #1890ff;
            border-color: #1890ff;
        }
        
        .btn-view:hover {
            background: #1890ff;
            color: white;
        }
        
        .btn-edit {
            color: #52c41a;
            border-color: #52c41a;
        }
        
        .btn-edit:hover {
            background: #52c41a;
            color: white;
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
        
        .loading {
            text-align: center;
            padding: 40px;
            color: #8c8c8c;
            font-size: 14px;
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
            max-width: 520px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .modal-header {
            padding: 16px 24px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #fff;
            border-radius: 8px 8px 0 0;
        }
        
        .modal-title {
            font-size: 16px;
            font-weight: 600;
            color: #262626;
        }
        
        .close {
            color: #8c8c8c;
            font-size: 20px;
            font-weight: bold;
            cursor: pointer;
            padding: 4px;
            border-radius: 4px;
            transition: all 0.3s;
        }
        
        .close:hover {
            color: #262626;
            background: #f5f5f5;
        }
        
        .modal-body {
            padding: 24px;
        }
        
        .form-group {
            margin-bottom: 16px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #262626;
            font-size: 14px;
        }
        
        .form-group input, .form-group select {
            width: 100%;
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
        
        .form-group input[readonly] {
            background: #f5f5f5;
            color: #8c8c8c;
        }
        
        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid #f0f0f0;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            background: #fafafa;
            border-radius: 0 0 8px 8px;
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
            border-color: #40a9ff;
            color: #40a9ff;
        }
        
        /* 分頁樣式 */
        .pagination {
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 1px solid #f0f0f0;
            background: #fafafa;
        }

        .pagination-info {
            display: flex;
            align-items: center;
            gap: 16px;
            color: #8c8c8c;
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

        /* 響應式設計 */
        @media (max-width: 768px) {
            .table-search input {
                width: 200px;
            }
            
            .nav-search input {
                width: 200px;
            }
        }
    </style>
</head>
<body>
    <!-- 主介面 -->
    <div class="dashboard">
        <!-- 引入側邊欄 -->
        <?php include 'sidebar.php'; ?>
        
        <!-- 主內容區 -->
        <div class="main-content" id="mainContent">
            <!-- 引入標題欄 -->
            <?php include 'header.php'; ?>
            
            <!-- 內容區域 -->
            <div class="content">
                <!-- 麵包屑 -->
                <div class="page-controls">
                    <div class="breadcrumb">
                        <a href="index.php">首頁</a> / 使用者管理
                    </div>
                    <div class="table-search">
                        <input type="text" id="tableSearchInput" placeholder="搜尋使用者..." onkeyup="filterTable()">
                        <a href="add_user.php" class="btn btn-primary" style="padding: 8px 12px; font-size: 14px;">
                            <i class="fas fa-plus" style="margin-right: 6px;"></i>
                            新增使用者
                        </a>
                    </div>
                </div>
                
                <div id="messageContainer"></div>
                
                <!-- 使用者表格 -->
                <div class="table-container">
                    <div id="tableContainer" data-users='<?php echo json_encode($users); ?>'>
                        <div class="loading">載入中...</div>
                    </div>
                    <!-- 分頁控制 -->
                    <div class="pagination" id="paginationContainer" style="display: none;">
                        <div class="pagination-info">
                            <span>每頁顯示：</span>
                            <select id="itemsPerPage" onchange="changeItemsPerPage()">
                                <option value="10" selected>10</option>
                                <option value="20">20</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                                <option value="all">全部</option>
                            </select>
                            <span id="pageInfo">顯示第 <span id="currentRange">1-10</span> 筆，共 0 筆</span>
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
    
    <!-- 查看用戶模態對話框 -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <span class="modal-title">查看用戶資料</span>
                <span class="close" onclick="closeModal('viewModal')">&times;</span>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>ID:</label>
                    <input type="text" id="viewUserId" readonly>
                </div>
                <div class="form-group">
                    <label>帳號:</label>
                    <input type="text" id="viewUsername" readonly>
                </div>
                <div class="form-group">
                    <label>姓名:</label>
                    <input type="text" id="viewName" readonly>
                </div>
                <div class="form-group">
                    <label>電子郵件:</label>
                    <input type="email" id="viewEmail" readonly>
                </div>
                <div class="form-group">
                    <label>角色:</label>
                    <input type="text" id="viewRole" readonly>
                </div>
                <div class="form-group">
                    <label>狀態:</label>
                    <input type="text" id="viewStatus" readonly>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('viewModal')">關閉</button>
            </div>
        </div>
    </div>
    
    <script>
    // 排序表格
    function sortTable(field) {
        let newSortOrder = 'asc';
        
        // 如果點擊的是當前排序欄位，則切換排序方向
        if (currentSortBy === field) {
            newSortOrder = currentSortOrder === 'asc' ? 'desc' : 'asc';
        }
        
        window.location.href = `users.php?sort_by=${field}&sort_order=${newSortOrder}`;
    }
    
    // 更新排序圖標
    function updateSortIcons() {
        // 清除所有圖標
        const icons = document.querySelectorAll('.sort-icon');
        icons.forEach(icon => {
            icon.className = 'sort-icon';
        });
        
        // 設置當前排序欄位的圖標
        const currentIcon = document.getElementById(`sort-${currentSortBy}`);
        if (currentIcon) {
            currentIcon.className = `sort-icon active ${currentSortOrder}`;
        }
    }
    
    // 渲染使用者表格
    function renderUserTable(users) {
        const tableContainer = document.getElementById('tableContainer');
        
        if (users.length === 0) {
            tableContainer.innerHTML = '<div class="loading">沒有找到使用者資料</div>';
            return;
        }
        
        let tableHTML = `
            <table class="user-table" id="userTable">
                <thead>
                    <tr>
                        <th onclick="sortTable('id')">ID <span class="sort-icon" id="sort-id"></span></th>
                        <th onclick="sortTable('username')">帳號 <span class="sort-icon" id="sort-username"></span></th>
                        <th onclick="sortTable('name')">姓名 <span class="sort-icon" id="sort-name"></span></th>
                        <th onclick="sortTable('email')">電子郵件 <span class="sort-icon" id="sort-email"></span></th>
                        <th onclick="sortTable('role')">角色 <span class="sort-icon" id="sort-role"></span></th>
                        <th onclick="sortTable('status')">狀態 <span class="sort-icon" id="sort-status"></span></th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
        `;
        
        users.forEach(user => {
            const roleClass = getRoleClass(user.role);
            // 確保 status 是數字類型
            const userStatus = parseInt(user.status);
            const statusClass = getStatusClass(userStatus);
            const roleName = user.role_name || getRoleName(user.role);
            tableHTML += `
                <tr>
                    <td>${user.id}</td>
                    <td>${user.username}</td>
                    <td>${user.name}</td>
                    <td>${user.email}</td>
                    <td><span class="role-badge ${roleClass}">${roleName}</span></td>
                    <td><span class="status-badge ${statusClass}">${userStatus === 0 ? '停用' : '啟用'}</span></td>
                    <td>
                        <div class="action-buttons">
                            <button onclick="viewUser(${user.id})" class="btn-view">查看</button>
                            <button onclick="editUser(${user.id})" class="btn-edit">編輯</button>
                        </div>
                    </td>
                </tr>
            `;
        });
        
        tableHTML += '</tbody></table>';
        tableContainer.innerHTML = tableHTML;
        
        // 初始化分頁
        initPagination();
    }
    
    // 獲取角色樣式類別
    function getRoleClass(role) {
        // 支援新舊代碼格式
        const roleUpper = role.toUpperCase();
        switch (roleUpper) {
            case 'STU':
            case 'STUDENT': return 'role-student';
            case 'TEA':
            case 'TEACHER': return 'role-teacher';
            case 'ADM':
            case 'ADMIN': return 'role-admin';
            case 'STA':
            case 'STAFF': return 'role-teacher'; // 行政人員使用老師樣式
            case 'DI':
            case 'DIRECTOR': return 'role-admin'; // 主任使用管理員樣式
            default: return 'role-student';
        }
    }
    
    // 獲取角色中文名稱
    function getRoleName(roleCode) {
        const roleMap = {
            'STU': '學生', 'student': '學生',
            'TEA': '老師', 'teacher': '老師',
            'ADM': '管理員', 'admin': '管理員',
            'STA': '行政人員', 'staff': '行政人員',
            'DI': '主任', 'director': '主任'
        };
        const roleUpper = roleCode.toUpperCase();
        return roleMap[roleCode] || roleMap[roleUpper] || roleCode;
    }

    // 獲取狀態樣式類別
    function getStatusClass(status) {
        // 確保 status 是數字類型，處理字符串 "0", "1" 或數字 0, 1
        const statusNum = parseInt(status, 10);
        if (isNaN(statusNum)) {
            return 'status-0'; // 如果無法解析，預設為停用
        }
        if (statusNum === 1) {
            return 'status-1'; // 啟用 - 綠色
        } else {
            return 'status-0'; // 停用 - 紅色
        }
    }
    
    // 查看使用者
    async function viewUser(userId) {
        const tableContainer = document.getElementById('tableContainer');
        const users = JSON.parse(tableContainer.dataset.users);
        const user = users.find(u => u.id == userId);

        if (user) {
            document.getElementById('viewUserId').value = user.id;
            document.getElementById('viewUsername').value = user.username;
            document.getElementById('viewName').value = user.name;
            document.getElementById('viewEmail').value = user.email;
            const roleName = user.role_name || getRoleName(user.role);
            document.getElementById('viewRole').value = roleName;
            // 確保 status 是數字類型
            const userStatus = parseInt(user.status);
            document.getElementById('viewStatus').value = userStatus === 0 ? '停用' : '啟用';
            
            document.getElementById('viewModal').style.display = 'block';
        } else {
            showMessage('載入使用者資料失敗', 'error');
        }
    }

    // 搜尋使用者 (本地端)
    function searchUsers() {
        const query = document.getElementById('searchInput').value.toLowerCase();
        const rows = document.querySelectorAll('#userTable tbody tr');
        // ... (此處省略本地搜尋邏輯，因為 filterTable 已實現)
        filterTable();
    }
    
    // 編輯使用者 - 跳轉到編輯頁面
    function editUser(userId) {
        window.location.href = `edit_user.php?id=${userId}`;
    }
    
    // 關閉模態對話框
    function closeModal(modalId) {
        document.getElementById(modalId).style.display = 'none';
    }
    
    // 點擊模態對話框外部關閉
    window.onclick = function(event) {
        const viewModal = document.getElementById('viewModal');
        
        if (event.target === viewModal) {
            viewModal.style.display = 'none';
        }
    }
    
    // 顯示訊息
    function showMessage(message, type) {
        const messageContainer = document.getElementById('messageContainer');
        messageContainer.innerHTML = `<div class="message ${type}">${message}</div>`;
        
        setTimeout(() => {
            messageContainer.innerHTML = '';
        }, 3000);
    }
    
    // 分頁相關變數
    let currentPage = 1;
    let itemsPerPage = 10;
    let allRows = [];
    let filteredRows = [];
    
    // 初始化分頁
    function initPagination() {
        const table = document.getElementById('userTable');
        if (!table) return;
        
        const tbody = table.getElementsByTagName('tbody')[0];
        if (!tbody) return;
        
        allRows = Array.from(tbody.getElementsByTagName('tr'));
        filteredRows = allRows;
        
        // 顯示分頁控制
        const paginationContainer = document.getElementById('paginationContainer');
        if (paginationContainer && allRows.length > 0) {
            paginationContainer.style.display = 'flex';
        }
        
        // 初始化分頁
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
        const table = document.getElementById('userTable');
        
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
    
    // 頁面載入時執行
    document.addEventListener('DOMContentLoaded', function() {
        const tableContainer = document.getElementById('tableContainer');
        const usersData = tableContainer.dataset.users;

        if (usersData) {
            const users = JSON.parse(usersData);
            renderUserTable(users);
        } else {
            showMessage('無法載入使用者資料', 'error');
        }

        // 獲取當前 URL 的排序參數來更新圖標
        const urlParams = new URLSearchParams(window.location.search);
        currentSortBy = urlParams.get('sort_by') || 'id';
        currentSortOrder = urlParams.get('sort_order') || 'desc';
        updateSortIcons();
    });
    </script>
</body>
</html>
