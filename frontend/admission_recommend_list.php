<?php
session_start();

// 檢查是否已登入
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: login.php");
    exit;
}

// 檢查是否為 admin1 用戶
$username = $_SESSION['username'] ?? '';
if ($username !== 'admin1') {
    header("Location: index.php");
    exit;
}

// 引入資料庫設定
require_once '../../Topics-frontend/frontend/config.php';

// 設置頁面標題
$page_title = '招生推薦管理';
$current_page = 'admission_recommend_list';

// 建立資料庫連接
try {
    $conn = getDatabaseConnection();
} catch (Exception $e) {
    die("資料庫連接失敗: " . $e->getMessage());
}

// 獲取所有招生推薦資料
try {
    $stmt = $conn->prepare("SELECT 
        id, recommender_name, recommender_student_id, recommender_grade, 
        recommender_department, recommender_phone, recommender_email,
        student_name, student_school, student_grade, 
        student_phone, student_email, student_line_id,
        recommendation_reason, student_interest, additional_info, 
        status, enrollment_status, proof_evidence,
        created_at, updated_at
        FROM admission_recommendations 
        ORDER BY created_at DESC");
    $stmt->execute();
    $recommendations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) {
    error_log("獲取招生推薦資料失敗: " . $e->getMessage());
    $recommendations = [];
}

// 統計資料
$stats = [
    'total' => count($recommendations),
    'pending' => count(array_filter($recommendations, function($r) { return ($r['status'] ?? 'pending') === 'pending'; })),
    'contacted' => count(array_filter($recommendations, function($r) { return ($r['status'] ?? '') === 'contacted'; })),
    'registered' => count(array_filter($recommendations, function($r) { return ($r['status'] ?? '') === 'registered'; })),
    'rejected' => count(array_filter($recommendations, function($r) { return ($r['status'] ?? '') === 'rejected'; }))
];

function getStatusText($status) {
    switch ($status) {
        case 'contacted': return '已聯繫';
        case 'registered': return '已報名';
        case 'rejected': return '已拒絕';
        default: return '待處理';
    }
}

function getStatusClass($status) {
    switch ($status) {
        case 'contacted': return 'status-contacted';
        case 'registered': return 'status-registered';
        case 'rejected': return 'status-rejected';
        default: return 'status-pending';
    }
}

function getEnrollmentStatusText($status) {
    switch ($status) {
        case '已入學': return '已入學';
        case '放棄入學': return '放棄入學';
        default: return '未入學';
    }
}

function getEnrollmentStatusClass($status) {
    switch ($status) {
        case '已入學': return 'enrollment-enrolled';
        case '放棄入學': return 'enrollment-cancelled';
        default: return 'enrollment-not';
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
            --primary-color: #1890ff;
            --text-color: #262626;
            --text-secondary-color: #8c8c8c;
            --border-color: #f0f0f0;
            --background-color: #f0f2f5;
            --card-background-color: #fff;
            --status-pending-bg: #fff7e6;
            --status-pending-text: #d46b08;
            --status-contacted-bg: #e6f7ff;
            --status-contacted-text: #0958d9;
            --status-registered-bg: #f6ffed;
            --status-registered-text: #52c41a;
            --status-rejected-bg: #fff2f0;
            --status-rejected-text: #cf1322;
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
        .stat-icon.pending { 
            background: linear-gradient(135deg, #fa8c16, #ffa940); 
        }
        .stat-icon.contacted { 
            background: linear-gradient(135deg, #1890ff, #40a9ff); 
        }
        .stat-icon.registered { 
            background: linear-gradient(135deg, #52c41a, #73d13d); 
        }
        .stat-icon.rejected { 
            background: linear-gradient(135deg, #f5222d, #ff4d4f); 
        }
        
        .stat-info h3 {
            font-size: 24px;
            margin-bottom: 4px;
            color: #262626;
            font-weight: 600;
        }
        
        .stat-info p {
            color: #8c8c8c;
            font-weight: 500;
            font-size: 14px;
        }
        
        .card {
            background: var(--card-background-color);
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
        }
        .card-header {
            padding: 16px 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #fafafa;
        }
        .card-header h3 {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-color);
        }
        .card-body {
            padding: 24px;
        }

        .table-container {
            overflow-x: auto;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        .table th, .table td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
            font-size: 14px;
        }
        .table th {
            background: #fafafa;
            font-weight: 600;
            white-space: nowrap;
        }
        .table tr:hover {
            background: #fafafa;
        }

        .search-input {
            padding: 8px 12px;
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            font-size: 14px;
            width: 250px;
        }
        .empty-state {
            text-align: center;
            padding: 40px;
            color: var(--text-secondary-color);
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
            border: 1px solid;
        }
        .status-pending {
            background: var(--status-pending-bg);
            color: var(--status-pending-text);
            border-color: #ffd591;
        }
        .status-contacted {
            background: var(--status-contacted-bg);
            color: var(--status-contacted-text);
            border-color: #91d5ff;
        }
        .status-registered {
            background: var(--status-registered-bg);
            color: var(--status-registered-text);
            border-color: #b7eb8f;
        }
        .status-rejected {
            background: var(--status-rejected-bg);
            color: var(--status-rejected-text);
            border-color: #ffa39e;
        }
        
        .enrollment-status {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        .enrollment-enrolled {
            background: #f6ffed;
            color: #52c41a;
        }
        .enrollment-cancelled {
            background: #fff7e6;
            color: #fa8c16;
        }
        .enrollment-not {
            background: #f5f5f5;
            color: #8c8c8c;
        }

        .btn-view {
            padding: 4px 12px;
            border: 1px solid #1890ff;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
            background: #fff;
            color: #1890ff;
            margin-right: 8px;
        }
        .btn-view:hover {
            background: #1890ff;
            color: white;
        }
        
        .info-row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 4px;
        }
        .info-label {
            font-weight: 600;
            color: var(--text-secondary-color);
            min-width: 80px;
        }
        .info-value {
            color: var(--text-color);
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
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['total']; ?></h3>
                            <p>總推薦數</p>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon pending">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['pending']; ?></h3>
                            <p>待處理</p>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon contacted">
                            <i class="fas fa-phone"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['contacted']; ?></h3>
                            <p>已聯繫</p>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon registered">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['registered']; ?></h3>
                            <p>已報名</p>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon rejected">
                            <i class="fas fa-times-circle"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $stats['rejected']; ?></h3>
                            <p>已拒絕</p>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3><?php echo $page_title; ?> (共 <?php echo count($recommendations); ?> 筆)</h3>
                        <input type="text" id="searchInput" class="search-input" placeholder="搜尋推薦人、學生姓名、學校或電話...">
                    </div>
                    <div class="card-body table-container">
                        <?php if (empty($recommendations)): ?>
                            <div class="empty-state">
                                <i class="fas fa-inbox fa-3x" style="margin-bottom: 16px;"></i>
                                <p>目前尚無任何招生推薦資料。</p>
                            </div>
                        <?php else: ?>
                            <table class="table" id="recommendationTable">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>推薦人資訊</th>
                                        <th>被推薦學生</th>
                                        <th>學生學校/年級</th>
                                        <th>聯絡方式</th>
                                        <th>學生興趣</th>
                                        <th>狀態</th>
                                        <th>入學狀態</th>
                                        <th>推薦時間</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recommendations as $item): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['id']); ?></td>
                                        <td>
                                            <div class="info-row">
                                                <span class="info-label">姓名:</span>
                                                <span class="info-value"><?php echo htmlspecialchars($item['recommender_name']); ?></span>
                                            </div>
                                            <div class="info-row">
                                                <span class="info-label">學號:</span>
                                                <span class="info-value"><?php echo htmlspecialchars($item['recommender_student_id']); ?></span>
                                            </div>
                                            <div class="info-row">
                                                <span class="info-label">科系:</span>
                                                <span class="info-value"><?php echo htmlspecialchars($item['recommender_department']); ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="info-row">
                                                <span class="info-label">姓名:</span>
                                                <span class="info-value"><?php echo htmlspecialchars($item['student_name']); ?></span>
                                            </div>
                                            <?php if (!empty($item['student_email'])): ?>
                                            <div class="info-row">
                                                <span class="info-label">Email:</span>
                                                <span class="info-value"><?php echo htmlspecialchars($item['student_email']); ?></span>
                                            </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="info-row">
                                                <span class="info-value"><?php echo htmlspecialchars($item['student_school']); ?></span>
                                            </div>
                                            <?php if (!empty($item['student_grade'])): ?>
                                            <div class="info-row">
                                                <span class="info-value"><?php echo htmlspecialchars($item['student_grade']); ?></span>
                                            </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($item['student_phone'])): ?>
                                            <div class="info-row">
                                                <span class="info-label">電話:</span>
                                                <span class="info-value"><?php echo htmlspecialchars($item['student_phone']); ?></span>
                                            </div>
                                            <?php endif; ?>
                                            <?php if (!empty($item['student_line_id'])): ?>
                                            <div class="info-row">
                                                <span class="info-label">LINE:</span>
                                                <span class="info-value"><?php echo htmlspecialchars($item['student_line_id']); ?></span>
                                            </div>
                                            <?php endif; ?>
                                            <?php if (!empty($item['recommender_phone'])): ?>
                                            <div class="info-row">
                                                <span class="info-label">推薦人:</span>
                                                <span class="info-value"><?php echo htmlspecialchars($item['recommender_phone']); ?></span>
                                            </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php 
                                            if (!empty($item['student_interest'])) {
                                                echo htmlspecialchars($item['student_interest']);
                                            } else {
                                                echo '<span style="color: #8c8c8c;">未填寫</span>';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo getStatusClass($item['status'] ?? 'pending'); ?>">
                                                <?php echo getStatusText($item['status'] ?? 'pending'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="enrollment-status <?php echo getEnrollmentStatusClass($item['enrollment_status'] ?? '未入學'); ?>">
                                                <?php echo getEnrollmentStatusText($item['enrollment_status'] ?? '未入學'); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('Y/m/d H:i', strtotime($item['created_at'])); ?></td>
                                        <td>
                                            <a href="../Topics-frontend/frontend/admission_recommend.php?id=<?php echo $item['id']; ?>" 
                                               class="btn-view" 
                                               target="_blank">
                                                <i class="fas fa-eye"></i> 查看詳情
                                            </a>
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

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('searchInput');
        const table = document.getElementById('recommendationTable');
        const rows = table ? table.getElementsByTagName('tbody')[0].getElementsByTagName('tr') : [];

        if (searchInput && rows.length > 0) {
            searchInput.addEventListener('keyup', function() {
                const filter = searchInput.value.toLowerCase();
                
                for (let i = 0; i < rows.length; i++) {
                    const cells = rows[i].getElementsByTagName('td');
                    let found = false;
                    
                    // 搜尋所有欄位
                    for (let j = 0; j < cells.length; j++) {
                        const cellText = cells[j].textContent || cells[j].innerText;
                        if (cellText.toLowerCase().indexOf(filter) > -1) {
                            found = true;
                            break;
                        }
                    }
                    
                    rows[i].style.display = found ? "" : "none";
                }
            });
        }
    });
    </script>
</body>
</html>

