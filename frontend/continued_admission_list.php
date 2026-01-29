<?php
require_once __DIR__ . '/session_config.php';

// 檢查登入狀態和角色權限
checkBackendLogin();

// 引入資料庫設定
require_once '../../Topics-frontend/frontend/config.php';
require_once __DIR__ . '/includes/continued_admission_ranking.php';

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
// 判斷是否為一般老師（支援 'TE', 'TEA', '老師'）
$is_teacher = ($user_role === 'TE' || $user_role === 'TEA' || $user_role === '老師');
$user_department_code = null;
$is_department_user = false;

// 如果是主任或老師，獲取其科系代碼
if (($is_director || $is_teacher) && $user_id > 0) {
    try {
        $conn_temp = getDatabaseConnection();
        if ($is_director) {
            // 主任從 director 表查詢
            $table_check = $conn_temp->query("SHOW TABLES LIKE 'director'");
            if ($table_check && $table_check->num_rows > 0) {
                $stmt_dept = $conn_temp->prepare("SELECT department FROM director WHERE user_id = ?");
            } else {
                // 如果沒有 director 表，從 teacher 表查詢（向後兼容）
                $stmt_dept = $conn_temp->prepare("SELECT department FROM teacher WHERE user_id = ?");
            }
        } else {
            // 老師直接從 teacher 表查詢
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

// 主任可以分配給老師
$can_assign = $is_director && !empty($user_department_code);
// 老師可以評分被分配的學生
$can_score = $is_teacher && !empty($user_id);

// 建立資料庫連接
try {
    $conn = getDatabaseConnection();
} catch (Exception $e) {
    die("資料庫連接失敗: " . $e->getMessage());
}

// 排序參數
$sortBy = $_GET['sort_by'] ?? 'created_at';
$sortOrder = $_GET['sort_order'] ?? 'desc';

// 顯示模式：active 或 history（history 顯示所有非今年的資料）
$view_mode = $_GET['view'] ?? 'active';

// 年份篩選：預設為報名編號 apply_no 的前四碼等於當年
$current_year = (int)date('Y');
// 在 active 模式下，強制使用今年，忽略 URL 參數
// 在 history 模式下，使用 URL 參數，但預設為去年（如果沒有提供）
if ($view_mode === 'active') {
    $filter_year = $current_year; // 目前名單只能顯示今年
} else {
    // 歷史資料：如果有提供年份參數且不是今年，使用該年份；否則使用去年
    $requested_year = isset($_GET['year']) ? (int)$_GET['year'] : ($current_year - 1);
    // 如果選擇的是今年，改為去年
    if ($requested_year === $current_year) {
        $filter_year = $current_year - 1;
    } else {
        $filter_year = $requested_year;
    }
}

// 如果是歷史檢視，更新標題提示
if ($view_mode === 'history') {
    $page_title .= ' (歷史資料)';
}

// 驗證排序參數，防止 SQL 注入
$allowed_columns = ['id', 'apply_no', 'name', 'school', 'status', 'created_at'];
if (!in_array($sortBy, $allowed_columns)) {
    $sortBy = 'created_at';
}
if (!in_array(strtolower($sortOrder), ['asc', 'desc'])) {
    $sortOrder = 'desc';
}

// 檢查 assigned_department 字段是否存在
$has_assigned_department = false;
$column_check = $conn->query("SHOW COLUMNS FROM continued_admission LIKE 'assigned_department'");
if ($column_check && $column_check->num_rows > 0) {
    $has_assigned_department = true;
} else {
    // 字段不存在，嘗試添加
    try {
        $conn->query("ALTER TABLE continued_admission ADD COLUMN assigned_department VARCHAR(50) DEFAULT NULL");
        $has_assigned_department = true;
    } catch (Exception $e) {
        error_log("添加 assigned_department 字段失敗: " . $e->getMessage());
    }
}

// 獲取續招報名資料（根據用戶權限過濾）
$assigned_dept_field = $has_assigned_department ? "ca.assigned_department" : "NULL as assigned_department";

// 根據 view_mode 設定狀態與年份過濾條件
$status_condition = '';
if ($view_mode === 'history') {
    // 歷史資料：顯示指定年份的資料（不管錄取狀態），apply_no 前四碼 = filter_year，且 filter_year 不能是今年
    // 注意：filter_year 在 history 模式下已經確保不是今年
    $status_condition = " AND LEFT(ca.apply_no, 4) = ? ";
} else {
    // 目前名單：只看非錄取，且 apply_no 前四碼 = 今年（強制使用 current_year）
    $status_condition = " AND (ca.status IS NULL OR (ca.status <> 'approved' AND ca.status <> 'AP')) AND LEFT(ca.apply_no, 4) = ? ";
}

// 檢查是否使用正規化分配表
$has_normalized_assignments = false;
$table_check = $conn->query("SHOW TABLES LIKE 'continued_admission_assignments'");
if ($table_check && $table_check->num_rows > 0) {
    $has_normalized_assignments = true;
}

if ($is_teacher && !empty($user_id) && !empty($user_department_code)) {
    // 老師只能看到被分配給他的學生，且該學生的 assigned_department 必須等於老師的科系代碼
    // 並且 reviewer_type 必須是 'teacher'（排除主任）
    if ($has_normalized_assignments) {
        if ($has_assigned_department) {
            // 有 assigned_department 欄位：檢查分配給老師且 reviewer_type = 'teacher'
            // 科系匹配會在 PHP 層再次檢查，這裡先不過濾 assigned_department，確保能看到被分配的學生
            $stmt = $conn->prepare("SELECT DISTINCT ca.id, ca.apply_no, ca.name, ca.school, ca.status, ca.created_at, $assigned_dept_field, sd.name as school_name 
                          FROM continued_admission ca
                          LEFT JOIN school_data sd ON ca.school = sd.school_code
                          INNER JOIN continued_admission_assignments caa ON ca.id = caa.application_id
                          WHERE caa.reviewer_user_id = ? 
                          AND caa.reviewer_type = 'teacher' " . $status_condition . "
                          ORDER BY ca.$sortBy $sortOrder");
            // active 模式用 current_year（今年），history 模式用 filter_year（已確保不是今年）
            $year_param = $view_mode === 'active' ? $current_year : $filter_year;
            $stmt->bind_param("ii", $user_id, $year_param);
        } else {
            // 沒有 assigned_department 欄位：只檢查分配給老師且 reviewer_type = 'teacher'（向後兼容）
            $stmt = $conn->prepare("SELECT DISTINCT ca.id, ca.apply_no, ca.name, ca.school, ca.status, ca.created_at, $assigned_dept_field, sd.name as school_name 
                          FROM continued_admission ca
                          LEFT JOIN school_data sd ON ca.school = sd.school_code
                          INNER JOIN continued_admission_assignments caa ON ca.id = caa.application_id
                          WHERE caa.reviewer_user_id = ? 
                          AND caa.reviewer_type = 'teacher' " . $status_condition . "
                          ORDER BY ca.$sortBy $sortOrder");
            // active 模式用 current_year（今年），history 模式用 filter_year（已確保不是今年）
            $year_param = $view_mode === 'active' ? $current_year : $filter_year;
            $stmt->bind_param("ii", $user_id, $year_param);
        }
    } else {
        // 如果沒有正規化表，嘗試使用舊的欄位
        $has_assigned_teacher = false;
        $teacher_column_check = $conn->query("SHOW COLUMNS FROM continued_admission LIKE 'assigned_teacher_1_id'");
        if ($teacher_column_check && $teacher_column_check->num_rows > 0) {
            $has_assigned_teacher = true;
        }
        
        if ($has_assigned_teacher) {
            if ($has_assigned_department) {
                // 有 assigned_department 欄位：檢查分配給老師
                // 科系匹配會在 PHP 層再次檢查，這裡先不過濾 assigned_department
                $stmt = $conn->prepare("SELECT ca.id, ca.apply_no, ca.name, ca.school, ca.status, ca.created_at, $assigned_dept_field, sd.name as school_name 
                              FROM continued_admission ca
                              LEFT JOIN school_data sd ON ca.school = sd.school_code
                              WHERE (ca.assigned_teacher_1_id = ? OR ca.assigned_teacher_2_id = ?) " . $status_condition . "
                              ORDER BY ca.$sortBy $sortOrder");
                $year_param = $view_mode === 'active' ? $current_year : $filter_year;
                $stmt->bind_param("iii", $user_id, $user_id, $year_param);
            } else {
                // 沒有 assigned_department 欄位：只檢查分配給老師（向後兼容，但理論上不應該有這種情況）
                $stmt = $conn->prepare("SELECT ca.id, ca.apply_no, ca.name, ca.school, ca.status, ca.created_at, $assigned_dept_field, sd.name as school_name 
                              FROM continued_admission ca
                              LEFT JOIN school_data sd ON ca.school = sd.school_code
                              WHERE (ca.assigned_teacher_1_id = ? OR ca.assigned_teacher_2_id = ?) " . $status_condition . "
                              ORDER BY ca.$sortBy $sortOrder");
                $year_param = $view_mode === 'active' ? $current_year : $filter_year;
                $stmt->bind_param("iii", $user_id, $user_id, $year_param);
            }
        } else {
            // 如果沒有分配欄位，老師看不到任何資料
            $stmt = $conn->prepare("SELECT ca.id, ca.apply_no, ca.name, ca.school, ca.status, ca.created_at, $assigned_dept_field, sd.name as school_name 
                          FROM continued_admission ca
                          LEFT JOIN school_data sd ON ca.school = sd.school_code
                          WHERE 1=0 " . $status_condition . "
                          ORDER BY ca.$sortBy $sortOrder");
            $year_param = $view_mode === 'active' ? $current_year : $filter_year;
            $stmt->bind_param("i", $year_param);
        }
    }
} elseif ($is_director && !empty($user_department_code)) {
    // 主任只能看到已分配給他的科系的名單（assigned_department = 他的科系代碼）
    // 如果沒有 assigned_department 字段，則通過 continued_admission_choices 來過濾
    if ($has_assigned_department) {
        $stmt = $conn->prepare("SELECT ca.id, ca.apply_no, ca.name, ca.school, ca.status, ca.created_at, $assigned_dept_field, sd.name as school_name 
                      FROM continued_admission ca
                      LEFT JOIN school_data sd ON ca.school = sd.school_code
                      WHERE ca.assigned_department = ? " . $status_condition . "
                      ORDER BY ca.$sortBy $sortOrder");
        // active 模式用 current_year（今年），history 模式用 filter_year（已確保不是今年）
        $year_param = $view_mode === 'active' ? $current_year : $filter_year;
        $stmt->bind_param("si", $user_department_code, $year_param);
    } else {
        // 如果沒有 assigned_department 字段，通過 continued_admission_choices 來過濾
        $stmt = $conn->prepare("SELECT DISTINCT ca.id, ca.apply_no, ca.name, ca.school, ca.status, ca.created_at, $assigned_dept_field, sd.name as school_name 
                      FROM continued_admission ca
                      LEFT JOIN school_data sd ON ca.school = sd.school_code
                      INNER JOIN continued_admission_choices cac ON ca.id = cac.application_id
                      WHERE cac.department_code = ? " . $status_condition . "
                      ORDER BY ca.$sortBy $sortOrder");
        // active 模式用 current_year（今年），history 模式用 filter_year（已確保不是今年）
        $year_param = $view_mode === 'active' ? $current_year : $filter_year;
        $stmt->bind_param("si", $user_department_code, $year_param);
    }
} elseif ($is_imd_user) {
    // IMD用戶只能看到志願選擇包含"資訊管理科"的續招報名（保留向後兼容）
    $stmt = $conn->prepare("SELECT DISTINCT ca.id, ca.apply_no, ca.name, ca.school, ca.status, ca.created_at, $assigned_dept_field, sd.name as school_name
                          FROM continued_admission ca
                          LEFT JOIN school_data sd ON ca.school = sd.school_code
                          INNER JOIN continued_admission_choices cac ON ca.id = cac.application_id
                          INNER JOIN departments d ON cac.department_code = d.code
                          WHERE (d.code = 'IM' OR d.name LIKE '%資訊管理%' OR d.name LIKE '%資管%') " . $status_condition . "
                          ORDER BY ca.$sortBy $sortOrder");
    // active 模式用 current_year（今年），history 模式用 filter_year（已確保不是今年）
    $year_param = $view_mode === 'active' ? $current_year : $filter_year;
    $stmt->bind_param("i", $year_param);
} else {
    // 招生中心/管理員可以看到所有續招報名
    $stmt = $conn->prepare("SELECT ca.id, ca.apply_no, ca.name, ca.school, ca.status, ca.created_at, $assigned_dept_field, sd.name as school_name
                          FROM continued_admission ca
                          LEFT JOIN school_data sd ON ca.school = sd.school_code
                      WHERE 1=1 " . $status_condition . "
                          ORDER BY ca.$sortBy $sortOrder");
    // active 模式用 current_year（今年），history 模式用 filter_year（已確保不是今年）
    $year_param = $view_mode === 'active' ? $current_year : $filter_year;
    $stmt->bind_param("i", $year_param);
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

// 為每個報名獲取志願選擇（包含代碼和名稱）和分配資訊
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
    
    // 獲取分配資訊（從正規化表或舊欄位）
    if ($has_normalized_assignments) {
        $assign_stmt = $conn->prepare("
            SELECT reviewer_user_id, reviewer_type, assignment_order
            FROM continued_admission_assignments
            WHERE application_id = ?
            ORDER BY assignment_order ASC
        ");
        $assign_stmt->bind_param('i', $app['id']);
        $assign_stmt->execute();
        $assign_result = $assign_stmt->get_result();
        $app['assigned_teacher_1_id'] = null;
        $app['assigned_teacher_2_id'] = null;
        $app['assigned_director_id'] = null;
        while ($assign_row = $assign_result->fetch_assoc()) {
            // 只處理 reviewer_type = 'teacher' 的分配（排除主任）
            if ($assign_row['reviewer_type'] === 'teacher') {
                if ($assign_row['assignment_order'] == 1) {
                    $app['assigned_teacher_1_id'] = $assign_row['reviewer_user_id'];
                } elseif ($assign_row['assignment_order'] == 2) {
                    $app['assigned_teacher_2_id'] = $assign_row['reviewer_user_id'];
                }
            } elseif ($assign_row['reviewer_type'] === 'director' && $assign_row['assignment_order'] == 3) {
                $app['assigned_director_id'] = $assign_row['reviewer_user_id'];
            }
        }
        $assign_stmt->close();
    } else {
        // 使用舊欄位（向後兼容）
        $old_check = $conn->query("SHOW COLUMNS FROM continued_admission LIKE 'assigned_teacher_1_id'");
        if ($old_check && $old_check->num_rows > 0) {
            $old_stmt = $conn->prepare("SELECT assigned_teacher_1_id, assigned_teacher_2_id FROM continued_admission WHERE id = ?");
            $old_stmt->bind_param('i', $app['id']);
            $old_stmt->execute();
            $old_result = $old_stmt->get_result();
            if ($old_row = $old_result->fetch_assoc()) {
                $app['assigned_teacher_1_id'] = $old_row['assigned_teacher_1_id'] ?? null;
                $app['assigned_teacher_2_id'] = $old_row['assigned_teacher_2_id'] ?? null;
            }
            $old_stmt->close();
        }
    }
}
unset($app);

// 對於老師：在獲取分配資訊後，再次過濾，確保只顯示主任分配給他的學生
if ($is_teacher && !empty($user_id) && !empty($applications)) {
    $filtered_applications = [];
    foreach ($applications as $app) {
        // 檢查是否真的被分配給該老師（必須先通過這個檢查）
        $is_assigned_to_teacher = false;
        if ($has_normalized_assignments) {
            // 檢查正規化分配表
            if (isset($app['assigned_teacher_1_id']) && (int)$app['assigned_teacher_1_id'] === (int)$user_id) {
                $is_assigned_to_teacher = true;
            } elseif (isset($app['assigned_teacher_2_id']) && (int)$app['assigned_teacher_2_id'] === (int)$user_id) {
                $is_assigned_to_teacher = true;
            }
        } else {
            // 檢查舊欄位
            if (isset($app['assigned_teacher_1_id']) && (int)$app['assigned_teacher_1_id'] === (int)$user_id) {
                $is_assigned_to_teacher = true;
            } elseif (isset($app['assigned_teacher_2_id']) && (int)$app['assigned_teacher_2_id'] === (int)$user_id) {
                $is_assigned_to_teacher = true;
            }
        }
        
        // 如果沒有被分配給該老師，跳過
        if (!$is_assigned_to_teacher) {
            continue;
        }
        
        // 如果有科系代碼，檢查 assigned_department 是否等於老師科系代碼
        if (!empty($user_department_code)) {
            $assigned_dept = trim(strtoupper($app['assigned_department'] ?? ''));
            $teacher_dept = trim(strtoupper($user_department_code));
            
            // 如果 assigned_department 有值且不匹配，跳過
            if (!empty($assigned_dept) && $assigned_dept !== $teacher_dept) {
                continue; // 跳過科系不匹配的學生
            }
        }
        
        // 保留被分配給該老師的學生
        $filtered_applications[] = $app;
    }
    $applications = $filtered_applications;
}

// 如果請求匯出 CSV 或 Excel，輸出並結束（Excel 使用 .xls 與 vnd.ms-excel header，但內容為 CSV，方便相容）
if (isset($_GET['export']) && in_array($_GET['export'], ['csv', 'excel'])) {
    $isExcel = $_GET['export'] === 'excel';
    $ext = $isExcel ? 'xls' : 'csv';
    $filename = 'continued_admission_' . ($view_mode === 'history' ? 'history' : 'active') . '_' . date('Ymd_His') . '.' . $ext;

    if ($isExcel) {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    } else {
        header('Content-Type: text/csv; charset=utf-8');
    }
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    // BOM for Excel compatibility (UTF-8)
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    // 表頭
    fputcsv($out, ['報名編號', '姓名', '學校', '審核狀態', '分配科系', '建立時間', '意願']);

    foreach ($applications as $a) {
        $apply_no = $a['apply_no'] ?? $a['id'] ?? '';
        $name = $a['name'] ?? '';
        $school = $a['school_name'] ?? $a['school'] ?? '';
        $status_text = getStatusText($a['status'] ?? '');
        $assigned_dept_code = $a['assigned_department'] ?? '';
        $assigned_dept_name = !empty($assigned_dept_code) ? getDepartmentName($assigned_dept_code, $department_data) : '';
        $created_at = $a['created_at'] ?? '';
        $choices = [];
        if (!empty($a['choices'])) {
            $dec = json_decode($a['choices'], true);
            if (is_array($dec)) $choices = $dec;
        }
        $choices_str = is_array($choices) ? implode(' | ', $choices) : '';

        fputcsv($out, [$apply_no, $name, $school, $status_text, $assigned_dept_name, $created_at, $choices_str]);
    }

    fclose($out);
    $conn->close();
    exit;
}

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
    
    // 統計已錄取的學生（根據 assigned_department，狀態為 'approved' 或 'AP'）
    $stmt_approved = $conn->prepare("
        SELECT assigned_department, COUNT(*) as enrolled_count
        FROM continued_admission
        WHERE (status = 'approved' OR status = 'AP')
        AND assigned_department IS NOT NULL
        AND assigned_department != ''
        GROUP BY assigned_department
    ");
    $stmt_approved->execute();
    $approved_result = $stmt_approved->get_result();
    
    // 組織已錄取學生的數據（按科系代碼統計）
    $approved_by_department = [];
    while ($row = $approved_result->fetch_assoc()) {
        $dept_code = $row['assigned_department'];
        $approved_by_department[$dept_code] = (int)$row['enrolled_count'];
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
    switch ($status) {
        case 'approved':
        case 'AP': return '錄取';
        case 'rejected':
        case 'RE': return '未錄取';
        case 'waitlist':
        case 'AD': return '備取';
        case 'pending':
        case 'PE': return '待審核';
        default: return '待審核';
    }
}

function getStatusClass($status) {
    switch ($status) {
        case 'approved':
        case 'AP': return 'status-approved';
        case 'rejected':
        case 'RE': return 'status-rejected';
        case 'waitlist':
        case 'AD': return 'status-waitlist';
        case 'pending':
        case 'PE': return 'status-pending';
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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
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

        .status-badge { padding: 4px 8px; border-radius: 4px; font-size: 16px; font-weight: 500; border: 1px solid; }
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
        
        .btn-secondary {
            background: #fff;
            color: #262626;
            border: 1px solid #d9d9d9;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
        }
        .btn-secondary:hover {
            background: #f5f5f5;
            border-color: #40a9ff;
            color: #1890ff;
        }
        .btn-link {
            color: #1890ff;
            background: none;
            border: none;
            cursor: pointer;
            padding: 4px 8px;
            font-size: 13px;
            text-decoration: none;
        }
        .btn-link:hover {
            color: #40a9ff;
            text-decoration: underline;
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

        /* 主任/IM用戶隱藏不需要的意願欄位 */
        .application-table.hide-choice1 th.choice1-column,
        .application-table.hide-choice1 td.choice1-column,
        .application-table.hide-choice2 th.choice2-column,
        .application-table.hide-choice2 td.choice2-column,
        .application-table.hide-choice3 th.choice3-column,
        .application-table.hide-choice3 td.choice3-column {
            display: none !important;
        }

        /* 志願選擇顯示樣式（保留用於其他可能的用途） */
        .choices-display { display: flex; flex-direction: column; gap: 4px; }

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
                            $ranking_active = ($current_tab === 'ranking') ? 'active' : '';
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
                                <?php if ($is_director || $is_admin_or_staff): // 主任和招生中心可以看到達到錄取標準名單 ?>
                                <div class="tab-item <?php echo $ranking_active; ?>" onclick="switchTab('ranking')">
                                    達到錄取標準名單
                                </div>
                                <?php endif; ?>
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
                                    <?php foreach ($department_stats as $name => $stats):
                                        if ($name == 'AA'){
                                            continue;
                                        }
                                         ?>
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
                            <?php
                            // 目前是 list TAB 下的檢視模式（active / history）
                            $current_view = $view_mode === 'history' ? 'history' : 'active';
                            $view_active = $current_view === 'active' ? 'active' : '';
                            $view_history = $current_view === 'history' ? 'active' : '';
                            $base_sort_qs = '&sort_by=' . urlencode($sortBy) . '&sort_order=' . urlencode($sortOrder);
                            ?>
                            <div style="display:flex; align-items:center; gap:12px; margin-bottom:12px; margin-left: 60px;margin-right: 60px;">
                                <div style="display:flex; gap:16px;">
                                    <?php
                                        // 產生帶年份參數的 URL
                                        $build_url = function($view, $year) use ($sortBy, $sortOrder, $current_year) {
                                            // active 模式不需要年份參數（固定顯示今年）
                                            if ($view === 'active') {
                                                return 'continued_admission_list.php?tab=list&view=' . urlencode($view) . '&sort_by=' . urlencode($sortBy) . '&sort_order=' . urlencode($sortOrder);
                                            } else {
                                                // history 模式需要年份參數，但不能是今年
                                                $year_to_use = ($year === $current_year) ? ($current_year - 1) : $year;
                                                return 'continued_admission_list.php?tab=list&view=' . urlencode($view) . '&year=' . intval($year_to_use) . '&sort_by=' . urlencode($sortBy) . '&sort_order=' . urlencode($sortOrder);
                                            }
                                        };
                                    ?>
                                    <a href="<?php echo htmlspecialchars($build_url('active', $current_year)); ?>" class="btn" style="padding:6px 10px; color:#877f7f; <?php echo $view_active ? 'background:#f0f7ff; border-color: #91d5ff; color:#1890ff;' : ''; ?>">目前名單</a>
                                    <a href="<?php echo htmlspecialchars($build_url('history', $filter_year)); ?>" class="btn" style="padding:6px 10px; color:#877f7f; <?php echo $view_history ? 'background:#f0f7ff; border-color: #91d5ff; color:#1890ff;' : ''; ?>">歷史資料</a>
                                </div>
                                <?php
                                    // 建立匯出連結，保留目前 GET 參數
                                    $export_params = $_GET;
                                    $export_params['tab'] = 'list';
                                    if (!isset($export_params['view'])) $export_params['view'] = $view_mode;
                                    // active 模式下不帶年份參數（固定顯示今年）
                                    if ($view_mode === 'active') {
                                        unset($export_params['year']);
                                    }
                                    $export_csv_params = $export_params; $export_csv_params['export'] = 'csv';
                                    $export_excel_params = $export_params; $export_excel_params['export'] = 'excel';
                                    $export_csv_url = 'continued_admission_list.php?' . http_build_query($export_csv_params);
                                    $export_excel_url = 'continued_admission_list.php?' . http_build_query($export_excel_params);
                                ?>
                                <div style="margin-left:auto; display:flex; gap:15px; align-items:center;">
                                    <a href="<?php echo htmlspecialchars($export_csv_url); ?>" class="btn" style="padding:6px 10px; background: #6291f9f5; color: white;">匯出 CSV</a>
                                    <a href="<?php echo htmlspecialchars($export_excel_url); ?>" class="btn" style="padding:6px 10px; background: rgb(40, 167, 69); color: white;">匯出 Excel</a>
                                    <div style="color:var(--text-secondary-color); font-size:14px;">
                                        顯示模式：<?php echo $current_view === 'history' ? '歷史資料' : '目前在審／未錄取'; ?>
                                        <?php if ($current_view === 'history'): ?>
                                            ，年份：
                                            <?php
                                            // 歷史資料的年份選項：排除今年，從去年開始往回 5 年
                                            $history_years = [];
                                            for ($y = $current_year - 1; $y >= $current_year - 6; $y--) {
                                                $history_years[] = $y;
                                            }
                                            ?>
                                            <select id="yearFilter" onchange="onChangeYear(this.value)" style="padding:4px 6px; border-radius:4px; border:1px solid #d9d9d9; margin-left:4px;">
                                                <?php foreach ($history_years as $y): ?>
                                                    <option value="<?php echo $y; ?>" <?php echo $y === $filter_year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php else: ?>
                                            <span style="margin-left:4px;">（<?php echo $current_year; ?>年）</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php if (empty($applications)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-inbox fa-3x" style="margin-bottom: 16px;"></i>
                                    <p>目前尚無任何續招報名資料。</p>
                                </div>
                            <?php else: 
                                // 根據用戶角色決定隱藏哪些欄位
                                $table_classes = 'table application-table';
                                if ($is_teacher || ($is_director && !empty($user_department_code))) {
                                    // 老師和主任：檢查哪些意願欄位需要顯示（只顯示自己科系的志願）
                                    $has_choice1 = false;
                                    $has_choice2 = false;
                                    $has_choice3 = false;
                                    $target_dept_code = !empty($user_department_code) ? $user_department_code : null;
                                    
                                    if ($target_dept_code) {
                                        foreach ($applications as $check_item) {
                                            $check_choices = $check_item['choices_with_codes'] ?? [];
                                            foreach ($check_choices as $check_choice) {
                                                if ($check_choice['code'] === $target_dept_code) {
                                                    $order = $check_choice['order'] ?? 0;
                                                    if ($order == 1) $has_choice1 = true;
                                                    elseif ($order == 2) $has_choice2 = true;
                                                    elseif ($order == 3) $has_choice3 = true;
                                                }
                                            }
                                        }
                                    }
                                    if (!$has_choice1) $table_classes .= ' hide-choice1';
                                    if (!$has_choice2) $table_classes .= ' hide-choice2';
                                    if (!$has_choice3) $table_classes .= ' hide-choice3';
                                } elseif ($is_imd_user) {
                                    // IM用戶：檢查哪些意願欄位需要顯示
                                    $has_choice1 = false;
                                    $has_choice2 = false;
                                    $has_choice3 = false;
                                    foreach ($applications as $check_item) {
                                        $check_choices = $check_item['choices_with_codes'] ?? [];
                                        foreach ($check_choices as $check_choice) {
                                            if ($check_choice['code'] === 'IM' || strpos($check_choice['name'], '資訊管理') !== false || strpos($check_choice['name'], '資管') !== false) {
                                                $order = $check_choice['order'] ?? 0;
                                                if ($order == 1) $has_choice1 = true;
                                                elseif ($order == 2) $has_choice2 = true;
                                                elseif ($order == 3) $has_choice3 = true;
                                            }
                                        }
                                    }
                                    if (!$has_choice1) $table_classes .= ' hide-choice1';
                                    if (!$has_choice2) $table_classes .= ' hide-choice2';
                                    if (!$has_choice3) $table_classes .= ' hide-choice3';
                                } else {
                                    // 招生中心/管理員：顯示所有志願欄位
                                    $has_choice1 = true;
                                    $has_choice2 = true;
                                    $has_choice3 = true;
                                }
                            ?>
                                <table class="<?php echo $table_classes; ?>" id="applicationTable"<?php if ($is_teacher || ($is_director && !empty($user_department_code))): ?> data-director-view="true"<?php endif; ?>>
                                    <thead>
                                        <tr>
                                            <th onclick="sortTable('apply_no')">報名編號 <span class="sort-icon" id="sort-apply_no"></span></th>
                                            <th onclick="sortTable('name')">姓名 <span class="sort-icon" id="sort-name"></span></th>
                                            <?php if ($is_teacher || ($is_director && !empty($user_department_code))): ?>
                                                <!-- 老師和主任：只顯示「志願」，不顯示數字 -->
                                                <th class="choice1-column">志願</th>
                                                <?php if (isset($has_choice2) && $has_choice2): ?><th class="choice2-column">志願</th><?php endif; ?>
                                                <?php if (isset($has_choice3) && $has_choice3): ?><th class="choice3-column">志願</th><?php endif; ?>
                                            <?php else: ?>
                                                <!-- 招生中心/管理員：顯示「志願1」、「志願2」、「志願3」 -->
                                                <th class="choice1-column">志願1</th>
                                                <th class="choice2-column">志願2</th>
                                                <th class="choice3-column">志願3</th>
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
                                            <?php 
                                            $current_status = $item['status'] ?? '';
                                            $is_approved = ($current_status === 'approved' || $current_status === 'AP');
                                            $choices_with_codes = $item['choices_with_codes'] ?? [];
                                            
                                            // 準備三個意願的資料
                                            $choice1 = null;
                                            $choice2 = null;
                                            $choice3 = null;
                                            
                                            foreach ($choices_with_codes as $choice_data) {
                                                $order = $choice_data['order'] ?? 0;
                                                if ($order == 1) $choice1 = $choice_data;
                                                elseif ($order == 2) $choice2 = $choice_data;
                                                elseif ($order == 3) $choice3 = $choice_data;
                                            }
                                            
                                            // 根據用戶角色決定顯示哪些志願
                                            $display_choice1 = false;
                                            $display_choice2 = false;
                                            $display_choice3 = false;
                                            
                                            if ($is_teacher || ($is_director && !empty($user_department_code))) {
                                                // 老師和主任：只顯示自己科系的志願
                                                $target_dept_code = !empty($user_department_code) ? $user_department_code : null;
                                                if ($target_dept_code) {
                                                    if ($choice1 && $choice1['code'] === $target_dept_code) $display_choice1 = true;
                                                    if ($choice2 && $choice2['code'] === $target_dept_code) $display_choice2 = true;
                                                    if ($choice3 && $choice3['code'] === $target_dept_code) $display_choice3 = true;
                                                }
                                            } elseif ($is_imd_user) {
                                                // IMD用戶：只顯示資訊管理科相關的志願
                                                if ($choice1 && ($choice1['code'] === 'IM' || strpos($choice1['name'], '資訊管理') !== false || strpos($choice1['name'], '資管') !== false)) $display_choice1 = true;
                                                if ($choice2 && ($choice2['code'] === 'IM' || strpos($choice2['name'], '資訊管理') !== false || strpos($choice2['name'], '資管') !== false)) $display_choice2 = true;
                                                if ($choice3 && ($choice3['code'] === 'IM' || strpos($choice3['name'], '資訊管理') !== false || strpos($choice3['name'], '資管') !== false)) $display_choice3 = true;
                                            } else {
                                                // 招生中心/管理員：顯示所有志願
                                                $display_choice1 = ($choice1 !== null);
                                                $display_choice2 = ($choice2 !== null);
                                                $display_choice3 = ($choice3 !== null);
                                            }
                                            
                                            // 顯示意願1
                                            ?>
                                            <td class="choice1-column">
                                                <?php if ($display_choice1 && $choice1): ?>
                                                    <span class="choice-item <?php echo $is_approved ? 'approved' : ''; ?>">
                                                        <?php echo htmlspecialchars($choice1['name']); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="no-choices">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="choice2-column">
                                                <?php if ($display_choice2 && $choice2): ?>
                                                    <span class="choice-item <?php echo $is_approved ? 'approved' : ''; ?>">
                                                        <?php echo htmlspecialchars($choice2['name']); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="no-choices">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="choice3-column">
                                                <?php if ($display_choice3 && $choice3): ?>
                                                    <span class="choice-item <?php echo $is_approved ? 'approved' : ''; ?>">
                                                        <?php echo htmlspecialchars($choice3['name']); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="no-choices">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php 
                                                $current_status = $item['status'] ?? '';
                                                $status_text = getStatusText($current_status);
                                                
                                                // 如果是錄取狀態，顯示錄取科系
                                                if (($current_status === 'approved' || $current_status === 'AP')) {
                                                    $assigned_dept = $item['assigned_department'] ?? '';
                                                    if (!empty($assigned_dept)) {
                                                        $dept_name = getDepartmentName($assigned_dept, $department_data);
                                                        $status_text .= ' - ' . $dept_name;
                                                    }
                                                }
                                                ?>
                                                <span class="status-badge <?php echo getStatusClass($current_status); ?>">
                                                    <?php echo htmlspecialchars($status_text); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <a href="continued_admission_detail.php?id=<?php echo $item['id']; ?>" class="btn-view">查看詳情</a>
                                                <?php 
                                                // 檢查是否為待審核狀態（支持 'PE' 和 'pending'，排除已審核狀態）
                                                $is_pending = ($current_status === 'pending' || $current_status === 'PE');
                                                $is_approved = ($current_status === 'approved' || $current_status === 'AP');
                                                $is_rejected = ($current_status === 'rejected' || $current_status === 'RE');
                                                $is_waitlist = ($current_status === 'waitlist' || $current_status === 'AD');
                                                
                                                $assigned_dept = $item['assigned_department'] ?? '';
                                                $assigned_teacher_1 = $item['assigned_teacher_1_id'] ?? null;
                                                $assigned_teacher_2 = $item['assigned_teacher_2_id'] ?? null;
                                                $assigned_director_id = $item['assigned_director_id'] ?? null;
                                                
                                                // 判斷是否已經分配（至少有一位老師被分配，或者主任已被分配）
                                                $is_assigned = ($assigned_teacher_1 !== null || $assigned_teacher_2 !== null || $assigned_director_id !== null);
                                                
                                                // 主任可以分配給老師（狀態為待審核且已分配給該科系，且尚未分配老師）
                                                $can_assign_this = false;
                                                if ($is_pending && $is_director && !empty($user_department_code)) {
                                                    $can_assign_this = ($assigned_dept === $user_department_code && !$is_assigned);
                                                }
                                                
                                                // 老師可以評分（已被分配給該老師）
                                                $can_score_this = false;
                                                $teacher_slot = null; // 1, 2, 或 3（3=主任）
                                                if ($is_teacher && !empty($user_id)) {
                                                    if ($assigned_teacher_1 == $user_id) {
                                                        $can_score_this = true;
                                                        $teacher_slot = 1;
                                                    } elseif ($assigned_teacher_2 == $user_id) {
                                                        $can_score_this = true;
                                                        $teacher_slot = 2;
                                                    }
                                                }
                                                
                                                // 主任可以評分（已分配給該科系且已經分配過老師）
                                                if ($is_director && !empty($user_id) && !empty($user_department_code) && $assigned_dept === $user_department_code) {
                                                    if ($is_assigned) {
                                                        // 已經分配過，允許評分
                                                        $can_score_this = true;
                                                        $teacher_slot = 3; // 使用 3 表示主任
                                                    }
                                                }
                                                
                                                // 顯示分配按鈕（只有主任可以看到，且狀態為待審核，且尚未分配）
                                                if ($can_assign_this): ?>
                                                    <button onclick="showAssignTeacherModal(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($user_department_code, ENT_QUOTES); ?>')" class="btn-review" style="margin-left: 8px;">分配</button>
                                                <?php endif; 
                                                
                                                // 顯示評分按鈕（老師和主任都可以看到，且已被分配）
                                                // 對於主任：只有在已經分配後才顯示評分按鈕
                                                if ($can_score_this): ?>
                                                    <a href="continued_admission_detail.php?id=<?php echo $item['id']; ?>&action=score&slot=<?php echo $teacher_slot; ?>" class="btn-review" style="margin-left: 8px;">評分</a>
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

                    <!-- 達到錄取標準名單 TAB 內容 -->
                    <?php if ($is_director || $is_admin_or_staff): ?>
                    <?php
                    // 獲取達到錄取標準的名單
                    $ranking_data = [];
                    if ($is_director && !empty($user_department_code)) {
                        // 主任：只顯示自己科系的名單
                        $ranking_data = getDepartmentRanking($conn, $user_department_code);
                    } elseif ($is_admin_or_staff) {
                        // 招生中心：顯示所有科系的名單
                        $all_departments = [];
                        $dept_result = $conn->query("SELECT code, name FROM departments WHERE code != 'AA' ORDER BY code");
                        if ($dept_result) {
                            while ($row = $dept_result->fetch_assoc()) {
                                $dept_ranking = getDepartmentRanking($conn, $row['code']);
                                if (!empty($dept_ranking) && !empty($dept_ranking['applications'])) {
                                    // 確保包含科系名稱
                                    if (!isset($dept_ranking['department_name'])) {
                                        $dept_ranking['department_name'] = $row['name'];
                                    }
                                    $all_departments[$row['code']] = $dept_ranking;
                                }
                            }
                        }
                        $ranking_data = ['all_departments' => $all_departments];
                    }
                    ?>
                    <div id="tab-ranking" class="tab-content <?php echo $ranking_active; ?>">
                        <div class="card-body">
                            <?php if ($is_director && !empty($user_department_code)): ?>
                                <!-- 主任：顯示單一科系名單 -->
                                <?php if (!empty($ranking_data) && !empty($ranking_data['applications'])): ?>
                                    <div style="margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center;">
                                        <div>
                                            <h4 style="margin: 0; color: var(--text-color);"><?php echo htmlspecialchars($department_data[$user_department_code] ?? $user_department_code); ?> - 達到錄取標準名單</h4>
                                            <p style="margin: 8px 0 0 0; color: var(--text-secondary-color); font-size: 14px;">
                                                錄取標準：<?php echo $ranking_data['cutoff_score']; ?> 分 | 
                                                名額：<?php echo $ranking_data['total_quota']; ?> 名 | 
                                                已完成評分：<?php echo count($ranking_data['applications']); ?> 人
                                            </p>
                                        </div>
                                        <button onclick="exportRankingExcel('<?php echo $user_department_code; ?>', '<?php echo htmlspecialchars($department_data[$user_department_code] ?? $user_department_code, ENT_QUOTES); ?>')" class="btn btn-primary" style="padding: 8px 16px;">
                                            <i class="fas fa-file-excel" style="margin-right: 6px;"></i> 匯出 Excel
                                        </button>
                                    </div>
                                    <div class="table-container">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>排名</th>
                                                    <th>報名編號</th>
                                                    <th>姓名</th>
                                                    <th>老師1評分</th>
                                                    <th>老師2評分</th>
                                                    <th>主任評分</th>
                                                    <th>平均分數</th>
                                                    <th>錄取狀態</th>
                                                    <th>操作</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($ranking_data['applications'] as $index => $app): 
                                                    // 獲取三位評審的評分
                                                    $teacher1_score = null;
                                                    $teacher2_score = null;
                                                    $director_score = null;
                                                    foreach ($app['scores'] as $score) {
                                                        if ($score['assignment_order'] == 1) {
                                                            $teacher1_score = $score;
                                                        } elseif ($score['assignment_order'] == 2) {
                                                            $teacher2_score = $score;
                                                        } elseif ($score['assignment_order'] == 3) {
                                                            $director_score = $score;
                                                        }
                                                    }
                                                ?>
                                                <tr>
                                                    <td><?php echo $index + 1; ?></td>
                                                    <td><?php echo htmlspecialchars($app['apply_no'] ?? $app['id']); ?></td>
                                                    <td><?php echo htmlspecialchars($app['name']); ?></td>
                                                    <td>
                                                        <?php if ($teacher1_score): ?>
                                                            <?php echo $teacher1_score['self_intro_score'] + $teacher1_score['skills_score']; ?> 分
                                                            <small style="color: #8c8c8c; display: block;">(自傳: <?php echo $teacher1_score['self_intro_score']; ?>, 專長: <?php echo $teacher1_score['skills_score']; ?>)</small>
                                                        <?php else: ?>
                                                            <span style="color: #8c8c8c;">未評分</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($teacher2_score): ?>
                                                            <?php echo $teacher2_score['self_intro_score'] + $teacher2_score['skills_score']; ?> 分
                                                            <small style="color: #8c8c8c; display: block;">(自傳: <?php echo $teacher2_score['self_intro_score']; ?>, 專長: <?php echo $teacher2_score['skills_score']; ?>)</small>
                                                        <?php else: ?>
                                                            <span style="color: #8c8c8c;">未評分</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($director_score): ?>
                                                            <?php echo $director_score['self_intro_score'] + $director_score['skills_score']; ?> 分
                                                            <small style="color: #8c8c8c; display: block;">(自傳: <?php echo $director_score['self_intro_score']; ?>, 專長: <?php echo $director_score['skills_score']; ?>)</small>
                                                        <?php else: ?>
                                                            <span style="color: #8c8c8c;">未評分</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td style="font-weight: bold; font-size: 16px; color: <?php echo $app['average_score'] >= $ranking_data['cutoff_score'] ? '#52c41a' : '#f5222d'; ?>;">
                                                        <?php echo number_format($app['average_score'], 2); ?> 分
                                                    </td>
                                                    <td>
                                                        <?php if ($app['average_score'] >= $ranking_data['cutoff_score']): ?>
                                                            <?php if ($index < $ranking_data['total_quota']): ?>
                                                                <span class="status-badge status-approved">正取 <?php echo $index + 1; ?> 號</span>
                                                            <?php else: ?>
                                                                <span class="status-badge status-waitlist">備取 <?php echo $index - $ranking_data['total_quota'] + 1; ?> 號</span>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <span class="status-badge status-rejected">不錄取</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <a href="continued_admission_ranking_detail.php?id=<?php echo $app['id']; ?>" 
                                                           style="display: inline-block; padding: 6px 16px; border: 1px solid #91d5ff; border-radius: 6px; background: white; color: #1890ff; text-decoration: underline; font-size: 14px; transition: all 0.3s;">
                                                            查看詳情
                                                        </a>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <i class="fas fa-inbox fa-3x" style="margin-bottom: 16px;"></i>
                                        <p>目前尚無達到錄取標準的學生名單。</p>
                                    </div>
                                <?php endif; ?>
                            <?php elseif ($is_admin_or_staff): ?>
                                <!-- 招生中心：顯示所有科系名單 -->
                                <?php if (!empty($ranking_data) && isset($ranking_data['all_departments']) && !empty($ranking_data['all_departments'])): ?>
                                    <div style="margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; gap: 12px;">
                                        <div>
                                            <h4 style="margin: 0; color: var(--text-color);">達到錄取標準名單（全部科系）</h4>
                                            <p style="margin: 8px 0 0 0; color: var(--text-secondary-color); font-size: 14px;">
                                                共 <?php echo count($ranking_data['all_departments']); ?> 個科系
                                            </p>
                                        </div>
                                        <div style="display:flex; gap: 8px; align-items:center;">
                                            <a href="continued_admission_committee.php" class="btn btn-secondary" style="padding: 8px 16px; text-decoration:none; display:inline-flex; align-items:center; gap:8px;">
                                                <i class="fas fa-gavel"></i> 招生委員會：確認/公告/寄信
                                            </a>
                                            <button onclick="exportAllRankingExcel()" class="btn btn-primary" style="padding: 8px 16px;">
                                                <i class="fas fa-file-excel" style="margin-right: 6px;"></i> 匯出全部科系 Excel
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <!-- 科系分頁（樣式比照評分頁的 Tab） -->
                                    <div style="margin-bottom: 20px; border-bottom: 1px solid #f0f0f0;">
                                        <div id="rankingDeptTabs" style="display:flex; gap: 18px; align-items: center; overflow-x: auto; padding: 8px 4px 0 4px;">
                                            <button type="button" class="ranking-dept-tab active" data-dept="all" onclick="switchRankingDeptTab('all')"
                                                    style="background: none; border: none; padding: 10px 6px; cursor: pointer; font-size: 16px; color: #595959; border-bottom: 2px solid var(--primary-color);">
                                                全部科系
                                            </button>
                                            <?php foreach ($ranking_data['all_departments'] as $dept_code => $dept_ranking): 
                                                $dept_name = $department_data[$dept_code] ?? $dept_code;
                                            ?>
                                                <button type="button" class="ranking-dept-tab" data-dept="<?php echo htmlspecialchars($dept_code, ENT_QUOTES); ?>" onclick="switchRankingDeptTab('<?php echo htmlspecialchars($dept_code, ENT_QUOTES); ?>')"
                                                        style="background: none; border: none; padding: 10px 6px; cursor: pointer; font-size: 16px; color: #8c8c8c; border-bottom: 2px solid transparent; white-space: nowrap;">
                                                    <?php echo htmlspecialchars($dept_name); ?>
                                                </button>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>

                                    <?php foreach ($ranking_data['all_departments'] as $dept_code => $dept_ranking): ?>
                                        <div class="ranking-dept-section" data-dept-code="<?php echo htmlspecialchars($dept_code, ENT_QUOTES); ?>" style="margin-bottom: 32px;">
                                            <div style="background: #fafafa; padding: 12px 16px; border-bottom: 2px solid var(--primary-color); margin-bottom: 16px;">
                                                <h4 style="margin: 0; color: var(--text-color);">
                                                    <?php echo htmlspecialchars($department_data[$dept_code] ?? $dept_code); ?>
                                                </h4>
                                                <p style="margin: 8px 0 0 0; color: var(--text-secondary-color); font-size: 14px;">
                                                    錄取標準：<?php echo $dept_ranking['cutoff_score']; ?> 分 | 
                                                    名額：<?php echo $dept_ranking['total_quota']; ?> 名 | 
                                                    已完成評分：<?php echo count($dept_ranking['applications']); ?> 人
                                                </p>
                                            </div>
                                            <div class="table-container">
                                                <table class="table">
                                                    <thead>
                                                        <tr>
                                                            <th>排名</th>
                                                            <th>報名編號</th>
                                                            <th>姓名</th>
                                                            <th>老師1評分</th>
                                                            <th>老師2評分</th>
                                                            <th>主任評分</th>
                                                            <th>平均分數</th>
                                                            <th>錄取狀態</th>
                                                            <th>操作</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($dept_ranking['applications'] as $index => $app): 
                                                            // 獲取三位評審的評分
                                                            $teacher1_score = null;
                                                            $teacher2_score = null;
                                                            $director_score = null;
                                                            foreach ($app['scores'] as $score) {
                                                                if ($score['assignment_order'] == 1) {
                                                                    $teacher1_score = $score;
                                                                } elseif ($score['assignment_order'] == 2) {
                                                                    $teacher2_score = $score;
                                                                } elseif ($score['assignment_order'] == 3) {
                                                                    $director_score = $score;
                                                                }
                                                            }
                                                        ?>
                                                        <tr>
                                                            <td><?php echo $index + 1; ?></td>
                                                            <td><?php echo htmlspecialchars($app['apply_no'] ?? $app['id']); ?></td>
                                                            <td><?php echo htmlspecialchars($app['name']); ?></td>
                                                            <td>
                                                                <?php if ($teacher1_score): ?>
                                                                    <?php echo $teacher1_score['self_intro_score'] + $teacher1_score['skills_score']; ?> 分
                                                                    <small style="color: #8c8c8c; display: block;">(自傳: <?php echo $teacher1_score['self_intro_score']; ?>, 專長: <?php echo $teacher1_score['skills_score']; ?>)</small>
                                                                <?php else: ?>
                                                                    <span style="color: #8c8c8c;">未評分</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <?php if ($teacher2_score): ?>
                                                                    <?php echo $teacher2_score['self_intro_score'] + $teacher2_score['skills_score']; ?> 分
                                                                    <small style="color: #8c8c8c; display: block;">(自傳: <?php echo $teacher2_score['self_intro_score']; ?>, 專長: <?php echo $teacher2_score['skills_score']; ?>)</small>
                                                                <?php else: ?>
                                                                    <span style="color: #8c8c8c;">未評分</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <?php if ($director_score): ?>
                                                                    <?php echo $director_score['self_intro_score'] + $director_score['skills_score']; ?> 分
                                                                    <small style="color: #8c8c8c; display: block;">(自傳: <?php echo $director_score['self_intro_score']; ?>, 專長: <?php echo $director_score['skills_score']; ?>)</small>
                                                                <?php else: ?>
                                                                    <span style="color: #8c8c8c;">未評分</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td style="font-weight: bold; font-size: 16px; color: <?php echo $app['average_score'] >= $dept_ranking['cutoff_score'] ? '#52c41a' : '#f5222d'; ?>;">
                                                                <?php echo number_format($app['average_score'], 2); ?> 分
                                                            </td>
                                                            <td>
                                                                <?php if ($app['average_score'] >= $dept_ranking['cutoff_score']): ?>
                                                                    <?php if ($index < $dept_ranking['total_quota']): ?>
                                                                        <span class="status-badge status-approved">正取 <?php echo $index + 1; ?> 號</span>
                                                                    <?php else: ?>
                                                                        <span class="status-badge status-waitlist">備取 <?php echo $index - $dept_ranking['total_quota'] + 1; ?> 號</span>
                                                                    <?php endif; ?>
                                                                <?php else: ?>
                                                                    <span class="status-badge status-rejected">不錄取</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <a href="continued_admission_ranking_detail.php?id=<?php echo $app['id']; ?>" 
                                                                   style="display: inline-block; padding: 6px 16px; border: 1px solid #91d5ff; border-radius: 6px; background: white; color: #1890ff; text-decoration: underline; font-size: 14px; transition: all 0.3s;">
                                                                    查看詳情
                                                                </a>
                                                            </td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <i class="fas fa-inbox fa-3x" style="margin-bottom: 16px;"></i>
                                        <p>目前尚無達到錄取標準的學生名單。</p>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 訊息提示框 -->
    <div id="toast" style="position: fixed; top: 20px; right: 20px; background-color: #333; color: white; padding: 15px 20px; border-radius: 8px; z-index: 9999; display: none; opacity: 0; transition: opacity 0.5s;"></div>

    <!-- 分配老師模態框 -->
    <?php if ($can_assign): ?>
    <div id="assignTeacherModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>分配給老師</h3>
                <span class="close" onclick="closeAssignTeacherModal()">&times;</span>
            </div>
            <div class="modal-body">
                <p>學生：<span id="assignStudentName"></span></p>
                <div class="teacher-list">
                    <h4>選擇老師（最多兩位）：</h4>
                    <div class="teacher-options" id="assignTeacherOptions">
                        <div style="text-align: center; padding: 20px; color: var(--text-secondary-color);">
                            <i class="fas fa-spinner fa-spin"></i> 準備老師名單中...
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-cancel" onclick="closeAssignTeacherModal()">取消</button>
                <button class="btn-confirm" onclick="confirmAssignTeacher()">確認分配</button>
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
        const currentView = urlParams.get('view') || 'active'; // 保留當前 view（active/history）
        
        if (currentSortBy === field) {
            newSortOrder = currentSortOrder === 'asc' ? 'desc' : 'asc';
        }
        
        // 構建 URL：active 模式不帶年份參數，history 模式帶年份參數
        let url = `continued_admission_list.php?sort_by=${field}&sort_order=${newSortOrder}&tab=${currentTab}&view=${currentView}`;
        if (currentView === 'history') {
            const currentYear = urlParams.get('year') || '<?php echo $filter_year; ?>';
            url += `&year=${currentYear}`;
        }
        
        window.location.href = url;
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


    // 初始化時顯示/隱藏按鈕和搜尋框
    document.addEventListener('DOMContentLoaded', function() {
        // 初始化時顯示/隱藏按鈕和搜尋框
        const activeTab = document.querySelector('.tab-content.active');
        const searchInput = document.getElementById('searchInput');
        const deptFilterContainer = document.getElementById('departmentFilterContainer');
        
        if (activeTab && activeTab.id === 'tab-quota') {
            const quotaActionButtons = document.querySelectorAll('.quota-action-btn');
            quotaActionButtons.forEach(btn => {
                btn.style.display = 'inline-flex';
            });
            if (searchInput) {
                searchInput.style.display = 'none';
            }
            if (deptFilterContainer) {
                deptFilterContainer.style.display = 'none';
            }
        } else if (activeTab && activeTab.id === 'tab-ranking') {
            // 達到錄取標準名單 TAB
            if (searchInput) {
                searchInput.style.display = 'none';
            }
            if (deptFilterContainer) {
                deptFilterContainer.style.display = 'none';
            }
        } else {
            if (searchInput) {
                searchInput.style.display = 'block';
            }
            // 招生中心顯示科系篩選（已在頁面內容中顯示，不需要在這裡控制）
        }
    });

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
        if (nextBtn) nextBtn.disabled = currentPage >= totalPages || totalPages <= 1;
        
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

    // 年份變更時重新載入頁面
    function onChangeYear(year) {
        const urlParams = new URLSearchParams(window.location.search);
        const currentTab = urlParams.get('tab') || 'list';
        const currentView = urlParams.get('view') || 'active';
        const sortBy = urlParams.get('sort_by') || 'created_at';
        const sortOrder = urlParams.get('sort_order') || 'desc';
        
        // 如果是 active 模式，不允許改變年份（固定顯示今年）
        if (currentView === 'active') {
            return;
        }
        
        // history 模式下，確保選擇的年份不是今年
        const currentYear = <?php echo $current_year; ?>;
        if (parseInt(year) === currentYear) {
            // 如果選擇的是今年，改為去年
            year = currentYear - 1;
        }
        
        window.location.href = `continued_admission_list.php?tab=${currentTab}&view=${currentView}&year=${year}&sort_by=${sortBy}&sort_order=${sortOrder}`;
    }

    <?php if ($can_assign): ?>
    // 分配老師相關變數
    let currentAssignApplicationId = null;
    let currentAssignDepartmentCode = null;

    // 顯示分配老師模態框
    function showAssignTeacherModal(applicationId, studentName, departmentCode) {
        currentAssignApplicationId = applicationId;
        currentAssignDepartmentCode = departmentCode;
        
        document.getElementById('assignStudentName').textContent = studentName;
        const optionsContainer = document.getElementById('assignTeacherOptions');
        optionsContainer.innerHTML = '<div style="text-align: center; padding: 20px; color: var(--text-secondary-color);"><i class="fas fa-spinner fa-spin"></i> 載入老師名單中...</div>';
        
        // 載入該科系的老師名單
        fetch(`get_department_teachers.php?department=${encodeURIComponent(departmentCode)}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                console.log('get_department_teachers.php response:', data);
                
                if (data.success) {
                    if (data.teachers && data.teachers.length > 0) {
                        optionsContainer.innerHTML = '';
                        data.teachers.forEach(teacher => {
                            const label = document.createElement('label');
                            label.className = 'teacher-option';
                            label.innerHTML = `
                                <input type="checkbox" name="teacher" value="${teacher.user_id}" data-teacher-name="${teacher.name}">
                                <div class="teacher-info">
                                    <strong>${teacher.name}</strong>
                                    <span class="teacher-dept">${teacher.username || ''}</span>
                                </div>
                            `;
                            optionsContainer.appendChild(label);
                        });
                    } else {
                        let errorMsg = '該科系目前沒有可分配的老師。';
                        let debugDetails = '';
                        
                        if (data.debug) {
                            errorMsg += '<br><small style="color: #8c8c8c;">調試資訊：科系代碼=' + data.debug.department_code + 
                                      ', 科系名稱=' + (data.debug.department_name || '未找到') + 
                                      ', 找到數量=' + (data.debug.found_count || 0) + '</small>';
                            
                            // 顯示該科系的所有老師（包括主任）
                            if (data.debug.teachers_in_department && data.debug.teachers_in_department.length > 0) {
                                debugDetails += '<div style="margin-top: 10px; padding: 10px; background: #fff3cd; border-radius: 4px; text-align: left;">';
                                debugDetails += '<strong>該科系找到 ' + data.debug.teachers_in_department.length + ' 位老師/主任：</strong><ul style="margin: 5px 0; padding-left: 20px;">';
                                data.debug.teachers_in_department.forEach(teacher => {
                                    debugDetails += '<li>' + teacher.name + ' (role: ' + teacher.role + ', department: ' + teacher.department + ', 是否主任: ' + teacher.is_director + ')</li>';
                                });
                                debugDetails += '</ul></div>';
                            }
                            
                            // 顯示系統中所有老師
                            if (data.debug.all_teachers_sample && data.debug.all_teachers_sample.length > 0) {
                                debugDetails += '<div style="margin-top: 10px; padding: 10px; background: #e7f3ff; border-radius: 4px; text-align: left;">';
                                debugDetails += '<strong>系統中所有老師 (共 ' + (data.debug.all_teachers_count || data.debug.all_teachers_sample.length) + ' 位)：</strong><ul style="margin: 5px 0; padding-left: 20px; max-height: 150px; overflow-y: auto;">';
                                data.debug.all_teachers_sample.forEach(teacher => {
                                    debugDetails += '<li>' + teacher.name + ' (role: ' + teacher.role + ', department: ' + (teacher.department || 'NULL') + ')</li>';
                                });
                                debugDetails += '</ul></div>';
                            }
                            
                            // 顯示寬鬆匹配的老師
                            if (data.debug.loose_match_teachers && data.debug.loose_match_teachers.length > 0) {
                                debugDetails += '<div style="margin-top: 10px; padding: 10px; background: #d4edda; border-radius: 4px; text-align: left;">';
                                debugDetails += '<strong>寬鬆匹配找到 ' + data.debug.loose_match_count + ' 位老師：</strong><ul style="margin: 5px 0; padding-left: 20px;">';
                                data.debug.loose_match_teachers.forEach(teacher => {
                                    debugDetails += '<li>' + teacher.name + ' (role: ' + teacher.role + ', department: ' + teacher.department + ')</li>';
                                });
                                debugDetails += '</ul></div>';
                            }
                            
                            // 顯示該科系的所有用戶
                            if (data.debug.all_users_in_department && data.debug.all_users_in_department.length > 0) {
                                debugDetails += '<div style="margin-top: 10px; padding: 10px; background: #f8d7da; border-radius: 4px; text-align: left;">';
                                debugDetails += '<strong>該科系所有用戶 (共 ' + (data.debug.all_users_in_department_count || data.debug.all_users_in_department.length) + ' 位)：</strong><ul style="margin: 5px 0; padding-left: 20px;">';
                                data.debug.all_users_in_department.forEach(user => {
                                    debugDetails += '<li>' + user.name + ' (role: ' + user.role + ', teacher_dept: ' + (user.teacher_dept || 'NULL') + ', director_dept: ' + (user.director_dept || 'NULL') + ')</li>';
                                });
                                debugDetails += '</ul></div>';
                            }
                        }
                        
                        optionsContainer.innerHTML = '<div style="padding: 10px; color: var(--text-secondary-color); text-align: center;">' + errorMsg + debugDetails + '</div>';
                    }
                } else {
                    let errorMsg = data.message || '載入老師名單失敗';
                    if (data.debug && data.debug.error) {
                        errorMsg += ': ' + data.debug.error;
                    }
                    optionsContainer.innerHTML = '<p style="padding: 10px; color: #f5222d; text-align: center;">' + errorMsg + '</p>';
                }
            })
            .catch(error => {
                console.error('Error loading teachers:', error);
                optionsContainer.innerHTML = '<p style="padding: 10px; color: #f5222d; text-align: center;">載入老師名單失敗：' + error.message + '<br><small>請檢查瀏覽器控制台和伺服器日誌</small></p>';
            });
        
        document.getElementById('assignTeacherModal').style.display = 'flex';
    }

    // 關閉分配模態框
    function closeAssignTeacherModal() {
        document.getElementById('assignTeacherModal').style.display = 'none';
        currentAssignApplicationId = null;
        currentAssignDepartmentCode = null;
    }

    // 確認分配
    function confirmAssignTeacher() {
        const selectedTeachers = Array.from(document.querySelectorAll('input[name="teacher"]:checked'));
        
        if (selectedTeachers.length === 0) {
            showToast('請至少選擇一位老師', false);
            return;
        }
        
        if (selectedTeachers.length > 2) {
            showToast('最多只能選擇兩位老師', false);
            return;
        }
        
        const teacherIds = selectedTeachers.map(t => t.value);
        const teacher1Id = teacherIds[0] || null;
        const teacher2Id = teacherIds[1] || null;
        
        if (!currentAssignApplicationId) {
            showToast('系統錯誤：找不到報名記錄', false);
            return;
        }
        
        // 發送分配請求
        const formData = new FormData();
        formData.append('application_id', currentAssignApplicationId);
        formData.append('teacher_1_id', teacher1Id || '');
        formData.append('teacher_2_id', teacher2Id || '');
        
        fetch('assign_continued_admission_teacher.php', {
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

    // 點擊彈出視窗外部關閉
    document.addEventListener('DOMContentLoaded', function() {
        const assignModal = document.getElementById('assignTeacherModal');
        if (assignModal) {
            assignModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeAssignTeacherModal();
                }
            });
        }
    });
    <?php endif; ?>

    <?php if ($is_director || $is_admin_or_staff): ?>
    // Excel 匯出功能
    function exportRankingExcel(departmentCode, departmentName) {
        // 從 API 獲取單一科系的排名數據
        fetch(`get_department_ranking.php?department=${encodeURIComponent(departmentCode)}`)
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    showToast('獲取數據失敗：' + (data.message || '未知錯誤'), false);
                    return;
                }

                const deptData = data.department;
                const excelData = [
                    ['排名', '報名編號', '姓名', '老師1總分', '老師1自傳', '老師1專長', '老師2總分', '老師2自傳', '老師2專長', '主任總分', '主任自傳', '主任專長', '平均分數', '錄取狀態']
                ];

                deptData.applications.forEach((app, index) => {
                    // 獲取三位評審的評分
                    let teacher1Total = '', teacher1Intro = '', teacher1Skills = '';
                    let teacher2Total = '', teacher2Intro = '', teacher2Skills = '';
                    let directorTotal = '', directorIntro = '', directorSkills = '';

                    app.scores.forEach(score => {
                        const total = score.self_intro_score + score.skills_score;
                        if (score.assignment_order == 1) {
                            teacher1Total = total;
                            teacher1Intro = score.self_intro_score;
                            teacher1Skills = score.skills_score;
                        } else if (score.assignment_order == 2) {
                            teacher2Total = total;
                            teacher2Intro = score.self_intro_score;
                            teacher2Skills = score.skills_score;
                        } else if (score.assignment_order == 3) {
                            directorTotal = total;
                            directorIntro = score.self_intro_score;
                            directorSkills = score.skills_score;
                        }
                    });

                    // 確定錄取狀態
                    let status = '';
                    if (app.average_score >= deptData.cutoff_score) {
                        if (index < deptData.total_quota) {
                            status = `正取 ${index + 1} 號`;
                        } else {
                            status = `備取 ${index - deptData.total_quota + 1} 號`;
                        }
                    } else {
                        status = '不錄取';
                    }

                    excelData.push([
                        index + 1,
                        app.apply_no || app.id,
                        app.name,
                        teacher1Total || '',
                        teacher1Intro || '',
                        teacher1Skills || '',
                        teacher2Total || '',
                        teacher2Intro || '',
                        teacher2Skills || '',
                        directorTotal || '',
                        directorIntro || '',
                        directorSkills || '',
                        app.average_score.toFixed(2),
                        status
                    ]);
                });

                // 創建工作表
                const ws = XLSX.utils.aoa_to_sheet(excelData);
                
                // 設置列寬
                ws['!cols'] = [
                    { wch: 8 },  // 排名
                    { wch: 15 }, // 報名編號
                    { wch: 12 }, // 姓名
                    { wch: 12 }, // 老師1總分
                    { wch: 12 }, // 老師1自傳
                    { wch: 12 }, // 老師1專長
                    { wch: 12 }, // 老師2總分
                    { wch: 12 }, // 老師2自傳
                    { wch: 12 }, // 老師2專長
                    { wch: 12 }, // 主任總分
                    { wch: 12 }, // 主任自傳
                    { wch: 12 }, // 主任專長
                    { wch: 12 }, // 平均分數
                    { wch: 15 }  // 錄取狀態
                ];

                // 創建工作簿
                const wb = XLSX.utils.book_new();
                // 工作表名稱限制為31個字符
                const sheetName = departmentName.length > 31 
                    ? departmentName.substring(0, 31) 
                    : departmentName;
                XLSX.utils.book_append_sheet(wb, ws, sheetName);

                // 生成檔案名稱
                const now = new Date();
                const dateStr = now.getFullYear() + 
                               String(now.getMonth() + 1).padStart(2, '0') + 
                               String(now.getDate()).padStart(2, '0') + '_' +
                               String(now.getHours()).padStart(2, '0') + 
                               String(now.getMinutes()).padStart(2, '0');
                const fileName = `達到錄取標準名單_${departmentName}_${dateStr}.xlsx`;

                // 匯出檔案
                XLSX.writeFile(wb, fileName);
                showToast('Excel 匯出成功', true);
            })
            .catch(error => {
                console.error('匯出錯誤:', error);
                showToast('匯出失敗：' + error.message, false);
            });
    }

    // 取得目前在「達到錄取標準名單」TAB 內選取的科系（招生中心的科系分頁）
    // - 回傳 null：代表「全部科系」
    // - 回傳 array：代表只匯出指定科系（目前分頁）
    function getSelectedRankingDeptCodes() {
        const tabsWrap = document.getElementById('rankingDeptTabs');
        if (!tabsWrap) return null;

        const active = tabsWrap.querySelector('.ranking-dept-tab.active');
        if (!active) return null;

        const dept = active.getAttribute('data-dept');
        if (!dept || dept === 'all') return null;
        return [dept];
    }

    function exportAllRankingExcel() {
        // 從 API 獲取所有科系的排名數據
        fetch('get_all_department_ranking.php')
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    showToast('獲取數據失敗：' + (data.message || '未知錯誤'), false);
                    return;
                }

                // 創建工作簿
                const wb = XLSX.utils.book_new();

                // 為每個科系創建一個工作表
                const selectedDeptCodes = getSelectedRankingDeptCodes();
                const deptCodes = Object.keys(data.departments).filter(code => selectedDeptCodes === null || selectedDeptCodes.includes(code));

                if (deptCodes.length === 0) {
                    showToast('目前沒有可匯出的科系資料', false);
                    return;
                }

                deptCodes.forEach(deptCode => {
                    const deptData = data.departments[deptCode];
                    const excelData = [
                        ['排名', '報名編號', '姓名', '老師1總分', '老師1自傳', '老師1專長', '老師2總分', '老師2自傳', '老師2專長', '主任總分', '主任自傳', '主任專長', '平均分數', '錄取狀態']
                    ];

                    deptData.applications.forEach((app, index) => {
                        // 獲取三位評審的評分
                        let teacher1Total = '', teacher1Intro = '', teacher1Skills = '';
                        let teacher2Total = '', teacher2Intro = '', teacher2Skills = '';
                        let directorTotal = '', directorIntro = '', directorSkills = '';

                        app.scores.forEach(score => {
                            const total = score.self_intro_score + score.skills_score;
                            if (score.assignment_order == 1) {
                                teacher1Total = total;
                                teacher1Intro = score.self_intro_score;
                                teacher1Skills = score.skills_score;
                            } else if (score.assignment_order == 2) {
                                teacher2Total = total;
                                teacher2Intro = score.self_intro_score;
                                teacher2Skills = score.skills_score;
                            } else if (score.assignment_order == 3) {
                                directorTotal = total;
                                directorIntro = score.self_intro_score;
                                directorSkills = score.skills_score;
                            }
                        });

                        // 確定錄取狀態
                        let status = '';
                        if (app.average_score >= deptData.cutoff_score) {
                            if (index < deptData.total_quota) {
                                status = `正取 ${index + 1} 號`;
                            } else {
                                status = `備取 ${index - deptData.total_quota + 1} 號`;
                            }
                        } else {
                            status = '不錄取';
                        }

                        excelData.push([
                            index + 1,
                            app.apply_no || app.id,
                            app.name,
                            teacher1Total || '',
                            teacher1Intro || '',
                            teacher1Skills || '',
                            teacher2Total || '',
                            teacher2Intro || '',
                            teacher2Skills || '',
                            directorTotal || '',
                            directorIntro || '',
                            directorSkills || '',
                            app.average_score.toFixed(2),
                            status
                        ]);
                    });

                    // 創建工作表
                    const ws = XLSX.utils.aoa_to_sheet(excelData);
                    
                    // 設置列寬
                    ws['!cols'] = [
                        { wch: 8 },  // 排名
                        { wch: 15 }, // 報名編號
                        { wch: 12 }, // 姓名
                        { wch: 12 }, // 老師1總分
                        { wch: 12 }, // 老師1自傳
                        { wch: 12 }, // 老師1專長
                        { wch: 12 }, // 老師2總分
                        { wch: 12 }, // 老師2自傳
                        { wch: 12 }, // 老師2專長
                        { wch: 12 }, // 主任總分
                        { wch: 12 }, // 主任自傳
                        { wch: 12 }, // 主任專長
                        { wch: 12 }, // 平均分數
                        { wch: 15 }  // 錄取狀態
                    ];

                    // 添加工作表到工作簿（工作表名稱限制為31個字符）
                    const sheetName = deptData.department_name.length > 31 
                        ? deptData.department_name.substring(0, 31) 
                        : deptData.department_name;
                    XLSX.utils.book_append_sheet(wb, ws, sheetName);
                });

                // 生成檔案名稱
                const now = new Date();
                const dateStr = now.getFullYear() + 
                               String(now.getMonth() + 1).padStart(2, '0') + 
                               String(now.getDate()).padStart(2, '0') + '_' +
                               String(now.getHours()).padStart(2, '0') + 
                               String(now.getMinutes()).padStart(2, '0');
                const fileName = `達到錄取標準名單_全部科系_${dateStr}.xlsx`;

                // 匯出檔案
                XLSX.writeFile(wb, fileName);
                showToast('Excel 匯出成功', true);
            })
            .catch(error => {
                console.error('匯出錯誤:', error);
                showToast('匯出失敗：' + error.message, false);
            });
    }

    // Ranking TAB 科系分頁（招生中心）：比照「評分」那種 tab 切換顯示
    function switchRankingDeptTab(deptCode) {
        const tabs = document.querySelectorAll('#rankingDeptTabs .ranking-dept-tab');
        tabs.forEach(t => {
            const isActive = (t.getAttribute('data-dept') === deptCode);
            t.classList.toggle('active', isActive);
            t.style.color = isActive ? '#262626' : '#8c8c8c';
            t.style.borderBottomColor = isActive ? 'var(--primary-color)' : 'transparent';
        });

        const sections = document.querySelectorAll('.ranking-dept-section');
        sections.forEach(sec => {
            const code = sec.getAttribute('data-dept-code');
            sec.style.display = (deptCode === 'all' || deptCode === code) ? '' : 'none';
        });

        // 記住最後選擇（刷新後保留）
        try { localStorage.setItem('rankingDeptTab', deptCode); } catch (e) {}
    }

    document.addEventListener('DOMContentLoaded', function() {
        const tabsWrap = document.getElementById('rankingDeptTabs');
        if (!tabsWrap) return;
        let saved = 'all';
        try {
            const v = localStorage.getItem('rankingDeptTab');
            if (v) saved = v;
        } catch (e) {}

        // 若保存的科系不存在，就回到 all
        const exists = !!tabsWrap.querySelector(`.ranking-dept-tab[data-dept=\"${CSS.escape(saved)}\"]`);
        switchRankingDeptTab(exists ? saved : 'all');
    });
    <?php endif; ?>

    </script>
</body>
</html>
