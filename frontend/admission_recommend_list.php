<?php
require_once __DIR__ . '/session_config.php';
checkBackendLogin();

// DB 設定
require_once '../../Topics-frontend/frontend/config.php';

/* ======================
   使用者資訊
====================== */
$username  = $_SESSION['username'] ?? '';
$user_id   = (int)($_SESSION['user_id'] ?? 0);
$user_role = $_SESSION['role'] ?? '';

$is_admin_or_staff    = in_array($user_role, ['ADM', 'STA'], true);
$is_director          = ($user_role === 'DI');
$is_admission_center  = ($user_role === 'AC');   // ★ 補齊（解 Warning）

$user_department_code = null;
$is_department_user   = false;

/* ======================
   取得使用者科系
====================== */
if ($user_id > 0 && ($is_director || !$is_admin_or_staff)) {
    try {
        $conn_tmp = getDatabaseConnection();

        if ($is_director) {
            $stmt = $conn_tmp->prepare(
                "SELECT department FROM director WHERE user_id = ?"
            );
        } else {
            $stmt = $conn_tmp->prepare(
                "SELECT department FROM teacher WHERE user_id = ?"
            );
        }

        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $user_department_code = $row['department'] ?? null;
                $is_department_user   = !empty($user_department_code);
            }
            $stmt->close();
        }
        $conn_tmp->close();
    } catch (Exception $e) {
        error_log('Department fetch error: ' . $e->getMessage());
    }
}

/* ======================
   DB 連線
====================== */
$conn = getDatabaseConnection();

/* ======================
   SQL 條件
====================== */
$where_sql = '';
$params = [];
$types = '';

if ($is_department_user && !$is_admin_or_staff && !$is_admission_center) {
    $where_sql = "
        WHERE (
            ar.assigned_department IS NULL
            OR ar.assigned_department = ''
            OR ar.assigned_department = ?
        )
    ";
    $params[] = $user_department_code;
    $types .= 's';
}

/* ======================
   主查詢
====================== */
$sql = "
SELECT
    ar.id,

    rec.name        AS recommender_name,
    rec.id AS recommender_student_id,
    rec.grade       AS recommender_grade,
    rec.department  AS recommender_department,
    rec.phone       AS recommender_phone,
    rec.email       AS recommender_email,

    red.name        AS student_name,
    red.phone       AS student_phone,
    red.email       AS student_email,
    red.line_id     AS student_line_id,
    red.school      AS student_school,

    ar.recommendation_reason,
    ar.student_interest,
    ar.additional_info,
    COALESCE(ar.status,'pending') AS status,
    COALESCE(ar.enrollment_status,'未入學') AS enrollment_status,
    ar.proof_evidence,
    ar.assigned_department,
    ar.assigned_teacher_id,
    ar.created_at,
    ar.updated_at

FROM admission_recommendations ar
LEFT JOIN recommender rec
    ON ar.id = rec.recommendations_id
LEFT JOIN recommended red
    ON ar.id = red.recommendations_id
{$where_sql}
ORDER BY ar.created_at DESC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("SQL prepare failed: " . $conn->error);
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
$recommendations = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ======================
   老師清單（主任 / 招生中心）
