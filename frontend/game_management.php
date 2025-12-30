<?php
session_start();
// 檢查權限
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: login.php");
    exit;
}

require_once '../../Topics-frontend/frontend/config.php';
$conn = getDatabaseConnection();

// 定義遊戲列表
$games = [
    'fight' => [
        'name' => '格鬥問答',
        'desc' => '包含拳擊、格鬥技巧相關的知識問答'
    ],
    'nursing' => [
        'name' => '護理科互動',
        'desc' => '護理專業知識與互動情境題庫'
    ]
];

// 取得各遊戲題目數量
$stats = [];
foreach ($games as $key => $game) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM game_questions WHERE category = ?");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stats[$key] = $res['count'];
    $stmt->close();
}

// 模擬將遊戲陣列轉換為可搜尋的列表結構
$gameList = [];
foreach ($games as $key => $game) {
    $gameList[] = [
        'code' => $key,
        'name' => $game['name'],
        'desc' => $game['desc'],
        'count' => $stats[$key] ?? 0
    ];
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>遊戲管理 - Topics 後台管理系統</title>
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
        }

        .table-container { overflow-x: auto; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 16px 24px; text-align: left; border-bottom: 1px solid var(--border-color); font-size: 16px; white-space: nowrap; }
        .table th:first-child, .table td:first-child { padding-left: 60px; }
        .table th { background: #fafafa; font-weight: 600; color: #262626; }
        .table td { color: #595959; }
        .table tr:hover { background: #fafafa; }
        
        .badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-weight: 600; font-size: 14px; }
        .badge-success { background: #52c41a; color: white; } /* 綠色 */
        .badge-secondary { background: #d9d9d9; color: white; }

        .btn-view {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 16px; background: #1890ff; color: white;
            text-decoration: none; border-radius: 4px; font-size: 14px; transition: all 0.3s;
        }
        .btn-view:hover { background: #40a9ff; }

        .search-input {
            padding: 8px 12px; border: 1px solid #d9d9d9; border-radius: 6px; font-size: 14px; width: 250px; transition: all 0.3s;
        }
        .search-input:focus { outline: none; border-color: #1890ff; box-shadow: 0 0 0 2px rgba(24,144,255,0.2); }
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
                        <a href="index.php">首頁</a> / 遊戲管理
                    </div>
                    <div>
                        <input type="text" id="searchInput" class="search-input" placeholder="搜尋遊戲名稱...">
                    </div>
                </div>

                <div class="table-wrapper">
                    <div class="table-container">
                        <table class="table" id="gameTable">
                            <thead>
                                <tr>
                                    <th>遊戲名稱</th>
                                    <th>描述</th>
                                    <th style="text-align: center;">題目數量</th>
                                    <th style="text-align: center;">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($gameList as $game): ?>
                                    <tr>
                                        <td style="font-weight: 500; color: #262626;"><?php echo htmlspecialchars($game['name']); ?></td>
                                        <td style="color: #8c8c8c; font-size: 14px;"><?php echo htmlspecialchars($game['desc']); ?></td>
                                        <td style="text-align: center;">
                                            <span class="badge <?php echo $game['count'] > 0 ? 'badge-success' : 'badge-secondary'; ?>">
                                                <?php echo $game['count']; ?>
                                            </span>
                                        </td>
                                        <td style="text-align: center;">
                                            <a href="game_questions.php?category=<?php echo urlencode($game['code']); ?>" class="btn-view">
                                                <i class="fas fa-eye"></i> 管理題目
                                            </a>
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
        // 簡單的前端搜尋功能
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchInput');
            const table = document.getElementById('gameTable');
            
            if (searchInput && table) {
                searchInput.addEventListener('keyup', function() {
                    const filter = searchInput.value.toLowerCase();
                    const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
                    
                    for (let i = 0; i < rows.length; i++) {
                        const nameCell = rows[i].getElementsByTagName('td')[0];
                        const descCell = rows[i].getElementsByTagName('td')[1];
                        if (nameCell || descCell) {
                            const nameText = nameCell.textContent || nameCell.innerText;
                            const descText = descCell.textContent || descCell.innerText;
                            if (nameText.toLowerCase().indexOf(filter) > -1 || descText.toLowerCase().indexOf(filter) > -1) {
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