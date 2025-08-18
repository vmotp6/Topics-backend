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
        
        /* 主介面樣式 */
        .dashboard {
            display: flex;
            min-height: 100vh;
        }
        

        
        /* 內容區域 */
        .content {
            padding: 18px 36px;;
        }
        
        /* 麵包屑 */
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
        
        /* 表格區域 */
        .table-container {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            overflow: hidden;
            border: 1px solid #f0f0f0;
        }
        
        .table-header {
            padding: 16px 24px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #fafafa;
        }
        
        .table-title {
            font-size: 18px;
            font-weight: 600;
            color: #262626;
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
        
        .table-search button {
            padding: 8px 16px;
            background: #1890ff;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: background 0.3s;
            font-size: 16px;
        }
        
        .table-search button:hover {
            background: #40a9ff;
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
                <div class="breadcrumb">
                    <a href="index.php">首頁</a> / 使用者管理
                </div>
                
                <div id="messageContainer"></div>
                
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
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('viewModal')">關閉</button>
            </div>
        </div>
    </div>
    
    <script>
    const API_BASE_URL = 'http://100.79.58.120:5001';
    
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
    
    // 查看使用者
    async function viewUser(userId) {
        try {
            const response = await fetch(`${API_BASE_URL}/admin/users/${userId}`);
            const data = await response.json();
            
            if (response.ok) {
                document.getElementById('viewUserId').value = data.user.id;
                document.getElementById('viewUsername').value = data.user.username;
                document.getElementById('viewName').value = data.user.name;
                document.getElementById('viewEmail').value = data.user.email;
                document.getElementById('viewRole').value = data.user.role;
                
                document.getElementById('viewModal').style.display = 'block';
            } else {
                showMessage('載入使用者資料失敗', 'error');
            }
        } catch (error) {
            console.error('Error loading user:', error);
            showMessage('載入使用者資料失敗', 'error');
        }
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
        loadUsers();
    });
    </script>
</body>
</html>
