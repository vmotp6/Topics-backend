<?php
session_start();

// 檢查是否已登入
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: login.php");
    exit;
}

// 檢查是否為 admin1 或 IMD 用戶
$username = $_SESSION['username'] ?? '';
if ($username !== 'admin1' && $username !== 'IMD') {
    header("Location: index.php");
    exit;
}

// 引入資料庫設定
require_once '../../Topics-frontend/frontend/config.php';

// 設置頁面標題
$page_title = '五專入學說明會';
$current_page = 'admission_applications';

// 建立資料庫連接
try {
    $conn = getDatabaseConnection();
} catch (Exception $e) {
    die("資料庫連接失敗: " . $e->getMessage());
}

// 獲取所有五專入學說明會資料
$applications = [];
$stats = [
    'total' => 0,
    'receive_info' => 0
];

try {
    // 檢查表是否存在
    $table_check = $conn->query("SHOW TABLES LIKE 'admission_applications'");
    if (!$table_check || $table_check->num_rows == 0) {
        throw new Exception("資料表 'admission_applications' 不存在");
    }
    
    // 根據用戶身份決定查詢條件
    if ($username === 'IMD') {
        // IMD 用戶只能看到體驗課程為"資訊管理科"的資料（第一志願或第二志願）
        $sql = "SELECT * FROM admission_applications 
                WHERE (course_priority_1 = '資訊管理科' OR course_priority_2 = '資訊管理科')
                ORDER BY created_at DESC";
    } else {
        // admin1 用戶可以看到所有資料
        $sql = "SELECT * FROM admission_applications ORDER BY created_at DESC";
    }
    
    $result = $conn->query($sql);
    
    if ($result) {
        $applications = $result->fetch_all(MYSQLI_ASSOC);
        
        // 計算統計資料
        $stats['total'] = count($applications);
        foreach ($applications as $app) {
            if ($app['receive_info'] == 1) {
                $stats['receive_info']++;
            }
        }
    }
} catch (Exception $e) {
    error_log("獲取五專入學說明會資料失敗: " . $e->getMessage());
    $applications = [];
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - 後台管理系統</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #1890ff;
            --text-color: #262626;
            --text-secondary-color: #8c8c8c;
            --border-color: #f0f0f0;
            --background-color: #f0f2f5;
            --card-background-color: #fff;
        }
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: var(--background-color);
            color: var(--text-color);
            overflow-x: hidden;
        }
        .dashboard {
            display: flex;
            min-height: 100vh;
        }
        .content {
            padding: 24px;
            width: 100%;
        }
        .breadcrumb {
            margin-bottom: 16px;
            font-size: 16px;
            color: var(--text-secondary-color);
        }
        .breadcrumb a {
            color: var(--primary-color);
            text-decoration: none;
        }
        .breadcrumb a:hover {
            text-decoration: underline;
        }
        
        /* 統計卡片 */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            display: flex;
            align-items: center;
            gap: 12px;
            border: 1px solid #f0f0f0;
            transition: all 0.3s;
        }
        
        .stat-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            transform: translateY(-2px);
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
        .stat-icon.receive-info { 
            background: linear-gradient(135deg, #52c41a, #73d13d); 
        }
        
        .stat-info h3 {
            font-size: 24px;
            font-weight: 600;
            margin: 0;
            color: var(--text-color);
        }
        
        .stat-info p {
            font-size: 14px;
            color: var(--text-secondary-color);
            margin: 4px 0 0 0;
        }
        
        /* 表格樣式 */
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
        }
        
        .table-header h2 {
            font-size: 18px;
            font-weight: 600;
            margin: 0;
        }
        
        .table-search {
            display: flex;
            gap: 12px;
            align-items: center;
        }
        
        .table-search input {
            padding: 8px 12px;
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            font-size: 14px;
            width: 250px;
        }
        
        .table-search input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.2);
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        thead {
            background: #fafafa;
        }
        
        th {
            padding: 12px 16px;
            text-align: left;
            font-weight: 600;
            font-size: 14px;
            color: var(--text-color);
            border-bottom: 1px solid #f0f0f0;
        }
        
        td {
            padding: 12px 16px;
            font-size: 14px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        tbody tr:hover {
            background: #fafafa;
        }
        
        tbody tr:last-child td {
            border-bottom: none;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .badge-yes {
            background: #f6ffed;
            color: #52c41a;
            border: 1px solid #b7eb8f;
        }
        
        .badge-no {
            background: #fff2f0;
            color: #ff4d4f;
            border: 1px solid #ffccc7;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-secondary-color);
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.3;
        }
        
        .empty-state p {
            font-size: 16px;
        }
        
        /* 按鈕樣式 */
        .btn {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .btn-primary {
            background: var(--primary-color);
            color: white;
        }
        
        .btn-primary:hover {
            background: #40a9ff;
        }
        
        .btn-info {
            background: #13c2c2;
            color: white;
        }
        
        .btn-info:hover {
            background: #36cfc9;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        /* 模態框樣式 */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            overflow: auto;
        }
        
        .modal-content {
            background-color: #fff;
            margin: 5% auto;
            padding: 24px;
            border-radius: 8px;
            width: 90%;
            max-width: 600px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .modal-header h3 {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
            color: var(--text-color);
        }
        
        .close {
            color: #aaa;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            line-height: 1;
        }
        
        .close:hover {
            color: #000;
        }
        
        .info-group {
            margin-bottom: 16px;
        }
        
        .info-label {
            font-weight: 600;
            color: var(--text-secondary-color);
            font-size: 13px;
            margin-bottom: 4px;
        }
        
        .info-value {
            color: var(--text-color);
            font-size: 15px;
            padding: 8px 12px;
            background: #fafafa;
            border-radius: 4px;
        }
        
        .info-value:empty::before {
            content: '-';
            color: var(--text-secondary-color);
        }
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

                <!-- 統計卡片 -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon total">
                            <i class="fas fa-clipboard-list"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['total']; ?></h3>
                            <p>總報名數</p>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon receive-info">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['receive_info']; ?></h3>
                            <p>願意接收訊息</p>
                        </div>
                    </div>
                    
                </div>

                <!-- 資料表格 -->
                <div class="table-container">
                    <div class="table-header">
                        <h2>報名資料列表</h2>
                        <div class="table-search">
                            <input type="text" id="tableSearchInput" placeholder="搜尋報名資料..." onkeyup="filterTable()">
                        </div>
                    </div>
                    
                    <?php if (empty($applications)): ?>
                        <div class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <p>目前沒有任何報名資料</p>
                        </div>
                    <?php else: ?>
                        <table id="applicationsTable">
                            <thead>
                                <tr>
                                    <th>編號</th>
                                    <th>學生姓名</th>
                                    <th>學校名稱</th>
                                    <th>年級</th>
                                    <th>Email</th>
                                    <th>場次選擇</th>
                                   
                                    <th>接收訊息</th>
                                    <th>報名時間</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($applications as $app): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($app['id']); ?></td>
                                        <td><?php echo htmlspecialchars($app['student_name'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($app['school_name'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($app['grade'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($app['email'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($app['session_choice'] ?? '-'); ?></td>
                                        
                                        <td>
                                            <?php if ($app['receive_info'] == 1): ?>
                                                <span class="badge badge-yes">是</span>
                                            <?php else: ?>
                                                <span class="badge badge-no">否</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $app['created_at'] ? date('Y-m-d H:i', strtotime($app['created_at'])) : '-'; ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-info" onclick='showContactInfo(<?php echo json_encode($app, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                                    <i class="fas fa-user"></i>
                                                    查看聯絡人資訊
                                                </button>
                                                <button class="btn btn-primary" onclick='showCourseInfo(<?php echo json_encode($app, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                                    <i class="fas fa-book"></i>
                                                    查看體驗課程
                                                </button>
                                            </div>
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
    
    <!-- 聯絡人資訊模態框 -->
    <div id="contactModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>聯絡人資訊</h3>
                <span class="close" onclick="closeModal('contactModal')">&times;</span>
            </div>
            <div id="contactInfoContent">
                <!-- 動態載入內容 -->
            </div>
        </div>
    </div>
    
    <!-- 體驗課程模態框 -->
    <div id="courseModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>體驗課程資訊</h3>
                <span class="close" onclick="closeModal('courseModal')">&times;</span>
            </div>
            <div id="courseInfoContent">
                <!-- 動態載入內容 -->
            </div>
        </div>
    </div>
    
    <script>
        // 獲取當前用戶名（用於權限控制）
        const currentUsername = '<?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>';
        
        // 表格搜尋功能
        function filterTable() {
            const input = document.getElementById('tableSearchInput');
            const filter = input.value.toLowerCase();
            const table = document.getElementById('applicationsTable');
            const tr = table.getElementsByTagName('tr');
            
            for (let i = 1; i < tr.length; i++) {
                const td = tr[i].getElementsByTagName('td');
                let found = false;
                
                for (let j = 0; j < td.length; j++) {
                    if (td[j]) {
                        const txtValue = td[j].textContent || td[j].innerText;
                        if (txtValue.toLowerCase().indexOf(filter) > -1) {
                            found = true;
                            break;
                        }
                    }
                }
                
                tr[i].style.display = found ? '' : 'none';
            }
        }
        
        // HTML 轉義函數
        function escapeHtml(text) {
            if (!text) return '-';
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(text).replace(/[&<>"']/g, m => map[m]);
        }
        
        // 顯示聯絡人資訊
        function showContactInfo(app) {
            const content = document.getElementById('contactInfoContent');
            content.innerHTML = `
                <div class="info-group">
                    <div class="info-label">姓名</div>
                    <div class="info-value">${escapeHtml(app.parent_name)}</div>
                </div>
                <div class="info-group">
                    <div class="info-label">聯絡電話</div>
                    <div class="info-value">${escapeHtml(app.contact_phone)}</div>
                </div>
                <div class="info-group">
                    <div class="info-label">LINE ID</div>
                    <div class="info-value">${escapeHtml(app.line_id)}</div>
                </div>
                
            `;
            document.getElementById('contactModal').style.display = 'block';
        }
        
        // 顯示體驗課程資訊
        function showCourseInfo(app) {
            const content = document.getElementById('courseInfoContent');
            let html = '';
            
            // 如果是IMD用戶，只顯示資訊管理科
            if (currentUsername === 'IMD') {
                const targetCourse = '資訊管理科';
                let hasInfo = false;
                
                // 只顯示第一志願或第二志願為資訊管理科的項目
                if (app.course_priority_1 === targetCourse) {
                    html += `
                        <div class="info-group">
                            <div class="info-label">第一志願</div>
                            <div class="info-value">${escapeHtml(app.course_priority_1)}</div>
                        </div>
                    `;
                    hasInfo = true;
                }
                
                if (app.course_priority_2 === targetCourse) {
                    html += `
                        <div class="info-group">
                            <div class="info-label">第二志願</div>
                            <div class="info-value">${escapeHtml(app.course_priority_2)}</div>
                        </div>
                    `;
                    hasInfo = true;
                }
                
                // 如果沒有資訊管理科，顯示提示
                if (!hasInfo) {
                    html = '<div class="info-group"><div class="info-value" style="text-align: center; color: var(--text-secondary-color);">無相關課程資訊</div></div>';
                }
            } else {
                // admin1用戶可以看到所有志願
                html = `
                    <div class="info-group">
                        <div class="info-label">第一志願</div>
                        <div class="info-value">${escapeHtml(app.course_priority_1)}</div>
                    </div>
                    <div class="info-group">
                        <div class="info-label">第二志願</div>
                        <div class="info-value">${escapeHtml(app.course_priority_2)}</div>
                    </div>
                `;
            }
            
            content.innerHTML = html;
            document.getElementById('courseModal').style.display = 'block';
        }
        
        // 關閉模態框
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        // 點擊模態框外部關閉
        window.onclick = function(event) {
            const contactModal = document.getElementById('contactModal');
            const courseModal = document.getElementById('courseModal');
            if (event.target == contactModal) {
                contactModal.style.display = 'none';
            }
            if (event.target == courseModal) {
                courseModal.style.display = 'none';
            }
        }
    </script>
</body>
</html>

