<?php
session_start();

// 檢查是否已登入
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: login.php");
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

if (!function_exists('getDatabaseConnection')) {
    die('錯誤：資料庫連接函數未定義');
}

// 初始化變數
$enrollments = [];
$teachers = []; // 將用於儲存主任名單
$departments = []; // 將用於儲存可分配的部門列表（招生中心使用）
$identity_options = [];
$school_data = [];
$error_message = '';
$new_enrollments_count = 0; 
$user_id = $_SESSION['user_id'] ?? 0;

// 獲取使用者角色和用戶名
$username = isset($_SESSION['username']) ? $_SESSION['username'] : '';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';

$allowed_center_roles = ['ADM', 'STA'];
$is_admin_or_staff = in_array($user_role, $allowed_center_roles);
$user_department_code = null;
$is_department_user = false;

// 僅當用戶是老師 (TEA) 或主任 (DI) 時，查詢其所屬部門
if ($user_role === 'TEA' || $user_role === 'DI') { 
    try {
        $conn_temp = getDatabaseConnection();
        // 從 teacher/director 表格獲取部門代碼
        $stmt_dept = $conn_temp->prepare("SELECT department FROM teacher WHERE user_id = ?");
        $stmt_dept->bind_param("i", $user_id);
        $stmt_dept->execute();
        $result_dept = $stmt_dept->get_result();
        if ($row = $result_dept->fetch_assoc()) {
            $user_department_code = $row['department'];
            // 只要有部門代碼，就視為部門推廣人員
            if (!empty($user_department_code)) { 
                 $is_department_user = true; 
            }
        }
        $stmt_dept->close();
        $conn_temp->close(); 
    } catch (Exception $e) {
        error_log('Error fetching user department: ' . $e->getMessage());
    }
}

// 部門專屬判斷 (使用標準部門代碼 IM/AF)
$is_imd_user = ($user_department_code === 'IM'); 
$is_fld_user = ($user_department_code === 'AF'); 

// 判斷是否為招生中心/行政人員（負責分配部門）
$is_admission_center = $is_admin_or_staff && !$is_department_user;

// 設置頁面標題
if ($is_imd_user) {
    $page_title = '資訊管理科就讀意願名單';
} elseif ($is_fld_user) {
    $page_title = '應用外語科就讀意願名單';
} else {
    $page_title = '就讀意願名單';
}

// 基礎 SELECT 語句：新增三個志願的名稱、學制名稱和【科系代碼】
$base_select = "
    SELECT 
        ei.*, 
        u.name AS teacher_name, 
        u.username AS teacher_username,
        d1.name AS intention1_name, d1.code AS intention1_code,
        es1.name AS system1_name,
        d2.name AS intention2_name, d2.code AS intention2_code,
        es2.name AS system2_name,
        d3.name AS intention3_name, d3.code AS intention3_code,
        es3.name AS system3_name
";

// 基礎 JOIN 語句：分別為三個志願連線 enrollment_choices, departments, education_systems
$base_from_join = "
    FROM enrollment_intention ei
    LEFT JOIN user u ON ei.assigned_teacher_id = u.id
    LEFT JOIN teacher t ON u.id = t.user_id
    /* Join for Choice 1 */
    LEFT JOIN enrollment_choices ec1 ON ei.id = ec1.enrollment_id AND ec1.choice_order = 1
    LEFT JOIN departments d1 ON ec1.department_code = d1.code
    LEFT JOIN education_systems es1 ON ec1.system_code = es1.code
    /* Join for Choice 2 */
    LEFT JOIN enrollment_choices ec2 ON ei.id = ec2.enrollment_id AND ec2.choice_order = 2
    LEFT JOIN departments d2 ON ec2.department_code = d2.code
    LEFT JOIN education_systems es2 ON ec2.system_code = es2.code
    /* Join for Choice 3 */
    LEFT JOIN enrollment_choices ec3 ON ei.id = ec3.enrollment_id AND ec3.choice_order = 3
    LEFT JOIN departments d3 ON ec3.department_code = d3.code
    LEFT JOIN education_systems es3 ON ec3.system_code = es3.code
