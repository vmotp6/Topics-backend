<?php
session_start();

// 檢查是否已登入
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: login.php");
    exit;
}

// 檢查是否為 admin1 帳號
$username = isset($_SESSION['username']) ? $_SESSION['username'] : '';
if ($username !== 'admin1') {
    header("Location: index.php");
    exit;
}

// 引入資料庫設定
$config_path = '../../Topics-frontend/frontend/config.php';
if (!file_exists($config_path)) {
    $alt_paths = [
        '../../../Topics-frontend/frontend/config.php',
        __DIR__ . '/../../Topics-frontend/frontend/config.php',
        dirname(__DIR__) . '/../Topics-frontend/frontend/config.php'
    ];
    
    $found = false;
    foreach ($alt_paths as $alt_path) {
        if (file_exists($alt_path)) {
            $config_path = $alt_path;
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        die('錯誤：找不到資料庫設定檔案 (config.php)');
    }
}

require_once $config_path;

// 建立 PDO 連線（與 mobile_teacher.php 一致）
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USERNAME, DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('資料庫連接失敗: ' . $e->getMessage());
}

// 檢查 schools 表是否存在
$checkSchools = $pdo->query("SHOW TABLES LIKE 'schools'");
$schoolsTableExists = ($checkSchools->rowCount() > 0);

