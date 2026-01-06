<?php
// [修改] 檔案：Topics-backend/frontend/enrollment_list.php
require_once __DIR__ . '/session_config.php';

checkBackendLogin();

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
$teachers = [];
$departments = [];
$identity_options = [];
$school_data = [];
$error_message = '';
$new_enrollments_count = 0; 
$user_id = $_SESSION['user_id'] ?? 0;
$history_years = []; 

// 獲取使用者角色和用戶名
$username = isset($_SESSION['username']) ? $_SESSION['username'] : '';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';

$allowed_center_roles = ['ADM', 'STA'];
$is_admin_or_staff = in_array($user_role, $allowed_center_roles);
$user_department_code = null;
$is_department_user = false;

// 僅當用戶是老師 (TEA) 或主任 (DI) 時，查詢其所屬部門
$is_director = ($user_role === 'DI');
if ($user_role === 'TEA' || $user_role === 'DI') { 
    try {
        $conn_temp = getDatabaseConnection();
        if ($is_director) {
            $table_check = $conn_temp->query("SHOW TABLES LIKE 'director'");
            if ($table_check && $table_check->num_rows > 0) {
                $stmt_dept = $conn_temp->prepare("SELECT department FROM director WHERE user_id = ?");
            } else {
                $stmt_dept = $conn_temp->prepare("SELECT department FROM teacher WHERE user_id = ?");
            }
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

$is_imd_user = ($user_department_code === 'IM'); 
$is_fld_user = ($user_department_code === 'AF'); 
$is_admission_center = $is_admin_or_staff && !$is_department_user;

// ==========================================
// [新增] 處理分頁與歷史資料邏輯
// ==========================================
$view_mode = $_GET['view'] ?? 'active'; 
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : 0;

$current_month = (int)date('m');
$current_year = (int)date('Y');
$grad_threshold_year = ($current_month >= 8) ? $current_year : $current_year - 1;

// 設置頁面標題
if ($is_imd_user) {
    $page_title = '資訊管理科就讀意願名單';
} elseif ($is_fld_user) {
    $page_title = '應用外語科就讀意願名單';
} else {
    $page_title = '就讀意願名單';
}

if ($view_mode === 'history') {
    $page_title .= ' (歷史資料)';
}

// 排序參數
$sortBy = $_GET['sort_by'] ?? 'created_at';
$sortOrder = $_GET['sort_order'] ?? 'desc';

$allowed_columns = ['id', 'name', 'junior_high', 'assigned_department', 'created_at'];
if (!in_array($sortBy, $allowed_columns)) {
    $sortBy = 'created_at';
}
if (!in_array(strtolower($sortOrder), ['asc', 'desc'])) {
    $sortOrder = 'desc';
}

// 基礎 SELECT
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

$base_from_join = "
    FROM enrollment_intention ei
    LEFT JOIN user u ON ei.assigned_teacher_id = u.id
    LEFT JOIN teacher t ON u.id = t.user_id
    LEFT JOIN enrollment_choices ec1 ON ei.id = ec1.enrollment_id AND ec1.choice_order = 1
    LEFT JOIN departments d1 ON ec1.department_code = d1.code
    LEFT JOIN education_systems es1 ON ec1.system_code = es1.code
    LEFT JOIN enrollment_choices ec2 ON ei.id = ec2.enrollment_id AND ec2.choice_order = 2
    LEFT JOIN departments d2 ON ec2.department_code = d2.code
    LEFT JOIN education_systems es2 ON ec2.system_code = es2.code
    LEFT JOIN enrollment_choices ec3 ON ei.id = ec3.enrollment_id AND ec3.choice_order = 3
    LEFT JOIN departments d3 ON ec3.department_code = d3.code
    LEFT JOIN education_systems es3 ON ec3.system_code = es3.code
";

$order_by = " ORDER BY ei.$sortBy $sortOrder";

// [修正 A] 將函數定義移到這裡 (try 之前)
function getDynamicGradeText($grad_year, $static_grade_code, $options) {
    if (empty($grad_year)) {
        if (empty($static_grade_code)) return '未提供';
        $original_text = (is_array($options) && isset($options[$static_grade_code])) ? htmlspecialchars($options[$static_grade_code]) : $static_grade_code;
        return $original_text . ' <span style="font-size:12px; color:#999;">(舊資料)</span>';
    }

    $current_month = (int)date('m');
    $current_year = (int)date('Y');
    $this_year_grad = ($current_month >= 8) ? $current_year + 1 : $current_year;
    $diff = $grad_year - $this_year_grad;
    
    if ($diff == 0) return '<span style="font-weight:bold; color:#1890ff;">國三 (應屆)</span>';
    if ($diff == 1) return '國二';
    if ($diff == 2) return '國一';
    if ($diff < 0) return '<span style="color:#ff4d4f; font-weight:bold;">已畢業</span>';
    return '國小或其他';
}

function getSchoolName($code, $schools) {
    if (isset($schools[$code]) && $schools[$code] !== '') return htmlspecialchars($schools[$code]);
    return '未提供';
}

function getDepartmentName($code, $departments) {
    if (isset($departments[$code]) && $departments[$code] !== '') return htmlspecialchars($departments[$code]);
    return $code;
}

try {
    $conn = getDatabaseConnection();

    // 載入身分別與學校
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

    $department_data = [];
    $dept_result = $conn->query("SELECT code, name FROM departments");
    if ($dept_result) {
        while ($row = $dept_result->fetch_assoc()) {
            $department_data[$row['code']] = $row['name'];
        }
    }
    
    $years_sql = "SELECT DISTINCT graduation_year FROM enrollment_intention WHERE graduation_year <= ? AND graduation_year IS NOT NULL ORDER BY graduation_year DESC";
    $stmt_years = $conn->prepare($years_sql);
    if ($stmt_years) {
        $stmt_years->bind_param("i", $grad_threshold_year);
        $stmt_years->execute();
        $res_years = $stmt_years->get_result();
        while($y_row = $res_years->fetch_assoc()) {
            $history_years[] = $y_row['graduation_year'];
        }
        $stmt_years->close();
    }

    // 權限 WHERE 條件
    $perm_where = " WHERE 1=1 ";
    if ($is_director && !empty($user_department_code)) {
        $perm_where .= " AND ei.assigned_department = ? ";
    } elseif ($is_imd_user) {
        $perm_where .= " AND ((ec1.department_code = 'IM' OR ec2.department_code = 'IM' OR ec3.department_code = 'IM') OR ei.assigned_department = 'IM') ";
    } elseif ($is_fld_user) {
        $perm_where .= " AND ((ec1.department_code = 'AF' OR ec2.department_code = 'AF' OR ec3.department_code = 'AF') OR ei.assigned_department = 'AF') ";
    }

    // 狀態 WHERE 條件
    $status_where = "";
    if ($view_mode === 'history') {
        if ($selected_year > 0) {
            $status_where = " AND ei.graduation_year = " . intval($selected_year);
        } else {
            $status_where = " AND (ei.graduation_year <= $grad_threshold_year)";
        }
    } else {
        $status_where = " AND (ei.graduation_year > $grad_threshold_year OR ei.graduation_year IS NULL)";
    }
    
    // 組合最終 SQL
    $sql = $base_select . $base_from_join . $perm_where . $status_where . $order_by;
    
    $stmt = $conn->prepare($sql);
    
    if ($stmt) {
        if ($is_director && !empty($user_department_code)) {
            $stmt->bind_param("s", $user_department_code);
        }
        
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

    $new_enrollments = [];
    if ($view_mode === 'active' && !empty($enrollments)) {
        $one_hour_ago_timestamp = strtotime('-1 hour');
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

    if ($is_department_user) {
        if ($is_director && !empty($user_department_code)) {
            $table_check = $conn->query("SHOW TABLES LIKE 'director'");
            $director_join = $table_check && $table_check->num_rows > 0 ? "LEFT JOIN director dir ON u.id = dir.user_id" : "";
            
            $teacher_stmt = $conn->prepare(
                "SELECT DISTINCT u.id, u.username, u.name, 
                    COALESCE(dir.department, t.department) AS department_code, 
                    dep.name AS department_name,
                    u.role
                FROM user u
                LEFT JOIN teacher t ON u.id = t.user_id
                " . ($director_join ?: "") . "
                LEFT JOIN departments dep ON COALESCE(dir.department, t.department) = dep.code
                WHERE (u.role = 'TEA' OR u.role = 'DI')
                AND COALESCE(dir.department, t.department) = ?
                AND u.id != ?
                ORDER BY u.role DESC, u.name ASC"
            );
            
            if ($teacher_stmt) {
                $teacher_stmt->bind_param("si", $user_department_code, $user_id);
                if ($teacher_stmt->execute()) {
                    $teacher_result = $teacher_stmt->get_result();
                    if ($teacher_result) {
                        $teachers = $teacher_result->fetch_all(MYSQLI_ASSOC);
                    }
                }
            }
        } else {
            $table_check = $conn->query("SHOW TABLES LIKE 'director'");
            $director_join = $table_check && $table_check->num_rows > 0 ? "LEFT JOIN director dir ON u.id = dir.user_id" : "";
            $department_select = $table_check && $table_check->num_rows > 0 ? "dir.department" : "t.department";

            $director_stmt = $conn->prepare(
                "SELECT u.id, u.username, u.name, {$department_select} AS department_code, dep.name AS department_name, u.role\n"
                . "FROM user u\n"
                . "LEFT JOIN teacher t ON u.id = t.user_id\n"
                . $director_join . "\n"
                . "LEFT JOIN departments dep ON {$department_select} = dep.code\n"
                . "WHERE u.role = 'DI' OR u.username = 'IMD'\n" 
                . "ORDER BY u.name ASC"
            );

            if ($director_stmt && $director_stmt->execute()) {
                $director_result = $director_stmt->get_result();
                if ($director_result) {
                    $teachers = $director_result->fetch_all(MYSQLI_ASSOC);
                }
            }
        }
    }

    if ($is_admission_center) {
        $table_check = $conn->query("SHOW TABLES LIKE 'director'");
        $director_join = $table_check && $table_check->num_rows > 0 ? "LEFT JOIN director dir ON dir.department = d.code" : "";
        $department_select = $table_check && $table_check->num_rows > 0 ? "dir.department" : "t.department";
        
        $dept_stmt = $conn->prepare("
            SELECT DISTINCT d.code, d.name, 
                GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ') AS director_names
            FROM departments d
            LEFT JOIN director dir ON dir.department = d.code
            LEFT JOIN user u ON dir.user_id = u.id AND u.role = 'DI'
            GROUP BY d.code, d.name
            ORDER BY d.name ASC
        ");
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
        .main-content { overflow-x: hidden; }
        .content { padding: 24px; width: 100%; }

        /* Tabs Style */
        .tabs-container {
            display: flex;
            margin-bottom: 16px;
            border-bottom: 1px solid #d9d9d9;
            gap: 4px;
        }
        .tab-btn {
            padding: 10px 20px;
            cursor: pointer;
            border: 1px solid transparent;
            border-bottom: none;
            border-radius: 6px 6px 0 0;
            background: #fafafa;
            color: #595959;
            font-size: 15px;
            text-decoration: none;
            transition: all 0.3s;
        }
        .tab-btn:hover {
            color: var(--primary-color);
            background: #fff;
        }
        .tab-btn.active {
            background: #fff;
            color: var(--primary-color);
            border-color: #d9d9d9;
            border-bottom-color: #fff; /* Blend with content */
            font-weight: 600;
            margin-bottom: -1px; /* Overlap border */
        }
        .history-filter {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .history-select {
            padding: 6px 12px;
            border: 1px solid #d9d9d9;
            border-radius: 4px;
            font-size: 14px;
        }

        .page-controls { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; gap: 16px; }
        .breadcrumb { margin-bottom: 0; font-size: 16px; color: var(--text-secondary-color); }
        .breadcrumb a { color: var(--primary-color); text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }
        
        .table-wrapper {
            background: var(--card-background-color);
            border-radius: 0 8px 8px 8px; /* Top left square for tabs */
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
            padding-top: 20px;
        }
        /* Other styles similar to before */
        .table-container { overflow-x: auto; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 16px 24px; text-align: left; border-bottom: 1px solid var(--border-color); font-size: 16px; white-space: nowrap; }
        .table th:first-child, .table td:first-child { padding-left: 60px; }
        .table th { background: #fafafa; font-weight: 600; color: #262626; cursor: pointer; user-select: none; position: relative; }
        .table td { color: #595959; }
        .table th:hover { background: #f0f0f0; }
        .sort-icon { margin-left: 8px; font-size: 12px; color: #8c8c8c; }
        .sort-icon.active { color: #1890ff; }
        .sort-icon.asc::after { content: "↑"; }
        .sort-icon.desc::after { content: "↓"; }
        .table tr:hover { background: #fafafa; }
        .pagination { padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--border-color); background: #fafafa; }
        .pagination-info { display: flex; align-items: center; gap: 16px; color: var(--text-secondary-color); font-size: 14px; }
        .pagination-controls { display: flex; align-items: center; gap: 8px; }
        .pagination select { padding: 6px 12px; border: 1px solid #d9d9d9; border-radius: 6px; font-size: 14px; background: #fff; cursor: pointer; }
        .pagination button { padding: 6px 12px; border: 1px solid #d9d9d9; background: #fff; color: #595959; border-radius: 6px; cursor: pointer; font-size: 14px; transition: all 0.3s; }
        .pagination button:hover:not(:disabled) { border-color: #1890ff; color: #1890ff; }
        .pagination button:disabled { opacity: 0.5; cursor: not-allowed; }
        .pagination button.active { background: #1890ff; color: white; border-color: #1890ff; }
        .detail-row { background: #f9f9f9; }
        .table-row-clickable { cursor: pointer; }
        .search-input { padding: 8px 12px; border: 1px solid #d9d9d9; border-radius: 6px; font-size: 14px; width: 250px; transition: all 0.3s; }
        .search-input:focus { outline: none; border-color: #1890ff; box-shadow: 0 0 0 2px rgba(24,144,255,0.2); }
        .empty-state { text-align: center; padding: 40px; color: var(--text-secondary-color); }
        .assign-btn { background: var(--primary-color); color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; transition: all 0.3s; }
        .assign-btn:hover { background: #40a9ff; transform: translateY(-1px); }
        .assign-btn i { margin-right: 4px; }
        /* Modals... (Same as before) */
        .modal { position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; }
        .modal-content { background-color: white; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.15); width: 90%; max-width: 500px; max-height: 80vh; overflow-y: auto; }
        .modal-header { padding: 20px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }
        .close { font-size: 24px; font-weight: bold; cursor: pointer; color: var(--text-secondary-color); }
        .modal-body { padding: 20px; }
        .teacher-option { display: block; padding: 12px; border: 1px solid var(--border-color); border-radius: 6px; margin-bottom: 8px; cursor: pointer; transition: all 0.3s; }
        .teacher-option:hover { background-color: #f5f5f5; border-color: var(--primary-color); }
        .modal-footer { padding: 20px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 12px; }
        .btn-cancel { padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; background-color: #f5f5f5; color: var(--text-color); }
        .btn-confirm { padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; background-color: var(--primary-color); color: white; }
        /* Alert */
        .new-enrollment-alert { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 16px 20px; border-radius: 8px; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3); animation: slideDown 0.3s ease-out; }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        /* Contact Log styles */
        .contact-log-modal .modal-content { max-width: 600px; }
        .contact-log-item { background: #fff; border: 1px solid #e8e8e8; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
        .contact-log-header { display: flex; justify-content: space-between; border-bottom: 1px dashed #f0f0f0; padding-bottom: 12px; margin-bottom: 12px;}
        .form-control { padding: 8px 12px; border: 1px solid #d9d9d9; border-radius: 4px; font-size: 14px; }
        .form-control:focus { outline: none; border-color: #1890ff; box-shadow: 0 0 0 2px rgba(24,144,255,0.2); }
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

                <?php if ($new_enrollments_count > 0 && $view_mode === 'active'): ?>
                <div class="new-enrollment-alert" id="newEnrollmentAlert">
                    <div class="new-enrollment-alert-content" style="display:flex; align-items:center; gap:12px;">
                        <i class="fas fa-bell"></i>
                        <span>有 <strong><?php echo $new_enrollments_count; ?></strong> 筆新的就讀意願！</span>
                    </div>
                    <button onclick="closeNewEnrollmentAlert()" style="background:none; border:none; color:white; cursor:pointer;"><i class="fas fa-times"></i></button>
                </div>
                <?php endif; ?>

                <div class="tabs-container">
                    <a href="enrollment_list.php?view=active" class="tab-btn <?php echo $view_mode === 'active' ? 'active' : ''; ?>">
                        <i class="fas fa-user-graduate"></i> 目前學年 (Active)
                    </a>
                    <a href="enrollment_list.php?view=history" class="tab-btn <?php echo $view_mode === 'history' ? 'active' : ''; ?>">
                        <i class="fas fa-history"></i> 歷史資料 (Graduated)
                    </a>

                    <?php if ($view_mode === 'history'): ?>
                    <div class="history-filter">
                        <form action="enrollment_list.php" method="GET" style="margin:0;">
                            <input type="hidden" name="view" value="history">
                            <select name="year" class="history-select" onchange="this.form.submit()">
                                <option value="0">顯示全部歷史資料</option>
                                <?php foreach ($history_years as $year): ?>
                                    <option value="<?php echo $year; ?>" <?php echo $selected_year == $year ? 'selected' : ''; ?>>
                                        <?php echo $year; ?> 年畢業
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="table-wrapper">
                    <div class="table-container">
                        <?php if (empty($enrollments)): ?>
                            <div class="empty-state">
                                <i class="fas fa-inbox fa-3x" style="margin-bottom: 16px;"></i>
                                <p>
                                    <?php if ($view_mode === 'history'): ?>
                                        查無歷史資料<?php echo $selected_year > 0 ? " (畢業年份: $selected_year)" : ""; ?>。
                                    <?php else: ?>
                                        目前尚無就讀意願資料。
                                    <?php endif; ?>
                                </p>
                            </div>
                        <?php else: ?>
                            <?php
                                $director_active_choices = [];
                                if ($is_department_user && !empty($enrollments)) {
                                    $target_dept_code = $user_department_code;
                                    if ($is_imd_user) $target_dept_code = 'IM';
                                    if ($is_fld_user) $target_dept_code = 'AF';

                                    foreach ($enrollments as $row) {
                                        if (($row['intention1_code'] ?? '') === $target_dept_code) $director_active_choices[1] = true;
                                        if (($row['intention2_code'] ?? '') === $target_dept_code) $director_active_choices[2] = true;
                                        if (($row['intention3_code'] ?? '') === $target_dept_code) $director_active_choices[3] = true;
                                    }
                                }
                                $director_col_title = '相關意願';
                                if (!empty($director_active_choices)) {
                                    $active_keys = array_keys($director_active_choices);
                                    if (count($active_keys) === 1) {
                                        $director_col_title = '意願 ' . $active_keys[0];
                                    } else {
                                        $director_col_title = '意願';
                                    }
                                }
                            ?>
                            <table class="table enrollment-table" id="enrollmentTable">
                                <thead>
                                    <tr>
                                        <th onclick="sortTable('name')">姓名 <span class="sort-icon" id="sort-name"></span></th>
                                        
                                        <?php if ($is_department_user): ?>
                                            <th><?php echo htmlspecialchars($director_col_title); ?></th>
                                        <?php else: ?>
                                            <th class="choice1-column">意願1</th>
                                            <th class="choice2-column">意願2</th>
                                            <th class="choice3-column">意願3</th>
                                        <?php endif; ?>
                                        
                                        <th onclick="sortTable('junior_high')">就讀國中 <span class="sort-icon" id="sort-junior_high"></span></th>
                                        <th>目前年級</th>
                                        
                                        <?php if ($is_admission_center): ?>
                                            <th onclick="sortTable('assigned_department')">分配部門 / 負責老師 <span class="sort-icon" id="sort-assigned_department"></span></th>
                                        <?php elseif ($is_department_user): ?>
                                            <th>分配狀態</th>
                                        <?php endif; ?>
                                        
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($enrollments as $item): 
                                    $intention1_name = $item['intention1_name'] ?? '';
                                    $system1_name = $item['system1_name'] ?? '';
                                    $intention2_name = $item['intention2_name'] ?? '';
                                    $system2_name = $item['system2_name'] ?? '';
                                    $intention3_name = $item['intention3_name'] ?? '';
                                    $system3_name = $item['system3_name'] ?? '';
                                    
                                    $intention1_code = $item['intention1_code'] ?? '';
                                    $intention2_code = $item['intention2_code'] ?? '';
                                    $intention3_code = $item['intention3_code'] ?? '';

                                    $format_intention = function($name, $system) {
                                        if (empty($name)) return '無意願';
                                        $system_display = !empty($system) ? " ({$system})" : '';
                                        return htmlspecialchars($name . $system_display);
                                    };
                                    
                                    $display_text1 = $format_intention($intention1_name, $system1_name);
                                    $display_text2 = $format_intention($intention2_name, $system2_name);
                                    $display_text3 = $format_intention($intention3_name, $system3_name);

                                    $chosen_codes = array_filter([$intention1_code, $intention2_code, $intention3_code]);
                                    $chosen_codes_json = json_encode(array_unique($chosen_codes));
                                    ?>
                                    <tr class="table-row-clickable" onclick="toggleDetail(<?php echo $item['id']; ?>)">
                                        <td><?php echo htmlspecialchars($item['name']); ?></td>
                                        
                                        <?php if ($is_department_user): ?>
                                            <td>
                                                <?php 
                                                    $target_dept_code = $user_department_code;
                                                    if ($is_imd_user) $target_dept_code = 'IM';
                                                    if ($is_fld_user) $target_dept_code = 'AF';
                                                    
                                                    if (($item['intention1_code'] ?? '') === $target_dept_code) echo $display_text1;
                                                    elseif (($item['intention2_code'] ?? '') === $target_dept_code) echo $display_text2;
                                                    elseif (($item['intention3_code'] ?? '') === $target_dept_code) echo $display_text3;
                                                ?>
                                            </td>
                                        <?php else: ?>
                                            <td class="choice1-column"><?php echo $display_text1; ?></td>
                                            <td class="choice2-column"><?php echo $display_text2; ?></td>
                                            <td class="choice3-column"><?php echo $display_text3; ?></td>
                                        <?php endif; ?>

                                        <td><?php echo getSchoolName($item['junior_high'] ?? '', $school_data); ?></td>
                                        
                                        <td><?php echo getDynamicGradeText($item['graduation_year'] ?? '', $item['current_grade'] ?? '', $identity_options); ?></td>

                                        <?php if ($is_admission_center): ?>
                                        <td>
                                            <?php if (!empty($item['assigned_department'])): ?>
                                                <div style="line-height: 1.4;">
                                                    <span style="color: #52c41a; font-weight: bold; display: block; margin-bottom: 4px;">
                                                        <i class="fas fa-check-circle"></i> 
                                                        <?php echo getDepartmentName($item['assigned_department'], $department_data); ?>
                                                    </span>
                                                    
                                                    <?php if (!empty($item['assigned_teacher_id'])): ?>
                                                        <span style="font-size: 13px; color: #1890ff; background: #e6f7ff; padding: 2px 6px; border-radius: 4px; border: 1px solid #91d5ff;">
                                                            <i class="fas fa-chalkboard-teacher"></i> 
                                                            <?php echo htmlspecialchars($item['teacher_name'] ?? $item['teacher_username'] ?? '未知老師'); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span style="font-size: 12px; color: #999;">
                                                            (主任尚未指派)
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: #8c8c8c;">
                                                    <i class="fas fa-clock"></i> 未分配
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td onclick="event.stopPropagation();">
                                            <?php 
                                            $dept_name = !empty($item['assigned_department']) ? getDepartmentName($item['assigned_department'], $department_data) : '尚未分配';
                                            $teacher_name = !empty($item['teacher_name']) ? $item['teacher_name'] : (!empty($item['teacher_username']) ? $item['teacher_username'] : '尚未指派');
                                            if (empty($item['assigned_department'])) { $teacher_name = '-'; }
                                            ?>
                                            <button class="assign-btn" 
                                                    style="background: #17a2b8;"
                                                    data-student-id="<?php echo $item['id']; ?>"
                                                    data-student-name="<?php echo htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-dept-name="<?php echo htmlspecialchars($dept_name, ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-teacher-name="<?php echo htmlspecialchars($teacher_name, ENT_QUOTES, 'UTF-8'); ?>"
                                                    onclick="event.stopPropagation(); openDetailsModal(this)">
                                                <i class="fas fa-file-alt"></i> 詳情與紀錄
                                            </button>
                                        </td>
                                        <?php elseif ($is_department_user): ?>
                                        <td>
                                            <span style="display: none;" class="chosen-codes-data"><?php echo $chosen_codes_json; ?></span>
                                            <?php 
                                            $assigned_teacher_id = $item['assigned_teacher_id'];
                                            $is_assigned_to_me = ($assigned_teacher_id == $user_id);
                                            $is_assigned_to_others = (!empty($assigned_teacher_id) && !$is_assigned_to_me);
                                            ?>
                                            <?php if ($is_assigned_to_others): ?>
                                                <div style="line-height: 1.4;">
                                                    <span style="color: #52c41a; font-weight: bold;">
                                                        <i class="fas fa-check-circle"></i> 已分配 - 
                                                        <?php echo htmlspecialchars($item['teacher_name'] ?? $item['teacher_username'] ?? '未知老師'); ?>
                                                    </span>
                                                </div>
                                            <?php else: ?>
                                                <div style="line-height: 1.4;">
                                                    <span style="color: #ff9800; font-weight: bold;">
                                                        <i class="fas fa-exclamation-circle"></i> 待分配
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td onclick="event.stopPropagation();">
                                            <?php if ($view_mode === 'history'): ?>
                                                <button class="assign-btn" style="background: #17a2b8;" onclick="event.stopPropagation(); openContactLogsModal(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['name']); ?>')">
                                                    <i class="fas fa-address-book"></i> 紀錄
                                                </button>
                                            <?php else: ?>
                                                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                                    <?php if ($is_assigned_to_others): ?>
                                                    <button class="assign-btn" 
                                                            style="background: #28a745;"
                                                            data-student-id="<?php echo $item['id']; ?>"
                                                            data-student-name="<?php echo htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                            data-current-teacher-id="<?php echo $item['assigned_teacher_id']; ?>"
                                                            data-chosen-codes="<?php echo htmlspecialchars($chosen_codes_json, ENT_QUOTES, 'UTF-8'); ?>"
                                                            onclick="event.stopPropagation(); openAssignModalFromButton(this)">
                                                        <i class="fas fa-check-circle"></i> 已分配
                                                    </button>
                                                    <?php else: ?>
                                                    <button class="assign-btn" 
                                                            data-student-id="<?php echo $item['id']; ?>"
                                                            data-student-name="<?php echo htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                            data-current-teacher-id="<?php echo $item['assigned_teacher_id']; ?>"
                                                            data-chosen-codes="<?php echo htmlspecialchars($chosen_codes_json, ENT_QUOTES, 'UTF-8'); ?>"
                                                            onclick="event.stopPropagation(); openAssignModalFromButton(this)">
                                                        <i class="fas fa-user-plus"></i> 分配
                                                    </button>
                                                    <?php endif; ?>
                                                    
                                                    <button class="assign-btn" style="background: #17a2b8;" onclick="event.stopPropagation(); openContactLogsModal(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['name']); ?>')">
                                                        <i class="fas fa-address-book"></i> 紀錄
                                                    </button>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <?php else: ?>
                                        <td></td>
                                        <?php endif; ?>
                                    </tr>
                                    <tr id="detail-<?php echo $item['id']; ?>" class="detail-row" style="display: none;">
                                        <td colspan="<?php echo $is_admission_center ? '8' : ($is_department_user ? '6' : '7'); ?>" style="padding: 20px; background: #f9f9f9; border: 2px solid #b3d9ff; border-radius: 4px;">
                                            <table style="width: 100%; border-collapse: collapse;">
                                                <tr>
                                                    <td style="width: 50%; vertical-align: top; padding-right: 20px;">
                                                        <h4 style="margin: 0 0 10px 0; font-size: 16px;">基本資料</h4>
                                                        <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5; width: 120px;">姓名</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd;"><?php echo htmlspecialchars($item['name']); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">目前年級</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd;">
                                                                    <?php echo getDynamicGradeText($item['graduation_year'] ?? '', $item['current_grade'] ?? '', $identity_options); ?>
                                                                    <?php if (!empty($item['graduation_year'])): ?>
                                                                        <span style="font-size:12px; color:#888;">(預計 <?php echo $item['graduation_year']; ?> 年畢業)</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                            </tr>
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">就讀學校</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd;"><?php echo getSchoolName($item['junior_high'] ?? '', $school_data); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">聯絡電話</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd;"><?php echo htmlspecialchars($item['phone1']); ?></td>
                                                            </tr>
                                                        </table>
                                                    </td>
                                                    <td style="width: 50%; vertical-align: top; padding-left: 20px;">
                                                        <h4 style="margin: 0 0 10px 0; font-size: 16px;">其他資訊</h4>
                                                        <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5; width: 120px;">Email</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd;"><?php echo htmlspecialchars($item['email']); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">備註</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd;"><?php echo htmlspecialchars($item['remarks'] ?? '無'); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">填寫日期</td>
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
                    <?php if (!empty($enrollments)): ?>
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
                            <span id="pageInfo">顯示第 <span id="currentRange">1-<?php echo min(10, count($enrollments)); ?></span> 筆，共 <?php echo count($enrollments); ?> 筆</span>
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
    
    <?php if ($is_admission_center): ?>
    <div id="assignDepartmentModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header"><h3>分配學生至部門</h3><span class="close" onclick="closeAssignDepartmentModal()">&times;</span></div>
            <div class="modal-body"><p>學生：<span id="departmentStudentName"></span></p><div class="teacher-list"><h4>選擇部門：</h4><div class="teacher-options" id="departmentOptions"><div class="contact-log-loading"><i class="fas fa-spinner fa-spin"></i> 準備部門名單中...</div></div></div></div>
            <div class="modal-footer"><button class="btn-cancel" onclick="closeAssignDepartmentModal()">取消</button><button class="btn-confirm" onclick="assignDepartment()">確認分配</button></div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($is_department_user): ?>
    <div id="assignModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header"><h3>分配學生</h3><span class="close" onclick="closeAssignModal()">&times;</span></div>
            <div class="modal-body"><p>學生：<span id="studentName"></span></p><div class="teacher-list"><h4>選擇老師：</h4><div class="teacher-options" id="directorOptions"><div class="contact-log-loading"><i class="fas fa-spinner fa-spin"></i> 準備老師名單中...</div></div></div></div>
            <div class="modal-footer"><button class="btn-cancel" onclick="closeAssignModal()">取消</button><button class="btn-confirm" onclick="assignStudent()">確認分配</button></div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($is_department_user || $is_admission_center): ?>
    <div id="contactLogsModal" class="modal contact-log-modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header"><h3>詳細資料 - <span id="contactLogStudentName"></span></h3><span class="close" onclick="closeContactLogsModal()">&times;</span></div>
            <div class="modal-body">
                <div id="assignmentInfo" style="background: #f5f7fa; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #17a2b8; display: none;">
                    <h4 style="margin: 0 0 10px 0; color: #333; font-size: 16px; border-bottom: 1px solid #e8e8e8; padding-bottom: 8px;"><i class="fas fa-sitemap"></i> 目前分配狀態</h4>
                    <div style="display: flex; gap: 20px;">
                        <div style="flex: 1;"><span style="color: #666; font-size: 13px;">分配科系</span><br><strong id="detailDeptName" style="font-size: 15px; color: #1890ff;">-</strong></div>
                        <div style="flex: 1;"><span style="color: #666; font-size: 13px;">負責老師</span><br><strong id="detailTeacherName" style="font-size: 15px; color: #1890ff;">-</strong></div>
                    </div>
                </div>

                <?php if ($is_department_user): ?>
                <div class="add-log-form" style="background: #f0f2f5; padding: 15px; border-radius: 8px; margin-bottom: 15px;">
                    <h4 style="margin-top: 0; margin-bottom: 10px; font-size: 16px; color: #1890ff;"><i class="fas fa-plus-circle"></i> 新增聯絡紀錄</h4>
                    <input type="hidden" id="logStudentId">
                    <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                        <div style="flex: 1;">
                            <label style="font-size: 12px; color: #666;">聯絡日期</label>
                            <input type="date" id="logDate" class="form-control" style="width: 100%;" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div style="flex: 1;">
                            <label style="font-size: 12px; color: #666;">聯絡方式</label>
                            <select id="logMethod" class="form-control" style="width: 100%;">
                                <option value="電話">電話</option>
                                <option value="Line">Line</option>
                                <option value="現場">現場</option>
                                <option value="Email">Email</option>
                                <option value="其他">其他</option>
                            </select>
                        </div>
                    </div>
                    <div style="margin-bottom: 10px;">
                        <textarea id="logNotes" class="form-control" rows="3" placeholder="請輸入聯絡內容..." style="width: 100%; resize: vertical;"></textarea>
                    </div>
                    <div style="text-align: right;">
                        <button onclick="submitContactLog()" style="background: #1890ff; color: white; border: none; padding: 6px 16px; border-radius: 4px; cursor: pointer; transition: 0.3s;">
                            <i class="fas fa-save"></i> 儲存紀錄
                        </button>
                    </div>
                </div>
                <?php endif; ?>

                <h4 style="margin: 0 0 12px 0; color: #333; font-size: 16px;"><i class="fas fa-history"></i> 聯絡紀錄</h4>
                <div id="contactLogsList" class="contact-log-loading"><i class="fas fa-spinner fa-spin"></i> 載入中...</div>
            </div>
            <div class="modal-footer"><button class="btn-cancel" onclick="closeContactLogsModal()">關閉</button></div>
        </div>
    </div>
    <?php endif; ?>

    <script>
    let allDirectors = [];
    <?php if ($is_department_user && !empty($teachers)): ?>
    allDirectors = <?php echo json_encode($teachers, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
    <?php endif; ?>

    let allDepartments = [];
    <?php if ($is_admission_center && !empty($departments)): ?>
    allDepartments = <?php echo json_encode($departments, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
    <?php endif; ?>

    document.addEventListener('DOMContentLoaded', function() {
        window.sortTable = function(field) {
            let newSortOrder = 'asc';
            const urlParams = new URLSearchParams(window.location.search);
            const currentSortBy = urlParams.get('sort_by') || 'created_at';
            const currentSortOrder = urlParams.get('sort_order') || 'desc';
            if (currentSortBy === field) { newSortOrder = currentSortOrder === 'asc' ? 'desc' : 'asc'; }
            
            if (urlParams.has('view')) urlParams.set('view', '<?php echo $view_mode; ?>');
            if (urlParams.has('year')) urlParams.set('year', '<?php echo $selected_year; ?>');
            
            urlParams.set('sort_by', field);
            urlParams.set('sort_order', newSortOrder);
            window.location.search = urlParams.toString();
        };

        window.toggleDetail = function(id) {
            const detailRow = document.getElementById('detail-' + id);
            if (detailRow) {
                detailRow.style.display = (detailRow.style.display === 'none' || detailRow.style.display === '') ? 'table-row' : 'none';
            }
        };

        initPagination();
        
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.addEventListener('keyup', function() {
                const filter = searchInput.value.toLowerCase();
                const table = document.getElementById('enrollmentTable');
                if (!table) return;
                const tbody = table.getElementsByTagName('tbody')[0];
                const allTableRows = Array.from(tbody.getElementsByTagName('tr'));
                allRows = allTableRows.filter(row => !row.classList.contains('detail-row') && (row.classList.contains('table-row-clickable') || row.getElementsByTagName('td').length >= 5));
                filteredRows = allRows.filter(row => {
                    const cells = row.getElementsByTagName('td');
                    for (let j = 0; j < cells.length; j++) {
                        if ((cells[j].textContent || cells[j].innerText).toLowerCase().indexOf(filter) > -1) return true;
                    }
                    return false;
                });
                currentPage = 1;
                updatePagination();
            });
        }
    });

    let currentPage = 1;
    let itemsPerPage = 10;
    let allRows = [];
    let filteredRows = [];

    function initPagination() {
        const table = document.getElementById('enrollmentTable');
        if (table) {
            const tbody = table.getElementsByTagName('tbody')[0];
            const allTableRows = Array.from(tbody.getElementsByTagName('tr'));
            allRows = allTableRows.filter(row => !row.classList.contains('detail-row') && (row.classList.contains('table-row-clickable') || row.getElementsByTagName('td').length >= 5));
            filteredRows = [...allRows];
        }
        updatePagination();
    }

    function changeItemsPerPage() {
        const select = document.getElementById('itemsPerPage');
        if (select) { itemsPerPage = select.value === 'all' ? filteredRows.length : parseInt(select.value); }
        currentPage = 1;
        updatePagination();
    }

    function changePage(direction) {
        const totalItems = filteredRows.length;
        const totalPages = Math.ceil(totalItems / itemsPerPage);
        currentPage += direction;
        if (currentPage < 1) currentPage = 1;
        if (currentPage > totalPages) currentPage = totalPages;
        updatePagination();
    }

    function updatePagination() {
        const totalItems = filteredRows.length;
        const select = document.getElementById('itemsPerPage');
        let iPerPage = (select && select.value !== 'all') ? parseInt(select.value) : (select && select.value === 'all' ? totalItems : 10);
        
        const totalPages = Math.ceil(totalItems / iPerPage) || 1;
        if (currentPage > totalPages) currentPage = totalPages;
        
        let start = totalItems === 0 ? 0 : (currentPage - 1) * iPerPage + 1;
        let end = Math.min(currentPage * iPerPage, totalItems);
        
        const currentRangeEl = document.getElementById('currentRange');
        if (currentRangeEl) currentRangeEl.textContent = `${start}-${end}`;
        
        const prevBtn = document.getElementById('prevPage');
        const nextBtn = document.getElementById('nextPage');
        if (prevBtn) prevBtn.disabled = currentPage <= 1;
        if (nextBtn) nextBtn.disabled = currentPage >= totalPages;

        const pageNumbers = document.getElementById('pageNumbers');
        if (pageNumbers) {
            pageNumbers.innerHTML = '';
            for (let i = 1; i <= totalPages; i++) {
                if (totalPages > 10 && Math.abs(currentPage - i) > 2 && i !== 1 && i !== totalPages) continue;
                 const btn = document.createElement('button');
                 btn.textContent = i;
                 btn.className = i === currentPage ? 'active' : '';
                 btn.onclick = () => { currentPage = i; updatePagination(); };
                 pageNumbers.appendChild(btn);
            }
        }

        allRows.forEach(row => {
            row.style.display = 'none';
            const detailRow = document.getElementById('detail-' + row.getAttribute('onclick').match(/\d+/)[0]);
            if (detailRow) detailRow.style.display = 'none';
        });

        const rowsToShow = filteredRows.slice((currentPage - 1) * iPerPage, currentPage * iPerPage);
        rowsToShow.forEach(row => row.style.display = '');
    }

    function openAssignModalFromButton(button) {
        const studentId = parseInt(button.getAttribute('data-student-id'));
        const studentName = button.getAttribute('data-student-name');
        const currentTeacherId = button.getAttribute('data-current-teacher-id');
        const chosenCodesJson = button.getAttribute('data-chosen-codes') || '[]';
        openAssignModal(studentId, studentName, currentTeacherId, chosenCodesJson);
    }

    function openAssignModal(studentId, studentName, currentTeacherId, chosenCodesJson) {
        currentStudentId = studentId;
        document.getElementById('studentName').textContent = studentName;
        document.getElementById('assignModal').style.display = 'flex';
        let chosenCodes = [];
        try { chosenCodes = JSON.parse(chosenCodesJson); } catch (e) {}
        filterAndDisplayDirectors(currentTeacherId, chosenCodes);
    }
    
    function filterAndDisplayDirectors(currentTeacherId, chosenCodes) {
        const optionsContainer = document.getElementById('directorOptions');
        optionsContainer.innerHTML = '';
        const filterByChoice = chosenCodes.length > 0;
        let filteredTeachers = filterByChoice ? allDirectors.filter(t => t.department_code && chosenCodes.includes(t.department_code)) : allDirectors;

        if (filteredTeachers.length === 0) {
            optionsContainer.innerHTML = filterByChoice ? '<p class="empty-state" style="padding: 10px;">找不到符合學生志願科系的老師。</p>' : '<p class="empty-state" style="padding: 10px;">目前沒有任何老師資料可供分配。</p>';
        } else {
            let html = '';
            filteredTeachers.forEach(teacher => {
                const isChecked = (currentTeacherId && teacher.id == currentTeacherId);
                const teacherName = teacher.name ?? teacher.username;
                html += `<label class="teacher-option"><input type="radio" name="teacher" value="${teacher.id}" ${isChecked ? 'checked' : ''}><div class="teacher-info"><strong>${teacherName}</strong></div></label>`;
            });
            optionsContainer.innerHTML = html;
        }
    }

    let currentStudentId = null;
    function closeAssignModal() { document.getElementById('assignModal').style.display = 'none'; currentStudentId = null; }
    
    function assignStudent() {
        const selectedTeacher = document.querySelector('input[name="teacher"]:checked');
        if (!selectedTeacher) { alert('請選擇一位老師'); return; }
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'assign_student.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4 && xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (response.success) { alert('學生分配成功！'); location.reload(); } else { alert('分配失敗：' + (response.message || '未知錯誤')); }
                } catch (e) { alert('回應格式錯誤'); }
            }
        };
        xhr.send('student_id=' + encodeURIComponent(currentStudentId) + '&teacher_id=' + encodeURIComponent(selectedTeacher.value));
    }

    let currentDepartmentStudentId = null;
    function openDetailsModal(button) {
        const studentId = button.getAttribute('data-student-id');
        const studentName = button.getAttribute('data-student-name');
        const deptName = button.getAttribute('data-dept-name');
        const teacherName = button.getAttribute('data-teacher-name');
        document.getElementById('contactLogStudentName').textContent = studentName;
        document.getElementById('detailDeptName').textContent = deptName;
        document.getElementById('detailTeacherName').textContent = teacherName;
        const assignmentInfo = document.getElementById('assignmentInfo');
        if (assignmentInfo) assignmentInfo.style.display = 'block';
        document.getElementById('contactLogsModal').style.display = 'flex';
        loadContactLogs(studentId);
    }
    
    function openContactLogsModal(studentId, studentName) {
        document.getElementById('contactLogStudentName').textContent = studentName;
        
        // [新增] 設定表單隱藏欄位 ID
        const logInput = document.getElementById('logStudentId');
        if(logInput) logInput.value = studentId;
        
        // 清空輸入框
        if(document.getElementById('logNotes')) document.getElementById('logNotes').value = '';
        
        const assignmentInfo = document.getElementById('assignmentInfo');
        if (assignmentInfo) assignmentInfo.style.display = 'none';
        document.getElementById('contactLogsModal').style.display = 'flex';
        loadContactLogs(studentId);
    }
    
    function closeContactLogsModal() { document.getElementById('contactLogsModal').style.display = 'none'; }
    
    // [新增] 提交聯絡紀錄
    async function submitContactLog() {
        const studentId = document.getElementById('logStudentId').value;
        const date = document.getElementById('logDate').value;
        const method = document.getElementById('logMethod').value;
        const notes = document.getElementById('logNotes').value;
        
        if(!notes.trim()) {
            alert('請輸入聯絡內容');
            return;
        }
        
        const formData = new FormData();
        formData.append('enrollment_id', studentId);
        formData.append('contact_date', date);
        formData.append('method', method);
        formData.append('notes', notes);
        
        try {
            const response = await fetch('submit_contact_log.php', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            
            if(result.success) {
                // 清空並重新載入列表
                document.getElementById('logNotes').value = '';
                loadContactLogs(studentId);
            } else {
                alert(result.message || '儲存失敗');
            }
        } catch(e) {
            console.error(e);
            alert('系統錯誤，請稍後再試');
        }
    }
    
    async function loadContactLogs(studentId) {
        const logsList = document.getElementById('contactLogsList');
        logsList.innerHTML = '<div class="contact-log-loading"><i class="fas fa-spinner fa-spin"></i> 載入中...</div>';
        try {
            const response = await fetch(`get_contact_logs.php?enrollment_id=${studentId}`);
            const data = await response.json();
            if (data.success && data.logs) {
                if (data.logs.length === 0) {
                    logsList.innerHTML = '<div class="contact-log-empty"><i class="fas fa-inbox fa-3x" style="margin-bottom: 16px; color: #e8e8e8;"></i><p>目前尚無聯絡紀錄</p></div>';
                } else {
                    logsList.innerHTML = data.logs.map(log => {
                        const contactDate = log.contact_date || '未知';
                        return `<div class="contact-log-item"><div class="contact-log-header"><strong>${contactDate}</strong><span>${log.method || ''}</span></div><div class="contact-log-result">${log.notes || ''}</div></div>`;
                    }).join('');
                }
            } else { logsList.innerHTML = '載入失敗'; }
        } catch (e) { logsList.innerHTML = '載入錯誤'; }
    }

    function closeNewEnrollmentAlert() {
        const alert = document.getElementById('newEnrollmentAlert');
        if (alert) { alert.style.display = 'none'; }
    }
    </script>
</body>
</html>