====================== */
$teachers = [];
if ($is_department_user || $is_admission_center) {
    $tstmt = $conn->prepare("
        SELECT u.id, u.username, t.name
        FROM user u
        LEFT JOIN teacher t ON u.id = t.user_id
        ORDER BY t.name
    ");
    if ($tstmt) {
        $tstmt->execute();
        $tres = $tstmt->get_result();
        $teachers = $tres->fetch_all(MYSQLI_ASSOC);
        $tstmt->close();
    }
}

/* ======================
   統計資料
====================== */
$stats = [
    'total'      => count($recommendations),
    'pending'    => 0,
    'contacted'  => 0,
    'registered' => 0,
    'rejected'   => 0
];

foreach ($recommendations as $r) {
    $key = $r['status'] ?? 'pending';
    $stats[$key] = ($stats[$key] ?? 0) + 1;
}

/* ======================
   狀態輔助函式
====================== */
function getStatusText($s) {
    return match ($s) {
        'contacted'  => '已聯繫',
        'registered' => '已報名',
        'rejected'   => '已拒絕',
        default      => '待處理',
    };
}

function getStatusClass($s) {
    return 'status-' . ($s ?: 'pending');
}

function getEnrollmentStatusText($s) {
    return $s ?: '未入學';
}

function getEnrollmentStatusClass($s) {
    return match ($s) {
        '已入學'   => 'enrollment-enrolled',
        '放棄入學' => 'enrollment-cancelled',
        default    => 'enrollment-not'
    };
}
?>


<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>推薦名單管理 - Topics 後台管理系統</title>
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
        
        .page-controls { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-bottom: 16px; 
            gap: 16px; 
        }
        .breadcrumb {
            margin-bottom: 0;
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
        
        .table-wrapper {
            background: var(--card-background-color);
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
            display: flex;
            flex-direction: column;
        }

        .table-container {
            overflow-x: auto;
            flex: 1;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        .table th, .table td {
            padding: 16px 24px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
            font-size: 16px;
            white-space: nowrap;
        }
        .table th:first-child, .table td:first-child {
            padding-left: 60px;
        }
        .table th {
            background: #fafafa;
            font-weight: 600;
            color: #262626;
            cursor: pointer;
            user-select: none;
            position: relative;
        }
        .table td {
            color: #595959;
        }
        .table th:hover {
            background: #f0f0f0;
        }
        .sort-icon {
            margin-left: 8px;
            font-size: 12px;
            color: #8c8c8c;
        }
        .sort-icon.active {
            color: #1890ff;
        }
        .sort-icon.asc::after {
            content: "↑";
        }
        .sort-icon.desc::after {
            content: "↓";
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
            transition: all 0.3s;
        }
        .search-input:focus {
            outline: none;
            border-color: #1890ff;
            box-shadow: 0 0 0 2px rgba(24,144,255,0.2);
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
        
        /* 分頁樣式 */
        .pagination {
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 1px solid var(--border-color);
            background: #fafafa;
        }

        .pagination-info {
            display: flex;
            align-items: center;
            gap: 16px;
            color: var(--text-secondary-color);
            font-size: 14px;
        }

        .pagination-controls {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .pagination select {
            padding: 6px 12px;
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            font-size: 14px;
            background: #fff;
            cursor: pointer;
        }

        .pagination select:focus {
            outline: none;
            border-color: #1890ff;
            box-shadow: 0 0 0 2px rgba(24,144,255,0.2);
        }

        .pagination button {
            padding: 6px 12px;
            border: 1px solid #d9d9d9;
            background: #fff;
            color: #595959;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
        }

        .pagination button:hover:not(:disabled) {
            border-color: #1890ff;
            color: #1890ff;
        }

        .pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .pagination button.active {
            background: #1890ff;
            color: white;
            border-color: #1890ff;
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
                        <a href="index.php">首頁</a> / <?php echo $page_title; ?>
                        <?php if (!empty($recommendations)): ?>
                        <span style="margin-left: 16px; color: var(--text-secondary-color); font-size: 14px;">
                            (共 <?php echo count($recommendations); ?> 筆資料)
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="table-search">
                        <input type="text" id="searchInput" class="search-input" placeholder="搜尋被推薦人姓名、學校或電話...">
                    </div>
                </div>

                <div class="table-wrapper">
                    <div class="table-container">
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
                                        // 移除 IMD 特定過濾，現在使用角色過濾
                                        if ($is_director && !empty($user_department_code)) {
                                            // 如果是主任，檢查有多少符合條件的記錄（興趣是自己科系或已分配給自己科系）
                                            $filter_check = $conn->query("SELECT COUNT(*) as total FROM admission_recommendations WHERE (student_interest = '" . $conn->real_escape_string($user_department_code) . "' OR assigned_department = '" . $conn->real_escape_string($user_department_code) . "')");
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
                                            // 移除身份過濾相關的提示
                                            if ($filter_count == 0) {
                                                // 顯示提示：沒有數據
                                                echo "<div style='background: #fff7e6; border: 1px solid #ffd591; padding: 16px; margin: 16px; border-radius: 4px;'>";
                                                echo "<p><strong>提示：</strong>目前沒有推薦記錄。</p>";
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
                                        角色 = <?php echo htmlspecialchars($user_role); ?>, 
                                        科系 = <?php echo htmlspecialchars($user_department_code ?? '無'); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <table class="table" id="recommendationTable">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>被推薦人姓名</th>
                                        <th>學校</th>
                                        <th>年級</th>
                                        <th>學生興趣</th>
                                        <!-- <th>狀態</th> -->
                                        <!-- <th>入學狀態</th> -->
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
                                                <span class="info-value"><?php echo htmlspecialchars($item['student_name']); ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="info-value"><?php echo htmlspecialchars($item['student_school']); ?></span>
                                        </td>
                                        <td>
                                            <?php if (!empty($item['student_grade'])): ?>
                                                <span class="info-value"><?php echo htmlspecialchars($item['student_grade']); ?></span>
                                            <?php else: ?>
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
                                        <!-- <td>
                                            <span class="status-badge <?php echo getStatusClass($item['status'] ?? 'pending'); ?>">
                                                <?php echo getStatusText($item['status'] ?? 'pending'); ?>
                                            </span>
                                        </td> -->
                                        <!-- <td>
                                            <span class="enrollment-status <?php echo getEnrollmentStatusClass($item['enrollment_status'] ?? '未入學'); ?>">
                                                <?php echo getEnrollmentStatusText($item['enrollment_status'] ?? '未入學'); ?>
                                            </span>
                                        </td> -->
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
                                                   id="detail-btn-<?php echo $item['id']; ?>"
                                                   onclick="toggleDetail(<?php echo $item['id']; ?>)">
                                                    <i class="fas fa-eye"></i> <span class="btn-text">查看詳情</span>
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
                                                   id="detail-btn-<?php echo $item['id']; ?>"
                                                   onclick="toggleDetail(<?php echo $item['id']; ?>)">
                                                    <i class="fas fa-eye"></i> <span class="btn-text">查看詳情</span>
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
                                               id="detail-btn-<?php echo $item['id']; ?>"
                                               onclick="toggleDetail(<?php echo $item['id']; ?>)">
                                                <i class="fas fa-eye"></i> <span class="btn-text">查看詳情</span>
                                            </button>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                    <tr id="detail-<?php echo $item['id']; ?>" class="detail-row" style="display: none;">
                                        <td colspan="<?php echo $is_admission_center || $is_department_user ? '7' : '6'; ?>" style="padding: 20px; background: #f9f9f9; border: 2px solid #b3d9ff; border-radius: 4px;">
                                            <table style="width: 100%; border-collapse: collapse;">
                                                <tr>
                                                    <td style="width: 50%; vertical-align: top; padding-right: 20px;">
                                                        <h4 style="margin: 0 0 10px 0; font-size: 16px;">被推薦人資訊</h4>
                                                        <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5; width: 120px;">姓名</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd;"><?php echo htmlspecialchars($item['student_name']); ?></td>
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
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">電子郵件</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd;"><?php echo !empty($item['student_email']) ? htmlspecialchars($item['student_email']) : '未填寫'; ?></td>
                                                            </tr>
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">聯絡電話</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd;"><?php echo !empty($item['student_phone']) ? htmlspecialchars($item['student_phone']) : '未填寫'; ?></td>
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
                                                    <td style="width: 50%; vertical-align: top; padding-left: 20px;">
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
                                                                    <?php 
                                                                    // 構建文件路徑：文件存儲在前端目錄
                                                                    // 資料庫中存儲的路徑是 uploads/proof_evidence/xxx.jpg（相對於 frontend 目錄）
                                                                    if (!empty($item['proof_evidence'])) {
                                                                        // 確保路徑使用正斜線（Web 標準）
                                                                        $file_path = str_replace('\\', '/', $item['proof_evidence']);
                                                                        // 使用絕對 URL 路徑，從網站根目錄開始
                                                                        // 假設網站根目錄是 Topics-frontend 或 Topics-backend 的父目錄
                                                                        $file_url = '/Topics-frontend/frontend/' . $file_path;
                                                                        echo '<a href="' . htmlspecialchars($file_url) . '" target="_blank" style="color: #1890ff; text-decoration: none;">';
                                                                        echo '<i class="fas fa-file-download"></i> 查看文件';
                                                                        echo '</a>';
                                                                    } else {
                                                                        echo '<span style="color: #8c8c8c;">無文件</span>';
                                                                    }
                                                                    ?>
                                                                </td>
                                                            </tr>
                                                            <?php endif; ?>
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">推薦時間</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd;"><?php echo date('Y/m/d H:i', strtotime($item['created_at'])); ?></td>
                                                            </tr>
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
                    <!-- 分頁控制 -->
                    <?php if (!empty($recommendations)): ?>
                    <div class="pagination">
                        <div class="pagination-info">
                            <span>每頁顯示：</span>
                            <select id="itemsPerPage" onchange="changeItemsPerPage()">
                                <option value="10" selected>10</option>
                                <option value="20">20</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                                <option value="all">全部</option>
                            </select>
                            <span id="pageInfo">顯示第 <span id="currentRange">1-<?php echo min(10, count($recommendations)); ?></span> 筆，共 <?php echo count($recommendations); ?> 筆</span>
                        </div>
                        <div class="pagination-controls">
                            <button id="prevPage" onclick="changePage(-1)" disabled>上一頁</button>
                            <span id="pageNumbers"></span>
                            <button id="nextPage" onclick="changePage(1)">下一頁</button>
                        </div>
                    </div>
                    <?php endif; ?>
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
    // 分頁相關變數
    let currentPage = 1;
    let itemsPerPage = 10; // 預設每頁顯示 10 筆
    let allRows = [];
    let filteredRows = [];
    
    // 搜索功能
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('searchInput');
        const table = document.getElementById('recommendationTable');

        if (searchInput && table) {
            const tbody = table.getElementsByTagName('tbody')[0];
            if (tbody) {
                // 初始化：獲取所有行（排除詳情行和嵌套表格的行）
                // 只獲取 tbody 的直接子 tr 元素，排除 detail-row 和嵌套表格中的行
                const allTrElements = Array.from(tbody.getElementsByTagName('tr'));
                
                // 過濾：只保留主表格的資料行
                // 1. 排除 detail-row 本身
                // 2. 排除 detail-row 內部嵌套表格的所有行
                allRows = allTrElements.filter(row => {
                    // 排除詳情行本身
                    if (row.classList.contains('detail-row')) {
                        return false;
                    }
                    // 檢查是否是嵌套表格中的行
                    // 如果父元素鏈中有 detail-row，則這是嵌套表格中的行
                    let parent = row.parentElement;
                    while (parent && parent !== document.body) {
                        // 如果遇到 detail-row，說明這個 tr 在 detail-row 內部，應該排除
                        if (parent.classList && parent.classList.contains('detail-row')) {
                            return false;
                        }
                        // 如果遇到主表格的 tbody，說明這是主表格的行，保留
                        if (parent === tbody) {
                            return true;
                        }
                        parent = parent.parentElement;
                    }
                    // 如果沒有找到 tbody，可能是其他情況，排除
                    return false;
                });
                
                filteredRows = allRows;
                
                // 調試：確認行數
                console.log('總行數（過濾後）:', allRows.length);
                console.log('所有 tr 元素數:', allTrElements.length);
                console.log('itemsPerPage:', itemsPerPage);
                
                // 確保 itemsPerPage 是數字
                if (typeof itemsPerPage !== 'number') {
                    itemsPerPage = 10;
                }
                
                // 初始化分頁
                updatePagination();
            }
            
            searchInput.addEventListener('keyup', function() {
                const filter = searchInput.value.toLowerCase();
                
                if (!tbody) return;
                
                // 過濾行
                filteredRows = allRows.filter(row => {
                    const cells = row.getElementsByTagName('td');
                    let shouldShow = false;
                    
                    for (let j = 0; j < cells.length; j++) {
                        const cellText = cells[j].textContent || cells[j].innerText;
                        if (cellText.toLowerCase().indexOf(filter) > -1) {
                            shouldShow = true;
                            break;
                        }
                    }
                    
                    return shouldShow;
                });
                
                // 重置到第一頁並更新分頁
                currentPage = 1;
                updatePagination();
            });
        }
    });
    
    function changeItemsPerPage() {
        const selectValue = document.getElementById('itemsPerPage').value;
        itemsPerPage = selectValue === 'all' ? 'all' : parseInt(selectValue);
        currentPage = 1;
        updatePagination();
    }

    function changePage(direction) {
        const totalItems = filteredRows.length;
        let pageSize;
        if (itemsPerPage === 'all') {
            pageSize = totalItems;
        } else {
            pageSize = typeof itemsPerPage === 'number' ? itemsPerPage : parseInt(itemsPerPage);
        }
        const totalPages = pageSize >= totalItems ? 1 : Math.ceil(totalItems / pageSize);
        
        currentPage += direction;
        
        if (currentPage < 1) currentPage = 1;
        if (currentPage > totalPages) currentPage = totalPages;
        
        updatePagination();
    }

    function goToPage(page) {
        currentPage = page;
        updatePagination();
    }

    function updatePagination() {
        const totalItems = filteredRows.length;
        
        // 確保 itemsPerPage 是正確的數字或 'all'
        let pageSize;
        if (itemsPerPage === 'all') {
            pageSize = totalItems;
        } else {
            // 確保是數字類型
            pageSize = typeof itemsPerPage === 'number' ? itemsPerPage : parseInt(itemsPerPage);
            // 如果解析失敗，使用預設值 10
            if (isNaN(pageSize) || pageSize <= 0) {
                pageSize = 10;
                itemsPerPage = 10;
            }
        }
        
        const totalPages = pageSize >= totalItems ? 1 : Math.ceil(totalItems / pageSize);
        
        // 調試信息
        console.log('updatePagination - totalItems:', totalItems, 'pageSize:', pageSize, 'totalPages:', totalPages, 'currentPage:', currentPage);
        
        // 隱藏所有行（包括詳情行）
        allRows.forEach(row => row.style.display = 'none');
        // 隱藏所有詳情行
        document.querySelectorAll('.detail-row').forEach(row => row.style.display = 'none');
        
        if (itemsPerPage === 'all' || pageSize >= totalItems) {
            // 顯示所有過濾後的行（總數小於等於每頁顯示數，或選擇顯示全部）
            filteredRows.forEach(row => row.style.display = '');
            
            // 更新分頁資訊
            document.getElementById('currentRange').textContent = 
                totalItems > 0 ? `1-${totalItems}` : '0-0';
        } else {
            // 計算當前頁的範圍
            const start = (currentPage - 1) * pageSize;
            const end = Math.min(start + pageSize, totalItems);
            
            console.log('顯示範圍:', start, '到', end);
            
            // 顯示當前頁的行
            for (let i = start; i < end; i++) {
                if (filteredRows[i]) {
                    filteredRows[i].style.display = '';
                }
            }
            
            // 更新分頁資訊
            document.getElementById('currentRange').textContent = 
                totalItems > 0 ? `${start + 1}-${end}` : '0-0';
        }
        
        // 更新總數
        document.getElementById('pageInfo').innerHTML = 
            `顯示第 <span id="currentRange">${document.getElementById('currentRange').textContent}</span> 筆，共 ${totalItems} 筆`;
        
        // 更新上一頁/下一頁按鈕
        const prevBtn = document.getElementById('prevPage');
        const nextBtn = document.getElementById('nextPage');
        if (prevBtn) prevBtn.disabled = currentPage === 1;
        if (nextBtn) nextBtn.disabled = currentPage >= totalPages || totalPages <= 1;
        
        // 更新頁碼按鈕
        updatePageNumbers(totalPages);
    }

    function updatePageNumbers(totalPages) {
        const pageNumbers = document.getElementById('pageNumbers');
        if (!pageNumbers) return;
        
        pageNumbers.innerHTML = '';
        
        // 總是顯示頁碼按鈕（即使只有1頁）
        if (totalPages >= 1) {
            // 如果只有1頁，只顯示"1"
            // 如果有多頁，顯示所有頁碼
            const pagesToShow = totalPages === 1 ? [1] : Array.from({length: totalPages}, (_, i) => i + 1);
            
            for (let i of pagesToShow) {
                const btn = document.createElement('button');
                btn.textContent = i;
                btn.onclick = () => goToPage(i);
                if (i === currentPage) btn.classList.add('active');
                pageNumbers.appendChild(btn);
            }
        }
    }

    let currentOpenDetailId = null;
    
    function toggleDetail(id) {
        const detailRow = document.getElementById('detail-' + id);
        const detailBtn = document.getElementById('detail-btn-' + id);
        const btnText = detailBtn ? detailBtn.querySelector('.btn-text') : null;
        
        if (!detailRow) return;
        
        // 如果點擊的是當前已打開的詳情，則關閉它
        if (currentOpenDetailId === id) {
            detailRow.style.display = 'none';
            currentOpenDetailId = null;
            if (btnText) {
                btnText.textContent = '查看詳情';
                detailBtn.querySelector('i').className = 'fas fa-eye';
            }
            return;
        }
        
        // 如果已經有其他詳情打開，先關閉它
        if (currentOpenDetailId !== null) {
            const previousDetailRow = document.getElementById('detail-' + currentOpenDetailId);
            const previousDetailBtn = document.getElementById('detail-btn-' + currentOpenDetailId);
            const previousBtnText = previousDetailBtn ? previousDetailBtn.querySelector('.btn-text') : null;
            
            if (previousDetailRow) {
                previousDetailRow.style.display = 'none';
            }
            if (previousBtnText) {
                previousBtnText.textContent = '查看詳情';
                if (previousDetailBtn.querySelector('i')) {
                    previousDetailBtn.querySelector('i').className = 'fas fa-eye';
                }
            }
        }
        
        // 打開新的詳情
        detailRow.style.display = 'table-row';
        currentOpenDetailId = id;
        if (btnText) {
            btnText.textContent = '關閉詳情';
            detailBtn.querySelector('i').className = 'fas fa-eye-slash';
        }
    }

    // 分配推薦學生相關變數
    let currentRecommendationId = null;

    // 開啟分配推薦學生彈出視窗
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
    const assignRecommendationModal = document.getElementById('assignRecommendationModal');
    if (assignRecommendationModal) {
        assignRecommendationModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeAssignRecommendationModal();
            }
        });
    }

    // 分配部門相關變數
    let currentRecommendationDepartmentId = null;

    // 開啟分配部門彈出視窗
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
    const assignRecommendationDepartmentModal = document.getElementById('assignRecommendationDepartmentModal');
    if (assignRecommendationDepartmentModal) {
        assignRecommendationDepartmentModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeAssignRecommendationDepartmentModal();
            }
        });
    }
    </script>
</body>
</html>

