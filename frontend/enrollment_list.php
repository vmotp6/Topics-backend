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
$page_title = (isset($_SESSION['username']) && $_SESSION['username'] === 'IMD') ? '資管科就讀意願名單' : '就讀意願名單';

// 建立資料庫連接
$conn = getDatabaseConnection();

// 檢查是否為IMD用戶
$is_imd_user = (isset($_SESSION['username']) && $_SESSION['username'] === 'IMD');

// 獲取報名資料（根據用戶權限過濾）
if ($is_imd_user) {
    // IMD用戶只能看到資管科相關的就讀意願
    $stmt = $conn->prepare("SELECT * FROM enrollment_intention 
                           WHERE intention1 LIKE '%資管%' OR intention1 LIKE '%資訊管理%' 
                           OR intention2 LIKE '%資管%' OR intention2 LIKE '%資訊管理%' 
                           OR intention3 LIKE '%資管%' OR intention3 LIKE '%資訊管理%'
                           ORDER BY created_at DESC");
} else {
    // 一般管理員可以看到所有就讀意願
    $stmt = $conn->prepare("SELECT * FROM enrollment_intention ORDER BY created_at DESC");
}
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
        .main-content {
            /* 防止內部過寬的元素撐開主內容區，影響 header */
            overflow-x: hidden;
        }
        .content { padding: 24px; width: 100%; }

        .page-controls { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; gap: 16px; }
        .breadcrumb { margin-bottom: 0; font-size: 16px; color: var(--text-secondary-color); }
        .breadcrumb a { color: var(--primary-color); text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }
        
        .table-wrapper {
            background: var(--card-background-color);
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
            /* overflow: hidden; */ /* 移除此行以允許內部容器的捲軸顯示 */
        }

        .table-container {
            overflow-x: auto;
        }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 16px 24px; text-align: left; border-bottom: 1px solid var(--border-color); font-size: 16px; white-space: nowrap; }
        .table th { background: #fafafa; font-weight: 600; color: #262626; cursor: pointer; user-select: none; position: relative; }
        .table td {
            color: #595959; /* 與 users.php 統一表格內文顏色 */
        }
        .table th:hover { background: #f0f0f0; }
        .sort-icon { margin-left: 8px; font-size: 12px; color: #8c8c8c; }
        .sort-icon.active { color: #1890ff; }
        .sort-icon.asc::after { content: "↑"; }
        .sort-icon.desc::after { content: "↓"; }
        .table tr:hover { background: #fafafa; }

        .search-input {
            padding: 8px 12px;
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            font-size: 14px;
            width: 250px;
            transition: all 0.3s;
        }
        .search-input:focus {
            outline: none;
            border-color: #1890ff;
            box-shadow: 0 0 0 2px rgba(24,144,255,0.2);
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
                <div class="page-controls">
                    <div class="breadcrumb">
                        <a href="index.php">首頁</a> / <?php echo $page_title; ?>
                    </div>
                    <div class="table-search">
                        <input type="text" id="searchInput" class="search-input" placeholder="搜尋姓名或電話...">
                    </div>
                </div>

                <div class="table-wrapper">
                    <div class="table-container">
                        <?php if (empty($enrollments)): ?>
                            <div class="empty-state">
                                <i class="fas fa-inbox fa-3x" style="margin-bottom: 16px;"></i>
                                <p>目前尚無任何就讀意願登錄資料。</p>
                            </div>
                        <?php else: ?>
                            <table class="table" id="enrollmentTable">
                                <thead>
                                    <tr>
                                        <th onclick="sortTable(0)">姓名</th>
                                        <th onclick="sortTable(1)">身分別</th>
                                        <th onclick="sortTable(2)">性別</th>
                                        <th onclick="sortTable(3)">聯絡電話一</th>
                                        <th onclick="sortTable(4)">聯絡電話二</th>
                                        <th onclick="sortTable(5)">Email</th>
                                        <th onclick="sortTable(6)">就讀學校</th>
                                        <th onclick="sortTable(7)">年級</th>
                                        <th onclick="sortTable(8)">意願一 (學制)</th>
                                        <th onclick="sortTable(9)">意願二 (學制)</th>
                                        <th onclick="sortTable(10)">意願三 (學制)</th>
                                        <th onclick="sortTable(11)">Line ID</th>
                                        <th onclick="sortTable(12)">Facebook</th>
                                        <th onclick="sortTable(13)">備註</th>
                                        <th onclick="sortTable(14)">狀態</th>
                                        <th onclick="sortTable(15, 'date')">填寫日期</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($enrollments as $item): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['name']); ?></td>
                                        <td><?php echo htmlspecialchars($item['identity']); ?></td>
                                        <td><?php echo htmlspecialchars($item['gender'] ?? '未提供'); ?></td>
                                        <td><?php echo htmlspecialchars($item['phone1']); ?></td>
                                        <td><?php echo htmlspecialchars($item['phone2'] ?? '無'); ?></td>
                                        <td><?php echo htmlspecialchars($item['email']); ?></td>
                                        <td><?php echo htmlspecialchars($item['junior_high']); ?></td>
                                        <td><?php echo htmlspecialchars($item['current_grade']); ?></td>
                                        <td><?php echo htmlspecialchars($item['intention1'] . ' (' . ($item['system1'] ?? 'N/A') . ')'); ?></td>
                                        <td><?php echo htmlspecialchars($item['intention2'] . ' (' . ($item['system2'] ?? 'N/A') . ')'); ?></td>
                                        <td><?php echo htmlspecialchars($item['intention3'] . ' (' . ($item['system3'] ?? 'N/A') . ')'); ?></td>
                                        <td><?php echo htmlspecialchars($item['line_id'] ?? '無'); ?></td>
                                        <td><?php echo htmlspecialchars($item['facebook'] ?? '無'); ?></td>
                                        <td><?php echo htmlspecialchars($item['remarks'] ?? '無'); ?></td>
                                        <td><?php echo htmlspecialchars($item['status'] ?? 'pending'); ?></td>
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
        let sortStates = {}; // { colIndex: 'asc' | 'desc' }

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

        window.sortTable = function(colIndex, type = 'string') {
            const table = document.getElementById('enrollmentTable');
            const tbody = table.getElementsByTagName('tbody')[0];
            const rows = Array.from(tbody.getElementsByTagName('tr'));
            
            const currentOrder = sortStates[colIndex] === 'asc' ? 'desc' : 'asc';
            sortStates = { [colIndex]: currentOrder }; // Reset other column states

            rows.sort((a, b) => {
                const valA = a.getElementsByTagName('td')[colIndex].textContent.trim();
                const valB = b.getElementsByTagName('td')[colIndex].textContent.trim();

                let comparison = 0;
                if (type === 'date') {
                    comparison = new Date(valA) - new Date(valB);
                } else if (!isNaN(valA) && !isNaN(valB)) {
                    comparison = parseFloat(valA) - parseFloat(valB);
                } else {
                    comparison = valA.localeCompare(valB, 'zh-Hant');
                }

                return currentOrder === 'asc' ? comparison : -comparison;
            });

            // Re-append sorted rows
            rows.forEach(row => tbody.appendChild(row));

            // Update sort icons
            updateSortIcons(colIndex, currentOrder);
        };

        function updateSortIcons(activeIndex, order) {
            const headers = document.querySelectorAll('#enrollmentTable th');
            headers.forEach((th, index) => {
                const icon = th.querySelector('.sort-icon');
                if (icon) {
                    if (index === activeIndex) {
                        icon.className = `sort-icon active ${order}`;
                    } else {
                        icon.className = 'sort-icon';
                    }
                }
            });
        }

        // Initial sort by date desc
        function initialSort() {
            const dateColumnIndex = 15;
            sortStates = { [dateColumnIndex]: 'desc' };
            sortTable(dateColumnIndex, 'date'); // Sort once to set desc
            sortTable(dateColumnIndex, 'date'); // Sort again to trigger desc
        }

        if (rows.length > 0) {
            initialSort();
        }
    });
    </script>
</body>
</html>