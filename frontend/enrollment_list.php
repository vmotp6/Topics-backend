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
$page_title = '就讀意願名單';

// 建立資料庫連接
$conn = getDatabaseConnection();

// 獲取所有報名資料
$stmt = $conn->prepare("SELECT * FROM enrollment_applications ORDER BY created_at DESC");
$stmt->execute();
$result = $stmt->get_result();
$enrollments = $result->fetch_all(MYSQLI_ASSOC);

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
        .breadcrumb { margin-bottom: 16px; font-size: 16px; color: var(--text-secondary-color); }
        .breadcrumb a { color: var(--primary-color); text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }
        
        .card { background: var(--card-background-color); border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.03); border: 1px solid var(--border-color); margin-bottom: 24px; }
        .card-header { padding: 16px 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background: #fafafa; }
        .card-header h3 { font-size: 18px; font-weight: 600; color: var(--text-color); }
        .card-body { padding: 24px; }

        .table-container { overflow-x: auto; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 16px; text-align: left; border-bottom: 1px solid var(--border-color); font-size: 14px; white-space: nowrap; }
        .table th { background: #fafafa; font-weight: 600; cursor: pointer; user-select: none; }
        .table th .sort-icon { margin-left: 4px; color: var(--text-secondary-color); }
        .table tr:hover { background: #fafafa; }

        .search-input {
            padding: 8px 12px;
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            font-size: 14px;
            width: 250px;
        }

        .empty-state { text-align: center; padding: 40px; color: var(--text-secondary-color); }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include 'sidebar.php'; ?>
        <div class="main-content" id="mainContent">
            <?php include 'header.php'; ?>
            <div class="content">
                <div class="breadcrumb">
                    <a href="index.php">首頁</a> / <?php echo $page_title; ?>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3><?php echo $page_title; ?> (共 <?php echo count($enrollments); ?> 筆)</h3>
                        <input type="text" id="searchInput" class="search-input" placeholder="搜尋姓名或電話...">
                    </div>
                    <div class="card-body table-container">
                        <?php if (empty($enrollments)): ?>
                            <div class="empty-state">
                                <i class="fas fa-inbox fa-3x" style="margin-bottom: 16px;"></i>
                                <p>目前尚無任何就讀意願登錄資料。</p>
                            </div>
                        <?php else: ?>
                            <table class="table" id="enrollmentTable">
                                <thead>
                                    <tr>
                                        <th>姓名</th>
                                        <th>身分別</th>
                                        <th>聯絡電話</th>
                                        <th>Email</th>
                                        <th>就讀學校</th>
                                        <th>年級</th>
                                        <th>意願一</th>
                                        <th>意願二</th>
                                        <th>意願三</th>
                                        <th>推薦老師</th>
                                        <th>填寫日期</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($enrollments as $item): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['name']); ?></td>
                                        <td><?php echo htmlspecialchars($item['identity']); ?></td>
                                        <td><?php echo htmlspecialchars($item['phone1']); ?></td>
                                        <td><?php echo htmlspecialchars($item['email']); ?></td>
                                        <td><?php echo htmlspecialchars($item['junior_high']); ?></td>
                                        <td><?php echo htmlspecialchars($item['current_grade']); ?></td>
                                        <td><?php echo htmlspecialchars($item['intention1']); ?></td>
                                        <td><?php echo htmlspecialchars($item['intention2']); ?></td>
                                        <td><?php echo htmlspecialchars($item['intention3']); ?></td>
                                        <td><?php echo htmlspecialchars($item['recommended_teacher']); ?></td>
                                        <td><?php echo date('Y/m/d H:i', strtotime($item['created_at'])); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('searchInput');
        const table = document.getElementById('enrollmentTable');
        const rows = table ? table.getElementsByTagName('tbody')[0].getElementsByTagName('tr') : [];

        if (searchInput) {
            searchInput.addEventListener('keyup', function() {
                const filter = searchInput.value.toLowerCase();
                
                for (let i = 0; i < rows.length; i++) {
                    const nameCell = rows[i].getElementsByTagName('td')[0];
                    const phoneCell = rows[i].getElementsByTagName('td')[2];
                    
                    if (nameCell || phoneCell) {
                        const nameText = nameCell.textContent || nameCell.innerText;
                        const phoneText = phoneCell.textContent || phoneCell.innerText;
                        
                        if (nameText.toLowerCase().indexOf(filter) > -1 || phoneText.toLowerCase().indexOf(filter) > -1) {
                            rows[i].style.display = "";
                        } else {
                            rows[i].style.display = "none";
                        }
                    }
                }
            });
        }
    });
    </script>
</body>
</html>