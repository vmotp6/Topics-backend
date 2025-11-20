<?php
session_start();

// 檢查是否已登入
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: login.php");
    exit;
}

// 檢查是否為 admin1 或 IMD 用戶
$username = $_SESSION['username'] ?? '';
$is_imd = ($username === 'IMD');
$is_admin1 = ($username === 'admin1');
if (!$is_admin1 && !$is_imd) {
    header("Location: index.php");
    exit;
}

// 判斷是否為招生中心（admin1）或部門用戶（IMD）
$is_admission_center = $is_admin1;
$is_department_user = $is_imd;

// 引入資料庫設定
require_once '../../Topics-frontend/frontend/config.php';

// 設置頁面標題
$page_title = $is_imd ? '資管科被推薦人資訊' : '被推薦人資訊';
$current_page = 'admission_recommend_list';

// 建立資料庫連接
try {
    $conn = getDatabaseConnection();
} catch (Exception $e) {
    die("資料庫連接失敗: " . $e->getMessage());
}

// 獲取所有招生推薦資料
try {
    // 先檢查表是否存在
    $table_check = $conn->query("SHOW TABLES LIKE 'admission_recommendations'");
    if (!$table_check || $table_check->num_rows == 0) {
        throw new Exception("資料表 'admission_recommendations' 不存在");
    }
    
    // 如果是IMD帳號，顯示學生興趣是資訊管理科的記錄，或已被分配給IMD部門的記錄
    $where_clause = "";
    if ($is_imd) {
        $where_clause = " WHERE ((student_interest LIKE '%資管%' OR student_interest LIKE '%資訊管理%') OR assigned_department = 'IMD')";
    }
    
    // 檢查字段是否存在，如果不存在則動態添加
    $columns_to_check = ['assigned_department', 'assigned_teacher_id', 'status', 'enrollment_status'];
    foreach ($columns_to_check as $column) {
        $column_check = $conn->query("SHOW COLUMNS FROM admission_recommendations LIKE '$column'");
        if (!$column_check || $column_check->num_rows == 0) {
            // 字段不存在，動態添加
            try {
                if ($column === 'assigned_department') {
                    $conn->query("ALTER TABLE admission_recommendations ADD COLUMN assigned_department VARCHAR(50) DEFAULT NULL");
                } elseif ($column === 'assigned_teacher_id') {
                    $conn->query("ALTER TABLE admission_recommendations ADD COLUMN assigned_teacher_id INT DEFAULT NULL");
                } elseif ($column === 'status') {
                    $conn->query("ALTER TABLE admission_recommendations ADD COLUMN status VARCHAR(20) DEFAULT 'pending'");
                } elseif ($column === 'enrollment_status') {
                    $conn->query("ALTER TABLE admission_recommendations ADD COLUMN enrollment_status VARCHAR(20) DEFAULT NULL");
                }
            } catch (Exception $e) {
                error_log("添加字段 $column 失敗: " . $e->getMessage());
            }
        }
    }
    
    // 構建SQL查詢，使用COALESCE處理可能為NULL的字段
    $sql = "SELECT 
        ar.id, ar.recommender_name, ar.recommender_student_id, ar.recommender_grade, 
        ar.recommender_department, ar.recommender_phone, ar.recommender_email,
        ar.student_name, ar.student_school, ar.student_grade, 
        ar.student_phone, ar.student_email, ar.student_line_id,
        ar.recommendation_reason, ar.student_interest, ar.additional_info, 
        COALESCE(ar.status, 'pending') as status, 
        COALESCE(ar.enrollment_status, '未入學') as enrollment_status, 
        ar.proof_evidence,
        ar.assigned_department, ar.assigned_teacher_id,
        t.name as teacher_name, u.username as teacher_username,
        ar.created_at, ar.updated_at
        FROM admission_recommendations ar
        LEFT JOIN user u ON ar.assigned_teacher_id = u.id
        LEFT JOIN teacher t ON u.id = t.user_id
        $where_clause
        ORDER BY ar.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("SQL 準備失敗: " . $conn->error . " (SQL: " . $sql . ")");
    }
    
    if (!$stmt->execute()) {
        throw new Exception("SQL 執行失敗: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    $recommendations = $result->fetch_all(MYSQLI_ASSOC);
    
    // 調試信息：檢查總數（僅在開發環境顯示）
    if (isset($_GET['debug']) && $_GET['debug'] == '1') {
        $count_sql = "SELECT COUNT(*) as total FROM admission_recommendations" . ($is_imd ? " WHERE ((student_interest LIKE '%資管%' OR student_interest LIKE '%資訊管理%') OR assigned_department = 'IMD')" : "");
        $count_result = $conn->query($count_sql);
        if ($count_result) {
            $count_row = $count_result->fetch_assoc();
            error_log("招生推薦總數: " . $count_row['total'] . " (當前用戶: " . $username . ", IMD: " . ($is_imd ? '是' : '否') . ")");
        }
    }
} catch (Exception $e) {
    error_log("獲取招生推薦資料失敗: " . $e->getMessage());
    $recommendations = [];
    // 在開發模式下顯示錯誤信息
    if (isset($_GET['debug']) && $_GET['debug'] == '1') {
        echo "<div style='background: #fff2f0; border: 1px solid #ffccc7; padding: 16px; margin: 16px; border-radius: 4px;'>";
        echo "<strong>錯誤:</strong> " . htmlspecialchars($e->getMessage());
        echo "</div>";
    }
}

// 如果是部門用戶（IMD），獲取老師列表
$teachers = [];
if ($is_department_user) {
    try {
        $table_check = $conn->query("SHOW TABLES LIKE 'user'");
        if ($table_check && $table_check->num_rows > 0) {
            $teacher_stmt = $conn->prepare("
                SELECT u.id, u.username, t.name, t.department 
                FROM user u 
                LEFT JOIN teacher t ON u.id = t.user_id 
                WHERE u.role = '老師' 
                ORDER BY t.name ASC
            ");
            
            if ($teacher_stmt && $teacher_stmt->execute()) {
                $teacher_result = $teacher_stmt->get_result();
                if ($teacher_result) {
                    $teachers = $teacher_result->fetch_all(MYSQLI_ASSOC);
                }
            }
        }
    } catch (Exception $e) {
        error_log("獲取老師列表失敗: " . $e->getMessage());
    }
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
        button.btn-view {
            font-family: inherit;
        }
        .detail-row {
            background: #f9f9f9;
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
        
        /* 分配相關樣式 */
        .assign-btn {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.3s;
        }
        .assign-btn:hover {
            background: #40a9ff;
            transform: translateY(-1px);
        }
        
        /* 彈出視窗樣式 */
        .modal {
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .modal-content {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            width: 90%;
            max-width: 500px;
            max-height: 80vh;
            overflow-y: auto;
        }
        .modal-header {
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h3 {
            margin: 0;
            color: var(--text-color);
        }
        .close {
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            color: var(--text-secondary-color);
        }
        .close:hover {
            color: var(--text-color);
        }
        .modal-body {
            padding: 20px;
        }
        .modal-body p {
            margin-bottom: 16px;
            font-size: 16px;
        }
        .teacher-list h4 {
            margin-bottom: 12px;
            color: var(--text-color);
        }
        .teacher-options {
            max-height: 300px;
            overflow-y: auto;
        }
        .teacher-option {
            display: block;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            margin-bottom: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .teacher-option:hover {
            background-color: #f5f5f5;
            border-color: var(--primary-color);
        }
        .teacher-option input[type="radio"] {
            margin-right: 12px;
        }
        .teacher-info {
            display: inline-block;
            vertical-align: top;
        }
        .teacher-info strong {
            display: block;
            color: var(--text-color);
            margin-bottom: 4px;
        }
        .teacher-dept {
            color: var(--text-secondary-color);
            font-size: 14px;
        }
        .modal-footer {
            padding: 20px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }
        .btn-cancel, .btn-confirm {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
        }
        .btn-cancel {
            background-color: #f5f5f5;
            color: var(--text-color);
        }
        .btn-cancel:hover {
            background-color: #e8e8e8;
        }
        .btn-confirm {
            background-color: var(--primary-color);
            color: white;
        }
        .btn-confirm:hover {
            background-color: #40a9ff;
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
                        <input type="text" id="searchInput" class="search-input" placeholder="搜尋被推薦人姓名、學校或電話...">
                    </div>
                    <div class="card-body table-container">
                        <?php 
                        // 調試信息：檢查數據庫中的總記錄數
                        if (empty($recommendations)) {
                            try {
                                // 檢查表是否存在
                                $table_check = $conn->query("SHOW TABLES LIKE 'admission_recommendations'");
                                $table_exists = $table_check && $table_check->num_rows > 0;
                                
                                if ($table_exists) {
                                    // 獲取總記錄數
                                    $total_check = $conn->query("SELECT COUNT(*) as total FROM admission_recommendations");
                                    $total_row = $total_check ? $total_check->fetch_assoc() : null;
                                    $total_count = $total_row ? $total_row['total'] : 0;
                                    
                                    if ($total_count > 0) {
                                        // 有數據但查詢結果為空，可能是過濾條件或SQL問題
                                        if ($is_imd) {
                                            // 如果是IMD，檢查有多少符合條件的記錄（興趣是資管或已分配給IMD）
                                            $filter_check = $conn->query("SELECT COUNT(*) as total FROM admission_recommendations WHERE ((student_interest LIKE '%資管%' OR student_interest LIKE '%資訊管理%') OR assigned_department = 'IMD')");
                                            $filter_row = $filter_check ? $filter_check->fetch_assoc() : null;
                                            $filter_count = $filter_row ? $filter_row['total'] : 0;
                                            
                                            // 獲取所有學生興趣的值（用於調試）
                                            $interest_check = $conn->query("SELECT DISTINCT student_interest FROM admission_recommendations WHERE student_interest IS NOT NULL AND student_interest != '' LIMIT 10");
                                            $interest_values = [];
                                            if ($interest_check) {
                                                while ($row = $interest_check->fetch_assoc()) {
                                                    $interest_values[] = $row['student_interest'];
                                                }
                                            }
                                            
                                            // 檢查已分配給IMD的記錄數
                                            $assigned_check = $conn->query("SELECT COUNT(*) as total FROM admission_recommendations WHERE assigned_department = 'IMD'");
                                            $assigned_row = $assigned_check ? $assigned_check->fetch_assoc() : null;
                                            $assigned_count = $assigned_row ? $assigned_row['total'] : 0;
                                            
                                            if ($filter_count == 0) {
                                                // 顯示提示：有數據但不符合過濾條件
                                                echo "<div style='background: #fff7e6; border: 1px solid #ffd591; padding: 16px; margin: 16px; border-radius: 4px;'>";
                                                echo "<p><strong>提示：</strong>資料庫中共有 <strong>{$total_count}</strong> 筆推薦記錄，但沒有符合條件的記錄。</p>";
                                                echo "<p>IMD 帳號會顯示：學生興趣為「資訊管理科」的記錄，或已被分配給 IMD 部門的記錄。</p>";
                                                if ($assigned_count > 0) {
                                                    echo "<p>已分配給 IMD 的記錄數：<strong>{$assigned_count}</strong></p>";
                                                }
                                                if (!empty($interest_values)) {
                                                    echo "<p><strong>資料庫中的學生興趣值範例：</strong></p>";
                                                    echo "<ul style='margin: 8px 0; padding-left: 20px;'>";
                                                    foreach ($interest_values as $val) {
                                                        echo "<li>" . htmlspecialchars($val) . "</li>";
                                                    }
                                                    echo "</ul>";
                                                }
                                                echo "</div>";
                                            }
                                        } else {
                                            // admin1應該看到所有記錄，但查詢結果為空，可能是SQL問題
                                            echo "<div style='background: #fff2f0; border: 1px solid #ffccc7; padding: 16px; margin: 16px; border-radius: 4px;'>";
                                            echo "<p><strong>警告：</strong>資料庫中共有 <strong>{$total_count}</strong> 筆推薦記錄，但查詢結果為空。</p>";
                                            echo "<p>可能是SQL查詢有問題，請檢查錯誤日誌或聯繫系統管理員。</p>";
                                            if (isset($_GET['debug']) && $_GET['debug'] == '1') {
                                                echo "<p style='margin-top: 8px; font-size: 12px; color: #8c8c8c;'>SQL: " . htmlspecialchars($sql) . "</p>";
                                            }
                                            echo "</div>";
                                        }
                                    } else {
                                        // 表存在但沒有數據
                                        echo "<div style='background: #e6f7ff; border: 1px solid #91d5ff; padding: 16px; margin: 16px; border-radius: 4px;'>";
                                        echo "<p><strong>資訊：</strong>資料表存在，但目前沒有任何推薦記錄。</p>";
                                        echo "</div>";
                                    }
                                } else {
                                    // 表不存在
                                    echo "<div style='background: #fff2f0; border: 1px solid #ffccc7; padding: 16px; margin: 16px; border-radius: 4px;'>";
                                    echo "<p><strong>錯誤：</strong>資料表 'admission_recommendations' 不存在。</p>";
                                    echo "<p>請聯繫系統管理員建立資料表。</p>";
                                    echo "</div>";
                                }
                            } catch (Exception $e) {
                                // 顯示錯誤信息
                                echo "<div style='background: #fff2f0; border: 1px solid #ffccc7; padding: 16px; margin: 16px; border-radius: 4px;'>";
                                echo "<p><strong>錯誤：</strong>" . htmlspecialchars($e->getMessage()) . "</p>";
                                echo "</div>";
                            }
                        }
                        ?>
                        <?php if (empty($recommendations)): ?>
                            <div class="empty-state">
                                <i class="fas fa-inbox fa-3x" style="margin-bottom: 16px;"></i>
                                <p>目前尚無任何被推薦人資料。</p>
                                <?php if (isset($_GET['debug']) && $_GET['debug'] == '1'): ?>
                                    <p style="margin-top: 16px; color: #8c8c8c; font-size: 12px;">
                                        調試模式：當前用戶 = <?php echo htmlspecialchars($username); ?>, 
                                        IMD = <?php echo $is_imd ? '是' : '否'; ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <table class="table" id="recommendationTable">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>被推薦人姓名</th>
                                        <th>被推薦人電子郵件</th>
                                        <th>學校/年級</th>
                                        <th>聯絡方式</th>
                                        <th>學生興趣</th>
                                        <th>狀態</th>
                                        <th>入學狀態</th>
                                        <th>推薦時間</th>
                                        <?php if ($is_admission_center): ?>
                                        <th>分配部門</th>
                                        <th>操作</th>
                                        <?php elseif ($is_department_user): ?>
                                        <th>分配狀態</th>
                                        <th>操作</th>
                                        <?php else: ?>
                                        <th>操作</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recommendations as $item): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['id']); ?></td>
                                        <td>
                                            <div class="info-row">
                                                <span class="info-value" style="font-weight: 600;"><?php echo htmlspecialchars($item['student_name']); ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if (!empty($item['student_email'])): ?>
                                                <span class="info-value"><?php echo htmlspecialchars($item['student_email']); ?></span>
                                            <?php else: ?>
                                                <span style="color: #8c8c8c;">未填寫</span>
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
                                            <?php if (empty($item['student_phone']) && empty($item['student_line_id'])): ?>
                                            <span style="color: #8c8c8c;">未填寫</span>
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
                                        <?php if ($is_admission_center): ?>
                                        <td>
                                            <?php if (!empty($item['assigned_department'])): ?>
                                                <span style="color: #52c41a;">
                                                    <i class="fas fa-check-circle"></i> 已分配 - 
                                                    <?php echo htmlspecialchars($item['assigned_department']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="color: #8c8c8c;">
                                                    <i class="fas fa-clock"></i> 未分配
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                                <button type="button" 
                                                   class="btn-view" 
                                                        onclick="toggleDetail(<?php echo $item['id']; ?>)">
                                                    <i class="fas fa-eye"></i> 查看詳情
                                                </button>
                                                <button class="btn-view" style="background: #1890ff; color: white; border-color: #1890ff;" onclick="openAssignRecommendationDepartmentModal(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['student_name']); ?>', '<?php echo htmlspecialchars($item['assigned_department'] ?? ''); ?>')">
                                                    <i class="fas fa-building"></i> <?php echo !empty($item['assigned_department']) ? '重新分配' : '分配'; ?>
                                                </button>
                                            </div>
                                        </td>
                                        <?php elseif ($is_department_user): ?>
                                        <td>
                                            <?php if (!empty($item['assigned_teacher_id'])): ?>
                                                <span style="color: #52c41a;">
                                                    <i class="fas fa-check-circle"></i> 已分配 - 
                                                    <?php echo htmlspecialchars($item['teacher_name'] ?? $item['teacher_username'] ?? '未知老師'); ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="color: #8c8c8c;">
                                                    <i class="fas fa-clock"></i> 未分配
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                                <button type="button" 
                                                   class="btn-view" 
                                                        onclick="toggleDetail(<?php echo $item['id']; ?>)">
                                                    <i class="fas fa-eye"></i> 查看詳情
                                                </button>
                                                <button class="btn-view" style="background: #1890ff; color: white; border-color: #1890ff;" onclick="openAssignRecommendationModal(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['student_name']); ?>', <?php echo !empty($item['assigned_teacher_id']) ? $item['assigned_teacher_id'] : 'null'; ?>)">
                                                    <i class="fas fa-user-plus"></i> <?php echo !empty($item['assigned_teacher_id']) ? '重新分配' : '分配'; ?>
                                                </button>
                                            </div>
                                        </td>
                                        <?php else: ?>
                                        <td>
                                            <button type="button" 
                                               class="btn-view" 
                                                    onclick="toggleDetail(<?php echo $item['id']; ?>)">
                                                <i class="fas fa-eye"></i> 查看詳情
                                            </button>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                    <tr id="detail-<?php echo $item['id']; ?>" class="detail-row" style="display: none;">
                                        <td colspan="<?php echo $is_admission_center || $is_department_user ? '11' : '10'; ?>" style="padding: 20px; background: #f9f9f9; border: 2px solid #b3d9ff; border-radius: 4px;">
                                            <table style="width: 100%; border-collapse: collapse;">
                                                <tr>
                                                    <td style="width: 50%; vertical-align: top; padding-right: 20px;">
                                                        <h4 style="margin: 0 0 10px 0; font-size: 16px;">推薦人資訊</h4>
                                                        <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5; width: 120px;">姓名</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd;"><?php echo htmlspecialchars($item['recommender_name']); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">學號/教師編號</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd;"><?php echo htmlspecialchars($item['recommender_student_id']); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">年級</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd;"><?php echo htmlspecialchars($item['recommender_grade']); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">科系</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd;"><?php echo htmlspecialchars($item['recommender_department']); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">聯絡電話</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd;"><?php echo htmlspecialchars($item['recommender_phone']); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">電子郵件</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd;"><?php echo htmlspecialchars($item['recommender_email']); ?></td>
                                                            </tr>
                                                        </table>
                                                    </td>
                                                    <td style="width: 50%; vertical-align: top; padding-left: 20px;">
                                                        <h4 style="margin: 0 0 10px 0; font-size: 16px;">被推薦人資訊</h4>
                                                        <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5; width: 120px;">姓名</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd;"><?php echo htmlspecialchars($item['student_name']); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">電子郵件</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd;"><?php echo !empty($item['student_email']) ? htmlspecialchars($item['student_email']) : '未填寫'; ?></td>
                                                            </tr>
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">就讀學校</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd;"><?php echo htmlspecialchars($item['student_school']); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">年級</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd;"><?php echo !empty($item['student_grade']) ? htmlspecialchars($item['student_grade']) : '未填寫'; ?></td>
                                                            </tr>
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">聯絡電話</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd;"><?php echo htmlspecialchars($item['student_phone']); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">LINE ID</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd;"><?php echo !empty($item['student_line_id']) ? htmlspecialchars($item['student_line_id']) : '未填寫'; ?></td>
                                                            </tr>
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">學生興趣</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd;"><?php echo !empty($item['student_interest']) ? htmlspecialchars($item['student_interest']) : '未填寫'; ?></td>
                                                            </tr>
                                                        </table>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td colspan="2" style="padding-top: 20px;">
                                                        <h4 style="margin: 0 0 10px 0; font-size: 16px;">推薦資訊</h4>
                                                        <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5; width: 120px;">推薦理由</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd;"><?php echo nl2br(htmlspecialchars($item['recommendation_reason'])); ?></td>
                                                            </tr>
                                                            <?php if (!empty($item['additional_info'])): ?>
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">其他補充資訊</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd;"><?php echo nl2br(htmlspecialchars($item['additional_info'])); ?></td>
                                                            </tr>
                                                            <?php endif; ?>
                                                            <?php if (!empty($item['proof_evidence'])): ?>
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">證明文件</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd;">
                                                                    <a href="<?php echo htmlspecialchars($item['proof_evidence']); ?>" target="_blank">查看文件</a>
                                                                </td>
                                                            </tr>
                                                            <?php endif; ?>
                                                        </table>
                                                    </td>
                                                </tr>
                                            </table>
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

    <!-- 分配部門彈出視窗（admin1） -->
    <?php if ($is_admission_center): ?>
    <div id="assignRecommendationDepartmentModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>分配推薦學生至部門</h3>
                <span class="close" onclick="closeAssignRecommendationDepartmentModal()">&times;</span>
            </div>
            <div class="modal-body">
                <p>學生：<span id="recommendationDepartmentStudentName"></span></p>
                <div class="teacher-list">
                    <h4>選擇部門：</h4>
                    <div class="teacher-options">
                        <label class="teacher-option">
                            <input type="radio" name="recommendation_department" value="IMD">
                            <div class="teacher-info">
                                <strong>資管科 (IMD)</strong>
                                <span class="teacher-dept">資訊管理科</span>
                            </div>
                        </label>
                        <label class="teacher-option">
                            <input type="radio" name="recommendation_department" value="FLD">
                            <div class="teacher-info">
                                <strong>應用外語科 (FLD)</strong>
                                <span class="teacher-dept">應用外語科</span>
                            </div>
                        </label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-cancel" onclick="closeAssignRecommendationDepartmentModal()">取消</button>
                <button class="btn-confirm" onclick="assignRecommendationDepartment()">確認分配</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 分配學生彈出視窗（IMD） -->
    <?php if ($is_department_user): ?>
    <div id="assignRecommendationModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>分配推薦學生</h3>
                <span class="close" onclick="closeAssignRecommendationModal()">&times;</span>
            </div>
            <div class="modal-body">
                <p>學生：<span id="recommendationStudentName"></span></p>
                <div class="teacher-list">
                    <h4>選擇老師：</h4>
                    <div class="teacher-options">
                        <?php foreach ($teachers as $teacher): ?>
                        <label class="teacher-option">
                            <input type="radio" name="recommendation_teacher" value="<?php echo $teacher['id']; ?>">
                            <div class="teacher-info">
                                <strong><?php echo htmlspecialchars($teacher['name'] ?? $teacher['username']); ?></strong>
                                <span class="teacher-dept"><?php echo htmlspecialchars($teacher['department'] ?? '未設定科系'); ?></span>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-cancel" onclick="closeAssignRecommendationModal()">取消</button>
                <button class="btn-confirm" onclick="assignRecommendationStudent()">確認分配</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('searchInput');
        const table = document.getElementById('recommendationTable');
        const rows = table ? table.getElementsByTagName('tbody')[0].getElementsByTagName('tr') : [];

        if (searchInput && rows.length > 0) {
            searchInput.addEventListener('keyup', function() {
                const filter = searchInput.value.toLowerCase();
                
                for (let i = 0; i < rows.length; i++) {
                    // 跳過詳細資訊行
                    if (rows[i].classList.contains('detail-row')) {
                        continue;
                    }
                    
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
                    // 如果主行隱藏，也隱藏對應的詳細行
                    const detailRow = rows[i].nextElementSibling;
                    if (detailRow && detailRow.classList.contains('detail-row')) {
                        detailRow.style.display = found ? detailRow.style.display : "none";
                    }
                }
            });
        }
    });

    function toggleDetail(id) {
        const detailRow = document.getElementById('detail-' + id);
        if (detailRow) {
            if (detailRow.style.display === 'none' || detailRow.style.display === '') {
                detailRow.style.display = 'table-row';
            } else {
                detailRow.style.display = 'none';
            }
        }
    }

    // 分配推薦學生相關變數
    let currentRecommendationId = null;

    // 開啟分配推薦學生彈出視窗（IMD）
    <?php if ($is_department_user): ?>
    function openAssignRecommendationModal(recommendationId, studentName, currentTeacherId) {
        currentRecommendationId = recommendationId;
        document.getElementById('recommendationStudentName').textContent = studentName;
        document.getElementById('assignRecommendationModal').style.display = 'flex';
        
        // 清除之前的選擇，如果有已分配的老師則預選
        const radioButtons = document.querySelectorAll('input[name="recommendation_teacher"]');
        radioButtons.forEach(radio => {
            if (currentTeacherId && radio.value == currentTeacherId) {
                radio.checked = true;
            } else {
                radio.checked = false;
            }
        });
    }

    // 關閉分配推薦學生彈出視窗
    function closeAssignRecommendationModal() {
        document.getElementById('assignRecommendationModal').style.display = 'none';
        currentRecommendationId = null;
    }

    // 分配推薦學生
    function assignRecommendationStudent() {
        const selectedTeacher = document.querySelector('input[name="recommendation_teacher"]:checked');
        
        if (!selectedTeacher) {
            alert('請選擇一位老師');
            return;
        }

        const teacherId = selectedTeacher.value;
        
        // 發送AJAX請求
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'assign_recommendation_teacher.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4) {
                if (xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            alert('推薦學生分配成功！');
                            closeAssignRecommendationModal();
                            location.reload();
                        } else {
                            alert('分配失敗：' + (response.message || '未知錯誤'));
                        }
                    } catch (e) {
                        alert('回應格式錯誤：' + xhr.responseText);
                    }
                } else {
                    alert('請求失敗，狀態碼：' + xhr.status);
                }
            }
        };
        
        xhr.send('recommendation_id=' + encodeURIComponent(currentRecommendationId) + 
                 '&teacher_id=' + encodeURIComponent(teacherId));
    }

    // 點擊彈出視窗外部關閉
    document.getElementById('assignRecommendationModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeAssignRecommendationModal();
        }
    });
    <?php endif; ?>

    // 分配部門相關變數
    let currentRecommendationDepartmentId = null;

    // 開啟分配部門彈出視窗（admin1）
    <?php if ($is_admission_center): ?>
    function openAssignRecommendationDepartmentModal(recommendationId, studentName, currentDepartment) {
        currentRecommendationDepartmentId = recommendationId;
        document.getElementById('recommendationDepartmentStudentName').textContent = studentName;
        document.getElementById('assignRecommendationDepartmentModal').style.display = 'flex';
        
        // 清除之前的選擇，如果有已分配的部門則預選
        const radioButtons = document.querySelectorAll('input[name="recommendation_department"]');
        radioButtons.forEach(radio => {
            if (currentDepartment && radio.value === currentDepartment) {
                radio.checked = true;
            } else {
                radio.checked = false;
            }
        });
    }

    // 關閉分配部門彈出視窗
    function closeAssignRecommendationDepartmentModal() {
        document.getElementById('assignRecommendationDepartmentModal').style.display = 'none';
        currentRecommendationDepartmentId = null;
    }

    // 分配部門
    function assignRecommendationDepartment() {
        const selectedDepartment = document.querySelector('input[name="recommendation_department"]:checked');
        
        if (!selectedDepartment) {
            alert('請選擇一個部門');
            return;
        }

        const department = selectedDepartment.value;
        
        // 發送AJAX請求
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'assign_recommendation_department.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4) {
                if (xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            alert('推薦學生分配成功！');
                            closeAssignRecommendationDepartmentModal();
                            location.reload();
                        } else {
                            alert('分配失敗：' + (response.message || '未知錯誤'));
                        }
                    } catch (e) {
                        alert('回應格式錯誤：' + xhr.responseText);
                    }
                } else {
                    alert('請求失敗，狀態碼：' + xhr.status);
                }
            }
        };
        
        xhr.send('recommendation_id=' + encodeURIComponent(currentRecommendationDepartmentId) + 
                 '&department=' + encodeURIComponent(department));
    }

    // 點擊分配部門彈出視窗外部關閉
    document.getElementById('assignRecommendationDepartmentModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeAssignRecommendationDepartmentModal();
        }
    });
    <?php endif; ?>
    </script>
</body>
</html>