";

$order_by = " ORDER BY ei.created_at DESC";

try {
    // 建立資料庫連接
    $conn = getDatabaseConnection();

    // 載入身分別/年級與學校對應表，避免在列表中顯示原始代碼
    $identity_result = $conn->query("SELECT code, name FROM identity_options");
    if ($identity_result) {
        while ($row = $identity_result->fetch_assoc()) {
            $identity_options[$row['code']] = $row['name'];
        }
    }

    $school_result = $conn->query("SELECT school_code, name FROM school_data");
    if ($school_result) {
        while ($row = $school_result->fetch_assoc()) {
            $school_data[$row['school_code']] = $row['name'];
        }
    }

    // 輔助函數：轉換代碼為文字（避免直接顯示代碼）
    function getGenderText($code) {
        if ($code === 1 || $code === '1' || $code === '男') return '男';
        if ($code === 2 || $code === '2' || $code === '女') return '女';
        return '未提供';
    }

    function getIdentityText($code, $options) {
        if (isset($options[$code]) && $options[$code] !== '') {
            return htmlspecialchars($options[$code]);
        }
        if ($code === 1 || $code === '1') return '學生';
        if ($code === 2 || $code === '2') return '家長';
        return '未提供';
    }

    function getSchoolName($code, $schools) {
        if (isset($schools[$code]) && $schools[$code] !== '') {
            return htmlspecialchars($schools[$code]);
        }
        return '未提供';
    }

    // 根據用戶權限決定 WHERE 條件
    $where_clause = '';

    if ($is_imd_user) {
        // IM用戶看到所有選擇 IM 的記錄，或者被分配到 IM 部門的記錄
        $where_clause = "
            WHERE (ec1.department_code = 'IM' OR ec2.department_code = 'IM' OR ec3.department_code = 'IM') 
            OR ei.assigned_department = 'IM' 
        ";
    } elseif ($is_fld_user) {
        // AF用戶看到所有選擇 AF 的記錄，或者被分配到 AF 部門的記錄
        $where_clause = "
            WHERE (ec1.department_code = 'AF' OR ec2.department_code = 'AF' OR ec3.department_code = 'AF') 
            OR ei.assigned_department = 'AF'
        ";
    }
    
    // 組合最終查詢語句
    $sql = $base_select . $base_from_join . $where_clause . $order_by;
    
    $stmt = $conn->prepare($sql);
    
    if ($stmt) { 
        if (!$stmt->execute()) {
            throw new Exception('執行查詢失敗: ' . $stmt->error);
        }
        
        $result = $stmt->get_result();
        if ($result) {
            $enrollments = $result->fetch_all(MYSQLI_ASSOC);
        }
    } else {
         throw new Exception('準備查詢語句失敗: ' . $conn->error);
    }


    // 檢查是否有新的就讀意願（最近1小時內）
    $new_enrollments = [];
    $one_hour_ago_timestamp = strtotime('-1 hour');
    
    if (!empty($enrollments)) {
        foreach ($enrollments as $enrollment) {
            if (isset($enrollment['created_at']) && !empty($enrollment['created_at'])) {
                $created_timestamp = strtotime($enrollment['created_at']);
                if ($created_timestamp !== false && $created_timestamp >= $one_hour_ago_timestamp) {
                    $new_enrollments_count++;
                    $new_enrollments[] = $enrollment;
                }
            }
        }
    }

    // 獲取所有科系主任列表 (用於分配)
    if ($is_department_user) {
        // 優先使用 `director` 表來取得科系主任資料
        $table_check = $conn->query("SHOW TABLES LIKE 'director'");
        $director_join = $table_check && $table_check->num_rows > 0 ? "LEFT JOIN director dir ON u.id = dir.user_id" : "";
        $department_select = $table_check && $table_check->num_rows > 0 ? "dir.department" : "t.department";

        // 獲取所有主任，並連線到 departments 表獲取科系名稱
        $director_stmt = $conn->prepare(
            "SELECT u.id, u.username, u.name, {$department_select} AS department_code, dep.name AS department_name\n"
            . "FROM user u\n"
            . "LEFT JOIN teacher t ON u.id = t.user_id\n"
            . $director_join . "\n"
            . "LEFT JOIN departments dep ON {$department_select} = dep.code\n"
            . "WHERE u.role = 'DI' OR u.username = 'IMD' /* 確保 IMD 帳號（若存在於 user 表）也被抓到 */\n" 
            . "ORDER BY u.name ASC"
        );

        if ($director_stmt && $director_stmt->execute()) {
            $director_result = $director_stmt->get_result();
            if ($director_result) {
                $teachers = $director_result->fetch_all(MYSQLI_ASSOC); // $teachers 變數現在包含所有主任
            }
        }
    }

    // 如果使用者為招生中心，載入可分配的部門清單（不要硬編碼）
    if ($is_admission_center) {
        $dept_stmt = $conn->prepare("SELECT code, name FROM departments ORDER BY name ASC");
        if ($dept_stmt && $dept_stmt->execute()) {
            $dept_result = $dept_stmt->get_result();
            if ($dept_result) {
                $departments = $dept_result->fetch_all(MYSQLI_ASSOC);
            }
        }
    }

    $conn->close();
    
} catch (Exception $e) {
    $error_message = '資料庫操作失敗，請稍後再試: ' . $e->getMessage();
    error_log('enrollment_list.php 錯誤: ' . $e->getMessage());
    if (isset($conn)) {
        $conn->close();
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
        }

        .table-container {
            overflow-x: auto;
        }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 16px 24px; text-align: left; border-bottom: 1px solid var(--border-color); font-size: 16px; white-space: nowrap; }
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
        .assign-btn i {
            margin-right: 4px;
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
        
        /* 聯絡紀錄模態視窗樣式 */
        .contact-log-modal {
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
        .contact-log-modal .modal-content {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            width: 90%;
            max-width: 800px;
            max-height: 80vh;
            overflow-y: auto;
        }
        .contact-log-item {
            background: #f8f9fa;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 16px;
            margin-bottom: 12px;
        }
        .contact-log-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border-color);
        }
        .contact-log-date {
            font-weight: 600;
            color: var(--text-color);
            font-size: 16px;
        }
        .contact-log-method {
            background: var(--primary-color);
            color: white;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        .contact-log-teacher {
            color: var(--text-secondary-color);
            font-size: 14px;
            margin-top: 8px;
        }
        .contact-log-result {
            color: var(--text-color);
            line-height: 1.6;
            margin-top: 12px;
            white-space: pre-wrap;
        }
        .contact-log-notes {
            background: #fff3cd;
            border-left: 3px solid #ffc107;
            padding: 12px;
            margin-top: 12px;
            border-radius: 4px;
            color: #856404;
            font-size: 14px;
            white-space: pre-wrap;
        }
        .contact-log-empty {
            text-align: center;
            padding: 40px;
            color: var(--text-secondary-color);
        }
        .contact-log-loading {
            text-align: center;
            padding: 40px;
            color: var(--text-secondary-color);
        }
        
        /* 新就讀意願提醒樣式 */
        .new-enrollment-alert {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 16px 20px;
            border-radius: 8px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
            animation: slideDown 0.3s ease-out;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .new-enrollment-alert-content {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
        }
        
        .new-enrollment-alert-icon {
            font-size: 24px;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.1);
            }
        }
        
        .new-enrollment-alert-text {
            font-size: 16px;
            font-weight: 500;
        }
        
        .new-enrollment-alert-count {
            background: rgba(255, 255, 255, 0.3);
            padding: 4px 12px;
            border-radius: 12px;
            font-weight: 600;
            margin-left: 8px;
        }
        
        .new-enrollment-alert-close {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            transition: all 0.3s;
        }
        
        .new-enrollment-alert-close:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: rotate(90deg);
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
                    </div>
                    <div class="table-search">
                        <input type="text" id="searchInput" class="search-input" placeholder="搜尋姓名或電話...">
                    </div>
                </div>

                <?php if (!empty($error_message)): ?>
                <div style="background: #fff2f0; border: 1px solid #ffccc7; border-radius: 8px; padding: 16px; margin-bottom: 24px; color: #cf1322;">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
                </div>
                <?php endif; ?>

                <?php if ($new_enrollments_count > 0): ?>
                <div class="new-enrollment-alert" id="newEnrollmentAlert">
                    <div class="new-enrollment-alert-content">
                        <i class="fas fa-bell new-enrollment-alert-icon"></i>
                        <span class="new-enrollment-alert-text">
                            有 <span class="new-enrollment-alert-count"><?php echo $new_enrollments_count; ?></span> 筆新的就讀意願表單已提交！
                        </span>
                    </div>
                    <button class="new-enrollment-alert-close" onclick="closeNewEnrollmentAlert()" title="關閉提醒">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <?php endif; ?>

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
                                        <?php if ($is_imd_user): ?>
                                        <th onclick="sortTable(8)">意願(學制)</th>
                                        <?php else: ?>
                                        <th onclick="sortTable(8)">意願一 (學制)</th>
                                        <th onclick="sortTable(9)">意願二 (學制)</th>
                                        <th onclick="sortTable(10)">意願三 (學制)</th>
                                        <?php endif; ?>
                                        <th onclick="sortTable(11)">Line ID</th>
                                        <th onclick="sortTable(12)">Facebook</th>
                                        <th onclick="sortTable(13)">備註</th>
                                        <th onclick="sortTable(14, 'date')">填寫日期</th>
                                        <?php if ($is_admission_center): ?>
                                        <th onclick="sortTable(15)">分配部門</th>
                                        <th>操作</th>
                                        <?php elseif ($is_department_user): ?>
                                        <th onclick="sortTable(15)">分配狀態</th>
                                        <th>操作</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($enrollments as $item): 
                                    // 獲取意願和學制名稱
                                    $intention1_name = $item['intention1_name'] ?? '';
                                    $system1_name = $item['system1_name'] ?? '';
                                    $intention2_name = $item['intention2_name'] ?? '';
                                    $system2_name = $item['system2_name'] ?? '';
                                    $intention3_name = $item['intention3_name'] ?? '';
                                    $system3_name = $item['system3_name'] ?? '';
                                    
                                    // 獲取科系代碼，用於 JS 篩選主任
                                    $intention1_code = $item['intention1_code'] ?? '';
                                    $intention2_code = $item['intention2_code'] ?? '';
                                    $intention3_code = $item['intention3_code'] ?? '';

                                    // 格式化顯示文字的 Helper 函數
                                    $format_intention = function($name, $system) {
                                        if (empty($name)) return '無意願';
                                        $system_display = !empty($system) ? " ({$system})" : '';
                                        return htmlspecialchars($name . $system_display);
                                    };
                                    
                                    $display_text1 = $format_intention($intention1_name, $system1_name);
                                    $display_text2 = $format_intention($intention2_name, $system2_name);
                                    $display_text3 = $format_intention($intention3_name, $system3_name);

                                    // 確保其他欄位也使用安全操作符
                                    $phone2 = $item['phone2'] ?? '無';
                                    $line_id = $item['line_id'] ?? '無';
                                    $facebook = $item['facebook'] ?? '無';
                                    $remarks = $item['remarks'] ?? '無';

                                    // IM 用戶的單一志願顯示邏輯
                                    $imd_single_display = '無志願';
                                    if ($is_imd_user) {
                                        $imd_choices = [];
                                        // 由於 IM 用戶的查詢已經過濾，這裡只需要檢查意願名稱
                                        if ($intention1_code === 'IM') {
                                            $imd_choices[] = "第一志願: {$intention1_name} ({$system1_name})";
                                        }
                                        if ($intention2_code === 'IM') {
                                            $imd_choices[] = "第二志願: {$intention2_name} ({$system2_name})";
                                        }
                                        if ($intention3_code === 'IM') {
                                            $imd_choices[] = "第三志願: {$intention3_name} ({$system3_name})";
                                        }
                                        // 確保至少有一個 IM 志願被顯示，否則顯示無志願 (這與 SQL 過濾邏輯匹配)
                                        $imd_single_display = !empty($imd_choices) ? htmlspecialchars(implode(' | ', $imd_choices)) : '無志願';
                                    }

                                    // 準備要傳遞給 JS 的志願代碼列表
                                    $chosen_codes = array_filter([$intention1_code, $intention2_code, $intention3_code]);
                                    $chosen_codes_json = json_encode(array_unique($chosen_codes));
                                    
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['name']); ?></td>
                                        <td><?php echo getIdentityText($item['identity'] ?? '', $identity_options); ?></td>
                                        <td><?php echo getGenderText($item['gender'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($item['phone1']); ?></td>
                                        <td><?php echo htmlspecialchars($phone2); ?></td>
                                        <td><?php echo htmlspecialchars($item['email']); ?></td>
                                        <td><?php echo getSchoolName($item['junior_high'] ?? '', $school_data); ?></td>
                                        <td><?php echo getIdentityText($item['current_grade'] ?? '', $identity_options); ?></td>
                                        
                                        <?php if ($is_imd_user): ?>
                                        <td><?php echo $imd_single_display; ?></td>
                                        <?php else: ?>
                                        <td><?php echo $display_text1; ?></td>
                                        <td><?php echo $display_text2; ?></td>
                                        <td><?php echo $display_text3; ?></td>
                                        <?php endif; ?>
                                        
                                        <td><?php echo htmlspecialchars($line_id); ?></td>
                                        <td><?php echo htmlspecialchars($facebook); ?></td>
                                        <td><?php echo htmlspecialchars($remarks); ?></td>
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
                                            <button class="assign-btn" onclick="openAssignDepartmentModal(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['name']); ?>', '<?php echo htmlspecialchars($item['assigned_department'] ?? ''); ?>')">
                                                <i class="fas fa-building"></i> <?php echo !empty($item['assigned_department']) ? '重新分配' : '分配'; ?>
                                            </button>
                                        </td>
                                        <?php elseif ($is_department_user): ?>
                                        <td>
                                            <span style="display: none;" class="chosen-codes-data"><?php echo $chosen_codes_json; ?></span>
                                            <?php if (!empty($item['assigned_teacher_id'])): ?>
                                                <span style="color: #52c41a;">
                                                    <i class="fas fa-check-circle"></i> 已分配 - 
                                                    <?php echo htmlspecialchars($item['teacher_name'] ?? $item['teacher_username'] ?? '未知老師'); ?>
                                                </span >
                                            <?php else: ?>
                                                <span style="color: #8c8c8c;">
                                                    <i class="fas fa-clock"></i> 未分配
                                                </span >
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                                <button class="assign-btn" 
                                                        onclick="openAssignModal(
                                                            <?php echo $item['id']; ?>, 
                                                            '<?php echo htmlspecialchars($item['name']); ?>', 
                                                            <?php echo !empty($item['assigned_teacher_id']) ? $item['assigned_teacher_id'] : 'null'; ?>, 
                                                            '<?php echo $chosen_codes_json; ?>' /* 傳遞志願代碼 */
                                                        )">
                                                    <i class="fas fa-user-plus"></i> <?php echo !empty($item['assigned_teacher_id']) ? '重新分配主任' : '分配主任'; ?>
                                                </button>
                                                <?php if (!empty($item['assigned_teacher_id'])): ?>
                                                <button class="assign-btn" style="background: #17a2b8;" onclick="openContactLogsModal(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['name']); ?>')">
                                                    <i class="fas fa-address-book"></i> 查看聯絡紀錄
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <?php endif; ?>
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

    <?php if ($is_admission_center): ?>
    <div id="assignDepartmentModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>分配學生至部門</h3>
                <span class="close" onclick="closeAssignDepartmentModal()">&times;</span>
            </div>
            <div class="modal-body">
                <p>學生：<span id="departmentStudentName"></span></p>
                <div class="teacher-list">
                    <h4>選擇部門：</h4>
                    <div class="teacher-options">
                        <?php if (!empty($departments)): ?>
                            <?php foreach ($departments as $dep): ?>
                                <label class="teacher-option">
                                    <input type="radio" name="department" value="<?php echo htmlspecialchars($dep['code']); ?>">
                                    <div class="teacher-info">
                                        <strong><?php echo htmlspecialchars($dep['name'] . ' (' . $dep['code'] . ')'); ?></strong>
                                        <span class="teacher-dept"><?php echo htmlspecialchars($dep['name']); ?></span>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="empty-state" style="padding:10px;">目前沒有任何部門可供分配。</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-cancel" onclick="closeAssignDepartmentModal()">取消</button>
                <button class="btn-confirm" onclick="assignDepartment()">確認分配</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($is_department_user): ?>
    <div id="assignModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>分配學生 (主任)</h3>
                <span class="close" onclick="closeAssignModal()">&times;</span>
            </div>
            <div class="modal-body">
                <p>學生：<span id="studentName"></span></p>
                <div class="teacher-list">
                    <h4>選擇主任：</h4>
                    <div class="teacher-options" id="directorOptions">
                        <div class="contact-log-loading"><i class="fas fa-spinner fa-spin"></i> 準備主任名單中...</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-cancel" onclick="closeAssignModal()">取消</button>
                <button class="btn-confirm" onclick="assignStudent()">確認分配</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($is_department_user): ?>
    <div id="contactLogsModal" class="contact-log-modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>聯絡紀錄 - <span id="contactLogStudentName"></span></h3>
                <span class="close" onclick="closeContactLogsModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div id="contactLogsList" class="contact-log-loading">
                    <i class="fas fa-spinner fa-spin"></i> 載入中...
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-cancel" onclick="closeContactLogsModal()">關閉</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
    // 將 PHP 的主任名單轉換為 JS 陣列
    let allDirectors = [];
    <?php if ($is_department_user && !empty($teachers)): ?>
    allDirectors = <?php echo json_encode($teachers); ?>;
    <?php endif; ?>

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
                    const phoneCell = rows[i].getElementsByTagName('td')[3]; // 聯絡電話一的欄位索引
                    
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
            const dateColumnIndex = 14;
            sortStates = { [dateColumnIndex]: 'desc' };
            sortTable(dateColumnIndex, 'date'); // Sort once to set desc
            sortTable(dateColumnIndex, 'date'); // Sort again to trigger desc
        }

        if (rows.length > 0) {
            initialSort();
        }
    });

    // 分配學生相關變數
    let currentStudentId = null;

    /**
     * 開啟分配主任彈出視窗
     * @param {number} studentId 學生ID
     * @param {string} studentName 學生姓名
     * @param {number|null} currentTeacherId 當前分配的主任ID
     * @param {string} chosenCodesJson 學生志願的科系代碼 JSON 陣列
     */
    function openAssignModal(studentId, studentName, currentTeacherId, chosenCodesJson) {
        currentStudentId = studentId;
        document.getElementById('studentName').textContent = studentName;
        document.getElementById('assignModal').style.display = 'flex';
        
        // 步驟 1: 解析志願代碼
        let chosenCodes = [];
        try {
            chosenCodes = JSON.parse(chosenCodesJson);
        } catch (e) {
            console.error("解析 chosenCodesJson 失敗:", e);
        }
        
        // 步驟 2: 呼叫篩選函數
        filterAndDisplayDirectors(currentTeacherId, chosenCodes);
    }
    
    /**
     * 篩選並動態顯示主任名單
     * @param {number|null} currentTeacherId 當前分配的主任ID
     * @param {string[]} chosenCodes 學生選擇的科系代碼陣列 (e.g., ['IM', 'AF'])
     */
    function filterAndDisplayDirectors(currentTeacherId, chosenCodes) {
        const optionsContainer = document.getElementById('directorOptions');
        optionsContainer.innerHTML = ''; // 清空選項

        // 判斷是否有填寫志願 (chosenCodes 是一個包含有效科系代碼的陣列)
        const filterByChoice = chosenCodes.length > 0;
        let filteredDirectors = allDirectors;
        let html = '';

        if (filterByChoice) {
            // 情況 1: 志願有填寫 - 只顯示志願科系的主任
            filteredDirectors = allDirectors.filter(director => 
                // 確保主任有設定 department_code 且該代碼存在於學生的志願列表中
                director.department_code && chosenCodes.includes(director.department_code)
            );
        } else {
            // 情況 2: 志願是空的 - 顯示所有主任
            filteredDirectors = allDirectors;
        }

        if (filteredDirectors.length === 0) {
            if (filterByChoice) {
                 html = '<p class="empty-state" style="padding: 10px;">找不到符合學生志願科系的主任。</p>';
            } else {
                 html = '<p class="empty-state" style="padding: 10px;">目前沒有任何科系主任資料可供分配。</p>';
            }
        } else {
            // 渲染篩選過後的主任名單
            filteredDirectors.forEach(director => {
                const isChecked = (currentTeacherId && director.id == currentTeacherId);
                const departmentDisplay = director.department_name ? `${director.department_name} (${director.department_code})` : (director.department_code || '未設定');

                html += `
                    <label class="teacher-option">
                        <input type="radio" name="teacher" value="${director.id}" ${isChecked ? 'checked' : ''}>
                        <div class="teacher-info">
                            <strong>${director.name ?? director.username}</strong>
                            <span class="teacher-dept">${departmentDisplay}</span>
                        </div>
                    </label>
                `;
            });
        }
        
        optionsContainer.innerHTML = html;
    }


    // 關閉分配學生彈出視窗
    function closeAssignModal() {
        document.getElementById('assignModal').style.display = 'none';
        currentStudentId = null;
    }

    // 分配學生
    function assignStudent() {
        const selectedTeacher = document.querySelector('input[name="teacher"]:checked');
        
        if (!selectedTeacher) {
            alert('請選擇一位主任');
            return;
        }

        const teacherId = selectedTeacher.value;
        
        // 發送AJAX請求
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'assign_student.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4) {
                if (xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            alert('學生分配成功！');
                            closeAssignModal();
                            // 可以選擇重新載入頁面或更新UI
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
        
        xhr.send('student_id=' + encodeURIComponent(currentStudentId) + 
                 '&teacher_id=' + encodeURIComponent(teacherId));
    }

    // 點擊彈出視窗外部關閉
    <?php if ($is_department_user): ?>
    document.getElementById('assignModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeAssignModal();
        }
    });
    <?php endif; ?>

    // 分配部門相關變數
    let currentDepartmentStudentId = null;

    // 開啟分配部門彈出視窗
    function openAssignDepartmentModal(studentId, studentName, currentDepartment) {
        currentDepartmentStudentId = studentId;
        document.getElementById('departmentStudentName').textContent = studentName;
        document.getElementById('assignDepartmentModal').style.display = 'flex';
        
        // 清除之前的選擇，如果有已分配的部門則預選
        const radioButtons = document.querySelectorAll('input[name="department"]');
        radioButtons.forEach(radio => {
            if (currentDepartment && radio.value === currentDepartment) {
                radio.checked = true;
            } else {
                radio.checked = false;
            }
        });
    }

    // 關閉分配部門彈出視窗
    function closeAssignDepartmentModal() {
        document.getElementById('assignDepartmentModal').style.display = 'none';
        currentDepartmentStudentId = null;
    }

    // 分配部門
    function assignDepartment() {
        const selectedDepartment = document.querySelector('input[name="department"]:checked');
        
        if (!selectedDepartment) {
            alert('請選擇一個部門');
            return;
        }

        const department = selectedDepartment.value;
        
        // 發送AJAX請求
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'assign_department.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4) {
                if (xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            alert('學生分配成功！');
                            closeAssignDepartmentModal();
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
        
        xhr.send('student_id=' + encodeURIComponent(currentDepartmentStudentId) + 
                 '&department=' + encodeURIComponent(department));
    }

    // 點擊分配部門彈出視窗外部關閉
    <?php if ($is_admission_center): ?>
    document.getElementById('assignDepartmentModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeAssignDepartmentModal();
        }
    });
    <?php endif; ?>

    // 聯絡紀錄相關變數
    let currentContactLogStudentId = null;

    // 開啟聯絡紀錄模態視窗
    function openContactLogsModal(studentId, studentName) {
        currentContactLogStudentId = studentId;
        document.getElementById('contactLogStudentName').textContent = studentName;
        document.getElementById('contactLogsModal').style.display = 'flex';
        loadContactLogs(studentId);
    }

    // 關閉聯絡紀錄模態視窗
    function closeContactLogsModal() {
        document.getElementById('contactLogsModal').style.display = 'none';
        currentContactLogStudentId = null;
    }

    // 載入聯絡紀錄
    async function loadContactLogs(studentId) {
        const logsList = document.getElementById('contactLogsList');
        logsList.innerHTML = '<div class="contact-log-loading"><i class="fas fa-spinner fa-spin"></i> 載入中...</div>';

        try {
            const response = await fetch(`get_contact_logs.php?student_id=${studentId}`);
            const data = await response.json();

            if (data.success && data.logs) {
                if (data.logs.length === 0) {
                    logsList.innerHTML = `
                        <div class="contact-log-empty">
                            <i class="fas fa-inbox fa-3x" style="margin-bottom: 16px; color: #ccc;"></i>
                            <p>目前尚無聯絡紀錄</p>
                        </div>
                    `;
                } else {
                    logsList.innerHTML = data.logs.map(log => `
                        <div class="contact-log-item">
                            <div class="contact-log-header">
                                <div>
                                    <div class="contact-log-date">${formatDate(log.contact_date)}</div>
                                    <div class="contact-log-teacher">聯絡老師：${log.teacher_name || '未知'}</div>
                                </div>
                                <span class="contact-log-method">${log.method}</span>
                            </div>
                            <div class="contact-log-result">
                                <strong>聯絡結果：</strong><br>
                                ${escapeHtml(log.result)}
                            </div>
                            ${log.follow_up_notes ? `
                                <div class="contact-log-notes">
                                    <strong>後續追蹤備註：</strong><br>
                                    ${escapeHtml(log.follow_up_notes)}
                                </div>
                            ` : ''}
                        </div>
                    `).join('');
                }
            } else {
                logsList.innerHTML = `
                    <div class="contact-log-empty">
                        <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px; color: #ff9800;"></i>
                        <p>載入失敗：${data.message || '未知錯誤'}</p>
                    </div>
                `;
            }
        } catch (error) {
            console.error('載入聯絡紀錄錯誤:', error);
            logsList.innerHTML = `
                <div class="contact-log-empty">
                    <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px; color: #ff9800;"></i>
                    <p>載入失敗，請稍後再試</p>
                    <p style="font-size: 12px; color: #999; margin-top: 8px;">${error.message}</p>
                </div>
            `;
        }
    }

    // 格式化日期
    function formatDate(dateString) {
        if (!dateString) return '';
        const date = new Date(dateString);
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}年${month}月${day}日`;
    }

    // HTML 轉義函數
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // 點擊聯絡紀錄模態視窗外部關閉
    <?php if ($is_department_user): ?>
    document.getElementById('contactLogsModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeContactLogsModal();
        }
    });
    <?php endif; ?>

    // 關閉新就讀意願提醒
    function closeNewEnrollmentAlert() {
        const alert = document.getElementById('newEnrollmentAlert');
        if (alert) {
            alert.style.animation = 'slideDown 0.3s ease-out reverse';
            setTimeout(() => {
                alert.style.display = 'none';
            }, 300);
        }
    }
    </script>
</body>
</html>