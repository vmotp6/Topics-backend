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
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Topics 後台管理系統</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Microsoft JhengHei', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f8f9fa;
            color: #333;
            overflow-x: hidden;
        }
        
        /* 主介面樣式 */
        .dashboard {
            display: flex;
            min-height: 100vh;
        }
        
        /* 側邊欄 */
        .sidebar {
            width: 250px;
            background: #1a1a1a;
            color: #fff;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            transition: all 0.3s ease;
            z-index: 1000;
        }
        
        .sidebar.collapsed {
            width: 60px;
        }
        
        .sidebar-header {
            padding: 20px;
            background: #000;
            text-align: center;
            border-bottom: 1px solid #333;
        }
        
        .sidebar-logo {
            font-size: 1.2em;
            font-weight: bold;
            color: #fff;
        }
        
        .sidebar.collapsed .sidebar-logo {
            font-size: 1em;
        }
        
        .sidebar-user {
            padding: 20px;
            border-bottom: 1px solid #333;
            text-align: center;
        }
        
        .sidebar.collapsed .sidebar-user {
            padding: 15px 10px;
        }
        
        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: #444;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            font-size: 20px;
        }
        
        .sidebar.collapsed .user-avatar {
            width: 35px;
            height: 35px;
            font-size: 16px;
        }
        
        .user-name {
            font-weight: 600;
            margin-bottom: 5px;
            font-size: 14px;
        }
        
        .sidebar.collapsed .user-name {
            display: none;
        }
        
        .user-role {
            font-size: 11px;
            color: #ccc;
        }
        
        .sidebar.collapsed .user-role {
            display: none;
        }
        
        .sidebar-menu {
            padding: 20px 0;
        }
        
        .sidebar.collapsed .sidebar-menu {
            padding: 10px 0;
        }
        
        .menu-item {
            padding: 12px 20px;
            display: flex;
            align-items: center;
            color: #ccc;
            text-decoration: none;
            transition: all 0.3s;
            border-left: 3px solid transparent;
            font-size: 14px;
        }
        
        .sidebar.collapsed .menu-item {
            padding: 15px 10px;
            justify-content: center;
        }
        
        .menu-item:hover {
            background: #333;
            border-left-color: #666;
            color: #fff;
        }
        
        .menu-item.active {
            background: #333;
            border-left-color: #fff;
            color: #fff;
        }
        
        .menu-item i {
            margin-right: 12px;
            width: 20px;
            text-align: center;
        }
        
        .sidebar.collapsed .menu-item i {
            margin-right: 0;
        }
        
        .sidebar.collapsed .menu-item span {
            display: none;
        }
        
        /* 收合按鈕 */
        .collapse-btn {
            position: absolute;
            top: 20px;
            right: -15px;
            width: 30px;
            height: 30px;
            background: #333;
            border: none;
            border-radius: 50%;
            color: #fff;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            transition: all 0.3s;
            z-index: 1001;
        }
        
        .collapse-btn:hover {
            background: #555;
        }
        
        /* 主內容區 */
        .main-content {
            flex: 1;
            margin-left: 250px;
            background: #f8f9fa;
            transition: all 0.3s ease;
        }
        
        .main-content.expanded {
            margin-left: 60px;
        }
        
        /* 頂部導航 */
        .top-nav {
            background: #fff;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid #e9ecef;
        }
        
        .nav-left {
            display: flex;
            align-items: center;
        }
        
        .nav-title {
            font-size: 1.3em;
            font-weight: 600;
            color: #333;
        }
        
        .nav-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .nav-search {
            position: relative;
        }
        
        .nav-search input {
            padding: 8px 15px 8px 35px;
            border: 1px solid #ddd;
            border-radius: 20px;
            width: 250px;
            background: #f8f9fa;
        }
        
        .nav-search i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #666;
        }
        
        .nav-notifications {
            position: relative;
            cursor: pointer;
        }
        
        .nav-notifications i {
            font-size: 18px;
            color: #666;
        }
        
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #dc3545;
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .nav-user {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
        }
        
        .nav-user-avatar {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            background: #666;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }
        
        /* 內容區域 */
        .content {
            padding: 30px;
        }
        
        /* 統計卡片 */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 20px;
            border: 1px solid #e9ecef;
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
        }
        
        .stat-icon.users { background: #495057; }
        .stat-icon.students { background: #6c757d; }
        .stat-icon.teachers { background: #495057; }
        
        .stat-info h3 {
            font-size: 2em;
            margin-bottom: 5px;
            color: #333;
        }
        
        .stat-info p {
            color: #666;
            font-weight: 500;
        }
        
        /* 表格區域 */
        .table-container {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            overflow: hidden;
            border: 1px solid #e9ecef;
        }
        
        .table-header {
            padding: 20px 30px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8f9fa;
        }
        
        .table-title {
            font-size: 1.3em;
            font-weight: 600;
            color: #333;
        }
        
        .table-search {
            display: flex;
            gap: 10px;
        }
        
        .table-search input {
            padding: 8px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            width: 250px;
            background: #fff;
        }
        
        .table-search button {
            padding: 8px 20px;
            background: #495057;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        .table-search button:hover {
            background: #6c757d;
        }
        
        .user-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .user-table th {
            background: #f8f9fa;
            padding: 15px 20px;
            text-align: left;
            font-weight: 600;
            color: #333;
            border-bottom: 1px solid #e9ecef;
        }
        
        .user-table td {
            padding: 15px 20px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .user-table tr:hover {
            background: #f8f9fa;
        }
        
        .role-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .role-student {
            background: #e9ecef;
            color: #495057;
        }
        
        .role-teacher {
            background: #f8f9fa;
            color: #6c757d;
        }
        
        .role-admin {
            background: #f8f9fa;
            color: #495057;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .btn-view, .btn-edit, .btn-delete {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            text-decoration: none;
            display: inline-block;
            transition: background 0.3s;
        }
        
        .btn-view {
            background: #495057;
            color: white;
        }
        
        .btn-view:hover {
            background: #6c757d;
        }
        
        .btn-edit {
            background: #6c757d;
            color: white;
        }
        
        .btn-edit:hover {
            background: #495057;
        }
        
        .btn-delete {
            background: #dc3545;
            color: white;
        }
        
        .btn-delete:hover {
            background: #c82333;
        }
        
        .message {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .loading {
            text-align: center;
            padding: 20px;
            color: #666;
        }
        
        /* 響應式設計 */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s;
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .table-search input {
                width: 200px;
            }
        }
    </style>
</head>
<body>
    <!-- 主介面 -->
    <div class="dashboard">
        <!-- 側邊欄 -->
        <div class="sidebar" id="sidebar">
            <button class="collapse-btn" id="collapseBtn">
                <i class="fas fa-bars"></i>
            </button>
            
            <div class="sidebar-header">
                <div class="sidebar-logo">Topics</div>
            </div>
            
            <div class="sidebar-user">
                <div class="user-avatar">
                    <i class="fas fa-user"></i>
                </div>
                <div class="user-name"><?php echo htmlspecialchars($_SESSION['admin_username']); ?></div>
                <div class="user-role">系統管理員</div>
            </div>
            
            <div class="sidebar-menu">
                <a href="#" class="menu-item active">
                    <i class="fas fa-home"></i>
                    <span>首頁</span>
                </a>
                <a href="#" class="menu-item">
                    <i class="fas fa-users"></i>
                    <span>使用者管理</span>
                </a>
                <a href="#" class="menu-item">
                    <i class="fas fa-chart-bar"></i>
                    <span>統計分析</span>
                </a>
                <a href="#" class="menu-item">
                    <i class="fas fa-cog"></i>
                    <span>系統設定</span>
                </a>
                <a href="?action=logout" class="menu-item">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>登出</span>
                </a>
            </div>
        </div>
        
        <!-- 主內容區 -->
        <div class="main-content" id="mainContent">
            <!-- 頂部導航 -->
            <div class="top-nav">
                <div class="nav-left">
                    <div class="nav-title">使用者管理</div>
                </div>
                
                <div class="nav-right">
                    <div class="nav-search">
                        <i class="fas fa-search"></i>
                        <input type="text" id="searchInput" placeholder="搜尋使用者..." onkeyup="searchUsers()">
                    </div>
                    
                    <div class="nav-notifications">
                        <i class="fas fa-bell"></i>
                        <span class="notification-badge">3</span>
                    </div>
                    
                    <div class="nav-user">
                        <div class="nav-user-avatar">A</div>
                        <span><?php echo htmlspecialchars($_SESSION['admin_username']); ?></span>
                    </div>
                </div>
            </div>
            
            <!-- 內容區域 -->
            <div class="content">
                <div id="messageContainer"></div>
                
                <!-- 統計卡片 -->
                <div class="stats-grid" id="statsGrid">
                    <div class="loading">載入中...</div>
                </div>
                
                <!-- 使用者表格 -->
                <div class="table-container">
                    <div class="table-header">
                        <div class="table-title">使用者列表</div>
                        <div class="table-search">
                            <input type="text" id="tableSearchInput" placeholder="搜尋使用者..." onkeyup="filterTable()">
                            <button onclick="filterTable()">搜尋</button>
                        </div>
                    </div>
                    
                    <div id="tableContainer">
                        <div class="loading">載入中...</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    const API_BASE_URL = 'http://localhost:5001';
    
    // 側邊欄收合功能
    document.getElementById('collapseBtn').addEventListener('click', function() {
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        
        sidebar.classList.toggle('collapsed');
        mainContent.classList.toggle('expanded');
        
        // 更新按鈕圖標
        const icon = this.querySelector('i');
        if (sidebar.classList.contains('collapsed')) {
            icon.className = 'fas fa-chevron-right';
        } else {
            icon.className = 'fas fa-bars';
        }
    });
    
    // 載入統計資料
    async function loadStats() {
        try {
            const response = await fetch(`${API_BASE_URL}/admin/stats`);
            const data = await response.json();
            
            if (response.ok) {
                document.getElementById('statsGrid').innerHTML = `
                    <div class="stat-card">
                        <div class="stat-icon users">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-info">
                            <h3>${data.total_users}</h3>
                            <p>總使用者數</p>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon students">
                            <i class="fas fa-graduation-cap"></i>
                        </div>
                        <div class="stat-info">
                            <h3>${data.total_students}</h3>
                            <p>學生數</p>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon teachers">
                            <i class="fas fa-chalkboard-teacher"></i>
                        </div>
                        <div class="stat-info">
                            <h3>${data.total_teachers}</h3>
                            <p>老師數</p>
                        </div>
                    </div>
                `;
            } else {
                showMessage('載入統計資料失敗', 'error');
            }
        } catch (error) {
            console.error('Error loading stats:', error);
            showMessage('載入統計資料失敗', 'error');
        }
    }
    
    // 載入使用者資料
    async function loadUsers() {
        try {
            const response = await fetch(`${API_BASE_URL}/admin/users`);
            const data = await response.json();
            
            if (response.ok) {
                renderUserTable(data.users);
            } else {
                showMessage('載入使用者資料失敗', 'error');
            }
        } catch (error) {
            console.error('Error loading users:', error);
            showMessage('載入使用者資料失敗', 'error');
        }
    }
    
    // 搜尋使用者
    async function searchUsers() {
        const query = document.getElementById('searchInput').value;
        if (query.length < 2) {
            loadUsers();
            return;
        }
        
        try {
            const response = await fetch(`${API_BASE_URL}/admin/users/search?q=${encodeURIComponent(query)}`);
            const data = await response.json();
            
            if (response.ok) {
                renderUserTable(data.users);
            } else {
                showMessage('搜尋失敗', 'error');
            }
        } catch (error) {
            console.error('Error searching users:', error);
            showMessage('搜尋失敗', 'error');
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
                        <th>ID</th>
                        <th>帳號</th>
                        <th>姓名</th>
                        <th>電子郵件</th>
                        <th>角色</th>
                        <th>註冊時間</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
        `;
        
        users.forEach(user => {
            const roleClass = getRoleClass(user.role);
            tableHTML += `
                <tr>
                    <td>${user.id}</td>
                    <td>${user.username}</td>
                    <td>${user.name}</td>
                    <td>${user.email}</td>
                    <td><span class="role-badge ${roleClass}">${user.role}</span></td>
                    <td>${user.created_at}</td>
                    <td>
                        <div class="action-buttons">
                            <a href="#" class="btn-view">查看</a>
                            <a href="#" class="btn-edit">編輯</a>
                            <button onclick="deleteUser(${user.id})" class="btn-delete">刪除</button>
                        </div>
                    </td>
                </tr>
            `;
        });
        
        tableHTML += '</tbody></table>';
        tableContainer.innerHTML = tableHTML;
    }
    
    // 獲取角色樣式類別
    function getRoleClass(role) {
        switch (role) {
            case 'student': return 'role-student';
            case 'teacher': return 'role-teacher';
            case 'admin': return 'role-admin';
            default: return 'role-student';
        }
    }
    
    // 刪除使用者
    async function deleteUser(userId) {
        if (!confirm('確定要刪除此使用者嗎？')) {
            return;
        }
        
        try {
            const response = await fetch(`${API_BASE_URL}/admin/users/${userId}`, {
                method: 'DELETE'
            });
            const data = await response.json();
            
            if (response.ok) {
                showMessage('使用者刪除成功', 'success');
                loadUsers();
                loadStats();
            } else {
                showMessage(data.message || '刪除失敗', 'error');
            }
        } catch (error) {
            console.error('Error deleting user:', error);
            showMessage('刪除失敗', 'error');
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
    
    // 表格搜尋功能
    function filterTable() {
        const input = document.getElementById('tableSearchInput');
        const filter = input.value.toLowerCase();
        const table = document.getElementById('userTable');
        
        if (!table) return;
        
        const tr = table.getElementsByTagName('tr');
        
        for (let i = 1; i < tr.length; i++) {
            const td = tr[i].getElementsByTagName('td');
            let found = false;
            
            for (let j = 0; j < td.length; j++) {
                const cell = td[j];
                if (cell) {
                    const txtValue = cell.textContent || cell.innerText;
                    if (txtValue.toLowerCase().indexOf(filter) > -1) {
                        found = true;
                        break;
                    }
                }
            }
            
            if (found) {
                tr[i].style.display = '';
            } else {
                tr[i].style.display = 'none';
            }
        }
    }
    
    // 頁面載入時執行
    document.addEventListener('DOMContentLoaded', function() {
        loadStats();
        loadUsers();
    });
    </script>
</body>
</html>