// 取得聯絡人清單
$contacts = [];
$error_message = '';
try {
    if ($schoolsTableExists) {
        // 如果有 schools 表，JOIN 獲取學校資訊
        $stmt = $pdo->query("
            SELECT 
                sc.id, 
                sc.school_id,
                COALESCE(s.name, sc.school_name) as school_name, 
                sc.contact_name, 
                sc.email, 
                COALESCE(s.city, sc.city) as city, 
                COALESCE(s.district, sc.district) as district,
                sc.is_active,
                sc.created_at,
                sc.updated_at
            FROM schools_contacts sc
            LEFT JOIN schools s ON sc.school_id = s.id
            ORDER BY sc.city, sc.district, sc.school_name
        ");
    } else {
        // 如果沒有 schools 表，直接查詢
        $stmt = $pdo->query("
            SELECT 
                id, 
                NULL as school_id,
                school_name, 
                contact_name, 
                email, 
                city, 
                district,
                is_active,
                created_at,
                updated_at
            FROM schools_contacts 
            ORDER BY city, district, school_name
        ");
    }
    $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = '讀取聯絡人資料失敗: ' . $e->getMessage();
    $contacts = [];
}

// 統計資訊
$total_contacts = count($contacts);
$active_contacts = count(array_filter($contacts, function($c) { return $c['is_active'] == 1; }));
$inactive_contacts = $total_contacts - $active_contacts;
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>學校聯絡人管理 - Topics 後台管理系統</title>
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
            overflow-x: hidden;
        }
        .content { padding: 24px; width: 100%; }

        .page-controls { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; gap: 16px; }
        .breadcrumb { margin-bottom: 0; font-size: 16px; color: var(--text-secondary-color); }
        .breadcrumb a { color: var(--primary-color); text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: var(--card-background-color);
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
        }
        
        .stat-icon.total { 
            background: linear-gradient(135deg, #1890ff, #40a9ff); 
        }
        .stat-icon.active { 
            background: linear-gradient(135deg, #52c41a, #73d13d); 
        }
        .stat-icon.inactive { 
            background: linear-gradient(135deg, #ff4d4f, #ff7875); 
        }
        
        .stat-info h3 {
            font-size: 28px;
            margin-bottom: 4px;
            color: var(--text-color);
            font-weight: 600;
        }
        
        .stat-info p {
            color: var(--text-secondary-color);
            font-weight: 500;
            font-size: 14px;
        }
        
        .table-wrapper {
            background: var(--card-background-color);
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
        }

        .table-container {
            overflow-x: auto;
        }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 16px 24px; text-align: left; border-bottom: 1px solid var(--border-color); font-size: 16px; }
        .table th { background: #fafafa; font-weight: 600; color: #262626; cursor: pointer; user-select: none; position: relative; }
        .table td {
            color: #595959;
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
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-badge.active {
            background: #f6ffed;
            color: #52c41a;
            border: 1px solid #b7eb8f;
        }
        
        .status-badge.inactive {
            background: #fff2f0;
            color: #ff4d4f;
            border: 1px solid #ffccc7;
        }
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
                        <a href="index.php">首頁</a> / 學校聯絡人管理
                    </div>
                    <div class="table-search">
                        <input type="text" id="searchInput" class="search-input" placeholder="搜尋學校名稱、聯絡人或Email...">
                    </div>
                </div>

                <?php if (!empty($error_message)): ?>
                <div style="background: #fff2f0; border: 1px solid #ffccc7; border-radius: 8px; padding: 16px; margin-bottom: 24px; color: #cf1322;">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
                </div>
                <?php endif; ?>

                <!-- 統計卡片 -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon total">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $total_contacts; ?></h3>
                            <p>總聯絡人數</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon active">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $active_contacts; ?></h3>
                            <p>啟用中</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon inactive">
                            <i class="fas fa-ban"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $inactive_contacts; ?></h3>
                            <p>已停用</p>
                        </div>
                    </div>
                </div>

                <div class="table-wrapper">
                    <div class="table-container">
                        <?php if (empty($contacts)): ?>
                            <div class="empty-state">
                                <i class="fas fa-inbox fa-3x" style="margin-bottom: 16px;"></i>
                                <p>目前尚無聯絡人資料。</p>
                            </div>
                        <?php else: ?>
                            <table class="table" id="contactsTable">
                                <thead>
                                    <tr>
                                        <th onclick="sortTable(0)">學校名稱</th>
                                        <th onclick="sortTable(1)">聯絡人姓名</th>
                                        <th onclick="sortTable(2)">Email</th>
                                        <th onclick="sortTable(3)">縣市</th>
                                        <th onclick="sortTable(4)">區/鄉鎮市</th>
                                        <th onclick="sortTable(5)">狀態</th>
                                        <th onclick="sortTable(6, 'date')">建立時間</th>
                                        <th onclick="sortTable(7, 'date')">更新時間</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($contacts as $contact): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($contact['school_name']); ?></td>
                                        <td><?php echo htmlspecialchars($contact['contact_name'] ?: '未提供'); ?></td>
                                        <td><?php echo htmlspecialchars($contact['email']); ?></td>
                                        <td><?php echo htmlspecialchars($contact['city'] ?: '未提供'); ?></td>
                                        <td><?php echo htmlspecialchars($contact['district'] ?: '未提供'); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo $contact['is_active'] == 1 ? 'active' : 'inactive'; ?>">
                                                <?php echo $contact['is_active'] == 1 ? '啟用' : '停用'; ?>
                                            </span>
                                        </td>
                                        <td><?php echo $contact['created_at'] ? date('Y/m/d H:i', strtotime($contact['created_at'])) : '-'; ?></td>
                                        <td><?php echo $contact['updated_at'] ? date('Y/m/d H:i', strtotime($contact['updated_at'])) : '-'; ?></td>
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
        const table = document.getElementById('contactsTable');
        const rows = table ? table.getElementsByTagName('tbody')[0].getElementsByTagName('tr') : [];

        if (searchInput) {
            searchInput.addEventListener('keyup', function() {
                const filter = searchInput.value.toLowerCase();
                
                for (let i = 0; i < rows.length; i++) {
                    const schoolCell = rows[i].getElementsByTagName('td')[0];
                    const contactCell = rows[i].getElementsByTagName('td')[1];
                    const emailCell = rows[i].getElementsByTagName('td')[2];
                    
                    if (schoolCell || contactCell || emailCell) {
                        const schoolText = schoolCell.textContent || schoolCell.innerText;
                        const contactText = contactCell.textContent || contactCell.innerText;
                        const emailText = emailCell.textContent || emailCell.innerText;
                        
                        if (schoolText.toLowerCase().indexOf(filter) > -1 || 
                            contactText.toLowerCase().indexOf(filter) > -1 || 
                            emailText.toLowerCase().indexOf(filter) > -1) {
                            rows[i].style.display = "";
                        } else {
                            rows[i].style.display = "none";
                        }
                    }
                }
            });
        }

        window.sortTable = function(colIndex, type = 'string') {
            const table = document.getElementById('contactsTable');
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
            const headers = document.querySelectorAll('#contactsTable th');
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
    });
    </script>
</body>
</html>

