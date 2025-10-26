<?php
session_start();

// 檢查是否已登入
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: login.php");
    exit;
}

// 資料庫連接設定（與招生中心保持一致）
$host = '100.79.58.120';
$dbname = 'topics_good';
$db_username = 'root';
$db_password = '';

// 設置頁面標題
$page_title = (isset($_SESSION['username']) && $_SESSION['username'] === 'IMD') ? '資管科續招報名管理' : '續招報名管理';
$current_page = 'continued_admission_list'; // 新增此行

// 檢查是否為IMD用戶
$is_imd_user = (isset($_SESSION['username']) && $_SESSION['username'] === 'IMD');

// 建立資料庫連接
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $db_username, $db_password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("資料庫連接失敗: " . $e->getMessage());
}

// 獲取續招報名資料（根據用戶權限過濾）
if ($is_imd_user) {
    // IMD用戶只能看到資管科相關的續招報名
    $stmt = $pdo->prepare("SELECT id, name, id_number, mobile, school_name, created_at, status, choices 
                          FROM continued_admission 
                          WHERE JSON_CONTAINS(choices, JSON_QUOTE('資訊管理科')) 
                          OR JSON_CONTAINS(choices, JSON_QUOTE('資管科'))
                          OR JSON_SEARCH(choices, 'one', '%資管%') IS NOT NULL
                          OR JSON_SEARCH(choices, 'one', '%資訊管理%') IS NOT NULL
                          ORDER BY created_at DESC");
} else {
    // 一般管理員可以看到所有續招報名
    $stmt = $pdo->prepare("SELECT id, name, id_number, mobile, school_name, created_at, status, choices FROM continued_admission ORDER BY created_at DESC");
}
$stmt->execute();
$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 獲取科系名額資料（檢查資料表是否存在）
$department_stats = [];
$departments = [];

try {
    // 檢查 department_quotas 資料表是否存在
    $stmt = $pdo->prepare("SHOW TABLES LIKE 'department_quotas'");
    $stmt->execute();
    $tableExists = $stmt->rowCount() > 0;
    
    if ($tableExists) {
        if ($is_imd_user) {
            // IMD用戶只能看到資管科的名額
            $stmt = $pdo->prepare("SELECT * FROM department_quotas 
                                  WHERE is_active = 1 
                                  AND (department_name LIKE '%資管%' OR department_name LIKE '%資訊管理%')
                                  ORDER BY department_name");
        } else {
            // 一般管理員可以看到所有科系名額
            $stmt = $pdo->prepare("SELECT * FROM department_quotas WHERE is_active = 1 ORDER BY department_name");
        }
        $stmt->execute();
        $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 計算各科系已錄取人數
        foreach ($departments as $dept) {
            if ($is_imd_user) {
                // IMD用戶只計算資管科相關的錄取人數
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM continued_admission 
                                      WHERE status = 'approved' 
                                      AND (JSON_CONTAINS(choices, JSON_QUOTE(?)) 
                                      OR JSON_SEARCH(choices, 'one', '%資管%') IS NOT NULL
                                      OR JSON_SEARCH(choices, 'one', '%資訊管理%') IS NOT NULL)");
            } else {
                // 一般管理員計算所有科系的錄取人數
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM continued_admission WHERE status = 'approved' AND JSON_CONTAINS(choices, JSON_QUOTE(?))");
            }
            $stmt->execute([$dept['department_name']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $department_stats[$dept['department_code']] = [
                'name' => $dept['department_name'],
                'total_quota' => $dept['total_quota'],
                'current_enrolled' => $result['count'],
                'remaining' => $dept['total_quota'] - $result['count']
            ];
        }
    }
} catch (PDOException $e) {
    // 如果資料表不存在或其他錯誤，設定為空陣列
    $departments = [];
    $department_stats = [];
}

function getStatusText($status) {
    switch ($status) {
        case 'approved': return '錄取';
        case 'rejected': return '未錄取';
        case 'waitlist': return '備取';
        default: return '待審核';
    }
}

function getStatusClass($status) {
    switch ($status) {
        case 'approved': return 'status-approved';
        case 'rejected': return 'status-rejected';
        case 'waitlist': return 'status-waitlist';
        default: return 'status-pending';
    }
}
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
            --primary-color: #1890ff; --text-color: #262626; --text-secondary-color: #8c8c8c;
            --border-color: #f0f0f0; --background-color: #f0f2f5; --card-background-color: #fff;
            --status-approved-bg: #f6ffed; --status-approved-text: #52c41a; --status-approved-border: #b7eb8f;
            --status-rejected-bg: #fff1f0; --status-rejected-text: #f5222d; --status-rejected-border: #ffa39e;
            --status-waitlist-bg: #fffbe6; --status-waitlist-text: #faad14; --status-waitlist-border: #ffe58f;
            --status-pending-bg: #e6f7ff; --status-pending-text: #1890ff; --status-pending-border: #91d5ff;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background: var(--background-color); color: var(--text-color); overflow-x: hidden; }
        .dashboard { display: flex; min-height: 100vh; }
        .content { padding: 24px; width: 100%; }
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
        .table th { background: #fafafa; font-weight: 600; }
        .table tr:hover { background: #fafafa; }

        .search-input { padding: 8px 12px; border: 1px solid #d9d9d9; border-radius: 6px; font-size: 14px; width: 250px; }
        .empty-state { text-align: center; padding: 40px; color: var(--text-secondary-color); }

        .status-badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500; border: 1px solid; }
        .status-approved { background: var(--status-approved-bg); color: var(--status-approved-text); border-color: var(--status-approved-border); }
        .status-rejected { background: var(--status-rejected-bg); color: var(--status-rejected-text); border-color: var(--status-rejected-border); }
        .status-waitlist { background: var(--status-waitlist-bg); color: var(--status-waitlist-text); border-color: var(--status-waitlist-border); }
        .status-pending { background: var(--status-pending-bg); color: var(--status-pending-text); border-color: var(--status-pending-border); }

        .status-select {
            padding: 4px 8px;
            border: 1px solid #d9d9d9;
            border-radius: 4px;
            font-size: 12px;
            background-color: #fff;
            cursor: pointer;
        }
        .status-select:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        .btn-view {
            padding: 4px 12px; border: 1px solid #1890ff; border-radius: 4px; cursor: pointer;
            font-size: 14px; text-decoration: none; display: inline-block; transition: all 0.3s;
            background: #fff; color: #1890ff; margin-right: 8px;
        }
        .btn-view:hover { background: #1890ff; color: white; }
        
        .btn-review {
            padding: 4px 12px; border: 1px solid #52c41a; border-radius: 4px; cursor: pointer;
            font-size: 14px; text-decoration: none; display: inline-block; transition: all 0.3s;
            background: #fff; color: #52c41a;
        }
        .btn-review:hover { background: #52c41a; color: white; }

        /* 科系名額管理樣式 */
        .quota-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; }
        .quota-card { background: #fff; border: 1px solid var(--border-color); border-radius: 8px; padding: 20px; transition: all 0.3s; }
        .quota-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.1); transform: translateY(-2px); }
        .quota-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .quota-header h4 { font-size: 16px; font-weight: 600; color: var(--text-color); margin: 0; }
        .quota-code { background: var(--primary-color); color: white; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500; }
        .quota-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 16px; }
        .stat-item { text-align: center; }
        .stat-label { display: block; font-size: 12px; color: var(--text-secondary-color); margin-bottom: 4px; }
        .stat-value { display: block; font-size: 18px; font-weight: 600; }
        .stat-value.total { color: var(--primary-color); }
        .stat-value.enrolled { color: var(--success-color); }
        .stat-value.remaining { color: var(--warning-color); }
        .stat-value.remaining.full { color: var(--danger-color); }
        .quota-progress { margin-top: 12px; }
        .progress-bar { width: 100%; height: 8px; background: #f0f0f0; border-radius: 4px; overflow: hidden; }
        .progress-fill { height: 100%; background: linear-gradient(90deg, var(--success-color), var(--warning-color)); transition: width 0.3s; }

        /* 志願選擇顯示樣式 */
        .choices-display { display: flex; flex-direction: column; gap: 4px; }
        .choice-item { padding: 4px 8px; border-radius: 4px; font-size: 12px; background: #f5f5f5; color: var(--text-color); }
        .choice-item.first-choice { background: var(--primary-color); color: white; font-weight: 500; }
        .no-choices { color: var(--text-secondary-color); font-style: italic; }
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

                <!-- 科系名額管理卡片 -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-graduation-cap"></i> 科系名額管理</h3>
                        <?php if (!empty($department_stats)): ?>
                            <a href="department_quota_management.php" class="btn-secondary">
                                <i class="fas fa-cog"></i> 管理名額
                            </a>
                        <?php else: ?>
                            <a href="setup_department_quotas.php" class="btn-primary">
                                <i class="fas fa-database"></i> 設定名額
                            </a>
                        <?php endif; ?>
                    </div>
                    <div class="card-body" id="quotaManagementContent">
                        <?php if (!empty($department_stats)): ?>
                            <div class="quota-grid">
                                <?php foreach ($department_stats as $code => $stats): ?>
                                <div class="quota-card">
                                    <div class="quota-header">
                                        <h4><?php echo htmlspecialchars($stats['name']); ?></h4>
                                        <span class="quota-code"><?php echo htmlspecialchars($code); ?></span>
                                    </div>
                                    <div class="quota-stats">
                                        <div class="stat-item">
                                            <span class="stat-label">總名額</span>
                                            <span class="stat-value total"><?php echo $stats['total_quota']; ?></span>
                                        </div>
                                        <div class="stat-item">
                                            <span class="stat-label">已錄取</span>
                                            <span class="stat-value enrolled"><?php echo $stats['current_enrolled']; ?></span>
                                        </div>
                                        <div class="stat-item">
                                            <span class="stat-label">剩餘名額</span>
                                            <span class="stat-value remaining <?php echo $stats['remaining'] <= 0 ? 'full' : ''; ?>"><?php echo max(0, $stats['remaining']); ?></span>
                                        </div>
                                    </div>
                                    <div class="quota-progress">
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?php echo $stats['total_quota'] > 0 ? min(100, ($stats['current_enrolled'] / $stats['total_quota']) * 100) : 0; ?>%"></div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-graduation-cap fa-3x" style="margin-bottom: 16px; color: var(--text-secondary-color);"></i>
                                <h4 style="margin-bottom: 12px;">科系名額管理尚未設定</h4>
                                <p style="margin-bottom: 20px; color: var(--text-secondary-color);">
                                    您需要先建立科系名額資料表，才能使用名額管理功能。
                                </p>
                                <a href="setup_department_quotas.php" class="btn-primary">
                                    <i class="fas fa-database"></i> 立即設定科系名額
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3><?php echo $page_title; ?> (共 <?php echo count($applications); ?> 筆)</h3>
                        <input type="text" id="searchInput" class="search-input" placeholder="搜尋姓名、身分證或電話...">
                    </div>
                    <div class="card-body table-container">
                        <?php if (empty($applications)): ?>
                            <div class="empty-state">
                                <i class="fas fa-inbox fa-3x" style="margin-bottom: 16px;"></i>
                                <p>目前尚無任何續招報名資料。</p>
                            </div>
                        <?php else: ?>
                            <table class="table" id="applicationTable">
                                <thead>
                                    <tr>
                                        <th>報名編號</th>
                                        <th>姓名</th>
                                        <th>身分證字號</th>
                                        <th>行動電話</th>
                                        <th>就讀國中</th>
                                        <th>志願選擇</th>
                                        <th>審核狀態</th>
                                        <th>報名日期</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($applications as $item): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['id']); ?></td>
                                        <td><?php echo htmlspecialchars($item['name']); ?></td>
                                        <td><?php echo htmlspecialchars($item['id_number']); ?></td>
                                        <td><?php echo htmlspecialchars($item['mobile']); ?></td>
                                        <td><?php echo htmlspecialchars($item['school_name']); ?></td>
                                        <td>
                                            <?php 
                                            $choices = json_decode($item['choices'], true);
                                            if (!empty($choices) && is_array($choices)): 
                                            ?>
                                                <div class="choices-display">
                                                    <?php foreach ($choices as $index => $choice): ?>
                                                        <span class="choice-item <?php echo $index === 0 ? 'first-choice' : ''; ?>">
                                                            <?php echo ($index + 1) . '. ' . htmlspecialchars($choice); ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="no-choices">未選擇</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo getStatusClass($item['status']); ?>">
                                                <?php echo getStatusText($item['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('Y/m/d H:i', strtotime($item['created_at'])); ?></td>
                                        <td>
                                            <a href="continued_admission_detail.php?id=<?php echo $item['id']; ?>" class="btn-view">查看詳情</a>
                                            <?php if ($item['status'] === 'pending'): ?>
                                                <a href="continued_admission_detail.php?id=<?php echo $item['id']; ?>&action=review" class="btn-review">審核</a>
                                            <?php endif; ?>
                                        </td>
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

    <!-- 訊息提示框 -->
    <div id="toast" style="position: fixed; top: 20px; right: 20px; background-color: #333; color: white; padding: 15px 20px; border-radius: 8px; z-index: 9999; display: none; opacity: 0; transition: opacity 0.5s;"></div>

    <script>
    function showToast(message, isSuccess = true) {
        const toast = document.getElementById('toast');
        toast.textContent = message;
        toast.style.backgroundColor = isSuccess ? '#4CAF50' : '#f44336';
        toast.style.display = 'block';
        toast.style.opacity = 1;
        setTimeout(() => {
            toast.style.opacity = 0;
            setTimeout(() => { toast.style.display = 'none'; }, 500);
        }, 3000);
    }


    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('searchInput');
        const table = document.getElementById('applicationTable');
        const rows = table ? table.getElementsByTagName('tbody')[0].getElementsByTagName('tr') : [];

        if (searchInput) {
            searchInput.addEventListener('keyup', function() {
                const filter = searchInput.value.toLowerCase();
                
                for (let i = 0; i < rows.length; i++) {
                    const nameCell = rows[i].getElementsByTagName('td')[1];
                    const idCell = rows[i].getElementsByTagName('td')[2];
                    const phoneCell = rows[i].getElementsByTagName('td')[3];
                    
                    if (nameCell || idCell || phoneCell) {
                        const nameText = nameCell.textContent || nameCell.innerText;
                        const idText = idCell.textContent || idCell.innerText;
                        const phoneText = phoneCell.textContent || phoneCell.innerText;
                        
                        if (nameText.toLowerCase().indexOf(filter) > -1 || 
                            idText.toLowerCase().indexOf(filter) > -1 ||
                            phoneText.toLowerCase().indexOf(filter) > -1) {
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
