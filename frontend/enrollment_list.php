<?php
// 開啟錯誤報告以進行除錯
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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

// 用於診斷的變數
$debug_log = [];
$debug_sql = "";
$debug_params = [];

// ==========================================
// [關鍵修正] 獲取 User ID 與 自動修復機制
// ==========================================
$user_id = 0;
$username = $_SESSION['username'] ?? '';
$user_role = $_SESSION['role'] ?? '';

// 1. 嘗試從 Session 獲取
if (!empty($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $debug_log[] = "從 Session['user_id'] 獲取 ID: " . $user_id;
} elseif (!empty($_SESSION['id'])) {
    $user_id = $_SESSION['id'];
    $debug_log[] = "從 Session['id'] 獲取 ID: " . $user_id;
}

// 2. [保險機制] 如果 Session 沒抓到 ID，但有 Username，則從資料庫反查
if (($user_id == 0 || empty($user_id)) && !empty($username)) {
    try {
        $conn_auth = getDatabaseConnection();
        $debug_log[] = "嘗試使用 Username '$username' 反查 ID...";
        
        $stmt_auth = $conn_auth->prepare("SELECT id, role FROM user WHERE username = ?");
        if ($stmt_auth) {
            $stmt_auth->bind_param("s", $username);
            $stmt_auth->execute();
            $res_auth = $stmt_auth->get_result();
            if ($row_auth = $res_auth->fetch_assoc()) {
                $user_id = $row_auth['id'];
                // 自動修復 Session
                $_SESSION['user_id'] = $user_id;
                $_SESSION['id'] = $user_id;
                $debug_log[] = ">>> 反查成功！修復 User ID 為: " . $user_id;
            } else {
                $debug_log[] = "反查失敗：資料庫找不到此帳號";
            }
            $stmt_auth->close();
        }
        $conn_auth->close();
    } catch (Exception $e) {
        $debug_log[] = "反查時發生錯誤: " . $e->getMessage();
    }
}

$debug_log[] = "最終確認 User ID: " . $user_id;

// 初始化變數
$enrollments = [];
$teachers = [];
$departments = [];
$identity_options = [];
$school_data = [];
$error_message = '';
$new_enrollments_count = 0; 
$history_years = []; 

$allowed_center_roles = ['ADM', 'STA'];
$is_admin_or_staff = in_array($user_role, $allowed_center_roles);
$user_department_code = null;
$is_department_user = false;

// [修改] 移除 IMD/FLD 硬編碼，完全依賴 role 判斷
$is_director = ($user_role === 'DI');

// 僅當用戶是老師 (TEA) 或主任 (DI) 時，查詢其所屬部門
// 這裡加上 user_id > 0 的檢查，避免 ID 為 0 時浪費資源查詢
if (($user_role === 'TEA' || $user_role === 'DI') && empty($user_department_code) && $user_id > 0) { 
    try {
        $conn_temp = getDatabaseConnection();
        $found_department = false;

        $debug_log[] = "開始查找科系代碼... (Role: $user_role)";

        // 步驟 1: 如果是主任，優先嘗試從 director 表查找
        if ($is_director) {
            $table_check = $conn_temp->query("SHOW TABLES LIKE 'director'");
            if ($table_check && $table_check->num_rows > 0) {
                $stmt_dept = $conn_temp->prepare("SELECT department FROM director WHERE user_id = ?");
                if ($stmt_dept) {
                    $stmt_dept->bind_param("i", $user_id);
                    $stmt_dept->execute();
                    $result_dept = $stmt_dept->get_result();
                    if ($row = $result_dept->fetch_assoc()) {
                        $raw_dept = $row['department'];
                        $user_department_code = trim($raw_dept);
                        $debug_log[] = "查 director 表: 成功! 代碼='$user_department_code'";
                        if (!empty($user_department_code)) {
                            $found_department = true;
                        }
                    } else {
                        $debug_log[] = "查 director 表: 未找到記錄";
                    }
                    $stmt_dept->close();
                }
            }
        }

        // 步驟 2: 如果尚未找到 (director 表沒查到，或是身分為一般老師)，則查 teacher 表
        if (!$found_department) {
            $stmt_dept = $conn_temp->prepare("SELECT department FROM teacher WHERE user_id = ?");
            if ($stmt_dept) {
                $stmt_dept->bind_param("i", $user_id);
                $stmt_dept->execute();
                $result_dept = $stmt_dept->get_result();
                if ($row = $result_dept->fetch_assoc()) {
                    $raw_dept = $row['department'];
                    $user_department_code = trim($raw_dept);
                    $debug_log[] = "查 teacher 表: 成功! 代碼='$user_department_code'";
                    if (!empty($user_department_code)) {
                        $is_department_user = true; 
                    }
                } else {
                    $debug_log[] = "查 teacher 表: 未找到記錄";
                }
                $stmt_dept->close();
            }
        }

        // 最終檢查並記錄
        if (!empty($user_department_code)) {
             $is_department_user = true;
        } else {
            if ($is_director) {
                $debug_log[] = "警告: 無法找到科系代碼 (DB中無此 User ID 的部門資料)";
                error_log("警告: 主任 (user_id=$user_id) 在 director 和 teacher 表中都找不到科系代碼");
            }
        }

        $conn_temp->close(); 
    } catch (Exception $e) {
        $debug_log[] = "查詢部門時發生錯誤: " . $e->getMessage();
        error_log('Error fetching user department: ' . $e->getMessage());
    }
}

// 判斷是否為特定科系使用者
$is_imd_user = ($user_department_code === 'IM'); 
$is_fld_user = ($user_department_code === 'AF'); 
$is_admission_center = $is_admin_or_staff && !$is_department_user;

// ==========================================
// 處理分頁與歷史資料邏輯
// ==========================================
$view_mode = $_GET['view'] ?? 'active'; 
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : 0;

$current_month = (int)date('m');
$current_year = (int)date('Y');
$grad_threshold_year = ($current_month >= 8) ? $current_year : $current_year - 1;

// 設置頁面標題
if ($is_department_user) {
    $page_title = '系科就讀意願名單 (' . htmlspecialchars($user_department_code ?? '未知') . ')';
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

    // ==========================================
    // [修改] 權限過濾邏輯與參數綁定
    // ==========================================
    $perm_where = " WHERE 1=1 ";
    $bind_params = [];
    $bind_types = "";

    if ($is_director) {
        // [主任權限]：
        if (!empty($user_department_code)) {
            // [修正] 不只看 assigned_department，也要看第一志願 (ec1.department_code)
            $perm_where .= " AND (UPPER(TRIM(ei.assigned_department)) = UPPER(?) OR UPPER(TRIM(ec1.department_code)) = UPPER(?)) ";
            $bind_params[] = trim($user_department_code);
            $bind_params[] = trim($user_department_code);
            $bind_types .= "ss";
            $debug_log[] = "應用主任過濾條件: 分配科系 OR 第一志願 = $user_department_code";
        } else {
            // 是主任但找不到部門代碼 -> 什麼都看不到
            $perm_where .= " AND 1=0 "; 
            $debug_log[] = "應用主任過濾條件: 1=0 (未找到部門代碼)";
        }
    } elseif ($is_department_user) {
        // [一般老師權限]
        $perm_where .= " AND ((ec1.department_code = ? OR ec2.department_code = ? OR ec3.department_code = ?) OR ei.assigned_department = ?) ";
        $bind_params[] = $user_department_code;
        $bind_params[] = $user_department_code;
        $bind_params[] = $user_department_code;
        $bind_params[] = $user_department_code;
        $bind_types .= "ssss";
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
        // Active 模式
        $status_where = " AND (ei.graduation_year > $grad_threshold_year OR ei.graduation_year IS NULL)";
    }
    
    // 組合最終 SQL
    $sql = $base_select . $base_from_join . $perm_where . $status_where . $order_by;
    
    // 記錄 SQL 供診斷
    $debug_sql = $sql;
    $debug_params = $bind_params;

    $stmt = $conn->prepare($sql);
    
    if ($stmt) {
        if (!empty($bind_params)) {
            $stmt->bind_param($bind_types, ...$bind_params);
        }
        
        if (!$stmt->execute()) {
            throw new Exception('執行查詢失敗: ' . $stmt->error);
        }
        
        $result = $stmt->get_result();
        if ($result) {
            $enrollments = $result->fetch_all(MYSQLI_ASSOC);
            $debug_log[] = "查詢成功，找到 " . count($enrollments) . " 筆資料";
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

    // 載入老師列表
    if ($is_department_user) {
        if ($is_director && !empty($user_department_code)) {
            // 主任：找自己系上的老師
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
    $error_message = '資料庫操作失敗: ' . $e->getMessage();
    $debug_log[] = "例外狀況: " . $e->getMessage();
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

        /* Debug Panel Style */
        .debug-panel {
            background: #fffbe6;
            border: 1px solid #ffe58f;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 14px;
            color: #d46b08;
            white-space: pre-wrap;
            word-break: break-all;
        }
        .debug-panel h4 { margin-top: 0; margin-bottom: 10px; color: #fa8c16; }

        /* Tabs Style */
        .tabs-container { margin-bottom: 24px; }
        .tabs-nav { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--border-color); background: var(--card-background-color); border-radius: 8px 8px 0 0; padding: 0 24px; min-height: 56px; }
        .tabs-nav-left { display: flex; }
        .tabs-nav-right { display: flex; align-items: center; margin-left: auto; margin-right: 10px; }
        .tab-item { padding: 16px 24px; cursor: pointer; font-size: 16px; font-weight: 500; color: var(--text-secondary-color); border-bottom: 2px solid transparent; margin-bottom: -2px; transition: all 0.3s; }
        .tab-item:hover { color: var(--primary-color); }
        .tab-item.active { color: var(--primary-color); border-bottom-color: var(--primary-color); }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .history-filter { margin-left: auto; display: flex; align-items: center; gap: 10px; }
        .history-select { padding: 6px 12px; border: 1px solid #d9d9d9; border-radius: 4px; font-size: 14px; }

        .page-controls { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; gap: 16px; }
        .breadcrumb { margin-bottom: 0; font-size: 16px; color: var(--text-secondary-color); }
        .breadcrumb a { color: var(--primary-color); text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }
        
        .card { background: var(--card-background-color); border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.03); border: 1px solid var(--border-color); margin-bottom: 24px; }
        .card-header { padding: 16px 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background: #fafafa; }
        .card-header h3 { font-size: 18px; font-weight: 600; color: var(--text-color); }
        .card-body { padding: 24px; }
        .card-body.table-container { padding: 0; }
        .table-container { overflow-x: auto; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 16px 24px; text-align: left; border-bottom: 1px solid var(--border-color); font-size: 16px; white-space: nowrap; }
        .table th:first-child, .table td:first-child { padding-left: 60px; }
        .table th { background: #fafafa; font-weight: 600; color: #262626; cursor: pointer; user-select: none; position: relative; }
        .table th:hover { background: #f0f0f0; }
        .sort-icon { margin-left: 8px; font-size: 12px; color: #8c8c8c; }
        .sort-icon.active { color: #1890ff; }
        .sort-icon.asc::after { content: "↑"; }
        .sort-icon.desc::after { content: "↓"; }
        .table td { color: #595959; }
        .table tr:hover { background: #fafafa; }
        
        /* Pagination */
        .pagination { padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--border-color); background: #fafafa; }
        .pagination-info { display: flex; align-items: center; gap: 16px; color: var(--text-secondary-color); font-size: 14px; }
        .pagination-controls { display: flex; align-items: center; gap: 8px; }
        .pagination select { padding: 6px 12px; border: 1px solid #d9d9d9; border-radius: 6px; font-size: 14px; background: #fff; cursor: pointer; }
        .pagination select:focus { outline: none; border-color: #1890ff; box-shadow: 0 0 0 2px rgba(24,144,255,0.2); }
        .pagination button { padding: 6px 12px; border: 1px solid #d9d9d9; background: #fff; color: #595959; border-radius: 6px; cursor: pointer; font-size: 14px; transition: all 0.3s; }
        .pagination button:hover:not(:disabled) { border-color: #1890ff; color: #1890ff; }
        .pagination button:disabled { opacity: 0.5; cursor: not-allowed; }
        .pagination button.active { background: #1890ff; color: white; border-color: #1890ff; }

        .detail-row { background: #f9f9f9; }
        .table-row-clickable { cursor: pointer; }
        .btn-view { padding: 4px 12px; border: 1px solid #1890ff; border-radius: 4px; cursor: pointer; font-size: 14px; text-decoration: none; display: inline-block; transition: all 0.3s; background: #fff; color: #1890ff; margin-right: 8px; }
        .btn-view:hover { background: #1890ff; color: white; }
        button.btn-view { font-family: inherit; }
        .search-input { padding: 8px 12px; border: 1px solid #d9d9d9; border-radius: 6px; font-size: 14px; width: 250px; }
        .search-input:focus { outline: none; border-color: #1890ff; box-shadow: 0 0 0 2px rgba(24,144,255,0.2); }
        .empty-state { text-align: center; padding: 40px; color: var(--text-secondary-color); }
        .assign-btn { background: var(--primary-color); color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; transition: all 0.3s; }
        .assign-btn:hover { background: #40a9ff; transform: translateY(-1px); }
        .assign-btn i { margin-right: 4px; }
        
        /* Modal */
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
                </div>

                <?php if (!empty($error_message)): ?>
                <div style="background: #fff2f0; border: 1px solid #ffccc7; border-radius: 8px; padding: 16px; margin-bottom: 24px; color: #cf1322;">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
                </div>
                <?php endif; ?>

                <div class="card">
                    <div class="tabs-container">
                        <div class="tabs-nav">
                            <div class="tabs-nav-left">
                                <div class="tab-item <?php echo $view_mode === 'active' ? 'active' : ''; ?>" onclick="window.location.href='enrollment_list.php?view=active'">
                                    目前學年
                                </div>
                                <div class="tab-item <?php echo $view_mode === 'history' ? 'active' : ''; ?>" onclick="window.location.href='enrollment_list.php?view=history'">
                                    歷史資料
                                </div>
                            </div>
                            <div class="tabs-nav-right">
                                <input type="text" id="searchInput" class="search-input" placeholder="搜尋姓名或電話..." onkeyup="filterTable()">
                                <?php if ($view_mode === 'history'): ?>
                                <form action="enrollment_list.php" method="GET" style="margin:0; display: flex; align-items: center;">
                                    <input type="hidden" name="view" value="history">
                                    <select name="year" class="history-select" onchange="this.form.submit()" style="padding: 6px 12px; border: 1px solid #d9d9d9; border-radius: 6px; font-size: 14px; background: #fff; cursor: pointer;">
                                        <option value="0">顯示全部歷史資料</option>
                                        <?php foreach ($history_years as $year): ?>
                                            <option value="<?php echo $year; ?>" <?php echo $selected_year == $year ? 'selected' : ''; ?>>
                                                <?php echo $year; ?> 年畢業
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="card-body table-container">
                        <?php if (empty($enrollments)): ?>
                            <div class="empty-state">
                                <i class="fas fa-inbox fa-3x" style="margin-bottom: 16px;"></i>
                                <p>
                                    <?php if ($view_mode === 'history'): ?>
                                        查無歷史資料<?php echo $selected_year > 0 ? " (畢業年份: $selected_year)" : ""; ?>。
                                    <?php else: ?>
                                        目前尚無就讀意願資料。
                                        <?php if(empty($user_department_code) && ($is_director || $is_department_user)): ?>
                                            <br><span style="color:red;">(警告：系統無法識別您的科系，請參考上方診斷資訊)</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                        <?php else: ?>
                            <?php
                                $director_active_choices = [];
                                if ($is_department_user && !empty($enrollments)) {
                                    $target_dept_code = $user_department_code;
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
                                                    
                                                    // 檢查學生的意願是否包含該科系
                                                    if (($item['intention1_code'] ?? '') === $target_dept_code) {
                                                        echo $display_text1;
                                                    } elseif (($item['intention2_code'] ?? '') === $target_dept_code) {
                                                        echo $display_text2;
                                                    } elseif (($item['intention3_code'] ?? '') === $target_dept_code) {
                                                        echo $display_text3;
                                                    } else {
                                                        // 如果意願中沒有該科系，但已經分配給該科系，顯示分配狀態
                                                        if (!empty($item['assigned_department']) && trim($item['assigned_department']) === $target_dept_code) {
                                                            echo '<span style="color: #52c41a;"><i class="fas fa-check-circle"></i> 已分配</span>';
                                                        } else {
                                                            echo '<span style="color: #999;">無相關意願</span>';
                                                        }
                                                    }
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
                                            <button type="button" 
                                                class="btn-view" 
                                                id="detail-btn-<?php echo $item['id']; ?>"
                                                onclick="event.stopPropagation(); toggleDetail(<?php echo $item['id']; ?>)">
                                                <i class="fas fa-eye"></i> <span class="btn-text">查看詳情</span>
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
                                            <?php if ($is_assigned_to_me): ?>
                                                <div style="line-height: 1.4;">
                                                    <span style="color: #52c41a; font-weight: bold;">
                                                        <i class="fas fa-check-circle"></i> 已分配 - 自行聯絡
                                                    </span>
                                                </div>
                                            <?php elseif ($is_assigned_to_others): ?>
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
                                            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                                <?php if ($view_mode === 'history'): ?>
                                                    <button type="button" 
                                                        class="btn-view" 
                                                        id="detail-btn-<?php echo $item['id']; ?>"
                                                        onclick="event.stopPropagation(); toggleDetail(<?php echo $item['id']; ?>)">
                                                        <i class="fas fa-eye"></i> <span class="btn-text">查看詳情</span>
                                                    </button>
                                                <?php else: ?>
                                                    <?php if ($is_assigned_to_me): ?>
                                                        <button type="button" 
                                                            class="btn-view" 
                                                            id="detail-btn-<?php echo $item['id']; ?>"
                                                            onclick="event.stopPropagation(); toggleDetail(<?php echo $item['id']; ?>)">
                                                            <i class="fas fa-eye"></i> <span class="btn-text">查看詳情</span>
                                                        </button>
                                                        <button type="button" 
                                                            class="btn-view" 
                                                            style="border-color: #52c41a; color: #52c41a;"
                                                            data-student-id="<?php echo (int)$item['id']; ?>"
                                                            data-student-name="<?php echo htmlspecialchars($item['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                                            data-assigned-teacher-id="<?php echo (int)($item['assigned_teacher_id'] ?? 0); ?>"
                                                            onmouseover="this.style.background='#52c41a'; this.style.color='white';"
                                                            onmouseout="this.style.background='#fff'; this.style.color='#52c41a';"
                                                            onclick="event.stopPropagation(); openContactLogsModal(this.dataset.studentId, this.dataset.studentName, this.dataset.assignedTeacherId)">
                                                            <i class="fas fa-address-book"></i> 填寫聯絡紀錄
                                                        </button>
                                                    <?php elseif ($is_assigned_to_others): ?>
                                                        <button type="button" 
                                                            class="btn-view" 
                                                            id="detail-btn-<?php echo $item['id']; ?>"
                                                            onclick="event.stopPropagation(); toggleDetail(<?php echo $item['id']; ?>)">
                                                            <i class="fas fa-eye"></i> <span class="btn-text">查看詳情</span>
                                                        </button>
                                                    <?php else: ?>
                                                        <button type="button" 
                                                            class="btn-view" 
                                                            style="border-color: #fa8c16; color: #fa8c16;"
                                                            data-student-id="<?php echo $item['id']; ?>"
                                                            data-student-name="<?php echo htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                            data-current-teacher-id="<?php echo $item['assigned_teacher_id']; ?>"
                                                            data-chosen-codes="<?php echo htmlspecialchars($chosen_codes_json, ENT_QUOTES, 'UTF-8'); ?>"
                                                            onmouseover="this.style.background='#fa8c16'; this.style.color='white';"
                                                            onmouseout="this.style.background='#fff'; this.style.color='#fa8c16';"
                                                            onclick="event.stopPropagation(); openAssignModalFromButton(this)">
                                                            <i class="fas fa-user-plus"></i> 分配
                                                        </button>
                                                        <button type="button" 
                                                            class="btn-view" 
                                                            id="detail-btn-<?php echo $item['id']; ?>"
                                                            onclick="event.stopPropagation(); toggleDetail(<?php echo $item['id']; ?>)">
                                                            <i class="fas fa-eye"></i> <span class="btn-text">查看詳情</span>
                                                        </button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                    <tr id="detail-<?php echo $item['id']; ?>" class="detail-row" style="display: none;">
                                        <td colspan="<?php echo $is_admission_center ? '8' : ($is_department_user ? '6' : '8'); ?>" style="padding: 20px; background: #f9f9f9; border: 2px solid #b3d9ff; border-radius: 4px;">
                                            <div id="detail-content-<?php echo $item['id']; ?>" style="text-align: center; padding: 20px;">
                                                <i class="fas fa-spinner fa-spin"></i> 載入中...
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

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
                                    <span id="pageInfo">顯示第 <span id="currentRange">1-10</span> 筆，共 <?php echo count($enrollments); ?> 筆</span>
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

    <div id="assignModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>分配學生</h3>
                <span class="close" onclick="closeAssignModal()">&times;</span>
            </div>
            <div class="modal-body">
                <p id="assignStudentName" style="margin-bottom: 16px; font-weight: bold;"></p>
                <div id="assignStatusInfo" style="margin-bottom: 12px; padding: 8px; background: #f0f0f0; border-radius: 4px; font-size: 13px; color: #666; display: none;"></div>
                <div id="teacherList">
                    <?php if (empty($teachers)): ?>
                        <p style="color: #999;">目前無可分配的老師。</p>
                    <?php else: ?>
                        <?php if ($is_director): ?>
                            <label class="teacher-option" id="selfContactOption" style="border: 2px solid #1890ff; background: #e6f7ff;">
                                <input type="radio" name="selected_teacher" value="0" id="selfContactRadio">
                                <span style="color: #1890ff; font-weight: bold;">
                                    <i class="fas fa-user"></i> 自行聯絡（主任自行處理）
                                </span>
                            </label>
                        <?php endif; ?>
                        
                        <?php foreach ($teachers as $teacher): ?>
                            <label class="teacher-option">
                                <input type="radio" name="selected_teacher" value="<?php echo $teacher['id']; ?>">
                            <?php echo htmlspecialchars(!empty($teacher['name']) ? $teacher['name'] : $teacher['username']); ?>
                        <?php if($teacher['role'] === 'DI') echo ' (主任)'; ?>
                            </label>
<?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-cancel" onclick="closeAssignModal()">取消</button>
                <button class="btn-confirm" onclick="confirmAssign()">確定分配</button>
            </div>
        </div>
    </div>

    <div id="contactLogsModal" class="modal contact-log-modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>聯絡紀錄 - <span id="contactLogStudentName"></span></h3>
                <span class="close" onclick="closeContactLogsModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div id="addLogSection" style="margin-bottom: 20px; background: #f9f9f9; padding: 16px; border-radius: 8px; display: none;">
                    <h4 style="margin-bottom: 12px;">新增紀錄</h4>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 12px;">
                        <div>
                            <label style="display:block; font-size: 13px; color:#666; margin-bottom:6px; font-weight:600;">聯絡日期</label>
                            <input type="date" id="newLogDate" class="form-control" style="width: 100%;">
                        </div>
                        <div>
                            <label style="display:block; font-size: 13px; color:#666; margin-bottom:6px; font-weight:600;">聯絡方式</label>
                            <select id="newLogMethod" class="form-control" style="width: 100%;">
                                <option value="電話">電話</option>
                                <option value="Line">Line</option>
                                <option value="Email">Email</option>
                                <option value="面談">面談</option>
                                <option value="其他">其他</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label style="display:block; font-size: 13px; color:#666; margin-bottom:6px; font-weight:600;">聯絡紀錄</label>
                        <textarea id="newLogContent" class="form-control" rows="4" placeholder="請輸入聯絡內容和結果..."></textarea>
                    </div>
                    <div style="margin-top: 12px; text-align: right;">
                        <button class="assign-btn" onclick="submitContactLog()">
                            <i class="fas fa-paper-plane"></i> 新增
                        </button>
                    </div>
                </div>
                <div id="contactLogsList">
                    </div>
            </div>
        </div>
    </div>

    <script>
        // JS logic remains largely the same, ensuring variables are defined
        let currentStudentId = null;
        
        function sortTable(column) {
            const urlParams = new URLSearchParams(window.location.search);
            let currentSortBy = urlParams.get('sort_by') || 'created_at';
            let currentSortOrder = urlParams.get('sort_order') || 'desc';
            
            let newSortOrder = 'asc';
            if (currentSortBy === column && currentSortOrder === 'asc') {
                newSortOrder = 'desc';
            }
            
            urlParams.set('sort_by', column);
            urlParams.set('sort_order', newSortOrder);
            window.location.search = urlParams.toString();
        }

        // Apply sort icons
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const sortBy = urlParams.get('sort_by') || 'created_at';
            const sortOrder = urlParams.get('sort_order') || 'desc';
            
            const icon = document.getElementById('sort-' + sortBy);
            if (icon) {
                icon.classList.add('active');
                icon.classList.add(sortOrder);
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
            const table = document.getElementById('enrollmentTable');
            if (!table) return;
            
            const tbody = table.getElementsByTagName('tbody')[0];
            if (!tbody) return;
            
            // 只選取主列（有 table-row-clickable 類別的列）
            allRows = Array.from(tbody.querySelectorAll('tr.table-row-clickable'));
            filteredRows = allRows;
            
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
            const totalPages = itemsPerPage === 'all' ? 1 : Math.ceil(totalItems / itemsPerPage);
            
            // 首先隱藏所有主列和關聯的詳情列
            allRows.forEach(row => {
                row.style.display = 'none';
                // 同時確保關聯的詳情列也被隱藏
                const nextRow = row.nextElementSibling;
                if (nextRow && nextRow.classList.contains('detail-row')) {
                    nextRow.style.display = 'none';
                    // 重置按鈕狀態
                    const detailBtn = row.querySelector('.btn-view[id^="detail-btn-"]');
                    if (detailBtn) {
                        const btnText = detailBtn.querySelector('.btn-text');
                        if (btnText) btnText.textContent = '查看詳情';
                        const icon = detailBtn.querySelector('i');
                        if (icon) icon.className = 'fas fa-eye';
                    }
                }
            });
            // 重置當前打開的詳情 ID
            currentOpenDetailId = null;
            
            if (itemsPerPage === 'all' || itemsPerPage >= totalItems) {
                // 顯示所有過濾後的行
                filteredRows.forEach(row => row.style.display = '');
                
                // 更新分頁資訊
                const rangeElem = document.getElementById('currentRange');
                if (rangeElem) rangeElem.textContent = totalItems > 0 ? `1-${totalItems}` : '0-0';
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
                const rangeElem = document.getElementById('currentRange');
                if (rangeElem) rangeElem.textContent = totalItems > 0 ? `${start + 1}-${end}` : '0-0';
            }
            
            // 更新總數
            const pageInfo = document.getElementById('pageInfo');
            if (pageInfo) {
                const rangeText = document.getElementById('currentRange') ? document.getElementById('currentRange').textContent : '0-0';
                pageInfo.innerHTML = `顯示第 <span id="currentRange">${rangeText}</span> 筆，共 ${totalItems} 筆`;
            }
            
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
            
            if (totalPages >= 1) {
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
        
        // 表格搜尋功能
        function filterTable() {
            const input = document.getElementById('searchInput');
            if (!input) return;
            const filter = input.value.toLowerCase();
            
            // 使用 allRows 進行過濾
            filteredRows = allRows.filter(row => {
                const text = row.textContent.toLowerCase();
                return text.includes(filter);
            });
            
            currentPage = 1;
            updatePagination();
        }

        function openAssignModalFromButton(btn) {
            const studentId = btn.dataset.studentId;
            const studentName = btn.dataset.studentName;
            const currentTeacherId = btn.dataset.currentTeacherId || '';
            const isDirector = <?php echo isset($is_director) && $is_director ? 'true' : 'false'; ?>;
            const currentUserId = <?php echo isset($user_id) && is_numeric($user_id) ? (int)$user_id : 0; ?>;
            
            currentStudentId = studentId;
            document.getElementById('assignStudentName').textContent = '學生：' + studentName;
            
            // 顯示分配狀態資訊
            const statusInfo = document.getElementById('assignStatusInfo');
            const selfContactOption = document.getElementById('selfContactOption');
            const selfContactRadio = document.getElementById('selfContactRadio');
            
            if (currentTeacherId && currentTeacherId != '0') {
                // 已分配給老師
                statusInfo.style.display = 'block';
                statusInfo.innerHTML = '<i class="fas fa-info-circle"></i> 該學生已分配給老師，不能改回自行聯絡';
                statusInfo.style.background = '#fff7e6';
                statusInfo.style.color = '#d46b08';
                
                // 如果是主任且已分配給其他老師，禁用自行聯絡選項
                if (isDirector && selfContactOption && currentTeacherId != currentUserId) {
                    selfContactOption.style.opacity = '0.5';
                    selfContactOption.style.pointerEvents = 'none';
                    if (selfContactRadio) {
                        selfContactRadio.disabled = true;
                    }
                } else if (isDirector && selfContactOption) {
                    selfContactOption.style.opacity = '1';
                    selfContactOption.style.pointerEvents = 'auto';
                    if (selfContactRadio) {
                        selfContactRadio.disabled = false;
                    }
                }
            } else {
                // 未分配或自行聯絡
                statusInfo.style.display = 'none';
                if (isDirector && selfContactOption) {
                    selfContactOption.style.opacity = '1';
                    selfContactOption.style.pointerEvents = 'auto';
                    if (selfContactRadio) {
                        selfContactRadio.disabled = false;
                    }
                }
            }
            
            // Select current teacher
            const radios = document.getElementsByName('selected_teacher');
            radios.forEach(radio => {
                if (radio.value == currentTeacherId) {
                    radio.checked = true;
                } else {
                    radio.checked = false;
                }
            });
            
            // 如果當前是自行聯絡（currentTeacherId 為空或 0），且是主任，選中自行聯絡
            if (isDirector && (!currentTeacherId || currentTeacherId == '0') && selfContactRadio) {
                selfContactRadio.checked = true;
            }
            
            document.getElementById('assignModal').style.display = 'flex';
        }

        function closeAssignModal() {
            document.getElementById('assignModal').style.display = 'none';
        }

        function confirmAssign() {
            const selectedTeacher = document.querySelector('input[name="selected_teacher"]:checked');
            if (!selectedTeacher) {
                alert('請選擇一位老師或自行聯絡');
                return;
            }
            
            const teacherId = selectedTeacher.value;
            const isDirector = <?php echo isset($is_director) && $is_director ? 'true' : 'false'; ?>;
            
            // 如果是主任選擇自行聯絡（teacherId = 0），後端會自動設置為主任自己的ID
            const formData = new FormData();
            formData.append('student_id', currentStudentId);
            formData.append('teacher_id', teacherId); // 0 表示主任自行聯絡
            
            fetch('assign_student.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const message = teacherId == '0' && isDirector ? '已設置為自行聯絡' : '分配成功';
                    alert(message);
                    location.reload();
                } else {
                    alert('分配失敗: ' + (data.message || '未知錯誤'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('發生錯誤，請稍後再試');
            });
        }

        // Toggle Detail Row (like admission_recommend_list.php)
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
            
            // 如果詳情內容還沒有載入，則載入
            const detailContent = document.getElementById('detail-content-' + id);
            if (detailContent && detailContent.innerHTML.includes('載入中')) {
                loadDetailContent(id);
            }
        }
        
        function loadDetailContent(id) {
            const detailContent = document.getElementById('detail-content-' + id);
            if (!detailContent) return;
            
            // 從 API 獲取詳細資料
            fetch(`get_student_details.php?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayDetailContent(id, data);
                    } else {
                        const errorMsg = escapeHtml(data.message || '未知錯誤');
                        detailContent.innerHTML = '<p style="color:red; text-align:center;">載入失敗: ' + errorMsg + '</p>';
                    }
                })
                .catch(error => {
                    console.error('Error loading student details:', error);
                    detailContent.innerHTML = '<p style="color:red; text-align:center;">載入失敗，請稍後再試</p>';
                });
        }
        
        function displayDetailContent(id, data) {
            const student = data.student;
            const choices = data.choices || [];
            const contactLogsCount = data.contact_logs_count || 0;
            
            const detailContent = document.getElementById('detail-content-' + id);
            if (!detailContent) return;
            
            // 格式化日期
            const formatDate = (dateStr) => {
                if (!dateStr) return '未提供';
                const date = new Date(dateStr);
                return date.toLocaleString('zh-TW', { 
                    year: 'numeric', 
                    month: '2-digit', 
                    day: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            };
            
            // 轉義 HTML 函數
            const escapeHtml = (text) => {
                if (!text) return '未提供';
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            };
            
            // 構建意願選項 HTML
            let choicesHtml = '';
            if (choices.length > 0) {
                choices.forEach(choice => {
                    const deptName = escapeHtml(choice.department_name || choice.department_code || '未指定');
                    const systemName = escapeHtml(choice.system_name || choice.system_code || '');
                    const systemDisplay = systemName ? ' (' + systemName + ')' : '';
                    choicesHtml += '<tr><td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5; width: 120px;">意願 ' + choice.choice_order + '</td><td style="padding: 5px; border: 1px solid #ddd;">' + deptName + systemDisplay + '</td></tr>';
                });
            } else {
                choicesHtml = '<tr><td colspan="2" style="padding: 5px; border: 1px solid #ddd; color: #999;">無意願資料</td></tr>';
            }
            
            // 構建完整 HTML（使用表格格式，類似 admission_recommend_list.php）
            const html = '<table style="width: 100%; border-collapse: collapse;"><tr><td style="width: 50%; vertical-align: top; padding-right: 20px;"><h4 style="margin: 0 0 10px 0; font-size: 16px;">基本資料</h4><table style="width: 100%; border-collapse: collapse; font-size: 14px;"><tr><td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5; width: 120px;">姓名</td><td style="padding: 5px; border: 1px solid #ddd;">' + escapeHtml(student.name) + '</td></tr><tr><td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">身分別</td><td style="padding: 5px; border: 1px solid #ddd;">' + escapeHtml(student.identity_text) + '</td></tr><tr><td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">性別</td><td style="padding: 5px; border: 1px solid #ddd;">' + escapeHtml(student.gender_text) + '</td></tr><tr><td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">電話1</td><td style="padding: 5px; border: 1px solid #ddd;">' + escapeHtml(student.phone1) + '</td></tr><tr><td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">電話2</td><td style="padding: 5px; border: 1px solid #ddd;">' + escapeHtml(student.phone2) + '</td></tr><tr><td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">Email</td><td style="padding: 5px; border: 1px solid #ddd;">' + escapeHtml(student.email) + '</td></tr><tr><td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">Line ID</td><td style="padding: 5px; border: 1px solid #ddd;">' + escapeHtml(student.line_id) + '</td></tr><tr><td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">Facebook</td><td style="padding: 5px; border: 1px solid #ddd;">' + escapeHtml(student.facebook) + '</td></tr><tr><td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">就讀國中</td><td style="padding: 5px; border: 1px solid #ddd;">' + escapeHtml(student.junior_high_name || student.junior_high) + '</td></tr><tr><td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">目前年級</td><td style="padding: 5px; border: 1px solid #ddd;">' + escapeHtml(student.current_grade_name || student.current_grade) + '</td></tr><tr><td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">畢業年份</td><td style="padding: 5px; border: 1px solid #ddd;">' + (student.graduation_year || '未提供') + '</td></tr><tr><td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">建立時間</td><td style="padding: 5px; border: 1px solid #ddd;">' + formatDate(student.created_at) + '</td></tr>' + (student.remarks ? '<tr><td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">備註</td><td style="padding: 5px; border: 1px solid #ddd; white-space: pre-wrap;">' + escapeHtml(student.remarks) + '</td></tr>' : '') + '</table></td><td style="width: 50%; vertical-align: top; padding-left: 20px;"><h4 style="margin: 0 0 10px 0; font-size: 16px;">就讀意願</h4><table style="width: 100%; border-collapse: collapse; font-size: 14px;">' + choicesHtml + '</table><h4 style="margin: 10px 0 10px 0; font-size: 16px;">分配資訊</h4><table style="width: 100%; border-collapse: collapse; font-size: 14px;"><tr><td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5; width: 120px;">分配科系</td><td style="padding: 5px; border: 1px solid #ddd;">' + escapeHtml(student.assigned_department_name || student.assigned_department || '尚未分配') + '</td></tr><tr><td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">負責老師</td><td style="padding: 5px; border: 1px solid #ddd;">' + escapeHtml(student.assigned_teacher_name || student.teacher_name || student.teacher_username || '尚未指派') + '</td></tr>' + (student.recommended_teacher_name ? '<tr><td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">推薦老師</td><td style="padding: 5px; border: 1px solid #ddd;">' + escapeHtml(student.recommended_teacher_name) + '</td></tr>' : '') + '</table><h4 style="margin: 10px 0 10px 0; font-size: 16px;">聯絡紀錄</h4><p>共有 <strong>' + contactLogsCount + '</strong> 筆聯絡紀錄</p><button class="btn-view" style="margin-top: 8px;" data-student-id="' + (parseInt(student.id) || 0) + '" data-student-name="' + escapeHtml(String(student.name || '')) + '" data-assigned-teacher-id="' + (parseInt(student.assigned_teacher_id || 0) || 0) + '" onclick="const btn = this; openContactLogsModal(parseInt(btn.dataset.studentId) || 0, btn.dataset.studentName || \'\', btn.dataset.assignedTeacherId || \'0\')"><i class="fas fa-eye"></i> 查看聯絡紀錄</button></td></tr></table>';
            
            detailContent.innerHTML = html;
        }
        
        // 轉義 HTML 函數（供其他函數使用）
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Contact Logs functions
        function openContactLogsModal(studentId, studentName, assignedTeacherId) {
            console.log('openContactLogsModal called:', studentId, studentName, assignedTeacherId); // 調試用
            // 確保 studentId 是數字
            const id = parseInt(studentId) || 0;
            if (!id) {
                console.error('openContactLogsModal: studentId is missing or invalid:', studentId);
                alert('錯誤：無法取得學生ID');
                return;
            }
            currentStudentId = id;
            const nameElement = document.getElementById('contactLogStudentName');
            const modalElement = document.getElementById('contactLogsModal');
            if (!nameElement || !modalElement) {
                console.error('openContactLogsModal: Modal elements not found');
                alert('錯誤：找不到聯絡紀錄視窗元素');
                return;
            }
            nameElement.textContent = studentName || '未知';
            modalElement.style.display = 'flex';
            
            // 檢查是否顯示新增記錄區塊
            const isAdmissionCenter = <?php echo isset($is_admission_center) && $is_admission_center ? 'true' : 'false'; ?>;
            const isDirector = <?php echo isset($is_director) && $is_director ? 'true' : 'false'; ?>;
            const currentUserId = <?php echo isset($user_id) && is_numeric($user_id) ? (int)$user_id : 0; ?>;
            const addLogSection = document.getElementById('addLogSection');
            
            // 招生中心不能寫記錄
            if (isAdmissionCenter) {
                addLogSection.style.display = 'none';
            } else if (isDirector) {
                // 主任：檢查學生是否已分配給其他老師
                const assignedTeacherIdInt = parseInt(assignedTeacherId) || 0;
                // 如果已分配給其他老師（assigned_teacher_id 不為空且不等於主任自己的ID），則不能寫記錄
                if (assignedTeacherIdInt > 0 && assignedTeacherIdInt !== currentUserId) {
                    // 已分配給其他老師，主任只能查看
                    addLogSection.style.display = 'none';
                    console.log('Director cannot write log: student assigned to teacher', assignedTeacherIdInt);
                } else {
                    // 未分配或分配給主任自己（自行聯絡），可以寫記錄
                    addLogSection.style.display = 'block';
                    // 設置預設值
                    const today = new Date().toISOString().split('T')[0];
                    document.getElementById('newLogDate').value = today;
                    document.getElementById('newLogMethod').value = '電話';
                    document.getElementById('newLogContent').value = '';
                }
            } else {
                // 老師可以寫記錄（但需要檢查分配狀態，由後端API驗證）
                addLogSection.style.display = 'block';
                // 設置預設值
                const today = new Date().toISOString().split('T')[0];
                document.getElementById('newLogDate').value = today;
                document.getElementById('newLogMethod').value = '電話';
                document.getElementById('newLogContent').value = '';
            }
            
            loadContactLogs(studentId);
        }

        function closeContactLogsModal() {
            document.getElementById('contactLogsModal').style.display = 'none';
        }
        
        // 轉義 HTML 函數（供 loadContactLogs 使用）
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function loadContactLogs(studentId) {
            const list = document.getElementById('contactLogsList');
            if (!list) {
                console.error('contactLogsList element not found');
                return;
            }
            list.innerHTML = '<p style="text-align:center;">載入中...</p>';
            
            // 使用新的 API 端點，支援顯示分配資訊
            const apiUrl = '../../Topics-frontend/frontend/api/contact_logs_api.php?enrollment_id=' + encodeURIComponent(studentId);
            fetch(apiUrl)
            .then(res => {
                if (!res.ok) {
                    return res.text().then(text => {
                        try {
                            const data = JSON.parse(text);
                            throw new Error(data.message || 'HTTP error! status: ' + res.status);
                        } catch (e) {
                            if (e instanceof Error && e.message.includes('HTTP error')) {
                                throw e;
                            }
                            throw new Error('HTTP error! status: ' + res.status + ', response: ' + text.substring(0, 100));
                        }
                    });
                }
                return res.json();
            })
            .then(data => {
                if (data.success && data.logs && data.logs.length > 0) {
                    list.innerHTML = data.logs.map(log => {
                        const method = log.method || log.contact_method || '其他';
                        const notes = log.notes || log.result || '';
                        const contactDate = log.contact_date || '';
                        const createdAt = log.created_at || '';
                        
                        // 日期部分：从 contact_date 提取
                        let datePart = '';
                        if (contactDate) {
                            // 处理格式：YYYY-MM-DD HH:MM:SS 或 YYYY-MM-DD
                            const dateMatch = contactDate.match(/^(\d{4}-\d{2}-\d{2})/);
                            if (dateMatch) {
                                datePart = dateMatch[1];
                            } else {
                                datePart = contactDate;
                            }
                        }
                        
                        // 时间部分：从 created_at 提取
                        let timePart = '';
                        if (createdAt) {
                            // 处理格式：YYYY-MM-DD HH:MM:SS
                            const timeMatch = createdAt.match(/\s+(\d{2}:\d{2})(?::\d{2})?/);
                            if (timeMatch) {
                                timePart = timeMatch[1];
                            }
                        }
                        
                        const escapedDate = escapeHtml(datePart || '未提供');
                        const escapedTime = escapeHtml(timePart || '未提供');
                        const escapedMethod = escapeHtml(method);
                        const escapedNotes = escapeHtml(notes);
                        
                        return '<div class="contact-log-item">' +
                            '<div class="contact-log-header" style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">' +
                                '<div style="flex: 1;">' +
                                    '<div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">' +
                                        '<div style="color:#333; font-size:14px; font-weight:600;">' +
                                            '<i class="fas fa-calendar-alt" style="color:#1890ff; margin-right: 4px;"></i>' + escapedDate +
                                        '</div>' +
                                        '<div style="color:#666; font-size:14px;">' +
                                            '<i class="fas fa-clock" style="color:#52c41a; margin-right: 4px;"></i>' + escapedTime +
                                        '</div>' +
                                        '<div style="background: #e3f2fd; color: #1976d2; padding: 4px 12px; border-radius: 4px; font-size: 13px; font-weight:600;">' +
                                            '<i class="fas fa-phone" style="margin-right: 4px;"></i>' + escapedMethod +
                                        '</div>' +
                                    '</div>' +
                                '</div>' +
                            '</div>' +
                            '<div style="white-space: pre-wrap; padding-top: 12px; border-top: 1px solid #e8e8e8; color:#333; line-height:1.6; font-size:14px;">' +
                                '<div style="color:#666; font-size:12px; margin-bottom: 6px; font-weight:600;">紀錄內容：</div>' +
                                (escapedNotes ? escapedNotes : '<span style="color:#999;">無紀錄內容</span>') +
                            '</div>' +
                        '</div>';
                    }).join('');
                } else {
                    list.innerHTML = '<p style="text-align:center; color:#999;">尚無聯絡紀錄</p>';
                }
            })
            .catch(err => {
                console.error('Error loading contact logs:', err);
                list.innerHTML = '<p style="color:red; text-align:center;">載入失敗: ' + escapeHtml(err.message || '未知錯誤') + '</p>';
            });
        }

        function submitContactLog() {
            const content = document.getElementById('newLogContent').value.trim();
            const contactDate = document.getElementById('newLogDate').value;
            const contactMethod = document.getElementById('newLogMethod').value;
            
            if (!content) {
                alert('請輸入聯絡內容');
                return;
            }
            
            if (!contactDate) {
                alert('請選擇聯絡日期');
                return;
            }
            
            if (!contactMethod) {
                alert('請選擇聯絡方式');
                return;
            }
            
            const formData = new FormData();
            formData.append('enrollment_id', currentStudentId);
            formData.append('notes', content);
            formData.append('method', contactMethod);
            formData.append('contact_date', contactDate);
            
            // 使用新的 API 端點
            fetch('../../Topics-frontend/frontend/api/contact_logs_api.php', {
                method: 'POST',
                body: formData
            })
            .then(res => {
                if (!res.ok) {
                    return res.text().then(text => {
                        try {
                            const data = JSON.parse(text);
                            throw new Error(data.message || 'HTTP error! status: ' + res.status);
                        } catch (e) {
                            if (e instanceof Error && e.message) {
                                throw e;
                            }
                            throw new Error('HTTP error! status: ' + res.status);
                        }
                    });
                }
                return res.json();
            })
            .then(data => {
                if (data.success) {
                    alert('聯絡紀錄已新增');
                    // 清空表單
                    const contentField = document.getElementById('newLogContent');
                    const dateField = document.getElementById('newLogDate');
                    const methodField = document.getElementById('newLogMethod');
                    if (contentField) contentField.value = '';
                    if (dateField) {
                        const today = new Date().toISOString().split('T')[0];
                        dateField.value = today;
                    }
                    if (methodField) methodField.value = '電話';
                    // 重新載入聯絡記錄
                    if (currentStudentId) {
                        loadContactLogs(currentStudentId);
                    }
                } else {
                    alert(data.message || '新增失敗');
                }
            })
            .catch(err => {
                console.error('Error submitting contact log:', err);
                alert('提交失敗: ' + (err.message || '未知錯誤'));
            });
        }


        // Click outside closes modals
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = "none";
            }
        }
        
    </script>
</body>
</html>