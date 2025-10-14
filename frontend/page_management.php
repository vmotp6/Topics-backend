<?php
session_start();

// 檢查是否已登入，如果沒有登入則跳轉到登入頁面
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: login.php");
    exit;
}

// 設置頁面標題
$page_title = '頁面管理';

// 定義可管理的頁面列表 - 基於實際存在的頁面
$manageable_pages = [
    [
        'id' => 'homepage',
        'name' => '首頁',
        'icon' => 'fas fa-home',
        'color' => '#1890ff',
        'url' => 'page_editor.php?page=homepage',
        'status' => 'active',
        'type' => 'backend'
    ],
    [
        'id' => 'admission',
        'name' => '入學說明會報名',
        'icon' => 'fas fa-graduation-cap',
        'color' => '#52c41a',
        'url' => 'edit_admission.php',
        'status' => 'active',
        'type' => 'frontend',
        'file_path' => '../../Topics-frontend/frontend/admission.php'
    ],
    [
        'id' => 'ai_chat',
        'name' => 'AI聊天',
        'icon' => 'fas fa-robot',
        'color' => '#722ed1',
        'url' => 'page_editor.php?page=ai_chat',
        'status' => 'active',
        'type' => 'frontend',
        'file_path' => '../../Topics-frontend/frontend/AI.php'
    ],
    [
        'id' => 'teacher_profile',
        'name' => '教師檔案',
        'icon' => 'fas fa-user-tie',
        'color' => '#fa8c16',
        'url' => 'page_editor.php?page=teacher_profile',
        'status' => 'active',
        'type' => 'frontend',
        'file_path' => '../../Topics-frontend/frontend/teacher_profile.php'
    ],
    [
        'id' => 'cooperation',
        'name' => '產學合作',
        'icon' => 'fas fa-handshake',
        'color' => '#13c2c2',
        'url' => 'page_editor.php?page=cooperation',
        'status' => 'active',
        'type' => 'frontend',
        'file_path' => '../../Topics-frontend/frontend/cooperation_upload.php'
    ],
    [
        'id' => 'qa',
        'name' => 'qa',
        'icon' => 'fas fa-handshake',
        'color' => '#13c2c2',
        'url' => 'page_editor.php?page=qa',
        'status' => 'active',
        'type' => 'frontend',
        'file_path' => '../../Topics-frontend/frontend/qa.php'
    ]
];
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>頁面管理 - Topics 後台管理系統</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* 從目標頁面複製的基本樣式 */
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

        /* 主介面樣式 (假設 sidebar.php 和 header.php 會補齊 .dashboard 和 .main-content 的樣式) */
        .dashboard {
            display: flex;
            min-height: 100vh;
        }
        
        /* 內容區域 - 使用目標頁面的間距 */
        .content {
            padding: 24px;
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

        /* 表格容器 - 新增 Ant Design 風格的容器樣式 */
        .table-container {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            overflow: hidden;
            border: 1px solid #f0f0f0;
        }

        /* 表格標頭 - 包含標題、搜尋和篩選 */
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

        /* 搜尋 & 篩選區 (修改自原 filter-bar) */
        .filter-bar {
            display: flex;
            gap: 8px; /* 修改 gap */
            align-items: center; /* 垂直置中 */
        }

        .filter-bar input,
        .filter-bar select {
            padding: 8px 12px;
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            font-size: 14px;
            background: #fff;
            transition: all 0.3s;
        }

        .filter-bar input:focus,
        .filter-bar select:focus {
            outline: none;
            border-color: #1890ff;
            box-shadow: 0 0 0 2px rgba(24,144,255,0.2);
        }
        
        /* 表格 - 使用新名稱 page-table 並調整樣式 */
        .page-table {
            width: 100%;
            border-collapse: collapse;
        }

        .page-table thead {
            /* 標頭顏色已在 .table-header 設置，這裡主要設置 th 的樣式 */
        }

        .page-table th {
            background: #fafafa;
            padding: 16px 24px; /* 增大 padding */
            text-align: left;
            font-weight: 600;
            color: #262626;
            border-bottom: 1px solid #f0f0f0;
            font-size: 16px; /* 增大字體 */
        }
        
        .page-table td {
            padding: 16px 24px; /* 增大 padding */
            border-bottom: 1px solid #f0f0f0;
            font-size: 16px; /* 增大字體 */
            color: #595959;
        }

        .page-table tr:hover {
            background: #fafafa; /* 統一 hover 顏色 */
        }
        
        /* 狀態標籤 - 保持原狀但更新字體大小 */
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 14px; /* 調整字體大小 */
            font-weight: 500;
            display: inline-block; /* 確保能正確應用 padding */
        }

        .status-active {
            background: #f6ffed;
            color: #52c41a;
            border: 1px solid #b7eb8f;
        }

        .status-inactive {
            background: #fff2f0;
            color: #ff4d4f;
            border: 1px solid #ffccc7;
        }

        /* 按鈕 - 調整為目標頁面的風格 */
        .btn-action {
             padding: 4px 12px; /* 調整 padding */
            border: 1px solid #1890ff; /* 設置邊框顏色 */
            border-radius: 4px; /* 調整圓角 */
            cursor: pointer;
            font-size: 14px; /* 調整字體大小 */
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
            background: #fff;
            color: #1890ff; /* 設置文字顏色 */
        }
        
        .btn-action:hover {
            background: #1890ff;
            color: white;
        }

        .page-icon {
            width: 36px;
            height: 36px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 16px;
        }
        
        /* 移除原有的 .pages-list 和 .btn, .btn-primary 樣式，統一使用新風格 */
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include 'sidebar.php'; ?>
        <div class="main-content">
            <?php include 'header.php'; ?>
            <div class="content">

                <div class="breadcrumb">
                    <a href="index.php">首頁</a> / 頁面管理
                </div>

                <div class="table-container">
                    <div class="table-header">
                        <div class="table-title">頁面列表</div>
                        <div class="filter-bar">
                            <input type="text" id="searchInput" placeholder="搜尋頁面名稱...">
                            <select id="statusFilter">
                                <option value="">全部狀態</option>
                                <option value="active">啟用</option>
                                <option value="inactive">停用</option>
                            </select>
                        </div>
                    </div>

                    <div class="table-content">
                        <table class="page-table" id="pageTable">
                            <thead>
                                <tr>
                                    <th>頁面名稱</th>
                                    <th style="width:120px;">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($manageable_pages as $page): ?>
                                <tr data-name="<?php echo $page['name']; ?>">
                                    <td><?php echo $page['name']; ?></td>
                                    <td>
                                        <a class="btn-action" href="<?php echo $page['url']; ?>">編輯</a>
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

    <script>
        // 搜尋 & 篩選
        const searchInput = document.getElementById('searchInput');
        // 選取表格內容列
        const rows = document.querySelectorAll('#pageTable tbody tr');

        function filterTable() {
            const searchText = searchInput.value.toLowerCase();

            rows.forEach(row => {
                // 搜尋頁面名稱 (已修改，因為原 HTML 中 name 在 index 1)
                const name = row.cells[0].innerText.toLowerCase(); // 頁面名稱現在是第一個 td
                const matchSearch = name.includes(searchText);
                
                // 顯示或隱藏列
                row.style.display = matchSearch ? '' : 'none';
            });
        }

        // 綁定事件
        searchInput.addEventListener('input', filterTable);
    </script>
</body>
</html>
