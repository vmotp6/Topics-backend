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
$page_title = (isset($_SESSION['username']) && $_SESSION['username'] === 'IMD') ? '資管科續招報名管理' : '續招報名管理';
$current_page = 'continued_admission_list'; // 新增此行

// 獲取使用者角色和用戶名
$user_role = $_SESSION['role'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;
$username = $_SESSION['username'] ?? '';

// 檢查是否為IMD用戶（保留向後兼容）
$is_imd_user = ($username === 'IMD');

// 判斷是否為招生中心/行政人員
$allowed_center_roles = ['ADM', 'STA'];
$is_admin_or_staff = in_array($user_role, $allowed_center_roles);

// 判斷是否為主任
$is_director = ($user_role === 'DI');
$user_department_code = null;
$is_department_user = false;

// 如果是主任，獲取其科系代碼
if ($is_director && $user_id > 0) {
    try {
        $conn_temp = getDatabaseConnection();
        $table_check = $conn_temp->query("SHOW TABLES LIKE 'director'");
        if ($table_check && $table_check->num_rows > 0) {
            $stmt_dept = $conn_temp->prepare("SELECT department FROM director WHERE user_id = ?");
        } else {
            $stmt_dept = $conn_temp->prepare("SELECT department FROM teacher WHERE user_id = ?");
        }
        $stmt_dept->bind_param("i", $user_id);
        $stmt_dept->execute();
        $result_dept = $stmt_dept->get_result();
        if ($row = $result_dept->fetch_assoc()) {
            $user_department_code = $row['department'];
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

// 判斷是否為招生中心/行政人員（負責分配部門）
$is_admission_center = $is_admin_or_staff && !$is_department_user;

// 權限判斷：主任和科助不能管理名單（不能管理名額、不能修改狀態）
$can_manage_list = in_array($user_role, ['ADM', 'STA']); // 只有管理員和學校行政可以管理

// 主任可以審核分配給他的名單
$can_review = $can_manage_list || ($is_director && !empty($user_department_code));

// 建立資料庫連接
try {
    $conn = getDatabaseConnection();
} catch (Exception $e) {
    die("資料庫連接失敗: " . $e->getMessage());
}

// 排序參數
$sortBy = $_GET['sort_by'] ?? 'created_at';
$sortOrder = $_GET['sort_order'] ?? 'desc';

// 驗證排序參數，防止 SQL 注入
$allowed_columns = ['id', 'apply_no', 'name', 'school', 'status', 'created_at'];
if (!in_array($sortBy, $allowed_columns)) {
    $sortBy = 'created_at';
}
if (!in_array(strtolower($sortOrder), ['asc', 'desc'])) {
    $sortOrder = 'desc';
}

// 獲取續招報名資料（根據用戶權限過濾）
if ($is_director && !empty($user_department_code)) {
    // 主任只能看到已分配給他的科系的名單（assigned_department = 他的科系代碼）
    $stmt = $conn->prepare("SELECT ca.id, ca.apply_no, ca.name, ca.school, ca.status, ca.created_at, ca.assigned_department, sd.name as school_name 
                          FROM continued_admission ca
                          LEFT JOIN school_data sd ON ca.school = sd.school_code
                          WHERE ca.assigned_department = ?
                          ORDER BY ca.$sortBy $sortOrder");
    $stmt->bind_param("s", $user_department_code);
} elseif ($is_imd_user) {
    // IMD用戶只能看到志願選擇包含"資訊管理科"的續招報名（保留向後兼容）
    $stmt = $conn->prepare("SELECT DISTINCT ca.id, ca.apply_no, ca.name, ca.school, ca.status, ca.created_at, ca.assigned_department, sd.name as school_name
                          FROM continued_admission ca
                          LEFT JOIN school_data sd ON ca.school = sd.school_code
                          INNER JOIN continued_admission_choices cac ON ca.id = cac.application_id
                          INNER JOIN departments d ON cac.department_code = d.code
                          WHERE d.code = 'IM' OR d.name LIKE '%資訊管理%' OR d.name LIKE '%資管%'
                          ORDER BY ca.$sortBy $sortOrder");
} else {
    // 招生中心/管理員可以看到所有續招報名
    $stmt = $conn->prepare("SELECT ca.id, ca.apply_no, ca.name, ca.school, ca.status, ca.created_at, ca.assigned_department, sd.name as school_name 
                          FROM continued_admission ca
                          LEFT JOIN school_data sd ON ca.school = sd.school_code
                          ORDER BY ca.$sortBy $sortOrder");
}
$stmt->execute();
$applications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// 載入科系對應表，用於將科系代碼轉換為科系名稱
$department_data = [];
$dept_result = $conn->query("SELECT code, name FROM departments");
if ($dept_result) {
    while ($row = $dept_result->fetch_assoc()) {
        $department_data[$row['code']] = $row['name'];
    }
}

// 輔助函數：獲取科系名稱
function getDepartmentName($code, $departments) {
    if (isset($departments[$code]) && $departments[$code] !== '') {
        return htmlspecialchars($departments[$code]);
    }
    return $code; // 如果找不到名稱，返回代碼
}

// 為每個報名獲取志願選擇（包含代碼和名稱）
foreach ($applications as &$app) {
    $choices_stmt = $conn->prepare("
        SELECT cac.choice_order, d.name as department_name, cac.department_code
        FROM continued_admission_choices cac
        LEFT JOIN departments d ON cac.department_code = d.code
        WHERE cac.application_id = ?
        ORDER BY cac.choice_order ASC
    ");
    $choices_stmt->bind_param('i', $app['id']);
    $choices_stmt->execute();
    $choices_result = $choices_stmt->get_result();
    $choices = [];
    $choices_with_codes = [];
    while ($choice_row = $choices_result->fetch_assoc()) {
        $choices[] = $choice_row['department_name'] ?? $choice_row['department_code'];
        $choices_with_codes[] = [
            'order' => $choice_row['choice_order'],
            'code' => $choice_row['department_code'],
            'name' => $choice_row['department_name'] ?? $choice_row['department_code']
        ];
    }
    $app['choices'] = json_encode($choices, JSON_UNESCAPED_UNICODE);
    $app['choices_with_codes'] = $choices_with_codes; // 保存帶代碼的志願數據
    $choices_stmt->close();
}
unset($app);

// 獲取科系名額資料
$department_stats = [];

try {
    // 直接從 department_quotas 和 departments 表讀取續招名額資料
    $sql = "
        SELECT 
            d.code as department_code,
            d.name as department_name,
            COALESCE(dq.total_quota, 0) as total_quota
        FROM departments d
        LEFT JOIN department_quotas dq ON d.code = dq.department_code AND dq.is_active = 1
        ORDER BY d.code
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $departments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // 先一次性獲取所有已錄取的學生志願
    $stmt_approved = $conn->prepare("
        SELECT ca.id, cac.choice_order, cac.department_code, d.name as department_name
        FROM continued_admission ca
        INNER JOIN continued_admission_choices cac ON ca.id = cac.application_id
        LEFT JOIN departments d ON cac.department_code = d.code
        WHERE ca.status = 'approved'
        ORDER BY ca.id, cac.choice_order
    ");
    $stmt_approved->execute();
    $approved_result = $stmt_approved->get_result();
    
    // 組織已錄取學生的志願數據（按科系代碼統計）
    $approved_by_department = [];
    while ($row = $approved_result->fetch_assoc()) {
        $app_id = $row['id'];
        $dept_code = $row['department_code'];
        // 只統計第一志願
        if ($row['choice_order'] == 1) {
            if (!isset($approved_by_department[$dept_code])) {
                $approved_by_department[$dept_code] = 0;
            }
            $approved_by_department[$dept_code]++;
        }
    }

    // 計算各科系已錄取人數
    foreach ($departments as $dept) {
        $dept_code = $dept['department_code'];
        $enrolled_count = isset($approved_by_department[$dept_code]) ? $approved_by_department[$dept_code] : 0;
        
        $department_stats[$dept_code] = [
            'name' => $dept['department_name'],
            'code' => $dept_code,
            'total_quota' => (int)$dept['total_quota'],
            'current_enrolled' => $enrolled_count,
            'remaining' => max(0, (int)$dept['total_quota'] - $enrolled_count)
        ];
    }
} catch (Exception $e) {
    // 如果資料表不存在或其他錯誤，設定為空陣列
    $department_stats = [];
    error_log("獲取科系名額資料失敗: " . $e->getMessage());
}

function getStatusText($status) {
    // 支援資料庫狀態代碼和前端狀態值
    switch ($status) {
        case 'AP':
        case 'approved': return '錄取';
        case 'RE':
        case 'rejected': return '未錄取';
        case 'AD':
        case 'waitlist': return '備取';
        case 'PE':
        case 'pending': return '待審核';
        default: return '待審核';
    }
}

function getStatusClass($status) {
    // 支援資料庫狀態代碼和前端狀態值
    switch ($status) {
        case 'AP':
        case 'approved': return 'status-approved';
        case 'RE':
        case 'rejected': return 'status-rejected';
        case 'AD':
        case 'waitlist': return 'status-waitlist';
        case 'PE':
        case 'pending': return 'status-pending';
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
        .card-body.table-container { padding: 0; }

        .table-container { overflow-x: auto; }
        .table { width: 100%; border-collapse: collapse; }
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
        .table td {
            color: #595959;
        }
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
        
        .btn {
            padding: 8px 16px;
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
            background: #fff;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        
        .btn-primary {
            background: #1890ff;
            color: white;
            border-color: #1890ff;
        }
        
        .btn-primary:hover {
            background: #40a9ff;
            border-color: #40a9ff;
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
        .choice-item { padding: 4px 8px; border-radius: 4px; font-size: 12px; background: #f5f5f5; color: #8c8c8c; }
        .choice-item.approved { background: var(--primary-color); color: white; font-weight: 500; }
        .no-choices { color: var(--text-secondary-color); font-style: italic; }

        /* TAB 樣式 */
        .tabs-container { margin-bottom: 24px; }
        .tabs-nav { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--border-color); background: var(--card-background-color); border-radius: 8px 8px 0 0; padding: 0 24px; min-height: 56px; }
        .tabs-nav-left { display: flex; }
        .tabs-nav-right { display: flex; align-items: center; margin-left: auto; margin-right: 10px; }
        .tab-item { padding: 16px 24px; cursor: pointer; font-size: 16px; font-weight: 500; color: var(--text-secondary-color); border-bottom: 2px solid transparent; margin-bottom: -2px; transition: all 0.3s; }
        .tab-item:hover { color: var(--primary-color); }
        .tab-item.active { color: var(--primary-color); border-bottom-color: var(--primary-color); }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

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
            border-color: var(--primary-color);
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
            border-color: var(--primary-color);
            color: var(--primary-color);
        }

        .pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .pagination button.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
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

                <!-- TAB 切換容器 -->
                <div class="card">
                    <div class="tabs-container">
                        <div class="tabs-nav">
                            <?php 
                            // 從 URL 參數獲取當前 TAB，如果沒有則使用預設值
                            $current_tab = $_GET['tab'] ?? ($can_manage_list ? 'quota' : 'list');
                            $quota_active = ($current_tab === 'quota') ? 'active' : '';
                            $list_active = ($current_tab === 'list') ? 'active' : '';
                            ?>
                            <div class="tabs-nav-left">
                                <?php if ($can_manage_list): // 只有可以管理的角色才顯示名額管理 TAB ?>
                                <div class="tab-item <?php echo $quota_active; ?>" onclick="switchTab('quota')">
                                    科系名額管理
                                </div>
                                <?php endif; ?>
                                <div class="tab-item <?php echo $list_active; ?>" onclick="switchTab('list')">
                                   續招報名名單
                                </div>
                            </div>
                            <div class="tabs-nav-right" id="tabActionButtons">
                                <?php if ($can_manage_list): ?>
                                    <?php if (!empty($department_stats)): ?>
                                        <a href="department_quota_management.php" class="btn btn-primary quota-action-btn" style="padding: 8px 12px; font-size: 14px; display: none;">
                                            <i class="fas fa-cog" style="margin-right: 6px;"></i> 管理名額
                                        </a>
                                    <?php else: ?>
                                        <a href="setup_department_quotas.php" class="btn btn-primary quota-action-btn" style="padding: 8px 12px; font-size: 14px; display: none;">
                                            <i class="fas fa-database" style="margin-right: 6px;"></i> 設定名額
                                        </a>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <input type="text" id="searchInput" class="search-input list-action-btn" placeholder="搜尋姓名、身分證或電話..." style="display: none;">
                            </div>
                        </div>
                    </div>

                    <!-- 科系名額管理 TAB 內容 -->
                    <?php if ($can_manage_list): ?>
                    <div id="tab-quota" class="tab-content <?php echo $quota_active; ?>">
                        <div class="card-body" id="quotaManagementContent">
                            <?php if (!empty($department_stats)): ?>
                                <div class="quota-grid">
                                    <?php foreach ($department_stats as $name => $stats): ?>
                                    <div class="quota-card">
                                        <div class="quota-header">
                                            <h4><?php echo htmlspecialchars($stats['name']); ?></h4>
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
                    <?php endif; ?>

                    <!-- 續招報名名單 TAB 內容 -->
                    <div id="tab-list" class="tab-content <?php echo $list_active; ?>">
                        <div class="card-body table-container">
                            <?php if (empty($applications)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-inbox fa-3x" style="margin-bottom: 16px;"></i>
                                    <p>目前尚無任何續招報名資料。</p>
                                </div>
                            <?php else: ?>
                                <table class="table" id="applicationTable"<?php if ($is_director && !empty($user_department_code)): ?> data-director-view="true"<?php endif; ?>>
                                    <thead>
                                        <tr>
                                            <th onclick="sortTable('apply_no')">報名編號 <span class="sort-icon" id="sort-apply_no"></span></th>
                                            <th onclick="sortTable('name')">姓名 <span class="sort-icon" id="sort-name"></span></th>
                                            <th onclick="sortTable('school')">就讀國中 <span class="sort-icon" id="sort-school"></span></th>
                                            <th>志願</th>
                                            <?php if ($can_manage_list): ?>
                                            <th>分配部門</th>
                                            <?php endif; ?>
                                            <th onclick="sortTable('status')">審核狀態 <span class="sort-icon" id="sort-status"></span></th>
                                            <th>操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($applications as $item): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($item['apply_no'] ?? $item['id']); ?></td>
                                            <td><?php echo htmlspecialchars($item['name']); ?></td>
                                            <td><?php echo htmlspecialchars($item['school_name'] ?? $item['school'] ?? ''); ?></td>
                                            <td>
                                                <?php 
                                                $is_approved = ($item['status'] === 'approved');
                                                $choices_with_codes = $item['choices_with_codes'] ?? [];
                                                
                                                if (!empty($choices_with_codes)): 
                                                    // 主任：只顯示自己科系的志願（第幾志願）
                                                    if ($is_director && !empty($user_department_code)):
                                                        $found_choice = null;
                                                        foreach ($choices_with_codes as $choice_data):
                                                            if ($choice_data['code'] === $user_department_code):
                                                                $found_choice = $choice_data;
                                                                break;
                                                            endif;
                                                        endforeach;
                                                        
                                                        if ($found_choice):
                                                ?>
                                                            <span class="choice-item <?php echo $is_approved ? 'approved' : ''; ?>">
                                                                意願<?php echo $found_choice['order']; ?>：<?php echo htmlspecialchars($found_choice['name']); ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="no-choices">無相關志願</span>
                                                        <?php endif; ?>
                                                    <?php elseif ($is_imd_user): ?>
                                                        <!-- IMD用戶：只顯示資訊管理科相關的志願（保留向後兼容） -->
                                                        <?php
                                                        $filtered_choices = [];
                                                        foreach ($choices_with_codes as $choice_data):
                                                            if ($choice_data['code'] === 'IM' || strpos($choice_data['name'], '資訊管理') !== false || strpos($choice_data['name'], '資管') !== false):
                                                                $filtered_choices[] = $choice_data;
                                                            endif;
                                                        endforeach;
                                                        
                                                        if (!empty($filtered_choices)):
                                                        ?>
                                                            <div class="choices-display">
                                                                <?php foreach ($filtered_choices as $choice_data): ?>
                                                                    <span class="choice-item <?php echo $is_approved ? 'approved' : ''; ?>">
                                                                        <?php echo $choice_data['order'] . '. ' . htmlspecialchars($choice_data['name']); ?>
                                                                    </span>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        <?php else: ?>
                                                            <span class="no-choices">無相關志願</span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <!-- 招生中心/管理員：顯示所有志願 -->
                                                        <div class="choices-display">
                                                            <?php foreach ($choices_with_codes as $choice_data): ?>
                                                                <span class="choice-item <?php echo $is_approved ? 'approved' : ''; ?>">
                                                                    <?php echo $choice_data['order'] . '. ' . htmlspecialchars($choice_data['name']); ?>
                                                                </span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="no-choices">未選擇</span>
                                                <?php endif; ?>
                                            </td>
                                            <?php if ($can_manage_list): ?>
                                            <td>
                                                <?php 
                                                $assigned_dept = $item['assigned_department'] ?? '';
                                                if (!empty($assigned_dept)):
                                                    echo getDepartmentName($assigned_dept, $department_data);
                                                else:
                                                    echo '<span style="color: #8c8c8c;">未分配</span>';
                                                endif;
                                                ?>
                                            </td>
                                            <?php endif; ?>
                                            <td>
                                                <span class="status-badge <?php echo getStatusClass($item['status']); ?>">
                                                    <?php echo getStatusText($item['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php 
                                                // 檢查是否為待審核狀態（支持 'PE' 和 'pending'，以及資料庫狀態代碼）
                                                $is_pending = ($item['status'] === 'pending' || $item['status'] === 'PE' || !in_array($item['status'], ['approved', 'rejected', 'waitlist', 'AP', 'RE', 'AD']));
                                                
                                                // 主任只能審核分配給他的名單
                                                $can_review_this = false;
                                                if ($is_pending) {
                                                    if ($can_manage_list) {
                                                        // 招生中心可以審核所有待審核的名單
                                                        $can_review_this = true;
                                                    } elseif ($is_director && !empty($user_department_code)) {
                                                        // 主任只能審核分配給他的科系的名單
                                                        $assigned_dept = $item['assigned_department'] ?? '';
                                                        $can_review_this = ($assigned_dept === $user_department_code);
                                                    }
                                                }
                                                ?>
                                                <?php if ($is_director && !empty($user_department_code)): ?>
                                                    <!-- 主任：待審核時顯示審核按鈕，已審核後顯示查看詳情按鈕 -->
                                                    <?php if ($can_review_this): ?>
                                                        <a href="continued_admission_detail.php?id=<?php echo $item['id']; ?>&action=review" class="btn-review">審核</a>
                                                    <?php else: ?>
                                                        <!-- 已審核完成，顯示查看詳情 -->
                                                        <?php 
                                                        $assigned_dept = $item['assigned_department'] ?? '';
                                                        if ($assigned_dept === $user_department_code): ?>
                                                            <a href="continued_admission_detail.php?id=<?php echo $item['id']; ?>" class="btn-view">查看詳情</a>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <!-- 招生中心/管理員：顯示查看詳情和審核按鈕 -->
                                                    <a href="continued_admission_detail.php?id=<?php echo $item['id']; ?>" class="btn-view">查看詳情</a>
                                                    <?php if ($can_review_this): ?>
                                                        <a href="continued_admission_detail.php?id=<?php echo $item['id']; ?>&action=review" class="btn-review">審核</a>
                                                    <?php endif; ?>
                                                    <?php if ($can_manage_list && $is_pending): ?>
                                                        <button onclick="showAssignModal(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['name'], ENT_QUOTES); ?>', <?php echo htmlspecialchars(json_encode($item['choices_with_codes'] ?? []), ENT_QUOTES); ?>)" class="btn-view" style="margin-left: 8px;">分配部門</button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php endif; ?>
                        </div>
                        <!-- 分頁控制 -->
                        <?php if (!empty($applications)): ?>
                        <div class="pagination" id="paginationContainer">
                            <div class="pagination-info">
                                <span>每頁顯示：</span>
                                <select id="itemsPerPage" onchange="changeItemsPerPage()">
                                    <option value="10" selected>10</option>
                                    <option value="20">20</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                    <option value="all">全部</option>
                                </select>
                                <span id="pageInfo">顯示第 <span id="currentRange">1-10</span> 筆，共 <span id="totalItemsCount"><?php echo count($applications); ?></span> 筆</span>
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
    </div>

    <!-- 訊息提示框 -->
    <div id="toast" style="position: fixed; top: 20px; right: 20px; background-color: #333; color: white; padding: 15px 20px; border-radius: 8px; z-index: 9999; display: none; opacity: 0; transition: opacity 0.5s;"></div>

    <!-- 分配部門模態框 -->
    <?php if ($can_manage_list): ?>
    <div id="assignModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>分配學生至部門</h3>
                <span class="close" onclick="closeAssignModal()">&times;</span>
            </div>
            <div class="modal-body">
                <p>學生：<span id="assignStudentName"></span></p>
                <div class="teacher-list">
                    <h4>選擇部門：</h4>
                    <div class="teacher-options" id="departmentOptions">
                        <div style="text-align: center; padding: 10px; color: var(--text-secondary-color);">
                            <i class="fas fa-spinner fa-spin"></i> 準備部門名單中...
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-cancel" onclick="closeAssignModal()">取消</button>
                <button class="btn-confirm" onclick="confirmAssign()">確認分配</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
    // 排序表格
    function sortTable(field) {
        let newSortOrder = 'asc';
        
        // 如果點擊的是當前排序欄位，則切換排序方向
        const urlParams = new URLSearchParams(window.location.search);
        const currentSortBy = urlParams.get('sort_by') || 'created_at';
        const currentSortOrder = urlParams.get('sort_order') || 'desc';
        const currentTab = urlParams.get('tab') || 'list'; // 保留當前 TAB
        
        if (currentSortBy === field) {
            newSortOrder = currentSortOrder === 'asc' ? 'desc' : 'asc';
        }
        
        window.location.href = `continued_admission_list.php?sort_by=${field}&sort_order=${newSortOrder}&tab=${currentTab}`;
    }
    
    // 更新排序圖標
    function updateSortIcons() {
        // 清除所有圖標
        const icons = document.querySelectorAll('.sort-icon');
        icons.forEach(icon => {
            icon.className = 'sort-icon';
        });
        
        // 獲取當前 URL 的排序參數
        const urlParams = new URLSearchParams(window.location.search);
        const currentSortBy = urlParams.get('sort_by') || 'created_at';
        const currentSortOrder = urlParams.get('sort_order') || 'desc';
        
        // 設置當前排序欄位的圖標
        const currentIcon = document.getElementById(`sort-${currentSortBy}`);
        if (currentIcon) {
            currentIcon.className = `sort-icon active ${currentSortOrder}`;
        }
    }
    
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

    // TAB 切換功能
    function switchTab(tabName) {
        // 更新 URL 參數，保留排序參數
        const urlParams = new URLSearchParams(window.location.search);
        urlParams.set('tab', tabName);
        
        // 保留排序參數
        const sortBy = urlParams.get('sort_by') || 'created_at';
        const sortOrder = urlParams.get('sort_order') || 'desc';
        
        // 跳轉到新的 URL，保留排序參數和 TAB 參數
        window.location.href = `continued_admission_list.php?tab=${tabName}&sort_by=${sortBy}&sort_order=${sortOrder}`;
    }

    document.addEventListener('DOMContentLoaded', function() {
        // 初始化時顯示/隱藏按鈕和搜尋框
        const activeTab = document.querySelector('.tab-content.active');
        const searchInput = document.getElementById('searchInput');
        
        if (activeTab && activeTab.id === 'tab-quota') {
            const quotaActionButtons = document.querySelectorAll('.quota-action-btn');
            quotaActionButtons.forEach(btn => {
                btn.style.display = 'inline-flex';
            });
            if (searchInput) {
                searchInput.style.display = 'none';
            }
        } else {
            if (searchInput) {
                searchInput.style.display = 'block';
            }
        }
        
        // 更新排序圖標
        updateSortIcons();
        const searchInputEl = document.getElementById('searchInput');
        const table = document.getElementById('applicationTable');
        const rows = table ? table.getElementsByTagName('tbody')[0].getElementsByTagName('tr') : [];

        if (searchInputEl) {
            searchInputEl.addEventListener('keyup', function() {
                filterTable();
            });
        }
        
        // 初始化分頁
        initPagination();
    });

    // 分頁相關變數
    let currentPage = 1;
    let itemsPerPage = 10;
    let allRows = [];
    let filteredRows = [];

    // 初始化分頁
    function initPagination() {
        const table = document.getElementById('applicationTable');
        if (!table) return;
        
        const tbody = table.getElementsByTagName('tbody')[0];
        if (!tbody) return;
        
        allRows = Array.from(tbody.getElementsByTagName('tr'));
        filteredRows = allRows;
        
        // 更新總數
        updateTotalCount();
        
        // 初始化分頁
        updatePagination();
    }

    function changeItemsPerPage() {
        const select = document.getElementById('itemsPerPage');
        itemsPerPage = select.value === 'all' ? 
                      filteredRows.length : 
                      parseInt(select.value);
        currentPage = 1;
        updatePagination();
    }

    function changePage(direction) {
        const totalPages = Math.ceil(filteredRows.length / itemsPerPage);
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
        const totalPages = itemsPerPage === 'all' || itemsPerPage >= totalItems ? 1 : Math.ceil(totalItems / itemsPerPage);
        
        // 隱藏所有行
        allRows.forEach(row => row.style.display = 'none');
        
        if (itemsPerPage === 'all' || itemsPerPage >= totalItems) {
            // 顯示所有過濾後的行
            filteredRows.forEach(row => row.style.display = '');
            
            // 更新分頁資訊
            document.getElementById('currentRange').textContent = 
                totalItems > 0 ? `1-${totalItems}` : '0-0';
        } else {
            // 計算當前頁的範圍
            const start = (currentPage - 1) * itemsPerPage;
            const end = Math.min(start + itemsPerPage, totalItems);
            
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
        updateTotalCount();
        
        // 更新上一頁/下一頁按鈕
        const prevBtn = document.getElementById('prevPage');
        const nextBtn = document.getElementById('nextPage');
        if (prevBtn) prevBtn.disabled = currentPage === 1;
        if (nextBtn) nextBtn.disabled = currentPage >= totalPages;
        
        // 更新頁碼按鈕
        updatePageNumbers(totalPages);
    }

    function updateTotalCount() {
        const totalCountEl = document.getElementById('totalItemsCount');
        if (totalCountEl) {
            totalCountEl.textContent = filteredRows.length;
        }
    }

    function updatePageNumbers(totalPages) {
        const pageNumbers = document.getElementById('pageNumbers');
        if (!pageNumbers) return;
        
        pageNumbers.innerHTML = '';
        
        if (totalPages <= 1) return;
        
        // 顯示最多 5 個頁碼
        let startPage = Math.max(1, currentPage - 2);
        let endPage = Math.min(totalPages, currentPage + 2);
        
        // 如果接近開頭，顯示前 5 頁
        if (currentPage <= 3) {
            startPage = 1;
            endPage = Math.min(5, totalPages);
        }
        
        // 如果接近結尾，顯示後 5 頁
        if (currentPage >= totalPages - 2) {
            startPage = Math.max(1, totalPages - 4);
            endPage = totalPages;
        }
        
        // 第一頁
        if (startPage > 1) {
            const btn = document.createElement('button');
            btn.textContent = '1';
            btn.onclick = () => goToPage(1);
            if (currentPage === 1) btn.classList.add('active');
            pageNumbers.appendChild(btn);
            
            if (startPage > 2) {
                const ellipsis = document.createElement('span');
                ellipsis.textContent = '...';
                ellipsis.style.padding = '0 8px';
                pageNumbers.appendChild(ellipsis);
            }
        }
        
        // 中間頁碼
        for (let i = startPage; i <= endPage; i++) {
            const btn = document.createElement('button');
            btn.textContent = i;
            btn.onclick = () => goToPage(i);
            if (i === currentPage) btn.classList.add('active');
            pageNumbers.appendChild(btn);
        }
        
        // 最後一頁
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                const ellipsis = document.createElement('span');
                ellipsis.textContent = '...';
                ellipsis.style.padding = '0 8px';
                pageNumbers.appendChild(ellipsis);
            }
            
            const btn = document.createElement('button');
            btn.textContent = totalPages;
            btn.onclick = () => goToPage(totalPages);
            if (currentPage === totalPages) btn.classList.add('active');
            pageNumbers.appendChild(btn);
        }
    }

    // 表格搜尋功能
    function filterTable() {
        const searchInput = document.getElementById('searchInput');
        if (!searchInput) return;
        
        const filter = searchInput.value.toLowerCase();
        const table = document.getElementById('applicationTable');
        
        if (!table) return;
        
        const tbody = table.getElementsByTagName('tbody')[0];
        if (!tbody) return;
        
        allRows = Array.from(tbody.getElementsByTagName('tr'));
        
        filteredRows = allRows.filter(row => {
            const cells = row.getElementsByTagName('td');
            for (let j = 0; j < cells.length; j++) {
                const cell = cells[j];
                if (cell) {
                    const txtValue = cell.textContent || cell.innerText;
                    if (txtValue.toLowerCase().indexOf(filter) > -1) {
                        return true;
                    }
                }
            }
            return false;
        });
        
        currentPage = 1;
        updatePagination();
    }

    <?php if ($can_manage_list): ?>
    // 分配部門相關變數
    let currentAssignApplicationId = null;
    let currentAssignChoices = [];

    // 顯示分配模態框
    function showAssignModal(applicationId, studentName, choices) {
        currentAssignApplicationId = applicationId;
        currentAssignChoices = choices || [];
        
        document.getElementById('assignStudentName').textContent = studentName;
        const optionsContainer = document.getElementById('departmentOptions');
        optionsContainer.innerHTML = '';
        
        // 按照志願順序排序（choice_order）
        const sortedChoices = [...currentAssignChoices].sort((a, b) => (a.order || 0) - (b.order || 0));
        const departmentNames = <?php echo json_encode($department_data, JSON_UNESCAPED_UNICODE); ?>;
        
        if (sortedChoices.length === 0) {
            optionsContainer.innerHTML = '<p style="padding: 10px; color: var(--text-secondary-color);">此學生尚未填寫志願，無法進行分配。</p>';
        } else {
            // 按照志願順序顯示科系選項
            sortedChoices.forEach(choice => {
                const departmentCode = choice.code;
                const departmentName = departmentNames[departmentCode] || departmentCode;
                const choiceOrder = choice.order || '';
                
                const label = document.createElement('label');
                label.className = 'teacher-option';
                label.innerHTML = `
                    <input type="radio" name="department" value="${departmentCode}">
                    <div class="teacher-info">
                        <strong>${departmentName}</strong>
                        ${choiceOrder ? `<span class="teacher-dept">意願${choiceOrder}</span>` : ''}
                    </div>
                `;
                optionsContainer.appendChild(label);
            });
        }
        
        document.getElementById('assignModal').style.display = 'flex';
    }

    // 關閉分配模態框
    function closeAssignModal() {
        document.getElementById('assignModal').style.display = 'none';
        currentAssignApplicationId = null;
        currentAssignChoices = [];
    }

    // 確認分配
    function confirmAssign() {
        const selectedDepartment = document.querySelector('input[name="department"]:checked');
        
        if (!selectedDepartment) {
            showToast('請選擇一個部門', false);
            return;
        }
        
        const departmentCode = selectedDepartment.value;
        
        if (!currentAssignApplicationId) {
            showToast('系統錯誤：找不到報名記錄', false);
            return;
        }
        
        // 發送分配請求
        const formData = new FormData();
        formData.append('application_id', currentAssignApplicationId);
        formData.append('department', departmentCode);
        
        fetch('assign_continued_admission_department.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast(data.message || '分配成功', true);
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                showToast(data.message || '分配失敗', false);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('系統錯誤，請稍後再試', false);
        });
    }
    
    // 點擊分配視窗外部關閉
    <?php if ($can_manage_list): ?>
    document.getElementById('assignModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeAssignModal();
        }
    });
    <?php endif; ?>
    <?php endif; ?>

    </script>
</body>
</html>
