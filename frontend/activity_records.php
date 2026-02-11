<?php
require_once __DIR__ . '/session_config.php';

checkBackendLogin();

// 引入資料庫設定
require_once '../../Topics-frontend/frontend/config.php';

// 設置頁面標題
$page_title = '統計分析';

// 檢查用戶權限和部門
$current_user = isset($_SESSION['username']) ? $_SESSION['username'] : '';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$user_id = $_SESSION['user_id'] ?? null;
$user_department = '';
$department_filter = '';

// 角色映射：將中文名稱或舊代碼轉換為新代碼（向後兼容）
$role_map = [
    '管理員' => 'ADM',
    'admin' => 'ADM',
    'Admin' => 'ADM',
    '行政人員' => 'STA',
    '學校行政人員' => 'STA',
    'staff' => 'STA',
    '主任' => 'DI',
    'director' => 'DI',
    '老師' => 'TEA',
    'teacher' => 'TEA',
    '招生中心組員' => 'STAM',
    '資管科主任' => 'IM',
    '資管主任' => 'IM',
    'IM主任' => 'IM',
    '科助' => 'AS',
    'assistant' => 'AS',
    'ADM' => 'ADM',
    'STA' => 'STA',
    'DI' => 'DI',
    'TEA' => 'TEA',
    'STAM' => 'STAM',
    'IM' => 'IM',
    'AS' => 'AS'
];
if (isset($role_map[$user_role])) {
    $user_role = $role_map[$user_role];
}

// 判斷是否為管理員：角色為 ADM（管理員）或 STA（行政人員），或舊的中文角色名稱
// 也檢查後台登入狀態和用戶名
$is_admin = ($user_role === 'ADM' || $user_role === '管理員' || $user_role === 'admin' || 
             $current_user === 'admin' || $current_user === 'admin1' ||
             (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] && 
              ($user_role === 'ADM' || $user_role === '管理員' || $current_user === 'admin')));
$is_school_admin = ($user_role === '學校行政人員' || $user_role === '行政人員' || $user_role === 'STA' || 
                    $current_user === 'IMD' || $is_admin);
$is_director = ($user_role === 'DI');
$is_stam = ($user_role === 'STAM');
$is_staff = ($user_role === 'STA');

// 學年度定義：8/1 ~ 隔年 7/31
function getAcademicYearRangeAug1(): array {
    $m = (int)date('m');
    $y = (int)date('Y');
    if ($m >= 8) {
        $start_year = $y;
        $end_year = $y + 1;
    } else {
        $start_year = $y - 1;
        $end_year = $y;
    }
    return [
        'start' => sprintf('%04d-08-01 00:00:00', $start_year),
        'end' => sprintf('%04d-07-31 23:59:59', $end_year),
    ];
}

$academic_year = getAcademicYearRangeAug1();
$academic_year_start = $academic_year['start'];
$academic_year_end = $academic_year['end'];

// 檢查是否為資管科主任：role=DI 且部門代碼=IM
$is_im = false;
$user_department_code = null;

// 如果 user_id 不存在但有 username，先從資料庫獲取 user_id
if (!$user_id && $current_user) {
    require_once '../../Topics-frontend/frontend/config.php';
    $conn_get_id = getDatabaseConnection();
    try {
        $stmt_get_id = $conn_get_id->prepare("SELECT id FROM user WHERE username = ?");
        $stmt_get_id->bind_param("s", $current_user);
        $stmt_get_id->execute();
        $result_get_id = $stmt_get_id->get_result();
        if ($row_id = $result_get_id->fetch_assoc()) {
            $user_id = $row_id['id'];
            $_SESSION['user_id'] = $user_id;
        }
        $stmt_get_id->close();
    } catch (Exception $e) {
        error_log('Error fetching user_id in activity_records: ' . $e->getMessage());
    }
    $conn_get_id->close();
}

if ($is_director && $user_id) {
    // 查詢用戶的部門代碼
    require_once '../../Topics-frontend/frontend/config.php';
    $conn_dept = getDatabaseConnection();
    try {
        // 優先從 director 表獲取部門代碼
        $table_check = $conn_dept->query("SHOW TABLES LIKE 'director'");
        if ($table_check && $table_check->num_rows > 0) {
            // 檢查 new_student_basic_info 是否有 department_id 欄位
            $has_new_student_department_id = false;
            $dept_col_check = $conn->query("SHOW COLUMNS FROM new_student_basic_info LIKE 'department_id'");
            if ($dept_col_check && $dept_col_check->num_rows > 0) {
                $has_new_student_department_id = true;
            }
            $stmt_dept = $conn_dept->prepare("SELECT department FROM director WHERE user_id = ?");
        } else {
            $stmt_dept = $conn_dept->prepare("SELECT department FROM teacher WHERE user_id = ?");
        }
        $stmt_dept->bind_param("i", $user_id);
        $stmt_dept->execute();
        $result_dept = $stmt_dept->get_result();
        if ($row = $result_dept->fetch_assoc()) {
            $user_department_code = $row['department'];
            // 如果部門代碼是'IM'，則為資管科主任
            if ($user_department_code === 'IM') {
                $is_im = true;
            }
        }
        $stmt_dept->close();
    } catch (Exception $e) {
        error_log('Error fetching user department in activity_records: ' . $e->getMessage());
    }
    $conn_dept->close();
} elseif ($user_role === 'IM' || $user_role === '資管科主任') {
    // 如果role已經是IM或資管科主任，直接設置為true
    $is_im = true;
}

// 額外的安全檢查：如果映射後的 user_role 是 IM，確保 is_im 一定為 true
if ($user_role === 'IM') {
    $is_im = true;
}

// 如果是 IMD 帳號或 IM 角色，只能查看資管科的資料
if ($current_user === 'IMD' || $is_im) {
    $user_department = '資訊管理科';
    $department_filter = " AND (t.department = '資訊管理科' OR t.department LIKE '%資管%' OR t.department = 'IM')";
}

// 計算當前學年度的開始和結束日期
// 學年度定義：6月 ~ 隔年6月（例如：2026/06/01 ~ 2027/06/30 為 2026 學年度）
function getCurrentAcademicYearRange() {
    $current_month = (int)date('m');
    $current_year = (int)date('Y');
    
    // 如果當前月份 >= 6月，學年度從今年6月開始到明年6月結束
    // 如果當前月份 < 6月，學年度從去年6月開始到今年6月結束
    if ($current_month >= 6) {
        $start_year = $current_year;
        $end_year = $current_year + 1;
    } else {
        $start_year = $current_year - 1;
        $end_year = $current_year;
    }
    
    return [
        'start' => sprintf('%04d-06-01 00:00:00', $start_year),
        'end' => sprintf('%04d-06-30 23:59:59', $end_year)
    ];
}

// 根據西元年份計算學年度範圍（民國年）
// 學年度：6月 ~ 隔年6月
// 例如：2026年6月1日 ~ 2027年6月30日 為 115學年度（2026-1911=115）
function getAcademicYearRangeByRocYear($roc_year) {
    $ad_year = $roc_year + 1911; // 民國年轉西元年
    return [
        'start' => sprintf('%04d-06-01 00:00:00', $ad_year),
        'end' => sprintf('%04d-06-30 23:59:59', $ad_year + 1)
    ];
}

// 獲取新生統計的視圖類型（新生或歷屆學生）
$new_student_view = isset($_GET['new_student_view']) ? $_GET['new_student_view'] : 'active'; // 'active' 為新生，'previous' 為歷屆學生
$selected_roc_year = isset($_GET['roc_year']) ? (int)$_GET['roc_year'] : 0; // 選中的學年度（民國年）

// 建立資料庫連接
$conn = getDatabaseConnection();
    
    // 檢查資料庫連接
    if (!$conn) {
        die('資料庫連接失敗');
    }

// 設定科系過濾條件（需要在 $conn 建立後才能使用 real_escape_string）
// 1. 如果是主任（DI）且不是招生中心（STAM），只能查看自己科系的資料
if ($is_director && !$is_stam && $user_department_code && empty($department_filter)) {
    // 使用科系代碼過濾（例如：IM、CS、EE 等）
    $dept_code_escaped = $conn->real_escape_string($user_department_code);
    $department_filter = " AND (t.department = '$dept_code_escaped' OR d.code = '$dept_code_escaped')";
}
// 2. 如果是招生中心（STAM）、管理員（ADM）或行政人員（STA），可以查看全部科系
//    $department_filter 保持為空字串或現有值，不進行額外過濾
    
    // 檢查 activity_records 表是否存在
    $table_check = $conn->query("SHOW TABLES LIKE 'activity_records'");
    if ($table_check->num_rows === 0) {
        error_log('activity_records 表不存在');
    } else {
        error_log('activity_records 表存在');
        
        // 檢查表結構
        $structure_check = $conn->query("DESCRIBE activity_records");
        if ($structure_check) {
            $columns = $structure_check->fetch_all(MYSQLI_ASSOC);
            error_log('activity_records 表結構: ' . json_encode($columns));
        }
        
        // 檢查表中是否有數據
        $count_check = $conn->query("SELECT COUNT(*) as count FROM activity_records");
        if ($count_check) {
            $count_result = $count_check->fetch_assoc();
            error_log('activity_records 表記錄數: ' . $count_result['count']);
        }
        
        // 檢查 teacher 表是否存在
        $teacher_table_check = $conn->query("SHOW TABLES LIKE 'teacher'");
        if ($teacher_table_check->num_rows === 0) {
            error_log('teacher 表不存在');
        } else {
            error_log('teacher 表存在');
        }
    }

// 檢查是否有傳入 teacher_id
$teacher_id = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;

if ($teacher_id > 0) {
    // --- 詳細記錄視圖 ---
    // 查詢特定教師的活動記錄
    $activity_records = [];
    $teacher_name = '';
    $records_sql = "SELECT ar.*, COALESCE(u.name, u2.name) AS teacher_name, t.department AS teacher_department,
                           at.name AS activity_type_name, sd.name AS school_name
                    FROM activity_records ar
                    LEFT JOIN teacher t ON ar.teacher_id = t.user_id
                    LEFT JOIN user u ON t.user_id = u.id
                    LEFT JOIN user u2 ON ar.teacher_id = u2.id
                    LEFT JOIN activity_types at ON ar.activity_type = at.ID
                    LEFT JOIN school_data sd ON ar.school COLLATE utf8mb4_unicode_ci = sd.school_code COLLATE utf8mb4_unicode_ci
                    WHERE ar.teacher_id = ?
                      AND ar.activity_date >= ?
                      AND ar.activity_date <= ?
                      $department_filter
                    ORDER BY ar.activity_date DESC, ar.id DESC";
    $stmt = $conn->prepare($records_sql);
    $stmt->bind_param("iss", $teacher_id, $academic_year_start, $academic_year_end);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        $activity_records = $result->fetch_all(MYSQLI_ASSOC);
        if (!empty($activity_records)) {
            $teacher_name = $activity_records[0]['teacher_name'];
            $page_title = '活動紀錄 - ' . htmlspecialchars($teacher_name);
        }
    }
    $stmt->close();
} else {
    // --- 教師列表視圖 ---
    $teachers_with_records = [];
    $teachers_sql = "SELECT t.user_id, u.name AS teacher_name, COALESCE(d.name, t.department) AS teacher_department, COUNT(ar.id) AS record_count
                     FROM teacher t
                     JOIN activity_records ar ON t.user_id = ar.teacher_id
                     LEFT JOIN user u ON t.user_id = u.id
                     LEFT JOIN departments d ON t.department COLLATE utf8mb4_unicode_ci = d.code COLLATE utf8mb4_unicode_ci
                     WHERE 1=1
                       AND ar.activity_date >= ?
                       AND ar.activity_date <= ?
                       $department_filter
                     GROUP BY t.user_id, u.name, t.department
                     ORDER BY record_count DESC, u.name ASC";
    $teachers_stmt = $conn->prepare($teachers_sql);
    if ($teachers_stmt) {
        $teachers_stmt->bind_param("ss", $academic_year_start, $academic_year_end);
        $teachers_stmt->execute();
        $result = $teachers_stmt->get_result();
    } else {
        $result = false;
    }

    // 為了統計圖表，獲取所有活動記錄
    $all_activity_records = [];
    $all_records_sql = "SELECT ar.*, COALESCE(u.name, u2.name) AS teacher_name, COALESCE(d.name, t.department) AS teacher_department, at.name AS activity_type_name, COALESCE(sd.name, ar.school) AS school_name
                        FROM activity_records ar
                        LEFT JOIN teacher t ON ar.teacher_id = t.user_id
                        LEFT JOIN departments d ON t.department COLLATE utf8mb4_unicode_ci = d.code COLLATE utf8mb4_unicode_ci
                        LEFT JOIN user u ON t.user_id = u.id
                        LEFT JOIN user u2 ON ar.teacher_id = u2.id
                        LEFT JOIN activity_types at ON ar.activity_type = at.ID
                        LEFT JOIN school_data sd ON ar.school COLLATE utf8mb4_unicode_ci = sd.school_code COLLATE utf8mb4_unicode_ci
                        WHERE 1=1
                          AND ar.activity_date >= ?
                          AND ar.activity_date <= ?
                          $department_filter
                        ORDER BY ar.activity_date DESC, ar.id DESC";
    $all_records_stmt = $conn->prepare($all_records_sql);
    if ($all_records_stmt) {
        $all_records_stmt->bind_param("ss", $academic_year_start, $academic_year_end);
        $all_records_stmt->execute();
        $all_records_result = $all_records_stmt->get_result();
    } else {
        $all_records_result = false;
    }
    if ($all_records_result) {
        $all_activity_records = $all_records_result->fetch_all(MYSQLI_ASSOC);
        // 調試信息
        error_log('查詢到的活動記錄數量: ' . count($all_activity_records));
    } else {
        error_log('查詢活動記錄失敗: ' . $conn->error);
    }
    
    if (isset($teachers_stmt) && $teachers_stmt) { $teachers_stmt->close(); }
    if (isset($all_records_stmt) && $all_records_stmt) { $all_records_stmt->close(); }

    // ===== 依學校彙整活動紀錄 =====
    $school_summary = [];
    $school_summary_list = [];
    
    // 查詢所有活動紀錄，包含學校、回饋、參與對象等資訊
    $school_summary_sql = "SELECT 
                            ar.id,
                            ar.school,
                            COALESCE(sd.name, ar.school) AS school_name,
                            ar.activity_date,
                            ar.created_at,
                            ar.teacher_id,
                            t.department AS teacher_department,
                            COALESCE(d.name, t.department) AS department_name,
                            COALESCE(u.name, u2.name) AS teacher_name
                        FROM activity_records ar
                        LEFT JOIN school_data sd ON ar.school COLLATE utf8mb4_unicode_ci = sd.school_code COLLATE utf8mb4_unicode_ci
                        LEFT JOIN teacher t ON ar.teacher_id = t.user_id
                        LEFT JOIN departments d ON t.department COLLATE utf8mb4_unicode_ci = d.code COLLATE utf8mb4_unicode_ci
                        LEFT JOIN user u ON t.user_id = u.id
                        LEFT JOIN user u2 ON ar.teacher_id = u2.id
                        WHERE 1=1
                          AND ar.activity_date >= ?
                          AND ar.activity_date <= ?
                          $department_filter
                        ORDER BY ar.activity_date DESC";
    
    $school_summary_stmt = $conn->prepare($school_summary_sql);
    if ($school_summary_stmt) {
        $school_summary_stmt->bind_param("ss", $academic_year_start, $academic_year_end);
        $school_summary_stmt->execute();
        $school_summary_result = $school_summary_stmt->get_result();
    } else {
        $school_summary_result = null;
    }
    
    if ($school_summary_result) {
        $all_school_records = $school_summary_result->fetch_all(MYSQLI_ASSOC);
        
        // 為每個活動紀錄讀取回饋和參與對象資訊
        foreach ($all_school_records as $record) {
            $activity_id = $record['id'];
            $school_code = $record['school'];
            $school_name = $record['school_name'] ?: $school_code ?: '未設定學校';
            
            // 初始化學校資料
            if (!isset($school_summary[$school_code])) {
                $school_summary[$school_code] = [
                    'school_code' => $school_code,
                    'school_name' => $school_name,
                    // 教師活動紀錄（主觀評分）
                    'total_activities' => 0,
                    'feedback_score_sum' => 0,        // 分數加總（每筆活動依回饋選項換算 3/2/1 後取平均）
                    'feedback_scored_count' => 0,     // 有填主觀評分的活動次數
                    'feedback_avg' => 0,              // 主觀評分平均（僅計入有評分者）
                    'feedback_count' => [             // 各評分類型次數（依平均分數換算等級）
                        '意願較低' => 0,
                        '普通' => 0,
                        '熱烈' => 0,
                        '未評分' => 0,
                    ],
                    'grade_semester' => [
                        '國二上' => ['count' => 0, 'feedback' => ['意願較低' => 0, '普通' => 0, '熱烈' => 0, '未評分' => 0]],
                        '國二下' => ['count' => 0, 'feedback' => ['意願較低' => 0, '普通' => 0, '熱烈' => 0, '未評分' => 0]],
                        '國三上' => ['count' => 0, 'feedback' => ['意願較低' => 0, '普通' => 0, '熱烈' => 0, '未評分' => 0]],
                        '其他' => ['count' => 0, 'feedback' => ['意願較低' => 0, '普通' => 0, '熱烈' => 0, '未評分' => 0]]
                    ],
                    // 入學說明會場次（客觀數字）
                    'session_registered_count' => 0, // 報名人次
                    'session_attended_count' => 0,   // 出席人次（attendance_status=1）
                    'heat_index' => 0,               // 熱度指數（用於排序）
                    'departments' => [], // 記錄該學校有哪些科系參與
                    'dept_counts' => [], // 參與科系 × 學校矩陣用
                    'last_teacher_name' => '',
                    'last_created_at' => '',
                    'records' => [] // 主任用：教師/填寫日期明細
                ];
            }
            
            $school_summary[$school_code]['total_activities']++;
            
            // 記錄科系資訊（同時建立「參與科系 × 學校」矩陣）
            $dept_name = trim((string)($record['department_name'] ?? ''));
            if ($dept_name === '') {
                $dept_name = trim((string)($record['teacher_department'] ?? ''));
            }
            if ($dept_name === '') {
                $dept_name = '未指定';
            }

            if (!in_array($dept_name, $school_summary[$school_code]['departments'], true)) {
                $school_summary[$school_code]['departments'][] = $dept_name;
            }

            if (!isset($school_summary[$school_code]['dept_counts'][$dept_name])) {
                $school_summary[$school_code]['dept_counts'][$dept_name] = 0;
            }
            $school_summary[$school_code]['dept_counts'][$dept_name]++;
            
            // 讀取活動回饋（教師主觀評分）：依選項換算 3/2/1 後取平均，系統只做數值計算
            $feedback_sql = "SELECT afo.option 
                            FROM activity_feedback af
                            LEFT JOIN activity_feedback_options afo ON af.option_id = afo.id
                            WHERE af.activity_id = ?
                            ORDER BY af.option_id";
            $feedback_stmt = $conn->prepare($feedback_sql);
            $current_activity_feedback = '未評分';
            $activity_score = null; // 單筆活動分數（1~3），由回饋選項計算
            if ($feedback_stmt) {
                $feedback_stmt->bind_param("i", $activity_id);
                $feedback_stmt->execute();
                $feedback_result = $feedback_stmt->get_result();
                $options = [];
                while ($f_row = $feedback_result->fetch_assoc()) {
                    if (!empty($f_row['option'])) {
                        $options[] = trim((string)$f_row['option']);
                    }
                }
                $feedback_stmt->close();

                // 主觀評分對照（同時相容舊選項：熱烈/普通/冷淡）
                // 反應熱絡=3、詢問度高=2、反應冷淡=1
                $score_map_labels = [
                    3 => ['反應熱絡', '熱烈'],
                    2 => ['詢問度高', '普通'],
                    1 => ['反應冷淡', '冷淡'],
                ];
                $scores = [];
                foreach ($options as $opt) {
                    foreach ($score_map_labels as $s => $labels) {
                        if (in_array($opt, $labels, true)) {
                            $scores[] = (int)$s;
                            break;
                        }
                    }
                }
                if (!empty($scores)) {
                    $activity_score = array_sum($scores) / count($scores);
                    // 依平均分數換算等級（門檻可再依需求微調）
                    if ($activity_score >= 2.5) $current_activity_feedback = '熱烈';
                    elseif ($activity_score >= 1.5) $current_activity_feedback = '普通';
                    else $current_activity_feedback = '意願較低';
                }
            }
            
            // 累加該活動的回饋
            $school_summary[$school_code]['feedback_count'][$current_activity_feedback]++;
            if ($activity_score !== null) {
                $school_summary[$school_code]['feedback_score_sum'] += $activity_score;
                $school_summary[$school_code]['feedback_scored_count']++;
            }
            
            // 讀取參與對象（判斷年級和學期）
            $participants_sql = "SELECT io.name, io.code
                                FROM activity_participants ap
                                LEFT JOIN identity_options io ON ap.participants = io.code
                                WHERE ap.activity_id = ?
                                ORDER BY ap.participants";
            $grade_semester_key = '其他';
            $participants_stmt = $conn->prepare($participants_sql);
            if ($participants_stmt) {
                $participants_stmt->bind_param("i", $activity_id);
                $participants_stmt->execute();
                $participants_result = $participants_stmt->get_result();
                $activity_month = (int)date('m', strtotime($record['activity_date']));
                
                while ($p_row = $participants_result->fetch_assoc()) {
                    if (!empty($p_row['name'])) {
                        $participant_name = $p_row['name'];
                        
                        // 判斷年級和學期
                        // 學期判斷：9-1月為上學期，2-6月為下學期
                        if (strpos($participant_name, '國二') !== false || strpos($participant_name, '二年級') !== false) {
                            if ($activity_month >= 9 || $activity_month <= 1) {
                                $grade_semester_key = '國二上';
                            } elseif ($activity_month >= 2 && $activity_month <= 6) {
                                $grade_semester_key = '國二下';
                            }
                        } elseif (strpos($participant_name, '國三') !== false || strpos($participant_name, '三年級') !== false) {
                            if ($activity_month >= 9 || $activity_month <= 1) {
                                $grade_semester_key = '國三上';
                            }
                        }
                        
                        // 如果找到年級資訊，跳出迴圈
                        if ($grade_semester_key !== '其他') {
                            break;
                        }
                    }
                }
                $participants_stmt->close();
                
                // 更新年級學期統計
                $school_summary[$school_code]['grade_semester'][$grade_semester_key]['count']++;
                
                // 更新該年級學期的回饋統計（使用當前活動的回饋）
                // 確保回饋類型存在於陣列中
                if (isset($school_summary[$school_code]['grade_semester'][$grade_semester_key]['feedback'][$current_activity_feedback])) {
                    $school_summary[$school_code]['grade_semester'][$grade_semester_key]['feedback'][$current_activity_feedback]++;
                } else {
                    // 如果回饋類型不存在，視為未評分
                    $school_summary[$school_code]['grade_semester'][$grade_semester_key]['feedback']['未評分']++;
                }
            }

            // 主任視圖：保留教師姓名、填寫日期明細（可展開）
            if ($is_director) {
                $teacher_name = trim((string)($record['teacher_name'] ?? ''));
                if ($teacher_name === '') { $teacher_name = '未填寫'; }
                $created_at = (string)($record['created_at'] ?? '');
                $activity_date = (string)($record['activity_date'] ?? '');

                $school_summary[$school_code]['records'][] = [
                    'teacher_name' => $teacher_name,
                    'activity_date' => $activity_date,
                    'created_at' => $created_at,
                    'feedback' => $current_activity_feedback,
                    'score' => $activity_score,
                    'grade_semester' => $grade_semester_key,
                    'department' => $dept_name,
                ];

                if ($created_at !== '' && ($school_summary[$school_code]['last_created_at'] === '' || strtotime($created_at) > strtotime($school_summary[$school_code]['last_created_at']))) {
                    $school_summary[$school_code]['last_created_at'] = $created_at;
                    $school_summary[$school_code]['last_teacher_name'] = $teacher_name;
                }
            }
        }
        
        if (isset($school_summary_stmt)) {
            $school_summary_stmt->close();
        }
    }
    // ===== 依學校彙整活動紀錄結束 =====

    // 整合入學說明會場次（報名/出席）到同一份「依學校」彙整中（招生中心=全校；主任=本科系）
    try {
        $has_session_dept_col = false;
        $col_check = @$conn->query("SHOW COLUMNS FROM admission_sessions LIKE 'department_id'");
        if ($col_check && $col_check->num_rows > 0) $has_session_dept_col = true;

        $session_sql = "
            SELECT
                aa.school AS school_code,
                COALESCE(sd.name, aa.school) AS school_name,
                COUNT(*) AS registered_count,
                SUM(CASE WHEN ar.attendance_status = 1 THEN 1 ELSE 0 END) AS attended_count
            FROM admission_applications aa
            INNER JOIN admission_sessions s ON aa.session_id = s.id
            LEFT JOIN attendance_records ar ON ar.application_id = aa.id AND ar.session_id = s.id
            LEFT JOIN school_data sd ON aa.school COLLATE utf8mb4_unicode_ci = sd.school_code COLLATE utf8mb4_unicode_ci
            WHERE s.session_date >= ? AND s.session_date <= ?
        ";
        // 主任（科系層級）：只看自己科系場次；招生中心（全校層級）：看全部場次
        if ($has_session_dept_col && $is_director && !$is_stam && $user_department_code) {
            $session_sql .= " AND s.department_id = ? ";
        }
        $session_sql .= " GROUP BY aa.school, sd.name ORDER BY attended_count DESC, registered_count DESC";

        $stmt_sess = $conn->prepare($session_sql);
        if ($stmt_sess) {
            if ($has_session_dept_col && $is_director && !$is_stam && $user_department_code) {
                $stmt_sess->bind_param("sss", $academic_year_start, $academic_year_end, $user_department_code);
            } else {
                $stmt_sess->bind_param("ss", $academic_year_start, $academic_year_end);
            }
            $stmt_sess->execute();
            $rs_sess = $stmt_sess->get_result();
            if ($rs_sess) {
                while ($row = $rs_sess->fetch_assoc()) {
                    $sc = (string)($row['school_code'] ?? '');
                    if ($sc === '') $sc = '未設定學校';
                    $sn = (string)($row['school_name'] ?? $sc);
                    if (!isset($school_summary[$sc])) {
                        // 若只有說明會資料但沒有教師活動紀錄，也要納入排序清單
                        $school_summary[$sc] = [
                            'school_code' => $sc,
                            'school_name' => $sn,
                            'total_activities' => 0,
                            'feedback_score_sum' => 0,
                            'feedback_scored_count' => 0,
                            'feedback_avg' => 0,
                            'feedback_count' => ['意願較低' => 0, '普通' => 0, '熱烈' => 0, '未評分' => 0],
                            'grade_semester' => [
                                '國二上' => ['count' => 0, 'feedback' => ['意願較低' => 0, '普通' => 0, '熱烈' => 0, '未評分' => 0]],
                                '國二下' => ['count' => 0, 'feedback' => ['意願較低' => 0, '普通' => 0, '熱烈' => 0, '未評分' => 0]],
                                '國三上' => ['count' => 0, 'feedback' => ['意願較低' => 0, '普通' => 0, '熱烈' => 0, '未評分' => 0]],
                                '其他' => ['count' => 0, 'feedback' => ['意願較低' => 0, '普通' => 0, '熱烈' => 0, '未評分' => 0]],
                            ],
                            'session_registered_count' => 0,
                            'session_attended_count' => 0,
                            'heat_index' => 0,
                            'departments' => [],
                            'dept_counts' => [],
                            'last_teacher_name' => '',
                            'last_created_at' => '',
                            'records' => [],
                        ];
                    }
                    $school_summary[$sc]['session_registered_count'] = (int)($row['registered_count'] ?? 0);
                    $school_summary[$sc]['session_attended_count'] = (int)($row['attended_count'] ?? 0);
                }
            }
            $stmt_sess->close();
        }
    } catch (Throwable $e) {
        error_log('整合入學說明會（依學校）統計失敗: ' . $e->getMessage());
    }

    // 最終計算：平均分數 + 熱度指數（用於排序，不做 A/B/C 自動判斷）
    foreach ($school_summary as &$school) {
        $scored = (int)($school['feedback_scored_count'] ?? 0);
        $sum = (float)($school['feedback_score_sum'] ?? 0);
        $school['feedback_avg'] = ($scored > 0) ? round($sum / $scored, 2) : 0;
        // 熱度指數：教師主觀分數加總 + 說明會出席人次（加分關係；可再依需求調整權重）
        $school['heat_index'] = round($sum + (int)($school['session_attended_count'] ?? 0), 2);
    }
    unset($school);

    // 轉成排序後的列表（給表格與圖表用）
    $school_summary_list = array_values($school_summary);
    usort($school_summary_list, function($a, $b) {
        // 依「熱度指數」排序（高到低），再依說明會出席、教師活動次數做次排序
        $cmp = ((int)($b['heat_index'] ?? 0)) <=> ((int)($a['heat_index'] ?? 0));
        if ($cmp !== 0) return $cmp;
        $cmp2 = ((int)($b['session_attended_count'] ?? 0)) <=> ((int)($a['session_attended_count'] ?? 0));
        if ($cmp2 !== 0) return $cmp2;
        return ((int)($b['total_activities'] ?? 0)) <=> ((int)($a['total_activities'] ?? 0));
    });
    
    // 獲取出席記錄數據（用於出席統計圖表）- 從 admission_sessions 表抓取所有入學說明會場次
    // 注意：只計算與場次年份相同的簽到記錄
    // 同時獲取報名人數和科系資訊
    // 重要：顯示所有場次，即使沒有出席記錄或報名記錄
    // 這是聯動的，直接從 admission_sessions 表抓取，不需要手動設定
    
    // 初始化變數
    $attendance_stats_data = [];
    $all_sessions_list = [];
    
    // 先檢查 admission_sessions 表是否有資料
    $check_sessions = $conn->query("SELECT COUNT(*) as count FROM admission_sessions");
    $session_count = 0;
    if ($check_sessions) {
        $count_row = $check_sessions->fetch_assoc();
        $session_count = $count_row['count'];
        error_log('admission_sessions 表中共有 ' . $session_count . ' 筆場次資料');
    }
    
    // 檢查 admission_sessions 表是否有 department_id 欄位
    $has_department_id = false;
    $col_check = $conn->query("SHOW COLUMNS FROM admission_sessions LIKE 'department_id'");
    if ($col_check && $col_check->num_rows > 0) {
        $has_department_id = true;
    }
    
    // 檢查 admission_sessions 表是否有 session_end_date 欄位
    $has_session_end_date = false;
    $end_date_check = $conn->query("SHOW COLUMNS FROM admission_sessions LIKE 'session_end_date'");
    if ($end_date_check && $end_date_check->num_rows > 0) {
        $has_session_end_date = true;
    }
    
    // 使用最簡單的查詢，確保能獲取所有場次
    // 先獲取所有場次的基本資訊（不依賴子查詢）
    // 統一使用 session_id 作為欄位名稱，確保與後續處理一致
    if ($has_department_id) {
        $simple_sql = "
            SELECT 
                s.id as session_id,
                s.id,
                s.session_name,
                s.session_date,
                " . ($has_session_end_date ? "s.session_end_date" : "NULL") . " as session_end_date,
                s.department_id,
                COALESCE(d.name, '未指定') as department_name
            FROM admission_sessions s
            LEFT JOIN departments d ON s.department_id COLLATE utf8mb4_unicode_ci = d.code COLLATE utf8mb4_unicode_ci
            ORDER BY s.session_date DESC
        ";
    } else {
        $simple_sql = "
            SELECT 
                s.id as session_id,
                s.id,
                s.session_name,
                s.session_date,
                " . ($has_session_end_date ? "s.session_end_date" : "NULL") . " as session_end_date,
                NULL as department_id,
                '未指定' as department_name
            FROM admission_sessions s
            ORDER BY s.session_date DESC
        ";
    }
    
    $simple_result = $conn->query($simple_sql);
    if ($simple_result) {
        $all_sessions_list = $simple_result->fetch_all(MYSQLI_ASSOC);
        error_log('簡單查詢成功，獲取 ' . count($all_sessions_list) . ' 筆場次基本資料');
        
        // 為每個場次查詢出席和報名統計
        foreach ($all_sessions_list as &$session) {
            $session_id = $session['session_id'];
            $session_date = $session['session_date'];
            
            // 查詢出席人數（使用年份比較）
            $session_year = $session_date ? date('Y', strtotime($session_date)) : date('Y');
            $attendance_sql = "
                SELECT COUNT(*) as count 
                FROM attendance_records 
                WHERE session_id = ? 
                AND attendance_status = 1 
                AND check_in_time IS NOT NULL
                AND YEAR(check_in_time) = ?
            ";
            $attendance_stmt = $conn->prepare($attendance_sql);
            if ($attendance_stmt) {
                $attendance_stmt->bind_param("ii", $session_id, $session_year);
                $attendance_stmt->execute();
                $attendance_result = $attendance_stmt->get_result();
                if ($attendance_result) {
                    $attendance_row = $attendance_result->fetch_assoc();
                    $session['attendance_count'] = intval($attendance_row['count']);
                } else {
                    $session['attendance_count'] = 0;
                }
                $attendance_stmt->close();
            } else {
                $session['attendance_count'] = 0;
                error_log('出席人數查詢準備失敗: ' . $conn->error);
            }
            
            // 查詢報名人數（使用年份比較）
            $registration_sql = "
                SELECT COUNT(*) as count 
                FROM admission_applications 
                WHERE session_id = ?
                AND YEAR(created_at) = ?
            ";
            $registration_stmt = $conn->prepare($registration_sql);
            if ($registration_stmt) {
                $registration_stmt->bind_param("ii", $session_id, $session_year);
                $registration_stmt->execute();
                $registration_result = $registration_stmt->get_result();
                if ($registration_result) {
                    $registration_row = $registration_result->fetch_assoc();
                    $session['registration_count'] = intval($registration_row['count']);
                } else {
                    $session['registration_count'] = 0;
                }
                $registration_stmt->close();
            } else {
                $session['registration_count'] = 0;
                error_log('報名人數查詢準備失敗: ' . $conn->error);
            }
        }
        unset($session); // 釋放引用
        
        $attendance_stats_data = $all_sessions_list;
        error_log('最終統計資料：' . count($attendance_stats_data) . ' 筆場次');
    } else {
        error_log('簡單查詢失敗: ' . $conn->error);
        // 如果查詢失敗，至少嘗試獲取場次列表
        $fallback_sql = "SELECT id as session_id, session_name, session_date, " . ($has_session_end_date ? "session_end_date" : "NULL as session_end_date") . " FROM admission_sessions ORDER BY session_date DESC";
        $fallback_result = $conn->query($fallback_sql);
        if ($fallback_result) {
            $all_sessions_list = $fallback_result->fetch_all(MYSQLI_ASSOC);
            // 為每個場次添加預設值
            foreach ($all_sessions_list as &$session) {
                $session['department_id'] = null;
                $session['department_name'] = '未指定';
                $session['attendance_count'] = 0;
                $session['registration_count'] = 0;
            }
            unset($session);
            $attendance_stats_data = $all_sessions_list;
            error_log('使用備用查詢，返回 ' . count($attendance_stats_data) . ' 筆場次資料');
        } else {
            error_log('備用查詢也失敗: ' . $conn->error);
        }
    }
    
    // 獲取當前年份的場次列表（用於篩選下拉選單）
    // 只顯示當前年份的場次，不顯示以前年份的資料
    // 如果上面已經查詢過，直接使用並過濾；否則重新查詢
    $current_year = date('Y');
    if (!isset($all_sessions_list) || empty($all_sessions_list)) {
        if ($has_department_id) {
            $all_sessions_sql = "
                SELECT 
                    s.id,
                    s.id as session_id,
                    s.session_name,
                    s.session_date,
                    s.department_id,
                    COALESCE(d.name, '未指定') as department_name
                FROM admission_sessions s
                LEFT JOIN departments d ON s.department_id COLLATE utf8mb4_unicode_ci = d.code COLLATE utf8mb4_unicode_ci
                WHERE YEAR(s.session_date) = ?
                ORDER BY s.session_date DESC
            ";
        } else {
            $all_sessions_sql = "
                SELECT 
                    s.id,
                    s.id as session_id,
                    s.session_name,
                    s.session_date,
                    NULL as department_id,
                    '未指定' as department_name
                FROM admission_sessions s
                WHERE YEAR(s.session_date) = ?
                ORDER BY s.session_date DESC
            ";
        }
        $all_sessions_stmt = $conn->prepare($all_sessions_sql);
        $all_sessions_list = [];
        if ($all_sessions_stmt) {
            $all_sessions_stmt->bind_param("i", $current_year);
            $all_sessions_stmt->execute();
            $all_sessions_result = $all_sessions_stmt->get_result();
            if ($all_sessions_result) {
                $all_sessions_list = $all_sessions_result->fetch_all(MYSQLI_ASSOC);
                error_log('當前年份場次列表查詢成功，獲取 ' . count($all_sessions_list) . ' 筆場次');
            } else {
                error_log('場次列表查詢執行失敗: ' . $conn->error);
            }
            $all_sessions_stmt->close();
        } else {
            error_log('場次列表查詢準備失敗: ' . $conn->error);
        }
    } else {
        // 如果已經有資料，過濾出當前年份的場次
        $all_sessions_list = array_filter($all_sessions_list, function($session) use ($current_year) {
            if (!$session['session_date']) return false;
            $session_year = date('Y', strtotime($session['session_date']));
            return $session_year == $current_year;
        });
        $all_sessions_list = array_values($all_sessions_list); // 重新索引陣列
        error_log('從已有資料過濾出當前年份場次: ' . count($all_sessions_list) . ' 筆');
    }
    
    // 獲取新生基本資料統計（學校來源和科系分布）
    $new_student_school_stats = [];
    $new_student_department_stats = [];
    $has_new_student_department_id = false;
    
    try {
        // 檢查表是否存在
        $table_check = $conn->query("SHOW TABLES LIKE 'new_student_basic_info'");
        if ($table_check && $table_check->num_rows > 0) {
            // 取得當前學年度範圍
            $academic_year = getCurrentAcademicYearRange();
            
            // 根據視圖類型構建 WHERE 條件
            // 五專修業 5 年，畢業日：入學第 5 年的 7/31
            $graduateExpr = "DATE(CONCAT(YEAR(created_at) + 5, '-07-31'))";
            
            // 查詢所有可用的學年度選項（用於歷屆學生選擇）
            $available_roc_years = [];
            if ($new_student_view === 'previous') {
                // 查詢所有歷屆學生的學年度（非新生但仍在學）
                $yearSql = "SELECT DISTINCT 
                    (CASE 
                        WHEN MONTH(created_at) < 6 THEN YEAR(created_at) - 1
                        ELSE YEAR(created_at)
                    END) - 1911 AS roc_year
                    FROM new_student_basic_info
                    WHERE CURDATE() <= DATE(CONCAT(YEAR(created_at) + 5, '-07-31'))
                    AND created_at NOT BETWEEN ? AND ?
                    HAVING roc_year > 0
                    ORDER BY roc_year DESC";
                
                $yearStmt = $conn->prepare($yearSql);
                if ($yearStmt) {
                    $yearStmt->bind_param('ss', $academic_year['start'], $academic_year['end']);
                    $yearStmt->execute();
                    $yearResult = $yearStmt->get_result();
                    if ($yearResult) {
                        while ($yearRow = $yearResult->fetch_assoc()) {
                            $roc_year = (int)$yearRow['roc_year'];
                            if ($roc_year > 0) {
                                $available_roc_years[] = $roc_year;
                            }
                        }
                    }
                    $yearStmt->close();
                }
                rsort($available_roc_years);
            }
            
            // 構建 WHERE 條件和參數
            $where_params = [];
            $where_types = '';
            
            if ($new_student_view === 'previous') {
                // 歷屆學生：非新生但仍在學
                $base_where = " WHERE CURDATE() <= $graduateExpr
                    AND created_at NOT BETWEEN ? AND ?";
                $where_params[] = $academic_year['start'];
                $where_params[] = $academic_year['end'];
                $where_types .= 'ss';
                
                // 如果有選擇學年度，進一步篩選
                if ($selected_roc_year > 0) {
                    $selected_year_range = getAcademicYearRangeByRocYear($selected_roc_year);
                    $base_where .= " AND created_at >= ? AND created_at <= ?";
                    $where_params[] = $selected_year_range['start'];
                    $where_params[] = $selected_year_range['end'];
                    $where_types .= 'ss';
                }
                
                $where_condition = $base_where . " AND ns.previous_school IS NOT NULL AND ns.previous_school != ''";
                $where_condition_dept = $has_new_student_department_id
                    ? ($base_where . " AND ns.department_id IS NOT NULL AND ns.department_id != ''")
                    : null;
            } else {
                // 新生：當學年度新生
                $where_condition = " WHERE CURDATE() <= $graduateExpr
                    AND created_at BETWEEN ? AND ?
                    AND ns.previous_school IS NOT NULL AND ns.previous_school != ''";
                $where_condition_dept = $has_new_student_department_id
                    ? (" WHERE CURDATE() <= $graduateExpr
                    AND created_at BETWEEN ? AND ?
                    AND ns.department_id IS NOT NULL AND ns.department_id != ''")
                    : null;
                $where_params = [$academic_year['start'], $academic_year['end']];
                $where_types = 'ss';
            }
            
            // 查詢學校來源統計（按 previous_school 分組，包含科系信息）
            // 修正：只按 previous_school 分組，避免重複行
            $school_stats_sql = "
                SELECT 
                    COALESCE(sd.name, ns.previous_school, '未填寫') AS school_name,
                    ns.previous_school AS school_code,
                    COUNT(*) AS student_count
                FROM new_student_basic_info ns
                LEFT JOIN school_data sd ON ns.previous_school COLLATE utf8mb4_unicode_ci = sd.school_code COLLATE utf8mb4_unicode_ci
                $where_condition
                GROUP BY ns.previous_school
                ORDER BY student_count DESC, school_name ASC
            ";
            $school_stmt = $conn->prepare($school_stats_sql);
            if ($school_stmt) {
                if (!empty($where_params)) {
                    $school_stmt->bind_param($where_types, ...$where_params);
                }
                $school_stmt->execute();
                $school_stats_result = $school_stmt->get_result();
                if ($school_stats_result) {
                    $schools_data = $school_stats_result->fetch_all(MYSQLI_ASSOC);
                    
                    // 調試：記錄查詢結果
                    error_log('主查詢返回學校數: ' . count($schools_data) . ' 筆');
                    foreach ($schools_data as $school) {
                        error_log('  - 學校: ' . $school['school_name'] . ' (代碼: ' . $school['school_code'] . ') 學生數: ' . $school['student_count']);
                    }
                    
                    // 去重：確保每個學校只出現一次（按 school_code）
                    $schools_unique = [];
                    foreach ($schools_data as $school) {
                        $school_code = $school['school_code'] ?? '';
                        if ($school_code !== '' && !isset($schools_unique[$school_code])) {
                            $schools_unique[$school_code] = $school;
                        }
                    }
                    $schools_data = array_values($schools_unique);
                    
                    // 調試：記錄去重後結果
                    error_log('去重後學校數: ' . count($schools_data) . ' 筆');
                    error_log('去重後的數據結構: ' . json_encode($schools_data));
                    
                    // 為每個學校查詢科系分布（修正：只按 department_id 分組）
                    foreach ($schools_data as $idx => &$school) {
                        $school_code = $school['school_code'] ?? null;
                        error_log("[$idx] 學校 school_code: " . ($school_code ? "存在 ($school_code)" : "NULL"));
                        error_log("[$idx] 完整學校數據: " . json_encode($school));
                        
                        if ($school_code === null || $school_code === '') {
                            error_log("[$idx] 警告：school_code 為空，跳過此學校");
                            continue;
                        }
                        
                        error_log('正在查詢學校科系: ' . $school['school_name'] . ' (代碼: ' . $school_code . ')');
                        
                        if (!$has_new_student_department_id) {
                            $school['departments'] = [];
                            error_log('  科系欄位不存在，略過科系查詢');
                            continue;
                        }
                        // 重置參數（每次循環都要重置）
                        $dept_where_params = [$school_code];
                        $dept_where_types = 's';
                        $dept_base_where = "WHERE ns.previous_school = ?
                            AND ns.department_id IS NOT NULL AND ns.department_id != ''
                            AND CURDATE() <= DATE(CONCAT(YEAR(ns.created_at) + 5, '-07-31'))";
                        
                        if ($new_student_view === 'previous') {
                            $dept_base_where .= " AND ns.created_at NOT BETWEEN ? AND ?";
                            $dept_where_params[] = $academic_year['start'];
                            $dept_where_params[] = $academic_year['end'];
                            $dept_where_types .= 'ss';
                            
                            if ($selected_roc_year > 0) {
                                $selected_year_range = getAcademicYearRangeByRocYear($selected_roc_year);
                                $dept_base_where .= " AND ns.created_at >= ? AND ns.created_at <= ?";
                                $dept_where_params[] = $selected_year_range['start'];
                                $dept_where_params[] = $selected_year_range['end'];
                                $dept_where_types .= 'ss';
                            }
                        } else {
                            $dept_base_where .= " AND ns.created_at BETWEEN ? AND ?";
                            $dept_where_params[] = $academic_year['start'];
                            $dept_where_params[] = $academic_year['end'];
                            $dept_where_types .= 'ss';
                        }
                        
                        $dept_sql = "
                            SELECT 
                                COALESCE(d.name, ns.department_id, '未填寫') AS department_name,
                                ns.department_id,
                                COUNT(*) AS student_count
                            FROM new_student_basic_info ns
                            LEFT JOIN departments d ON ns.department_id COLLATE utf8mb4_unicode_ci = d.code COLLATE utf8mb4_unicode_ci
                            $dept_base_where
                            GROUP BY ns.department_id
                            ORDER BY student_count DESC, department_name ASC
                        ";
                        $dept_stmt = $conn->prepare($dept_sql);
                        if ($dept_stmt) {
                            $dept_stmt->bind_param($dept_where_types, ...$dept_where_params);
                            $dept_stmt->execute();
                            $dept_result = $dept_stmt->get_result();
                            $school['departments'] = $dept_result->fetch_all(MYSQLI_ASSOC);
                            error_log('  科系數: ' . count($school['departments']) . ' 個');
                            $dept_stmt->close();
                        } else {
                            $school['departments'] = [];
                            error_log('  科系查詢失敗: ' . $conn->error);
                        }
                    }
                    unset($school);  // 重要：清除引用
                    
                    // 🔴 關鍵調試：在賦值前檢查 $schools_data
                    error_log('【賦值前】$schools_data 內容:');
                    error_log('  數量: ' . count($schools_data));
                    error_log('  JSON: ' . json_encode($schools_data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
                    
                    $new_student_school_stats = $schools_data;
                    error_log('最終保存的學校數: ' . count($new_student_school_stats) . ' 筆');
                    
                    // 🔴 關鍵調試：在賦值後檢查 $new_student_school_stats
                    error_log('【賦值後】$new_student_school_stats 內容:');
                    error_log('  數量: ' . count($new_student_school_stats));
                    error_log('  JSON: ' . json_encode($new_student_school_stats, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
                }
                $school_stmt->close();
            }
            
            // 查詢科系分布統計（按 department_id 分組）- 保留用於單獨顯示
            // 修正：只按 department_id 分組，避免重複行
            if ($has_new_student_department_id && $where_condition_dept) {
                $dept_stats_sql = "
                    SELECT 
                        COALESCE(d.name, ns.department_id, '未填寫') AS department_name,
                        ns.department_id,
                        COUNT(*) AS student_count
                    FROM new_student_basic_info ns
                    LEFT JOIN departments d ON ns.department_id COLLATE utf8mb4_unicode_ci = d.code COLLATE utf8mb4_unicode_ci
                    $where_condition_dept
                    GROUP BY ns.department_id
                    ORDER BY student_count DESC, department_name ASC
                ";
                $dept_stmt = $conn->prepare($dept_stats_sql);
                if ($dept_stmt) {
                    if (!empty($where_params)) {
                        $dept_stmt->bind_param($where_types, ...$where_params);
                    }
                    $dept_stmt->execute();
                    $dept_stats_result = $dept_stmt->get_result();
                    if ($dept_stats_result) {
                        $new_student_department_stats = $dept_stats_result->fetch_all(MYSQLI_ASSOC);
                    }
                    $dept_stmt->close();
                }
            } else {
                $new_student_department_stats = [];
            }
            
            // 獲取所有科系列表（用於科系選擇下拉選單）
            $all_departments_sql = "SELECT code, name FROM departments ORDER BY name ASC";
            $all_departments_result = $conn->query($all_departments_sql);
            $all_departments = [];
            if ($all_departments_result) {
                $all_departments = $all_departments_result->fetch_all(MYSQLI_ASSOC);
            }
        }
    } catch (Exception $e) {
        error_log('查詢新生基本資料統計失敗: ' . $e->getMessage());
    }

    // 將「本屆新生入學」(new_student_basic_info) 合併回依學校彙整清單：
    // - 招生中心（全校層級）：看該國中來本校的人數（全校）
    // - 科主任（科系層級）：看該國中來「本科系」的人數
    $new_students_total_by_school = [];
    $new_students_by_school_dept = [];
    
    // 🔍 詳細調試：在合併前檢查兩個陣列
    error_log('【關鍵調試】合併前的 new_student_school_stats:');
    error_log('  數量: ' . count($new_student_school_stats));
    error_log('  類型: ' . gettype($new_student_school_stats));
    error_log('  JSON: ' . json_encode($new_student_school_stats, JSON_UNESCAPED_UNICODE));
    
    error_log('【關鍵調試】$school_summary_list:');
    error_log('  數量: ' . count($school_summary_list));
    error_log('  類型: ' . gettype($school_summary_list));
    
    if (!empty($new_student_school_stats) && is_array($new_student_school_stats)) {
        foreach ($new_student_school_stats as $srow) {
            $sc = (string)($srow['school_code'] ?? '');
            if ($sc === '') continue;
            $new_students_total_by_school[$sc] = (int)($srow['student_count'] ?? 0);
            $dept_list = $srow['departments'] ?? [];
            if (is_array($dept_list)) {
                foreach ($dept_list as $drow) {
                    $dept_id = (string)($drow['department_id'] ?? '');
                    if ($dept_id === '') continue;
                    $new_students_by_school_dept[$sc][$dept_id] = (int)($drow['student_count'] ?? 0);
                }
            }
        }
    }
    if (!empty($school_summary_list) && is_array($school_summary_list)) {
        foreach ($school_summary_list as &$sitem) {
            $sc = (string)($sitem['school_code'] ?? '');
            $sitem['new_students_total'] = $new_students_total_by_school[$sc] ?? 0;
            $sitem['new_students_dept'] = ($is_director && !$is_stam && !empty($user_department_code))
                ? (($new_students_by_school_dept[$sc][$user_department_code] ?? 0))
                : 0;
        }
        unset($sitem);
    }

    if ($result) {
        $teachers_with_records = $result->fetch_all(MYSQLI_ASSOC);
    }
}

// 續招錄取統計已移除，後續將重做

$conn->close();
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Topics 後台管理系統</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <style>
        :root {
            --primary-color: #1890ff;
            --text-color: #262626;
            --text-secondary-color: #8c8c8c;
            --border-color: #f0f0f0;
            --background-color: #f0f2f5;
            --card-background-color: #fff;
            --secondary-color: #764ba2;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
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
            min-height: 100vh;
        }

        .dashboard {
            display: flex;
            min-height: 100vh;
        }

        .main-content {
            flex: 1;
            overflow-x: hidden;
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
        }

        .card {
            background: var(--card-background-color);
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
            overflow: hidden;
        }

        .card-header {
            background: #fafafa;
            color: var(--text-color);
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border-color);
        }

        .card-header h3 {
            margin: 0;
            font-size: 1.3em;
            font-weight: 600;
        }

        .card-body {
            padding: 20px;
        }

        .search-input {
            padding: 10px 15px;
            border: 2px solid #e9ecef;
            border-radius: 25px;
            font-size: 14px;
            width: 300px;
            transition: all 0.3s ease;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        /* 響應式設計 - 篩選控制項 */
        @media (max-width: 768px) {
            .card-header > div:last-child {
                grid-template-columns: 1fr !important;
            }
            
            .search-input {
                width: 100% !important;
            }
        }

        .table-container {
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .table th,
        .table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }

        .table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #495057;
        }

        .table tbody tr:hover {
            background: #f8f9fa;
        }

        /* 按鈕樣式維持原樣不隨整體版型變更 */
        .btn-view {
            background: #667eea;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 20px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }

        .btn-view:hover {
            background: #764ba2;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }

        .empty-state i {
            color: #dee2e6;
            margin-bottom: 15px;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 0;
            border-radius: 15px;
            width: 80%;
            max-width: 600px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 20px;
            border-radius: 15px 15px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            margin: 0;
            font-size: 1.3em;
        }

        .close {
            color: white;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            transition: opacity 0.3s ease;
        }

        .close:hover {
            opacity: 0.7;
        }

        .modal-body {
            padding: 20px;
            max-height: 400px;
            overflow-y: auto;
        }

        .modal-footer {
            padding: 15px 20px;
            border-top: 1px solid #e9ecef;
            text-align: right;
        }

        .btn {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .btn:hover {
            background: var(--secondary-color);
        }

        .chart-container {
            position: relative;
            height: 400px;
            margin: 20px auto;
            max-width: 90%;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .chart-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .chart-title {
            font-size: 1.3em;
            font-weight: 600;
            color: #333;
            margin-bottom: 15px;
            text-align: center;
        }

        .chart-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
        }

        .table-container {
            overflow-x: auto;
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
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(24,144,255,0.2);
        }
        .empty-state {
            text-align: center;
            padding: 40px;
            color: var(--text-secondary-color);
        }
        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            color: #d9d9d9;
        }
        .detail-row {
            background: #f9f9f9;
        }
        .table-row-clickable {
            cursor: pointer;
        }
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
        /* Tab 容器 */
.dept-tabs {
    display: flex;
    border-bottom: 2px solid #e9ecef;
    margin-bottom: 20px;
    gap: 10px;
}

/* Tab 按鈕 */
.dept-tab-btn {
    padding: 10px 20px;
    background: transparent;
    border: none;
    border-bottom: 3px solid transparent;
    color: #6c757d;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    font-size: 15px;
}

/* 滑鼠經過 */
.dept-tab-btn:hover {
    color: #667eea;
    background-color: rgba(102, 126, 234, 0.05);
}

/* 啟用狀態 */
.dept-tab-btn.active {
    color: #667eea;
    border-bottom-color: #667eea;
}

/* 內容區塊 */
.dept-tab-content {
    display: none; /* 預設隱藏 */
    animation: fadeIn 0.3s ease;
}

.dept-tab-content.active {
    display: block; /* 啟用時顯示 */
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(5px); }
    to { opacity: 1; transform: translateY(0); }
}
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include 'sidebar.php'; ?>
        <div class="main-content" id="mainContent">
            <?php include 'header.php'; ?>
            <?php if ($teacher_id > 0): // 詳細記錄視圖 ?>
                <div class="content">
                    <div class="page-controls">
                        <div class="breadcrumb">
                            <a href="index.php">首頁</a> / <a href="teacher_activity_records.php">教師活動紀錄</a> / <?php echo htmlspecialchars($teacher_name); ?>
                        </div>
                        <div class="table-search">
                            <input type="text" id="searchInput" class="search-input" placeholder="搜尋學校或類型...">
                        </div>
                    </div>

                    <div class="table-wrapper">
                        <div class="table-container">
                            <?php if (empty($activity_records)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-inbox fa-3x" style="margin-bottom: 16px;"></i>
                                    <p>這位教師目前尚無任何活動紀錄。</p>
                                </div>
                            <?php else: ?>
                                <table class="table" id="recordsTable">
                                    <thead>
                                        <tr>
                                            <th>活動日期</th>
                                            <th>學校名稱</th>
                                            <th>活動類型</th>
                                            <th>活動時間</th>
                                            <th>提交時間</th>
                                            <th>操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($activity_records as $record): ?>
                                        <tr class="table-row-clickable" onclick="toggleDetail(<?php echo $record['id']; ?>)">
                                            <td><?php echo htmlspecialchars($record['activity_date']); ?></td>
                                            <td><?php echo htmlspecialchars($record['school_name'] ?? $record['school'] ?? '未設定'); ?></td>
                                            <td><?php echo htmlspecialchars($record['activity_type_name'] ?? '未設定'); ?></td>
                                            <td><?php echo ($record['activity_time'] == 1) ? '上班日' : (($record['activity_time'] == 2) ? '假日' : '未設定'); ?></td>
                                            <td><?php echo date('Y/m/d H:i', strtotime($record['created_at'])); ?></td>
                                            <td onclick="event.stopPropagation();">
                                                <button type="button" class="btn-view" id="detail-btn-<?php echo $record['id']; ?>" onclick="event.stopPropagation(); toggleDetail(<?php echo $record['id']; ?>)">
                                                    <i class="fas fa-eye"></i> <span class="btn-text">查看詳情</span>
                                                </button>
                                            </td>
                                        </tr>
                                        <tr id="detail-<?php echo $record['id']; ?>" class="detail-row" style="display: none;">
                                            <td colspan="6" style="padding: 20px; background: #f9f9f9; border: 2px solid #b3d9ff; border-radius: 4px;">
                                                <table style="width: 100%; border-collapse: collapse;">
                                                    <tr>
                                                        <td style="width: 50%; vertical-align: top; padding-right: 20px;">
                                                            <h4 style="margin: 0 0 10px 0; font-size: 16px;">基本資訊</h4>
                                                            <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                                                                <tr>
                                                                    <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5; width: 120px;">活動日期</td>
                                                                    <td style="padding: 5px; border: 1px solid #ddd;"><?php echo htmlspecialchars($record['activity_date']); ?></td>
                                                                </tr>
                                                                <tr>
                                                                    <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">學校名稱</td>
                                                                    <td style="padding: 5px; border: 1px solid #ddd;"><?php echo htmlspecialchars($record['school_name'] ?? $record['school'] ?? '未設定'); ?></td>
                                                                </tr>
                                                                <tr>
                                                                    <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">活動類型</td>
                                                                    <td style="padding: 5px; border: 1px solid #ddd;"><?php echo htmlspecialchars($record['activity_type_name'] ?? '未設定'); ?></td>
                                                                </tr>
                                                                <tr>
                                                                    <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">活動時間</td>
                                                                    <td style="padding: 5px; border: 1px solid #ddd;"><?php echo ($record['activity_time'] == 1) ? '上班日' : (($record['activity_time'] == 2) ? '假日' : '未設定'); ?></td>
                                                                </tr>
                                                                <tr>
                                                                    <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">提交時間</td>
                                                                    <td style="padding: 5px; border: 1px solid #ddd;"><?php echo date('Y/m/d H:i', strtotime($record['created_at'])); ?></td>
                                                                </tr>
                                                            </table>
                                                        </td>
                                                        <td style="width: 50%; vertical-align: top; padding-left: 20px;">
                                                            <h4 style="margin: 0 0 10px 0; font-size: 16px;">聯絡資訊</h4>
                                                            <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                                                                <?php if (!empty($record['contact_person'])): ?>
                                                                <tr>
                                                                    <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5; width: 120px;">聯絡人</td>
                                                                    <td style="padding: 5px; border: 1px solid #ddd;"><?php echo htmlspecialchars($record['contact_person']); ?></td>
                                                                </tr>
                                                                <?php endif; ?>
                                                                <?php if (!empty($record['contact_phone'])): ?>
                                                                <tr>
                                                                    <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">聯絡電話</td>
                                                                    <td style="padding: 5px; border: 1px solid #ddd;"><?php echo htmlspecialchars($record['contact_phone']); ?></td>
                                                                </tr>
                                                                <?php endif; ?>
                                                            </table>
                                                            <?php if (!empty($record['participants_other_text']) || !empty($record['feedback_other_text']) || !empty($record['suggestion'])): ?>
                                                            <h4 style="margin: 20px 0 10px 0; font-size: 16px;">其他資訊</h4>
                                                            <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                                                                <?php if (!empty($record['participants_other_text'])): ?>
                                                                <tr>
                                                                    <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5; width: 120px;">參與者其他說明</td>
                                                                    <td style="padding: 5px; border: 1px solid #ddd;"><?php echo nl2br(htmlspecialchars($record['participants_other_text'])); ?></td>
                                                                </tr>
                                                                <?php endif; ?>
                                                                <?php if (!empty($record['feedback_other_text'])): ?>
                                                                <tr>
                                                                    <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">回饋其他說明</td>
                                                                    <td style="padding: 5px; border: 1px solid #ddd;"><?php echo nl2br(htmlspecialchars($record['feedback_other_text'])); ?></td>
                                                                </tr>
                                                                <?php endif; ?>
                                                                <?php if (!empty($record['suggestion'])): ?>
                                                                <tr>
                                                                    <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">建議</td>
                                                                    <td style="padding: 5px; border: 1px solid #ddd;"><?php echo nl2br(htmlspecialchars($record['suggestion'])); ?></td>
                                                                </tr>
                                                                <?php endif; ?>
                                                                <?php if (!empty($record['uploaded_files'])): ?>
                                                                <tr>
                                                                    <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">上傳檔案</td>
                                                                    <td style="padding: 5px; border: 1px solid #ddd;">
                                                                        <?php 
                                                                        $files = json_decode($record['uploaded_files'], true);
                                                                        if (is_array($files) && !empty($files)) {
                                                                            foreach ($files as $file) {
                                                                                echo '<a href="../../Topics-frontend/frontend/' . htmlspecialchars($file) . '" target="_blank" style="display: block; margin-bottom: 4px;">' . htmlspecialchars(basename($file)) . '</a>';
                                                                            }
                                                                        } else {
                                                                            echo '無檔案';
                                                                        }
                                                                        ?>
                                                                    </td>
                                                                </tr>
                                                                <?php endif; ?>
                                                            </table>
                                                            <?php endif; ?>
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
                        <?php if (!empty($activity_records)): ?>
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
                                <span id="pageInfo">顯示第 <span id="currentRange">1-<?php echo min(10, count($activity_records)); ?></span> 筆，共 <?php echo count($activity_records); ?> 筆</span>
                            </div>
                            <div class="pagination-controls">
                                <button id="prevPage" onclick="changePage(-1)" disabled>上一頁</button>
                                <span id="pageNumbers"></span>
                                <button id="nextPage" onclick="changePage(1)">下一頁</button>
                    </div>
                </div>
                        <?php endif; ?>
            <?php else: // 教師列表視圖 ?>
                <div class="content">
                    <div class="page-controls">
                        <div class="breadcrumb">
                            <a href="index.php">首頁</a> / 教師活動紀錄管理
                        </div>
                    </div>

                    <!-- 依學校彙整活動紀錄 -->
                    <div class="card" style="margin-bottom: 24px;">
                        <div class="card-body">
                        <h4 style="color: #667eea; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <i class="fas fa-school"></i> 依學校彙整活動紀錄
                            <span style="font-size: 12px; color: #999; margin-left: 10px;">
                                (<?php echo date('Y年m月d日', strtotime($academic_year_start)); ?> ~ <?php echo date('Y年m月d日', strtotime($academic_year_end)); ?>)
                            </span>
                        </h4>
                        <?php if ($is_staff): ?>
                        <p style="color: #666; font-size: 14px; margin-bottom: 15px;">
                            <i class="fas fa-info-circle"></i> 招生中心視圖（全校層級）：整合教師活動主觀評分 + 入學說明會（報名/出席）數值
                        </p>
                        <?php elseif ($is_director): ?>
                        <p style="color: #666; font-size: 14px; margin-bottom: 15px;">
                            <i class="fas fa-info-circle"></i> 主任視圖（科系層級）：只看本科教師活動 + 本科說明會場次（department_id）
                        </p>
                        <?php endif; ?>
                        
                        <?php if (!empty($school_summary_list)): ?>
                            <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom: 14px;">
                                <button type="button" class="btn-view" id="btnSchoolViewHeatmap" onclick="showSchoolView('heatmap')">
                                    1️⃣ 學校 × 熱度（排序表格）
                                </button>
                                <button type="button" class="btn-view" id="btnSchoolViewFeedback" onclick="showSchoolView('feedback')">
                                    2️⃣ 各國中就讀意願平均（長條圖）
                                </button>
                                <button type="button" class="btn-view" id="btnSchoolViewGrade" onclick="showSchoolView('grade')">
                                    3️⃣ 學校 × 年級學期（堆疊長條圖）
                                </button>
                                <?php if ($is_staff): ?>
                                <button type="button" class="btn-view" id="btnSchoolViewMatrix" onclick="showSchoolView('matrix')">
                                    4️⃣ 參與科系 × 學校（矩陣表）
                                </button>
                                <?php endif; ?>
                            </div>

                            <!-- 1️⃣ Heatmap Table -->
                            <div class="school-view" id="schoolView-heatmap" style="display:block;">
                            <div class="table-wrapper">
                                <div class="table-container">
                                    <table class="table" style="font-size: 14px;">
                                        <thead>
                                            <tr>
                                                <th>學校名稱</th>
                                                <th style="text-align: center;">教師活動</th>
                                                <th style="text-align: center;">教師主觀評分</th>
                                                <th style="text-align: center;">入學說明會</th>
                                                <th style="text-align: center;">
                                                    本屆入學<?php echo ($is_director && !$is_stam) ? '(本科)' : '(全校)'; ?>
                                                </th>
                                                <th style="text-align: center;">熱度指數</th>
                                                <?php if ($is_director): // 主任顯示詳細資訊 ?>
                                                <th style="text-align: center;">年級學期分析</th>
                                                <th style="text-align: center;">老師</th>
                                                <th style="text-align: center;">填寫日期</th>
                                                <th style="text-align: center;">明細</th>
                                                <?php endif; ?>
                                                <th style="text-align: center;">參與科系</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($school_summary_list as $school):
                                                $feedback_display = [];
                                                if (($school['feedback_count']['熱烈'] ?? 0) > 0) $feedback_display[] = '熱烈: ' . (int)$school['feedback_count']['熱烈'];
                                                if (($school['feedback_count']['普通'] ?? 0) > 0) $feedback_display[] = '普通: ' . (int)$school['feedback_count']['普通'];
                                                if (($school['feedback_count']['意願較低'] ?? 0) > 0) $feedback_display[] = '意願較低: ' . (int)$school['feedback_count']['意願較低'];
                                                if (($school['feedback_count']['未評分'] ?? 0) > 0) $feedback_display[] = '未評分: ' . (int)$school['feedback_count']['未評分'];
                                                if (empty($feedback_display)) $feedback_display[] = '—';
                                                // 教師主觀評分：取整數後轉文字與顏色（3=熱烈, 2=普通, 1=意願較低, 0=未評分）
                                                $feedback_avg_val = (float)($school['feedback_avg'] ?? 0);
                                                $feedback_avg_int = (int)round($feedback_avg_val);
                                                $feedback_level_map = [3 => ['text' => '熱烈', 'color' => '#28a745'], 2 => ['text' => '普通', 'color' => '#17a2b8'], 1 => ['text' => '意願較低', 'color' => '#fd7e14'], 0 => ['text' => '未評分', 'color' => '#6c757d']];
                                                $feedback_level = $feedback_level_map[$feedback_avg_int] ?? $feedback_level_map[0];
                                                // 熱度指數：取整數後依區間轉文字與顏色（>=8 熱烈, 4~7 普通, 1~3 意願較低, 0 冷淡）
                                                $heat_val = (float)($school['heat_index'] ?? 0);
                                                $heat_int = (int)round($heat_val);
                                                if ($heat_int >= 8) { $heat_level = ['text' => '熱烈', 'color' => '#28a745']; }
                                                elseif ($heat_int >= 4) { $heat_level = ['text' => '普通', 'color' => '#17a2b8']; }
                                                elseif ($heat_int >= 1) { $heat_level = ['text' => '意願較低', 'color' => '#fd7e14']; }
                                                else { $heat_level = ['text' => '冷淡', 'color' => '#6c757d']; }
                                            ?>
                                            <?php
                                                $__schoolRowKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)($school['school_code'] ?? $school['school_name'] ?? 'school'));
                                                $__schoolDetailId = 'school-detail-' . $__schoolRowKey;
                                                $__rowBg = 'rgba(102,126,234,0.06)';
                                            ?>
                                            <tr style="background: <?php echo $__rowBg; ?>;">
                                                <td><strong><?php echo htmlspecialchars($school['school_name']); ?></strong></td>
                                                <td style="text-align: center;">
                                                    <div>
                                                        <span class="badge badge-success"><?php echo (int)($school['total_activities'] ?? 0); ?></span>
                                                    </div>
                                                </td>
                                                <td style="text-align: center;">
                                                    <div style="font-size: 12px; line-height: 1.35;">
                                                        <div><?php echo implode(' / ', $feedback_display); ?></div>
                                                        <div style="color:#666; margin-top:4px;">
                                                            平均：<strong style="color:<?php echo $feedback_level['color']; ?>;"><?php echo htmlspecialchars($feedback_level['text']); ?></strong>
                                                            <span style="font-size:11px;">（<?php echo (int)$feedback_avg_int; ?> 分，計分 <?php echo (int)($school['feedback_scored_count'] ?? 0); ?> 次）</span>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td style="text-align: center;">
                                                    <div style="font-size: 12px; line-height: 1.35;">
                                                        報名：<strong><?php echo (int)($school['session_registered_count'] ?? 0); ?></strong><br>
                                                        出席：<strong><?php echo (int)($school['session_attended_count'] ?? 0); ?></strong>
                                                    </div>
                                                </td>
                                                <td style="text-align: center;">
                                                    <?php
                                                        $incoming = ($is_director && !$is_stam && !empty($user_department_code))
                                                            ? (int)($school['new_students_dept'] ?? 0)
                                                            : (int)($school['new_students_total'] ?? 0);
                                                    ?>
                                                    <?php echo $incoming; ?>
                                                </td>
                                                <td style="text-align: center; max-width: 200px;">
                                                    <strong style="color:<?php echo $heat_level['color']; ?>;"><?php echo htmlspecialchars($heat_level['text']); ?></strong>
                                                    <span style="font-size:11px; color:#666;">（<?php echo $heat_int; ?>）</span>
                                                </td>
                                                <?php if ($is_director): // 主任顯示詳細資訊 ?>
                                                <td style="text-align: center;">
                                                    <div style="font-size: 12px;">
                                                        <?php if ($school['grade_semester']['國二上']['count'] > 0): ?>
                                                            <div>國二上: <?php echo $school['grade_semester']['國二上']['count']; ?> 次
                                                                <?php 
                                                                $g2u_feedback = [];
                                                                if ($school['grade_semester']['國二上']['feedback']['熱烈'] > 0) $g2u_feedback[] = '熱烈';
                                                                if ($school['grade_semester']['國二上']['feedback']['普通'] > 0) $g2u_feedback[] = '普通';
                                                                if ($school['grade_semester']['國二上']['feedback']['意願較低'] > 0) $g2u_feedback[] = '意願較低';
                                                                if ($school['grade_semester']['國二上']['feedback']['未評分'] > 0) $g2u_feedback[] = '未評分';
                                                                if (!empty($g2u_feedback)) echo ' (' . implode('/', $g2u_feedback) . ')';
                                                                ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if ($school['grade_semester']['國二下']['count'] > 0): ?>
                                                            <div>國二下: <?php echo $school['grade_semester']['國二下']['count']; ?> 次
                                                                <?php 
                                                                $g2d_feedback = [];
                                                                if ($school['grade_semester']['國二下']['feedback']['熱烈'] > 0) $g2d_feedback[] = '熱烈';
                                                                if ($school['grade_semester']['國二下']['feedback']['普通'] > 0) $g2d_feedback[] = '普通';
                                                                if ($school['grade_semester']['國二下']['feedback']['意願較低'] > 0) $g2d_feedback[] = '意願較低';
                                                                if ($school['grade_semester']['國二下']['feedback']['未評分'] > 0) $g2d_feedback[] = '未評分';
                                                                if (!empty($g2d_feedback)) echo ' (' . implode('/', $g2d_feedback) . ')';
                                                                ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if ($school['grade_semester']['國三上']['count'] > 0): ?>
                                                            <div>國三上: <?php echo $school['grade_semester']['國三上']['count']; ?> 次
                                                                <?php 
                                                                $g3u_feedback = [];
                                                                if ($school['grade_semester']['國三上']['feedback']['熱烈'] > 0) $g3u_feedback[] = '熱烈';
                                                                if ($school['grade_semester']['國三上']['feedback']['普通'] > 0) $g3u_feedback[] = '普通';
                                                                if ($school['grade_semester']['國三上']['feedback']['意願較低'] > 0) $g3u_feedback[] = '意願較低';
                                                                if ($school['grade_semester']['國三上']['feedback']['未評分'] > 0) $g3u_feedback[] = '未評分';
                                                                if (!empty($g3u_feedback)) echo ' (' . implode('/', $g3u_feedback) . ')';
                                                                ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if ($school['grade_semester']['其他']['count'] > 0): ?>
                                                            <div>其他: <?php echo $school['grade_semester']['其他']['count']; ?> 次</div>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td style="text-align: center; white-space: nowrap;">
                                                    <?php echo htmlspecialchars($school['last_teacher_name'] ?? ''); ?>
                                                </td>
                                                <td style="text-align: center; white-space: nowrap;">
                                                    <?php echo !empty($school['last_created_at']) ? date('Y/m/d', strtotime($school['last_created_at'])) : ''; ?>
                                                </td>
                                                <td style="text-align: center; white-space: nowrap;">
                                                    <button type="button" class="btn-view" style="padding: 4px 10px; font-size: 12px;" onclick="toggleSchoolDetail('<?php echo htmlspecialchars($__schoolDetailId, ENT_QUOTES, 'UTF-8'); ?>')">
                                                        <i class="fas fa-list"></i> 明細
                                                    </button>
                                                </td>
                                                <?php endif; ?>
                                                <td style="text-align: center;">
                                                    <?php if (!empty($school['departments'])): ?>
                                                        <div style="font-size: 12px;">
                                                            <?php echo implode('<br>', array_map('htmlspecialchars', $school['departments'])); ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <span style="color: #999;">未記錄</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php if ($is_director): ?>
                                            <tr id="<?php echo htmlspecialchars($__schoolDetailId, ENT_QUOTES, 'UTF-8'); ?>" style="display: none;">
                                                <td colspan="11" style="padding: 16px; background: #fafafa; border-top: 1px solid #eee;">
                                                    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:10px;">
                                                        <div style="font-weight: 600; color: #333;">
                                                            <?php echo htmlspecialchars($school['school_name']); ?>：教師填寫明細
                                                        </div>
                                                        <button type="button" class="btn-view" style="padding: 4px 10px; font-size: 12px; background:#dc3545;" onclick="toggleSchoolDetail('<?php echo htmlspecialchars($__schoolDetailId, ENT_QUOTES, 'UTF-8'); ?>')">
                                                            <i class="fas fa-times"></i> 收起
                                                        </button>
                                                    </div>
                                                    <div style="overflow-x:auto;">
                                                        <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                                                            <thead>
                                                                <tr>
                                                                    <th style="text-align:left; padding:8px; border-bottom:1px solid #e6e6e6;">老師</th>
                                                                    <th style="text-align:left; padding:8px; border-bottom:1px solid #e6e6e6;">活動日期</th>
                                                                    <th style="text-align:left; padding:8px; border-bottom:1px solid #e6e6e6;">填寫日期</th>
                                                                    <th style="text-align:left; padding:8px; border-bottom:1px solid #e6e6e6;">回饋</th>
                                                                    <th style="text-align:left; padding:8px; border-bottom:1px solid #e6e6e6;">年級/學期</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <?php
                                                                    $records = $school['records'] ?? [];
                                                                    usort($records, function($a, $b) {
                                                                        return strtotime((string)($b['created_at'] ?? '')) <=> strtotime((string)($a['created_at'] ?? ''));
                                                                    });
                                                                    foreach ($records as $r):
                                                                ?>
                                                                    <tr>
                                                                        <td style="padding:8px; border-bottom:1px solid #f0f0f0;"><?php echo htmlspecialchars($r['teacher_name'] ?? ''); ?></td>
                                                                        <td style="padding:8px; border-bottom:1px solid #f0f0f0;"><?php echo htmlspecialchars($r['activity_date'] ?? ''); ?></td>
                                                                        <td style="padding:8px; border-bottom:1px solid #f0f0f0;"><?php echo !empty($r['created_at']) ? date('Y/m/d', strtotime($r['created_at'])) : ''; ?></td>
                                                                        <td style="padding:8px; border-bottom:1px solid #f0f0f0;"><?php echo htmlspecialchars($r['feedback'] ?? ''); ?></td>
                                                                        <td style="padding:8px; border-bottom:1px solid #f0f0f0;"><?php echo htmlspecialchars($r['grade_semester'] ?? ''); ?></td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endif; ?>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            </div>

                            <!-- 2️⃣ 各國中就讀意願平均（長條圖）— 依上方「學校 × 熱度」表格同一筆資料繪製 -->
                            <div class="school-view" id="schoolView-feedback" style="display:none; margin-top: 12px;">
                                <div class="table-wrapper" style="padding: 16px;">
                                    <div style="font-weight: 600; margin-bottom: 8px;">2️⃣ 各國中「就讀意願平均」— 長條圖（Bar Chart）</div>
                                    <div style="font-size: 12px; color: #666; margin-bottom: 8px;">
                                        資料來源：同上「1️⃣ 學校 × 熱度（排序表格）」同一筆資料，Y 軸對應表格「熱度指數」欄之數值。<br>
                                        用途：快速看哪間國中熱、哪間冷。X 軸：國中名稱；Y 軸：熱度指數（熱烈≥8、普通4-7、較低1-3、冷淡0）。<?php echo $is_stam ? '招生中心：全校資料。' : '各科老師：僅顯示各國中來本科的活動數據。'; ?>
                                    </div>
                                    <canvas id="schoolFeedbackScoreChart" height="140"></canvas>
                                </div>
                            </div>

                            <!-- 3️⃣ 學校 × 年級學期（堆疊長條圖） -->
                            <div class="school-view" id="schoolView-grade" style="display:none; margin-top: 12px;">
                                <div class="table-wrapper" style="padding: 16px;">
                                    <div style="font-weight: 600; margin-bottom: 8px;">3️⃣ 學校 × 年級學期 — 堆疊長條圖</div>
                                    <canvas id="schoolGradeSemesterChart" height="140"></canvas>
                                </div>
                            </div>

                            <?php if ($is_staff): ?>
                            <!-- 4️⃣ 參與科系 × 學校（矩陣表）— 僅招生中心顯示 -->
                            <div class="school-view" id="schoolView-matrix" style="display:none; margin-top: 12px;">
                                <div class="table-wrapper" style="padding: 16px;">
                                    <div style="font-weight: 600; margin-bottom: 8px;">4️⃣ 參與科系 × 學校 — 矩陣表（交叉分析）</div>
                                    <div id="deptSchoolMatrixTable" style="overflow-x: auto;"></div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <script>
                                // 依學校彙整資料（給圖表使用）
                                window.__schoolSummaryList = <?php echo json_encode($school_summary_list ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
                                window.__schoolSummaryRole = { 
                                    isDirector: <?php echo $is_director ? 'true' : 'false'; ?>, 
                                    isStaff: <?php echo $is_staff ? 'true' : 'false'; ?> 
                                };
                                
                                window.__schoolCharts = window.__schoolCharts || {};
                                window.__schoolChartsRendered = window.__schoolChartsRendered || { feedback:false, grade:false, matrix:false };

                                function toggleSchoolDetail(id) {
                                    const el = document.getElementById(id);
                                    if (!el) return;
                                    el.style.display = (el.style.display === 'none' || el.style.display === '') ? 'table-row' : 'none';
                                }

                                function buildDeptSchoolMatrixTable(data) {
                                    const container = document.getElementById('deptSchoolMatrixTable');
                                    if (!container) return;

                                    const deptSet = new Set();
                                    data.forEach(s => {
                                        const counts = s.dept_counts || {};
                                        Object.keys(counts).forEach(d => deptSet.add(d));
                                    });
                                    const deptList = Array.from(deptSet).sort((a, b) => a.localeCompare(b, 'zh-Hant'));

                                    let html = '<table class="table" style="font-size: 13px; white-space: nowrap;">';
                                    html += '<thead><tr><th>學校</th>';
                                    deptList.forEach(d => { html += '<th style="text-align:center;">' + escapeHtml(d) + '</th>'; });
                                    html += '</tr></thead><tbody>';
                                    data.forEach(s => {
                                        html += '<tr><td><strong>' + escapeHtml(s.school_name || '') + '</strong></td>';
                                        deptList.forEach(d => {
                                            const v = (s.dept_counts && s.dept_counts[d]) ? s.dept_counts[d] : 0;
                                            html += '<td style="text-align:center;">' + v + '</td>';
                                        });
                                        html += '</tr>';
                                    });
                                    html += '</tbody></table>';
                                    container.innerHTML = html;
                                }

                                function escapeHtml(str) {
                                    return String(str)
                                        .replaceAll('&', '&amp;')
                                        .replaceAll('<', '&lt;')
                                        .replaceAll('>', '&gt;')
                                        .replaceAll('"', '&quot;')
                                        .replaceAll("'", '&#039;');
                                }

                                function renderFeedbackChart() {
                                    // 與「1️⃣ 學校 × 熱度（排序表格）」同一筆資料、同一順序，長條圖化表格「熱度指數」欄之數值
                                    const data = Array.isArray(window.__schoolSummaryList) ? window.__schoolSummaryList : [];
                                    if (!data.length) return;
                                    const labels = data.map(s => s.school_name);
                                    // Y 軸：表格「熱度指數」（heat_index）
                                    const heatValues = data.map(s => parseFloat(s.heat_index) || 0);
                                    const heatValuesMapped = heatValues.map(v => {
                                        const heat_int = Math.round(v);
                                        if (heat_int >= 8) return 3;      // 熱烈
                                        else if (heat_int >= 4) return 2; // 普通
                                        else if (heat_int >= 1) return 1; // 較低
                                        else return 0;                    // 冷淡
                                    });
                                    const heatLabels = heatValuesMapped.map(v => {
                                        const labelMap = { 3: '熱烈', 2: '普通', 1: '較低', 0: '冷淡' };
                                        return labelMap[v];
                                    });
                                    if (window.__schoolCharts.feedbackScore) window.__schoolCharts.feedbackScore.destroy();
                                    const ctx = document.getElementById('schoolFeedbackScoreChart')?.getContext('2d');
                                    if (!ctx) return;
                                    window.__schoolCharts.feedbackScore = new Chart(ctx, {
                                        type: 'bar',
                                        data: { 
                                            labels, 
                                            datasets: [{ 
                                                label: '熱度指數',
                                                data: heatValues,
                                                backgroundColor: heatValuesMapped.map(v => {
                                                    const colorMap = { 3: '#28a745', 2: '#17a2b8', 1: '#fd7e14', 0: '#6c757d' };
                                                    return colorMap[v];
                                                })
                                            }] 
                                        },
                                        options: { 
                                            responsive: true, 
                                            plugins: { 
                                                legend: { display: false },
                                                tooltip: {
                                                    callbacks: {
                                                        label: function(context) {
                                                            const value = context.parsed.y;
                                                            const heat_int = Math.round(value);
                                                            let label = '';
                                                            if (heat_int >= 8) label = '熱烈';
                                                            else if (heat_int >= 4) label = '普通';
                                                            else if (heat_int >= 1) label = '較低';
                                                            else label = '冷淡';
                                                            return '熱度指數: ' + value.toFixed(1) + ' (' + label + ')';
                                                        }
                                                    }
                                                }
                                            }, 
                                            scales: { 
                                                y: { 
                                                    beginAtZero: true,
                                                    ticks: {
                                                        callback: function(value) {
                                                            return value;
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    });
                                }

                                function renderGradeChart() {
                                    const data = Array.isArray(window.__schoolSummaryList) ? window.__schoolSummaryList : [];
                                    if (!data.length) return;
                                    const labels = data.map(s => s.school_name);
                                    const gsKeys = ['國二上', '國二下', '國三上', '其他'];
                                    const gsDatasets = gsKeys.map((k, idx) => ({
                                        label: k,
                                        data: data.map(s => ((s.grade_semester && s.grade_semester[k] && s.grade_semester[k].count) ? s.grade_semester[k].count : 0)),
                                        backgroundColor: ['#1890ff', '#13c2c2', '#faad14', '#bfbfbf'][idx]
                                    }));
                                    if (window.__schoolCharts.gradeSemester) window.__schoolCharts.gradeSemester.destroy();
                                    const ctx = document.getElementById('schoolGradeSemesterChart')?.getContext('2d');
                                    if (!ctx) return;
                                    window.__schoolCharts.gradeSemester = new Chart(ctx, {
                                        type: 'bar',
                                        data: { labels, datasets: gsDatasets },
                                        options: { responsive: true, plugins: { legend: { position: 'top' } }, scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true } } }
                                    });
                                }

                                function collapseAllSchoolDetails() {
                                    // 只在 heatmap 展開時需要；切走時全部收起，避免佔版面
                                    document.querySelectorAll('tr[id^="school-detail-"]').forEach(tr => {
                                        tr.style.display = 'none';
                                    });
                                }

                                function setActiveSchoolViewButton(view) {
                                    const ids = window.__schoolSummaryRole && window.__schoolSummaryRole.isStaff
                                        ? ['heatmap','feedback','grade','matrix']
                                        : ['heatmap','feedback','grade'];
                                    ids.forEach(v => {
                                        const btn = document.getElementById('btnSchoolView' + v.charAt(0).toUpperCase() + v.slice(1));
                                        if (!btn) return;
                                        btn.style.background = (v === view) ? '#1890ff' : '';
                                        btn.style.color = (v === view) ? '#fff' : '';
                                    });
                                }

                                function showSchoolView(view) {
                                    const views = window.__schoolSummaryRole && window.__schoolSummaryRole.isStaff
                                        ? ['heatmap','feedback','grade','matrix']
                                        : ['heatmap','feedback','grade'];
                                    views.forEach(v => {
                                        const el = document.getElementById('schoolView-' + v);
                                        if (el) el.style.display = (v === view) ? 'block' : 'none';
                                    });

                                    if (view !== 'heatmap') collapseAllSchoolDetails();
                                    setActiveSchoolViewButton(view);

                                    // lazy render
                                    if (view === 'feedback' && !window.__schoolChartsRendered.feedback) {
                                        renderFeedbackChart();
                                        window.__schoolChartsRendered.feedback = true;
                                    }
                                    if (view === 'grade' && !window.__schoolChartsRendered.grade) {
                                        renderGradeChart();
                                        window.__schoolChartsRendered.grade = true;
                                    }
                                    if (view === 'matrix' && window.__schoolSummaryRole && window.__schoolSummaryRole.isStaff && !window.__schoolChartsRendered.matrix) {
                                        const data = Array.isArray(window.__schoolSummaryList) ? window.__schoolSummaryList : [];
                                        buildDeptSchoolMatrixTable(data);
                                        window.__schoolChartsRendered.matrix = true;
                                    }
                                }

                                document.addEventListener('DOMContentLoaded', function() {
                                    // 預設顯示最推薦的 1️⃣
                                    showSchoolView('heatmap');
                                });
                            </script>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-school fa-3x" style="margin-bottom: 16px;"></i>
                                <h4>目前沒有學校活動紀錄</h4>
                                <p>系統中尚未有依學校彙整的活動紀錄資料</p>
                            </div>
                        <?php endif; ?>
                        </div>
                    </div>

                    <!-- 統計分析區塊 -->
                    <div class="card">
                        <div class="card-body">
                        <h4 style="color: #667eea; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <i class="fas fa-chart-bar"></i> 新生經營統計分析
                        </h4>
                        <!-- 新生經營活動鈕組 -->
                            <div style="display: flex; gap: 12px; margin-bottom: 24px; flex-wrap: wrap;">
                                <button class="btn-view" onclick="showNewStudentSchoolStats()">
                                    <i class="fas fa-school"></i> 學校來源統計
                                </button>
                                <button class="btn-view" onclick="showNewStudentSchoolChart()">
                                    <i class="fas fa-chart-bar"></i> 學校統計
                                </button>
                                <button class="btn-view" onclick="showNewStudentDepartmentStats()">
                                    <i class="fas fa-layer-group"></i> 科系統計
                                </button>
                                <button class="btn-view" onclick="clearNewStudentCharts()" style="background: #dc3545; color: white; border-color: #dc3545;">
                                    <i class="fas fa-arrow-up"></i> 收回圖表
                                </button>
                            </div>
                        
                        <!-- 新生經營活動內容區域 -->
                        <div id="newstudentAnalyticsContent" style="min-height: 200px;">
                            <div class="empty-state">
                                <i class="fas fa-chart-line fa-3x" style="margin-bottom: 16px;"></i>
                                <h4>選擇上方的統計類型來查看詳細分析</h4>
                                <p>提供新生經營活動參與度、活動類型分布、時間趨勢等多維度統計</p>
                            </div>
                        </div>
                    </div>

                        <h4 style="color: #667eea; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                            <i class="fas fa-chart-bar"></i> 招生活動統計分析
                        </h4>

                        <!-- 招生活動統計按鈕組 -->
                            <div style="display: flex; gap: 12px; margin-bottom: 24px; flex-wrap: wrap;">
                                <button class="btn-view" onclick="showTeacherStats()"><i class="fas fa-users"></i> 教師活動統計</button>
                                <button class="btn-view" onclick="showActivityTypeStats()"><i class="fas fa-chart-pie"></i> 活動類型分析</button>
                                <button class="btn-view" onclick="showTimeStats()"><i class="fas fa-calendar-alt"></i> 時間分布分析</button>
                                <button class="btn-view" onclick="showSchoolStats()"><i class="fas fa-school"></i> 合作學校統計</button>
                            <button class="btn-view" onclick="clearActivityCharts()" style="background: #dc3545; color: white; border-color: #dc3545;">
                                <i class="fas fa-arrow-up"></i> 收回圖表
                            </button>
                            </div>
                        
                        <!-- 招生活動統計內容區域 -->
                        <div id="activityAnalyticsContent" style="min-height: 200px;">
                                <div class="empty-state">
                                    <i class="fas fa-chart-line fa-3x" style="margin-bottom: 16px;"></i>
                                    <h4>選擇上方的統計類型來查看詳細分析</h4>
                                    <p>提供教師活動參與度、活動類型分布、時間趨勢等多維度統計</p>
                                </div>
                            </div>
                        
                        <!-- 就讀意願統計按鈕組 -->
                        <div style="border-top: 1px solid #f0f0f0; padding-top: 20px; margin-top: 20px;">
                            <h4 style="color: #667eea; margin-bottom: 15px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                                <i class="fas fa-graduation-cap"></i> 就讀意願統計分析
                                <span style="font-weight: normal; font-size: 0.9em;">
                                    <label for="enrollmentRocYearSelect" style="margin-left: 8px; color: #666;">屆別：</label>
                                    <select id="enrollmentRocYearSelect" onchange="onEnrollmentRocYearChange()" style="padding: 6px 10px; border-radius: 6px; border: 1px solid #ddd; min-width: 100px;">
                                        <option value="">全部</option>
                                        <!-- 屆別選項由 JS 載入 available_roc_years 後填入 -->
                                    </select>
                                </span>
                            </h4>
                            <div class="dept-tabs" style="margin-bottom: 20px;">
    <button type="button" class="dept-tab-btn active" onclick="switchEnrollmentTab(this, 'system')">
        <i class="fas fa-chart-bar"></i> 各科分配人數總覽
    </button>
    
    <button type="button" class="dept-tab-btn" onclick="switchEnrollmentTab(this, 'monthly')">
        <i class="fas fa-calendar-alt"></i> 月度趨勢分析
    </button>
    
    <?php if ($is_admin || $is_school_admin): ?>
    <button type="button" class="dept-tab-btn" onclick="switchEnrollmentTab(this, 'school_dept')">
        <i class="fas fa-school"></i> 國中選擇科系分析
    </button>
    <?php endif; ?>
    
    <div style="margin-left: auto;">
        <button type="button" onclick="clearEnrollmentCharts(); resetEnrollmentTabs();" style="border: none; background: none; color: #dc3545; cursor: pointer; font-size: 14px;">
            <i class="fas fa-times"></i> 清除/收合
        </button>
    </div>
</div>
                        </div>
                        
                        <!-- 就讀意願統計內容區域 -->
                        <div id="enrollmentAnalyticsContent" style="min-height: 200px;">
                        <div class="empty-state">
                                    <i class="fas fa-chart-line fa-3x" style="margin-bottom: 16px;"></i>
                                    <h4>選擇上方的統計類型來查看詳細分析</h4>
                                </div>
</div>
                        
                        <!-- 續招報名統計按鈕組 -->
                        <div style="border-top: 1px solid #f0f0f0; padding-top: 20px; margin-top: 20px;">
                            <h4 style="color: #667eea; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                                <i class="fas fa-file-alt"></i> 續招報名統計分析
                            </h4>
                            <div style="display: flex; gap: 12px; margin-bottom: 24px; flex-wrap: wrap;">
                                <button class="btn-view" onclick="showContinuedAdmissionGenderStats()">
                                    <i class="fas fa-venus-mars"></i> 性別分布分析
                                </button>
                                <button class="btn-view" onclick="showContinuedAdmissionCityStats()">
                                    <i class="fas fa-map-marker-alt"></i> 縣市分布分析
                                </button>
                                <button class="btn-view" onclick="showContinuedAdmissionMonthlyStats()">
                                    <i class="fas fa-calendar-alt"></i> 月度趨勢分析
                                </button>
                                <button class="btn-view" onclick="showContinuedAdmissionStatusStats()">
                                    <i class="fas fa-clipboard-check"></i> 審核狀態分析
                                </button>
                                <button class="btn-view" onclick="showContinuedAdmissionQuotaStats()">
                                    <i class="fas fa-chart-bar"></i> 錄取名額分析
                                </button>
                                <button class="btn-view" onclick="clearContinuedAdmissionCharts()" style="background: #dc3545; color: white; border-color: #dc3545;">
                                    <i class="fas fa-arrow-up"></i> 收回圖表
                                </button>
                            </div>
                        </div>
                        
                        <!-- 續招報名統計內容區域 -->
                        <div id="continuedAdmissionAnalyticsContent" style="min-height: 200px; margin-left: 15px; margin-right: 15px;">
                            <div style="margin-bottom: 20px;">
                                <h4 style="color: #667eea; margin-bottom: 15px;">
                                    <i class="fas fa-list-ol"></i> 志願選擇分析
                                    <span style="font-size: 0.8em; color: #999; margin-left: 10px;">（<?php echo $current_user === 'IMD' ? '資管科專屬視圖' : '續招報名統計專屬視圖'; ?>）</span>
                                </h4>
                                
                                <div class="chart-card">
                                    <div class="chart-title">志願選擇分布</div>
                                    <div class="chart-container">
                                        <canvas id="continuedAdmissionChoicesChart"></canvas>
                                    </div>
                                </div>
                                
                                <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                                    <h5 style="color: #333; margin-bottom: 15px;">志願詳細統計</h5>
                                    <div id="continuedAdmissionChoicesStats" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                                        <!-- 統計數據將由JavaScript動態載入 -->
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- 五專入學說明會統計按鈕組 -->
                        <div id="admissionStatsSection" style="border-top: 1px solid #f0f0f0; padding-top: 20px; margin-top: 20px; margin-left:15px;">
                            <h4 style="color: #667eea; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                                <i class="fas fa-graduation-cap"></i> 五專入學說明會統計分析
                            </h4>
                            <div style="display: flex; gap: 12px; margin-bottom: 24px; flex-wrap: wrap;">
                                <button class="btn-view" onclick="showAttendanceStats()" style="background: var(--success-color); color: white; border-color: var(--success-color);">
                                    <i class="fas fa-check-circle"></i> 出席統計圖表
                                </button>
                                <button class="btn-view" onclick="showAdmissionGradeStats()">
                                    <i class="fas fa-users"></i> 年級分布分析
                                </button>
                                <button class="btn-view" onclick="showAdmissionSchoolStats()">
                                    <i class="fas fa-school"></i> 學校分布分析
                                </button>
                                <button class="btn-view" onclick="showAdmissionSessionStats()">
                                    <i class="fas fa-calendar-alt"></i> 場次分布分析
                                </button>
                                <button class="btn-view" onclick="showAdmissionCourseStats()">
                                    <i class="fas fa-book-open"></i> 課程選擇分析
                                </button>
                                <button class="btn-view" onclick="showAdmissionReceiveInfoStats()">
                                    <i class="fas fa-envelope"></i> 資訊接收分析
                                </button>
                                <button class="btn-view" onclick="clearAdmissionCharts()" style="background: #dc3545; color: white; border-color: #dc3545;">
                                    <i class="fas fa-arrow-up"></i> 收回圖表
                                </button>
                            </div>
                        </div>
                        
                        <!-- 五專入學說明會統計內容區域 -->
                        <div id="admissionAnalyticsContent" style="min-height: 200px; margin-left: 15px; margin-right: 15px;">
                            <div class="empty-state">
                                <i class="fas fa-graduation-cap fa-3x" style="margin-bottom: 16px;"></i>
                                <h4>選擇上方的統計類型來查看詳細分析</h4>
                                <p>提供出席統計、年級分布、學校分布、場次分布、課程選擇、資訊接收等多維度統計</p>
                            </div>
                        </div>
                        </div>
                    </div>


                <?php endif; ?>
                </div>
        </div>
    </div>

    <!-- 查看 Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">查看活動紀錄</h3>
                <span class="close" onclick="closeModal('viewModal')">&times;</span>
            </div>
            <div class="modal-body" id="viewModalBody">
                <!-- Content will be injected by JS -->
            </div>
            <div class="modal-footer">
                <button class="btn" onclick="closeModal('viewModal')">關閉</button>
            </div>
        </div>
    </div>

    <script>
        // 切換「就讀意願統計」的 Tab
function switchEnrollmentTab(btn, type) {
    // 1. 處理外觀：移除所有按鈕的 active，並將當前按鈕設為 active
    const container = btn.parentElement;
    const buttons = container.querySelectorAll('.dept-tab-btn');
    buttons.forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    // 2. 執行邏輯：呼叫原本對應的圖表函式
    switch(type) {
        case 'system':
            showEnrollmentSystemStats();
            break;
        case 'grade':
            showEnrollmentGradeStats();
            break;
        case 'monthly':
            showEnrollmentMonthlyStats();
            break;
        case 'school_dept':
            // 檢查函式是否存在 (因為有權限判斷)
            if (typeof showEnrollmentSchoolDepartmentStats === 'function') {
                showEnrollmentSchoolDepartmentStats();
            }
            break;
    }
}

// 重置 Tab 狀態 (當點擊「清除/收合」時，移除所有 Tab 的選取狀態)
function resetEnrollmentTabs() {
    const tabs = document.querySelectorAll('.dept-tabs .dept-tab-btn');
    tabs.forEach(b => b.classList.remove('active'));
    // 可以選擇是否要讓第一個 Tab 回復預設，或是全部不選
    // 這裡示範全部不選，代表收合狀態
}
    // 將 PHP 數據傳遞給 JavaScript
    const activityRecords = <?php echo json_encode($all_activity_records ?? []); ?>;
    const attendanceStatsData = <?php echo json_encode(isset($attendance_stats_data) ? $attendance_stats_data : []); ?>;
    const allSessionsList = <?php echo json_encode(isset($all_sessions_list) ? $all_sessions_list : []); ?>;
    
    // 調試：輸出資料到控制台
    console.log('=== 出席統計資料調試 ===');
    console.log('attendanceStatsData 數量:', attendanceStatsData ? attendanceStatsData.length : 0);
    console.log('allSessionsList 數量:', allSessionsList ? allSessionsList.length : 0);
    if (attendanceStatsData && attendanceStatsData.length > 0) {
        console.log('attendanceStatsData 第一筆:', attendanceStatsData[0]);
    }
    const isTeacherListView = <?php echo $teacher_id > 0 ? 'false' : 'true'; ?>;
    const userDepartment = '<?php echo $user_department; ?>';
    const currentUser = '<?php echo $current_user; ?>';
    const userRole = '<?php echo $user_role; ?>';
    const isSchoolAdmin = <?php echo $is_school_admin ? 'true' : 'false'; ?>;
    
    // 從學校名稱中提取縣市（全局函數，供多處使用）
    function extractCityFromSchoolName(schoolName) {
        if (!schoolName) return '';
        
        // 先嘗試匹配 XX市立 或 XX縣立（例如：台北市立XX國中）
        let cityMatch = schoolName.match(/^(.+?)(?:市立|縣立)/);
        if (cityMatch) {
            const cityName = cityMatch[1] + (schoolName.includes('市立') ? '市' : '縣');
            return cityName;
        }
        
        // 再嘗試匹配 XX市 或 XX縣（在開頭，例如：台北市XX國中）
        cityMatch = schoolName.match(/^(.+?)(?:市|縣)/);
        if (cityMatch) {
            const cityName = cityMatch[1] + (schoolName.includes('市') ? '市' : '縣');
            return cityName;
        }
        
        return '';
    }

    // 調試信息
    console.log('PHP 傳遞的數據:', activityRecords);
    console.log('數據長度:', activityRecords.length);
    console.log('當前用戶:', currentUser);
    console.log('用戶部門:', userDepartment);
    console.log('用戶角色:', userRole);
    console.log('是否為學校行政人員:', isSchoolAdmin);
    
    // ========== 輔助函數 ==========
    
    // 構建 API URL，如果用戶有部門限制則添加參數
    function buildApiUrl(baseUrl, action) {
        let url = `${baseUrl}?action=${action}`;
        if (userDepartment) {
            url += `&department=${encodeURIComponent(userDepartment)}`;
        }
        return url;
    }
    
    // 就讀意願圖表專用：可帶入屆別（學年度民國年），僅影響就讀意願相關 API
    function buildEnrollmentApiUrl(baseUrl, action) {
        let url = buildApiUrl(baseUrl, action);
        const rocSelect = document.getElementById('enrollmentRocYearSelect');
        if (rocSelect && rocSelect.value !== '') {
            url += '&roc_year=' + encodeURIComponent(rocSelect.value);
        }
        return url;
    }
    
    // 目前就讀意願區顯示的圖表類型，用於切換屆別時重新載入同一圖表
    let currentEnrollmentChartType = 'system';
    
    function onEnrollmentRocYearChange() {
        if (typeof currentEnrollmentChartType === 'undefined') return;
        const fnMap = {
            'department': showEnrollmentDepartmentStats,
            'system': showEnrollmentSystemStats,
            'grade': showEnrollmentGradeStats,
            'monthly': showEnrollmentMonthlyStats,
            'school_department': showEnrollmentSchoolDepartmentStats
        };
        const fn = fnMap[currentEnrollmentChartType];
        if (typeof fn === 'function') fn();
    }
    
    function loadEnrollmentRocYearOptions() {
        const sel = document.getElementById('enrollmentRocYearSelect');
        if (!sel) return;
        const apiUrl = buildApiUrl('../../Topics-frontend/frontend/api/enrollment_stats_api.php', 'available_roc_years');
        fetch(apiUrl).then(r => r.json()).then(years => {
            if (!Array.isArray(years) || years.length === 0) return;
            const keepFirst = sel.options.length > 0 ? sel.options[0].cloneNode(true) : null;
            sel.innerHTML = '';
            if (keepFirst) sel.appendChild(keepFirst);
            let firstYear = null;
            years.forEach(roc => {
                const opt = document.createElement('option');
                opt.value = roc;
                opt.textContent = roc + '學年';
                sel.appendChild(opt);
                if (firstYear === null) firstYear = roc;
            });
            // 預設選擇該學年（第一個年份）
            if (firstYear !== null) {
                sel.value = firstYear;
            }
        }).catch(() => {});
    }
    
    // ========== 簡化版測試函數 ==========
    
    
    // 調試：檢查函數是否正確定義
    console.log('函數檢查:');
    console.log('showTeacherStats:', typeof showTeacherStats);
    console.log('showActivityTypeStats:', typeof showActivityTypeStats);
    console.log('showTimeStats:', typeof showTimeStats);
    console.log('showSchoolStats:', typeof showSchoolStats);
    
    // 招生活動統計 - 教師活動統計
        function showTeacherStats() {
        console.log('showTeacherStats 被調用');
        
        // 統計每位教師的活動
            const teacherStats = {};
            activityRecords.forEach(record => {
                const teacherName = record.teacher_name || '未知教師';
            const department = record.teacher_department || '未知科系';
            
                if (!teacherStats[teacherName]) {
                teacherStats[teacherName] = {
                    name: teacherName,
                    department: department,
                    totalActivities: 0
                };
            }
            teacherStats[teacherName].totalActivities++;
        });
        
        const teacherStatsArray = Object.values(teacherStats).sort((a, b) => b.totalActivities - a.totalActivities);
            
            const content = `
            <div style="margin-bottom: 20px;">
                <h4 style="color: #667eea; margin-bottom: 15px;">
                    <i class="fas fa-users"></i> 教師活動統計
                </h4>
                
                <div class="chart-card">
                    <div class="chart-title">教師活動參與度</div>
                <div class="chart-container">
                        <canvas id="teacherActivityChart"></canvas>
                    </div>
                </div>
                
                <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                    <h5 style="color: #333; margin-bottom: 15px;">教師詳細統計</h5>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
                        ${teacherStatsArray.map(teacher => `
                            <div style="background: white; padding: 20px; border-radius: 10px; border-left: 4px solid #667eea;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                                    <h5 style="margin: 0; color: #333;">${teacher.name}</h5>
                                    <span style="background: #667eea; color: white; padding: 4px 8px; border-radius: 12px; font-size: 0.8em;">
                                        ${teacher.department}
                                    </span>
                                </div>
                                <div style="text-align: center; background: #f8f9fa; padding: 10px; border-radius: 8px;">
                                    <div style="font-size: 1.5em; font-weight: bold; color: #667eea;">${teacher.totalActivities}</div>
                                    <div style="font-size: 0.8em; color: #666;">總活動數</div>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                </div>
                </div>
            `;

        document.getElementById('activityAnalyticsContent').innerHTML = content;
        
        // 創建長條圖
            setTimeout(() => {
            const canvasElement = document.getElementById('teacherActivityChart');
            if (!canvasElement) return;
            
            const ctx = canvasElement.getContext('2d');
            new Chart(ctx, {
                    type: 'bar',
                    data: {
                    labels: teacherStatsArray.map(teacher => teacher.name),
                        datasets: [{
                        label: '活動數量',
                        data: teacherStatsArray.map(teacher => teacher.totalActivities),
                        backgroundColor: '#667eea',
                        borderColor: '#5a6fd8',
                            borderWidth: 1
                        }]
                    },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    }
                }
                });
            }, 100);
        }

        function showActivityTypeStats() {
        console.log('showActivityTypeStats 被調用');
        
        // 統計活動類型（使用 activity_type_name 若可用）
            const typeStats = {};
            activityRecords.forEach(record => {
                const type = record.activity_type_name || record.activity_type || '未知類型';
                if (!typeStats[type]) {
                    typeStats[type] = 0;
                }
                typeStats[type]++;
            });
        
        const typeStatsArray = Object.entries(typeStats).map(([type, count]) => ({
            type,
            count,
            percentage: ((count / activityRecords.length) * 100).toFixed(1)
        })).sort((a, b) => b.count - a.count);

            const content = `
            <div style="margin-bottom: 20px;">
                <h4 style="color: #667eea; margin-bottom: 15px;">
                    <i class="fas fa-chart-pie"></i> 活動類型分布分析
                </h4>
                
                <div class="chart-card">
                    <div class="chart-title">活動類型圓餅圖</div>
                    <div class="chart-container">
                        <canvas id="activityTypePieChart"></canvas>
                    </div>
                </div>
                
                <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                    <h5 style="color: #333; margin-bottom: 15px;">詳細統計數據</h5>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                        ${typeStatsArray.map((item, index) => {
                            const colors = ['#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8'];
                            const color = colors[index % colors.length];
                            return `
                                <div style="background: white; padding: 15px; border-radius: 8px; border-left: 4px solid ${color};">
                                    <div style="font-weight: bold; color: #333; margin-bottom: 5px;">${item.type}</div>
                                    <div style="font-size: 1.5em; font-weight: bold; color: ${color};">${item.count}次</div>
                                    <div style="font-size: 0.9em; color: #666;">${item.percentage}%</div>
                </div>
            `;
                        }).join('')}
                    </div>
                </div>
            </div>
        `;
        
        document.getElementById('activityAnalyticsContent').innerHTML = content;
        
        // 創建圓餅圖
            setTimeout(() => {
            const canvasElement = document.getElementById('activityTypePieChart');
            if (!canvasElement) return;
            
            const ctx = canvasElement.getContext('2d');
            new Chart(ctx, {
                type: 'pie',
                    data: {
                    labels: typeStatsArray.map(item => item.type),
                        datasets: [{
                        data: typeStatsArray.map(item => item.count),
                        backgroundColor: [
                            '#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8'
                        ],
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 20,
                                usePointStyle: true,
                                font: { size: 16 }
                            }
                        },
                        tooltip: {
                            enabled: true,
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.parsed;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = ((value / total) * 100).toFixed(1);
                                    return `${label}: ${value}次 (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
                });
            }, 100);
        }

        function showTimeStats() {
        console.log('showTimeStats 被調用');
        
        // 統計月份分布
        const monthStats = {};
            activityRecords.forEach(record => {
            if (record.activity_date) {
                const date = new Date(record.activity_date);
                const month = date.getMonth() + 1; // 0-11 -> 1-12
                const monthKey = `${month}月`;
                
                if (!monthStats[monthKey]) {
                    monthStats[monthKey] = 0;
                }
                monthStats[monthKey]++;
            }
        });
        
        // 統計星期分布
        const dayStats = {};
        activityRecords.forEach(record => {
            if (record.activity_date) {
                const date = new Date(record.activity_date);
                const dayOfWeek = date.getDay(); // 0=Sunday, 1=Monday, ...
                const dayNames = ['星期日', '星期一', '星期二', '星期三', '星期四', '星期五', '星期六'];
                const dayKey = dayNames[dayOfWeek];
                
                if (!dayStats[dayKey]) {
                    dayStats[dayKey] = 0;
                }
                dayStats[dayKey]++;
            }
        });
        
        const monthStatsArray = Object.entries(monthStats).sort((a, b) => {
            const monthA = parseInt(a[0]);
            const monthB = parseInt(b[0]);
            return monthA - monthB;
        });
        
        const dayStatsArray = Object.entries(dayStats).sort((a, b) => {
            const dayOrder = ['星期一', '星期二', '星期三', '星期四', '星期五', '星期六', '星期日'];
            return dayOrder.indexOf(a[0]) - dayOrder.indexOf(b[0]);
        });

            const content = `
            <div style="margin-bottom: 20px;">
                <h4 style="color: #667eea; margin-bottom: 15px;">
                    <i class="fas fa-calendar-alt"></i> 時間分布分析
                </h4>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div class="chart-card">
                        <div class="chart-title">月份分布</div>
                <div class="chart-container">
                            <canvas id="monthStatsChart"></canvas>
                        </div>
                    </div>
                    
                    <div class="chart-card">
                        <div class="chart-title">星期分布</div>
                        <div class="chart-container">
                            <canvas id="dayStatsChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <div style="background: #f8f9fa; padding: 20px; border-radius: 10px;">
                    <h5 style="color: #333; margin-bottom: 15px;">詳細時間統計</h5>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div>
                            <h6 style="color: #667eea; margin-bottom: 10px;">月份統計</h6>
                            ${monthStatsArray.map(([month, count]) => `
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid #eee;">
                                    <span>${month}</span>
                                    <span style="font-weight: bold; color: #667eea;">${count}次</span>
                                </div>
                            `).join('')}
                        </div>
                        <div>
                            <h6 style="color: #667eea; margin-bottom: 10px;">星期統計</h6>
                            ${dayStatsArray.map(([day, count]) => `
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid #eee;">
                                    <span>${day}</span>
                                    <span style="font-weight: bold; color: #667eea;">${count}次</span>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                </div>
                </div>
            `;

        document.getElementById('activityAnalyticsContent').innerHTML = content;
        
        // 創建圖表
            setTimeout(() => {
            // 月份分布圖
            const monthCanvas = document.getElementById('monthStatsChart');
            if (monthCanvas) {
                const monthCtx = monthCanvas.getContext('2d');
                new Chart(monthCtx, {
                    type: 'line',
                    data: {
                        labels: monthStatsArray.map(([month]) => month),
                        datasets: [{
                            label: '活動數量',
                            data: monthStatsArray.map(([, count]) => count),
                            borderColor: '#667eea',
                            backgroundColor: 'rgba(102, 126, 234, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false }
                        },
                        scales: {
                            y: { beginAtZero: true, ticks: { stepSize: 1 } }
                        }
                    }
                });
            }
            
            // 星期分布圖
            const dayCanvas = document.getElementById('dayStatsChart');
            if (dayCanvas) {
                const dayCtx = dayCanvas.getContext('2d');
                new Chart(dayCtx, {
                    type: 'bar',
                    data: {
                        labels: dayStatsArray.map(([day]) => day),
                        datasets: [{
                            label: '活動數量',
                            data: dayStatsArray.map(([, count]) => count),
                            backgroundColor: '#28a745',
                            borderColor: '#1e7e34',
                            borderWidth: 1
                        }]
                    },
                    options: { 
                        responsive: true, 
                        maintainAspectRatio: false, 
                        plugins: {
                            legend: { display: false }
                        },
                        scales: {
                            y: { beginAtZero: true, ticks: { stepSize: 1 } }
                        }
                    }
                });
            }
            }, 100);
        }

        function showSchoolStats() {
        console.log('showSchoolStats 被調用');
        
        // 統計合作學校（直接使用 school 欄位文字）
            const schoolStats = {};
            activityRecords.forEach(record => {
            const schoolName = record.school_name || record.school || '未知學校';
            if (!schoolStats[schoolName]) {
                schoolStats[schoolName] = {
                    name: schoolName,
                    count: 0,
                    teachers: new Set()
                };
            }
            schoolStats[schoolName].count++;
            if (record.teacher_name) {
                schoolStats[schoolName].teachers.add(record.teacher_name);
            }
        });
        
        const schoolStatsArray = Object.values(schoolStats).map(school => ({
            ...school,
            teacherCount: school.teachers.size,
            teachers: Array.from(school.teachers)
        })).sort((a, b) => b.count - a.count);

            const content = `
            <div style="margin-bottom: 20px;">
                <h4 style="color: #667eea; margin-bottom: 15px;">
                    <i class="fas fa-school"></i> 合作學校統計
                </h4>
                
                <div class="chart-card">
                    <div class="chart-title">學校合作頻率</div>
                <div class="chart-container">
                    <canvas id="schoolStatsChart"></canvas>
                    </div>
                </div>
                
                <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                    <h5 style="color: #333; margin-bottom: 15px;">學校詳細統計</h5>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 20px;">
                        ${schoolStatsArray.map((school, index) => {
                            const colors = ['#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8'];
                            const color = colors[index % colors.length];
                            return `
                                <div style="background: white; padding: 20px; border-radius: 10px; border-left: 4px solid ${color};">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                                        <h5 style="margin: 0; color: #333;">${school.name}</h5>
                                        <span style="background: ${color}; color: white; padding: 4px 8px; border-radius: 12px; font-size: 0.8em;">
                                            ${school.count}次合作
                                        </span>
                                    </div>
                                    
                                    <div style="margin-bottom: 15px;">
                                        <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                            <span style="color: #666;">參與教師數:</span>
                                            <span style="font-weight: bold; color: ${color};">${school.teacherCount}人</span>
                                        </div>
                                        <div style="display: flex; justify-content: space-between;">
                                            <span style="color: #666;">總活動數:</span>
                                            <span style="font-weight: bold; color: ${color};">${school.count}次</span>
                                        </div>
                                    </div>
                                    
                                    ${school.teachers.length > 0 ? `
                                        <div>
                                            <div style="color: #666; font-size: 0.9em; margin-bottom: 8px;">參與教師:</div>
                                            <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                                                ${school.teachers.map(teacher => `
                                                    <span style="background: #f8f9fa; padding: 2px 6px; border-radius: 4px; font-size: 0.8em; color: #666;">
                                                        ${teacher}
                                                    </span>
                                                `).join('')}
                                            </div>
                                        </div>
                                    ` : ''}
                </div>
            `;
                        }).join('')}
                    </div>
                </div>
            </div>
        `;
        
        document.getElementById('activityAnalyticsContent').innerHTML = content;
        
        // 創建長條圖
            setTimeout(() => {
            const canvasElement = document.getElementById('schoolStatsChart');
            if (!canvasElement) return;
            
            const ctx = canvasElement.getContext('2d');
            new Chart(ctx, {
                    type: 'bar',
                    data: {
                    labels: schoolStatsArray.map(school => school.name),
                        datasets: [{
                        label: '合作次數',
                        data: schoolStatsArray.map(school => school.count),
                        backgroundColor: '#667eea',
                        borderColor: '#5a6fd8',
                            borderWidth: 1
                        }]
                    },
                    options: { 
                        responsive: true, 
                        maintainAspectRatio: false, 
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        },
                        x: {
                            ticks: {
                                maxRotation: 45,
                                minRotation: 0
                            }
                        }
                    }
                    }
                });
            }, 100);
    }
    
    function showAttendanceStats() {
        console.log('showAttendanceStats 被調用');
        console.log('attendanceStatsData:', attendanceStatsData);
        console.log('allSessionsList:', allSessionsList);
        
        // 檢查數據是否存在
        if (!attendanceStatsData || attendanceStatsData.length === 0) {
            const content = `
                <div style="text-align: center; padding: 40px; color: #999;">
                    <i class="fas fa-inbox fa-3x" style="margin-bottom: 16px;"></i>
                    <h4>暫無場次資料</h4>
                    <p>目前系統中還沒有場次資料，請先到「場次設定」頁面新增場次。</p>
                    <a href="settings.php" style="display: inline-block; margin-top: 20px; padding: 10px 20px; background: #667eea; color: white; text-decoration: none; border-radius: 6px;">
                        <i class="fas fa-plus"></i> 前往場次設定
                    </a>
                </div>
            `;
            const admissionContent = document.getElementById('admissionAnalyticsContent');
            if (admissionContent) {
                admissionContent.innerHTML = content;
            }
            return;
        }
        
        // 處理原始數據，添加計算欄位
        const processedData = attendanceStatsData.map(item => ({
            sessionId: item.session_id,
            sessionName: item.session_name || '未命名場次',
            sessionDate: item.session_date,
            sessionEndDate: item.session_end_date,
            departmentId: item.department_id,
            departmentName: item.department_name || '未指定',
            attendanceCount: parseInt(item.attendance_count) || 0,
            registrationCount: parseInt(item.registration_count) || 0,
            attendanceRate: item.registration_count > 0 
                ? ((parseInt(item.attendance_count) || 0) / parseInt(item.registration_count) * 100).toFixed(1)
                : 0
        }));
        
        console.log('processedData:', processedData);

        
        // 生成場次選項（只顯示當前年份的場次）
        const currentYear = new Date().getFullYear();
        const currentYearSessions = allSessionsList.filter(s => {
            if (!s.session_date) return false;
            const sessionYear = new Date(s.session_date).getFullYear();
            return sessionYear === currentYear;
        });
        
        // 調試：輸出場次列表
        console.log('allSessionsList:', allSessionsList);
        console.log('currentYearSessions:', currentYearSessions);
        console.log('processedData:', processedData);
        
        const sessionOptions = currentYearSessions.map(s => {
            // 確保使用正確的 ID 欄位
            const sessionId = s.id || s.session_id;
            console.log('生成場次選項:', s.session_name, 'ID:', sessionId, '原始資料:', s);
            return `<option value="${sessionId}">${s.session_name} (${s.session_date ? new Date(s.session_date).toLocaleDateString('zh-TW') : '未設定日期'})</option>`;
        }).join('');
        
        // 調試：檢查 ID 映射
        console.log('=== 場次 ID 映射檢查 ===');
        console.log('allSessionsList 中的 ID:', currentYearSessions.map(s => ({ name: s.session_name, id: s.id || s.session_id })));
        console.log('processedData 中的 sessionId:', processedData.map(item => ({ name: item.sessionName, sessionId: item.sessionId })));
        
        // 獲取日期範圍
        const dates = processedData.map(item => item.sessionDate).filter(d => d);
        const minDate = dates.length > 0 ? new Date(Math.min(...dates.map(d => new Date(d)))).toISOString().split('T')[0] : '';
        const maxDate = dates.length > 0 ? new Date(Math.max(...dates.map(d => new Date(d)))).toISOString().split('T')[0] : '';
        
        const content = `
            <div style="margin-bottom: 20px;">
                <h4 style="color: #667eea; margin-bottom: 15px;">
                    <i class="fas fa-check-circle"></i> 出席統計圖表
                </h4>
                <p style="color: #666; margin-bottom: 20px;">顯示各場次的出席人數統計，支援依場次、日期、科系等條件篩選，方便分析招生活動效果</p>
                
                <!-- 篩選器 -->
                <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-bottom: 20px;">
                    <h5 style="color: #333; margin-bottom: 15px;">
                        <i class="fas fa-filter"></i> 篩選條件
                    </h5>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
                        <div>
                            <label style="display: block; margin-bottom: 5px; color: #666; font-size: 14px; font-weight: 500;">場次選擇</label>
                            <select id="filterSessionId" onchange="filterAttendanceStats()" style="width: 100%; padding: 8px 12px; border: 1px solid #d9d9d9; border-radius: 6px; background: #fff; color: #333; font-size: 14px; cursor: pointer;">
                                <option value="">全部場次</option>
                                ${sessionOptions}
                            </select>
                        </div>

                        <div>
                            <label style="display: block; margin-bottom: 5px; color: #666; font-size: 14px; font-weight: 500;">開始日期</label>
                            <input type="date" id="filterStartDate" onchange="filterAttendanceStats()" value="${minDate}" style="width: 100%; padding: 8px 12px; border: 1px solid #d9d9d9; border-radius: 6px; background: #fff; color: #333; font-size: 14px;">
                        </div>
                        <div>
                            <label style="display: block; margin-bottom: 5px; color: #666; font-size: 14px; font-weight: 500;">結束日期</label>
                            <input type="date" id="filterEndDate" onchange="filterAttendanceStats()" value="${maxDate}" style="width: 100%; padding: 8px 12px; border: 1px solid #d9d9d9; border-radius: 6px; background: #fff; color: #333; font-size: 14px;">
                        </div>
                    </div>
                    <div style="margin-top: 15px; display: flex; gap: 10px; align-items: center;">
                        <button onclick="filterAttendanceStats()" style="padding: 8px 20px; background: #667eea; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px;">
                            <i class="fas fa-search"></i> 套用篩選
                        </button>
                        <button onclick="resetAttendanceFilters()" style="padding: 8px 20px; background: #999; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px;">
                            <i class="fas fa-redo"></i> 重置
                        </button>
                        <span id="filterResultCount" style="color: #666; font-size: 14px; margin-left: 10px;"></span>
                    </div>
                </div>
                
                <!-- 統計摘要 -->
                <div id="attendanceSummary" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px;"></div>
                
                <!-- 圖表區域 -->
                <div style="display: grid; grid-template-columns: 1fr; gap: 20px;">
                    <div class="chart-card">
                        <div class="chart-title">各場次出席人數統計</div>
                        <div class="chart-container" style="height: 500px;">
                            <canvas id="attendanceSessionChart"></canvas>
                        </div>
                    </div>
                    
                    <div class="chart-card">
                        <div class="chart-title">出席率統計</div>
                        <div class="chart-container" style="height: 400px;">
                            <canvas id="attendanceRateChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <!-- 詳細統計表格 -->
                <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                    <h5 style="color: #333; margin-bottom: 15px;">
                        <i class="fas fa-table"></i> 場次詳細統計
                    </h5>
                    <div style="overflow-x: auto;">
                        <table id="attendanceDetailTable" style="width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden;">
                            <thead>
                                <tr style="background: #667eea; color: white;">
                                    <th style="padding: 12px; text-align: left; font-weight: 600;">場次名稱</th>
                                    <th style="padding: 12px; text-align: center; font-weight: 600;">場次日期</th>
                                    <th style="padding: 12px; text-align: center; font-weight: 600;">報名人數</th>
                                    <th style="padding: 12px; text-align: center; font-weight: 600;">實到人數</th>
                                    <th style="padding: 12px; text-align: center; font-weight: 600;">出席率</th>
                                </tr>
                            </thead>
                            <tbody id="attendanceDetailTableBody">
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        `;
        
        const admissionContent = document.getElementById('admissionAnalyticsContent');
        if (admissionContent) {
            admissionContent.innerHTML = content;
            
            // 儲存原始數據供篩選使用
            window.attendanceStatsRawData = processedData;
            
            // 初始化顯示
            filterAttendanceStats();
        }
    }
    
    // 篩選出席統計
    function filterAttendanceStats() {
        if (!window.attendanceStatsRawData) {
            console.error('attendanceStatsRawData 不存在');
            return;
        }
        
        const sessionId = document.getElementById('filterSessionId')?.value || '';
        const department = document.getElementById('filterDepartment')?.value || '';
        const startDate = document.getElementById('filterStartDate')?.value || '';
        const endDate = document.getElementById('filterEndDate')?.value || '';
        
        let filteredData = [...window.attendanceStatsRawData];
        
        // 應用篩選
        if (sessionId) {
            // 確保類型一致進行比較
            const sessionIdNum = parseInt(sessionId);
            console.log('=== 篩選除錯資訊 ===');
            console.log('選擇的場次 ID:', sessionIdNum);
            console.log('原始資料數量:', window.attendanceStatsRawData.length);
            console.log('原始資料前3筆:', window.attendanceStatsRawData.slice(0, 3).map(item => ({
                sessionId: item.sessionId,
                sessionName: item.sessionName,
                sessionIdType: typeof item.sessionId
            })));
            
            filteredData = filteredData.filter(item => {
                // 處理可能的 ID 欄位名稱不一致問題
                const itemSessionId = parseInt(item.sessionId || item.session_id || 0);
                const match = itemSessionId === sessionIdNum;
                if (match) {
                    console.log('✓ 找到匹配的場次:', item.sessionName, 'ID:', itemSessionId);
                }
                return match;
            });
            
            console.log('篩選後資料數量:', filteredData.length);
            if (filteredData.length > 0) {
                console.log('篩選後資料:', filteredData);
            } else {
                console.warn('⚠ 沒有找到匹配的資料！');
                console.log('所有場次的 ID 列表:', window.attendanceStatsRawData.map(item => ({
                    id: item.sessionId || item.session_id,
                    name: item.sessionName
                })));
            }
        }

        if (startDate) {
            filteredData = filteredData.filter(item => {
                const itemDate = item.sessionDate ? new Date(item.sessionDate).toISOString().split('T')[0] : '';
                return itemDate >= startDate;
            });
        }
        if (endDate) {
            filteredData = filteredData.filter(item => {
                const itemDate = item.sessionDate ? new Date(item.sessionDate).toISOString().split('T')[0] : '';
                return itemDate <= endDate;
            });
        }
        
        // 按日期排序
        filteredData.sort((a, b) => {
            const dateA = a.sessionDate ? new Date(a.sessionDate) : new Date(0);
            const dateB = b.sessionDate ? new Date(b.sessionDate) : new Date(0);
            return dateB - dateA;
        });
        
        // 更新結果計數
        const countElement = document.getElementById('filterResultCount');
        if (countElement) {
            countElement.textContent = `共找到 ${filteredData.length} 個場次`;
        }
        
        // 更新統計摘要
        updateAttendanceSummary(filteredData);
        
        // 更新圖表
        updateAttendanceCharts(filteredData);
        
        // 更新詳細表格
        updateAttendanceDetailTable(filteredData);
    }
    
    // 重置篩選
    function resetAttendanceFilters() {
        const dates = window.attendanceStatsRawData.map(item => item.sessionDate).filter(d => d);
        const minDate = dates.length > 0 ? new Date(Math.min(...dates.map(d => new Date(d)))).toISOString().split('T')[0] : '';
        const maxDate = dates.length > 0 ? new Date(Math.max(...dates.map(d => new Date(d)))).toISOString().split('T')[0] : '';
        
        if (document.getElementById('filterSessionId')) document.getElementById('filterSessionId').value = '';
        if (document.getElementById('filterDepartment')) document.getElementById('filterDepartment').value = '';
        if (document.getElementById('filterStartDate')) document.getElementById('filterStartDate').value = minDate;
        if (document.getElementById('filterEndDate')) document.getElementById('filterEndDate').value = maxDate;
        
        filterAttendanceStats();
    }
    
    // 更新統計摘要
    function updateAttendanceSummary(data) {
        const totalSessions = data.length;
        const totalRegistrations = data.reduce((sum, item) => sum + item.registrationCount, 0);
        const totalAttendances = data.reduce((sum, item) => sum + item.attendanceCount, 0);
        const avgAttendanceRate = totalRegistrations > 0 
            ? ((totalAttendances / totalRegistrations) * 100).toFixed(1)
            : 0;
        
        const summaryHtml = `
            <div style="background: white; padding: 20px; border-radius: 8px; border-left: 4px solid #667eea; text-align: center;">
                <div style="font-size: 14px; color: #999; margin-bottom: 5px;">場次總數</div>
                <div style="font-size: 32px; color: #667eea; font-weight: bold;">${totalSessions}</div>
            </div>
            <div style="background: white; padding: 20px; border-radius: 8px; border-left: 4px solid #52c41a; text-align: center;">
                <div style="font-size: 14px; color: #999; margin-bottom: 5px;">總報名人數</div>
                <div style="font-size: 32px; color: #52c41a; font-weight: bold;">${totalRegistrations}</div>
            </div>
            <div style="background: white; padding: 20px; border-radius: 8px; border-left: 4px solid #1890ff; text-align: center;">
                <div style="font-size: 14px; color: #999; margin-bottom: 5px;">總實到人數</div>
                <div style="font-size: 32px; color: #1890ff; font-weight: bold;">${totalAttendances}</div>
            </div>
            <div style="background: white; padding: 20px; border-radius: 8px; border-left: 4px solid #faad14; text-align: center;">
                <div style="font-size: 14px; color: #999; margin-bottom: 5px;">平均出席率</div>
                <div style="font-size: 32px; color: #faad14; font-weight: bold;">${avgAttendanceRate}%</div>
            </div>
        `;
        
        const summaryElement = document.getElementById('attendanceSummary');
        if (summaryElement) {
            summaryElement.innerHTML = summaryHtml;
        }
    }
    
    // 更新圖表
    function updateAttendanceCharts(data) {
        // 銷毀舊圖表
        if (window.attendanceSessionChartInstance) {
            window.attendanceSessionChartInstance.destroy();
            window.attendanceSessionChartInstance = null;
        }
        if (window.attendanceRateChartInstance) {
            window.attendanceRateChartInstance.destroy();
            window.attendanceRateChartInstance = null;
        }
        
        // 如果沒有數據，顯示空狀態並恢復圖表容器
        if (!data || data.length === 0) {
            const sessionChartContainer = document.querySelector('#attendanceSessionChart')?.closest('.chart-container');
            const rateChartContainer = document.querySelector('#attendanceRateChart')?.closest('.chart-container');
            
            if (sessionChartContainer) {
                sessionChartContainer.innerHTML = '<div style="text-align: center; padding: 40px; color: #999;">沒有符合條件的資料</div>';
            }
            if (rateChartContainer) {
                rateChartContainer.innerHTML = '<div style="text-align: center; padding: 40px; color: #999;">沒有符合條件的資料</div>';
            }
            return;
        }
        
        // 恢復圖表容器（如果之前被替換了）
        const sessionChartCard = document.querySelector('#attendanceSessionChart')?.closest('.chart-card');
        const rateChartCard = document.querySelector('#attendanceRateChart')?.closest('.chart-card');
        if (sessionChartCard) {
            const sessionContainer = sessionChartCard.querySelector('.chart-container');
            if (sessionContainer && !sessionContainer.querySelector('canvas')) {
                sessionContainer.innerHTML = '<canvas id="attendanceSessionChart"></canvas>';
            }
        }
        if (rateChartCard) {
            const rateContainer = rateChartCard.querySelector('.chart-container');
            if (rateContainer && !rateContainer.querySelector('canvas')) {
                rateContainer.innerHTML = '<canvas id="attendanceRateChart"></canvas>';
            }
        }
        
        // 準備數據
        const labels = data.map(item => item.sessionName.length > 20 ? item.sessionName.substring(0, 20) + '...' : item.sessionName);
        const attendanceData = data.map(item => item.attendanceCount);
        const registrationData = data.map(item => item.registrationCount);
        const rateData = data.map(item => parseFloat(item.attendanceRate));
        
        // 創建出席人數橫條圖
        setTimeout(() => {
            const canvasElement = document.getElementById('attendanceSessionChart');
            if (!canvasElement) return;
            
            const ctx = canvasElement.getContext('2d');
            window.attendanceSessionChartInstance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: '實到人數',
                        data: attendanceData,
                        backgroundColor: 'rgba(82, 196, 26, 0.8)',
                        borderColor: '#52c41a',
                        borderWidth: 2
                    }, {
                        label: '報名人數',
                        data: registrationData,
                        backgroundColor: 'rgba(24, 144, 255, 0.6)',
                        borderColor: '#1890ff',
                        borderWidth: 2
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top'
                        },
                        tooltip: {
                            callbacks: {
                                afterLabel: function(context) {
                                    const index = context.dataIndex;
                                    const item = data[index];
                                    return `場次日期: ${item.sessionDate ? new Date(item.sessionDate).toLocaleDateString('zh-TW') : '未設定'}\\n出席率: ${item.attendanceRate}%`;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            },
                            title: {
                                display: true,
                                text: '人數'
                            }
                        },
                        y: {
                            ticks: {
                                maxRotation: 45,
                                minRotation: 0
                            },
                            title: {
                                display: true,
                                text: '場次名稱'
                            }
                        }
                    }
                }
            });
        }, 100);
        
        // 創建出席率折線圖
        setTimeout(() => {
            const rateCanvas = document.getElementById('attendanceRateChart');
            if (!rateCanvas) return;
            
            const rateCtx = rateCanvas.getContext('2d');
            window.attendanceRateChartInstance = new Chart(rateCtx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: '出席率 (%)',
                        data: rateData,
                        borderColor: '#faad14',
                        backgroundColor: 'rgba(250, 173, 20, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointRadius: 5,
                        pointHoverRadius: 7
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const index = context.dataIndex;
                                    const item = data[index];
                                    return `出席率: ${item.attendanceRate}% (${item.attendanceCount}/${item.registrationCount})`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            ticks: {
                                callback: function(value) {
                                    return value + '%';
                                }
                            },
                            title: {
                                display: true,
                                text: '出席率 (%)'
                            }
                        },
                        x: {
                            ticks: {
                                maxRotation: 45,
                                minRotation: 45
                            },
                            title: {
                                display: true,
                                text: '場次名稱'
                            }
                        }
                    }
                }
            });
        }, 200);
    }
    
    // 更新詳細表格
    function updateAttendanceDetailTable(data) {
        const tbody = document.getElementById('attendanceDetailTableBody');
        if (!tbody) return;
        
        if (data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="padding: 20px; text-align: center; color: #999;">沒有符合條件的資料</td></tr>';
            return;
        }
        
        tbody.innerHTML = data.map(item => {
            const sessionDate = item.sessionDate ? new Date(item.sessionDate).toLocaleDateString('zh-TW', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit'
            }) : '未設定';
            const rateColor = parseFloat(item.attendanceRate) >= 70 ? '#52c41a' : parseFloat(item.attendanceRate) >= 50 ? '#faad14' : '#ff4d4f';
            
            return `
                <tr style="border-bottom: 1px solid #f0f0f0;">
                    <td style="padding: 12px;">${item.sessionName}</td>
                    <td style="padding: 12px; text-align: center;">${sessionDate}</td>
                    <td style="padding: 12px; text-align: center;">${item.registrationCount}</td>
                    <td style="padding: 12px; text-align: center; font-weight: 600; color: #52c41a;">${item.attendanceCount}</td>
                    <td style="padding: 12px; text-align: center;">
                        <span style="color: ${rateColor}; font-weight: 600;">${item.attendanceRate}%</span>
                    </td>
                </tr>
            `;
        }).join('');
    }
    
    // 顯示新生學校來源統計
    function showNewStudentSchoolStats() {
        console.log('showNewStudentSchoolStats 被調用');
        sessionStorage.setItem('lastNewStudentChartType', 'schoolStats');
        
        // 🔴 關鍵：在客戶端直接印出 PHP 傳來的原始 JSON
        console.log('%c🚨 PHP $new_student_school_stats (原始):', 'color: red; font-weight: bold;');
        const rawJSON = <?php echo json_encode($new_student_school_stats, JSON_UNESCAPED_UNICODE); ?>;
        console.log('陣列長度:', rawJSON.length);
        console.log('元素 0 的鍵:', Object.keys(rawJSON[0] || {}));
        console.log('元素 1 的鍵:', Object.keys(rawJSON[1] || {}));
        console.log('完整原始數據:', rawJSON);
        
        const schoolStats = rawJSON;
        
        // 逐一檢查每個元素
        for (let i = 0; i < schoolStats.length; i++) {
            const item = schoolStats[i];
            console.log(`\n【${i}】元素詳細檢查:`, {
                '有無 school_name': 'school_name' in item,
                '有無 school_code': 'school_code' in item,
                '有無 student_count': 'student_count' in item,
                '有無 heat_index': 'heat_index' in item,
                '有無 feedback_avg': 'feedback_avg' in item,
                '有無 total_activities': 'total_activities' in item,
                '有無 departments': 'departments' in item,
                '元素的鍵': Object.keys(item)
            });
            
            if (item.feedback_avg !== undefined || item.heat_index !== undefined) {
                console.warn(`⚠️ 元素 [${i}] 看起來來自 school_summary_list（有 feedback_avg/heat_index）！`);
            }
        }
        console.log('=== 結束 ===\n');
        
        // 調試信息：直接顯示在頁面上
        console.log('======================== 調試信息 ========================');
        console.log('總學校數:', schoolStats.length);
        console.log('完整數據:', schoolStats);
        schoolStats.forEach((school, idx) => {
            console.log(`[${idx}] 學校: ${school.school_name} (代碼: ${school.school_code}) 學生: ${school.student_count} 科系: ${school.departments?.length || 0}`);
            if (school.departments) {
                school.departments.forEach((dept, deptIdx) => {
                    console.log(`    [${deptIdx}] ${dept.department_name} (${dept.department_id}): ${dept.student_count}`);
                });
            }
        });
        console.log('====================================================');
        
        if (!schoolStats || schoolStats.length === 0) {
            document.getElementById('newstudentAnalyticsContent').innerHTML = `
                <div style="text-align: center; padding: 40px; color: #6c757d;">
                    <i class="fas fa-inbox fa-3x" style="margin-bottom: 16px;"></i>
                    <h4>暫無數據</h4>
                    <p>目前沒有學校來源統計數據</p>
                </div>
            `;
            return;
        }
        
        const totalStudents = schoolStats.reduce((sum, item) => sum + parseInt(item.student_count || 0), 0);
        
        const content = `
            <div style="background:  border-radius: 10px; padding: 20px; margin-bottom: 20px;">

                <div id="debugInfo" style="background: white; border: 1px solid #dee2e6; border-radius: 8px; padding: 15px; font-family: monospace; font-size: 12px; color: #333; display: none; max-height: 600px; overflow-y: auto;">
                    <div style="margin-bottom: 15px; padding-bottom: 15px; border-bottom: 2px solid #dee2e6;">
                        <div style="margin-bottom: 10px;"><strong>🚨 PHP 數據來源檢查</strong></div>
                        <div style="background: #f8f9fa; padding: 10px; border-radius: 6px; margin-bottom: 10px;">
                            <div style="margin-bottom: 8px; padding: 8px; background: white; border-left: 4px solid #dc3545;">
                                <strong>⚠️ 警告:</strong> 如果下方所有元素都顯示有「feedback_avg」或「heat_index」欄位，
                                表示 PHP 誤將「就讀意願統計」(school_summary_list) 的數據傳給了 JavaScript
                            </div>
                        </div>
                        <div style="background: #f0f0f0; padding: 10px; border-radius: 6px; line-height: 1.8;">
                            ${schoolStats.map((item, idx) => `
                                <div style="margin-bottom: 8px; padding: 8px; background: white; border-radius: 4px; border-left: 4px solid ${(item.feedback_avg !== undefined || item.heat_index !== undefined) ? '#dc3545' : '#28a745'};">
                                    <strong>[${idx}] 數據來源判定:</strong>
                                    <div style="margin-left: 15px; font-size: 11px;">
                                        ${(() => {
                                            const keys = Object.keys(item);
                                            const isNewStudent = 'student_count' in item && 'departments' in item;
                                            const isSchoolSummary = 'feedback_avg' in item || 'heat_index' in item;
                                            
                                            if (isSchoolSummary && !isNewStudent) {
                                                return `<span style="color: #dc3545;">❌ 來自 school_summary_list（不該在這裡！）</span><br/>鍵: ${keys.join(', ')}`;
                                            } else if (isNewStudent) {
                                                return `<span style="color: #28a745;">✓ 來自 new_student_school_stats（正確）</span><br/>鍵: ${keys.join(', ')}`;
                                            } else {
                                                return `<span style="color: #ffc107;">⚠️ 不明的數據來源</span><br/>鍵: ${keys.join(', ')}`;
                                            }
                                        })()}
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                    
                    <div style="margin-bottom: 15px; padding-bottom: 15px; border-bottom: 2px solid #dee2e6;">
                        <div style="margin-bottom: 10px;"><strong>⚠️ PHP 數據結構檢查</strong></div>
                        <div style="background: #f8f9fa; padding: 10px; border-radius: 6px; margin-bottom: 10px; line-height: 1.6; color: #dc3545;">
                            <strong>注意:</strong> 如果下方顯示 null、undefined 或結構異常，表示 PHP 端有問題
                        </div>
                        <div style="background: #f0f0f0; padding: 10px; border-radius: 6px; line-height: 1.8;">
                            <div><strong>schoolStats.length:</strong> <span style="color: #667eea;">${schoolStats.length}</span></div>
                            <div><strong>第一個元素:</strong> <span style="color: #28a745;">${schoolStats[0] ? '✓ 存在' : '✗ NULL'}</span></div>
                            <div><strong>第二個元素:</strong> <span style="color: ${schoolStats[1] ? '#28a745' : '#dc3545;'}">${schoolStats[1] ? '✓ 存在' : '✗ NULL 或 undefined'}</span></div>
                            <div style="margin-top: 10px; padding: 10px; background: white; border-radius: 4px; border-left: 4px solid #dc3545;">
                                <strong>完整 JSON (Raw):</strong><br/>
                                <pre style="margin: 5px 0; white-space: pre-wrap; word-break: break-all; max-height: 150px; overflow-y: auto;">${JSON.stringify(schoolStats, null, 2)}</pre>
                            </div>
                        </div>
                    </div>
                    
                    <div style="margin-bottom: 10px;"><strong>🔍 查詢結果摘要</strong></div>
                    <div style="background: #f8f9fa; padding: 10px; border-radius: 6px; margin-bottom: 10px; line-height: 1.6;">
                        <div>📊 返回學校數: <strong style="color: #dc3545;">${schoolStats.length}</strong> 所</div>
                        <div>👥 總學生人數: <strong style="color: #28a745;">${totalStudents}</strong> 人</div>
                    </div>
                    
                    <div style="margin-bottom: 10px;"><strong>🏫 各校詳細資訊</strong></div>
                    ${schoolStats.map((school, idx) => `
                        <div style="background: #f8f9fa; padding: 10px; margin-bottom: 8px; border-left: 4px solid #667eea; border-radius: 4px;">
                            <div><strong>[${idx + 1}]</strong> <span style="color: #667eea;">${school?.school_name || '❌ NULL'}</span></div>
                            <div style="margin-left: 20px; font-size: 11px; color: #666;">
                                <div>• 代碼: <code style="background: white; padding: 2px 6px; border-radius: 3px; color: #e83e8c;">${school?.school_code || 'NULL'}</code></div>
                                <div>• 學生: <strong style="color: #28a745;">${school?.student_count !== undefined ? school.student_count : '❌ undefined'}</strong> 人</div>
                                <div>• 科系: <strong>${school?.departments?.length !== undefined ? school.departments.length : '❌ undefined'}</strong> 個</div>
                                <div style="margin-top: 6px; padding: 6px; background: white; border-radius: 3px; border-left: 2px solid #ffc107;">
                                    <strong>元素鍵:</strong> <code>${Object.keys(school).join(', ')}</code>
                                </div>
                                ${school?.departments && school.departments.length > 0 ? `
                                    <div style="margin-top: 6px; margin-left: 10px;">
                                        ${school.departments.map(dept => `<div>- ${dept?.department_name || '❌ NULL'} (${dept?.department_id || 'NULL'}): ${dept?.student_count !== undefined ? dept.student_count : '❌ undefined'}人</div>`).join('')}
                                    </div>
                                ` : ''}
                            </div>
                        </div>
                    `).join('')}
                    
                    <div style="margin-top: 15px; padding: 10px; background: #e8f4f8; border-radius: 6px; font-size: 11px; color: #333;">
                        <strong>💡 診斷提示:</strong><br/>
                        • 如果元素顯示 null 或 undefined，表示 PHP 端構建的 JSON 有問題<br/>
                        • 如果所有元素都有 feedback_avg/heat_index，說明 PHP 傳錯數據來源<br/>
                        • 請檢查瀏覽器開發者工具的 Console 標籤查看完整診斷
                    </div>
                </div>
            </div>
            
            <div style="margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
                    <h4 style="color: #667eea; margin: 0;">
                        <i class="fas fa-school"></i> 國中學校來源統計
                    </h4>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <select id="newStudentViewSelect" onchange="changeNewStudentView(this.value)" style="padding: 8px 12px; border: 1px solid #d9d9d9; border-radius: 6px; background: #fff; color: #333; font-size: 14px; cursor: pointer;">
                            <option value="active" <?php echo $new_student_view === 'active' ? 'selected' : ''; ?>>新生資料</option>
                            <option value="previous" <?php echo $new_student_view === 'previous' ? 'selected' : ''; ?>>歷屆學生資料</option>
                        </select>
                        <?php if ($new_student_view === 'previous' && !empty($available_roc_years)): ?>
                            <select id="rocYearSelect" onchange="changeRocYear(this.value)" style="padding: 8px 12px; border: 1px solid #d9d9d9; border-radius: 6px; background: #fff; color: #333; font-size: 14px; cursor: pointer;">
                                <option value="0">全部學年</option>
                                <?php foreach ($available_roc_years as $roc_year): ?>
                                    <option value="<?php echo $roc_year; ?>" <?php echo $selected_roc_year == $roc_year ? 'selected' : ''; ?>>
                                        <?php echo $roc_year; ?>學年
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 10px; margin-bottom: 20px;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; text-align: center;">
                        <div>
                            <div style="font-size: 2.5em; font-weight: bold; margin-bottom: 5px;">${schoolStats.length}</div>
                            <div style="font-size: 1em; opacity: 0.9;">參與國中數</div>
                        </div>
                        <div>
                            <div style="font-size: 2.5em; font-weight: bold; margin-bottom: 5px;">${totalStudents}</div>
                            <div style="font-size: 1em; opacity: 0.9;">總學生數</div>
                        </div>
                    </div>
                </div>
                
                ${schoolStats.length === 0 ? `
                    <div style="text-align: center; padding: 40px; color: #6c757d; background: white; border-radius: 10px;">
                        <i class="fas fa-search fa-3x" style="margin-bottom: 16px; opacity: 0.3;"></i>
                        <h4>沒有資料</h4>
                        <p>目前沒有學校來源數據</p>
                    </div>
                ` : `
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 20px;">
                        ${schoolStats.map((school, index) => {
                            const colors = ['#a8b5f0', '#7dd87d', '#ffd966', '#f5a5a5', '#7dd4e8', '#b8a5e8', '#ffb366', '#7dd4c8'];
                            const color = colors[index % colors.length];
                            
                            return `
                                <div style="background: white; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-radius: 10px; overflow: hidden;">
                                    <div style="background: ${color}; color: white; padding: 15px 20px;">
                                        <h5 style="margin: 0; font-size: 1.1em; font-weight: 600; display: flex; justify-content: space-between; align-items: center;">
                                            <span><i class="fas fa-school"></i> ${school.school_name || '未填寫'}</span>
                                            <span style="background: rgba(255,255,255,0.3); padding: 4px 12px; border-radius: 15px; font-size: 0.9em;">
                                                ${school.student_count || 0}人
                                            </span>
                                        </h5>
                                    </div>
                                    <div style="padding: 20px;">
                                        <div style="margin-bottom: 15px; font-size: 0.9em; color: #666;">
                                            共選擇 <strong style="color: ${color};">${(school.departments || []).length}</strong> 個科系
                                        </div>
                                        
                                        ${(school.departments || []).length > 0 ? `
                                            <div style="max-height: 400px; overflow-y: auto;">
                                                <table style="width: 100%; border-collapse: collapse;">
                                                    <thead>
                                                        <tr style="background: #f8f9fa; border-bottom: 2px solid #dee2e6;">
                                                            <th style="padding: 10px; text-align: left; font-weight: 600; color: #495057; font-size: 0.9em;">科系名稱</th>
                                                            <th style="padding: 10px; text-align: center; font-weight: 600; color: #495057; font-size: 0.9em;">總數</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        ${(school.departments || []).map((dept, deptIndex) => {
                                                            return `
                                                                <tr style="border-bottom: 1px solid #e9ecef; ${deptIndex % 2 === 0 ? 'background: #f8f9fa;' : ''}">
                                                                    <td style="padding: 12px 10px; font-weight: 500; color: #333;">${dept.department_name || '未填寫'}</td>
                                                                    <td style="padding: 12px 10px; text-align: center; font-weight: bold; color: ${color};">${dept.student_count || 0}</td>
                                                                </tr>
                                                            `;
                                                        }).join('')}
                                                    </tbody>
                                                </table>
                                            </div>
                                        ` : `
                                            <div style="text-align: center; padding: 20px; color: #999; font-size: 0.9em;">
                                                <i class="fas fa-info-circle"></i> 此學校的學生尚未填寫科系資訊
                                            </div>
                                        `}
                                    </div>
                                </div>
                            `;
                        }).join('')}
                    </div>
                `}
            </div>
        `;
        
        document.getElementById('newstudentAnalyticsContent').innerHTML = content;
    }
    
    
    // 顯示學校統計（無科系下拉，直接整體統計）
    function showNewStudentSchoolChart() {
        console.log('showNewStudentSchoolChart 被調用');
        sessionStorage.setItem('lastNewStudentChartType', 'schoolChart');
        
        const schoolStats = <?php echo json_encode($new_student_school_stats); ?>;
        
        if (!schoolStats || schoolStats.length === 0) {
            document.getElementById('newstudentAnalyticsContent').innerHTML = `
                <div style="text-align: center; padding: 40px; color: #6c757d;">
                    <i class="fas fa-inbox fa-3x" style="margin-bottom: 16px;"></i>
                    <h4>暫無數據</h4>
                    <p>目前沒有學校統計數據</p>
                </div>
            `;
            return;
        }
        
        const normalized = (schoolStats || [])
            .map(item => ({
                school_name: item.school_name || '未填寫',
                student_count: parseInt(item.student_count || 0) || 0
            }))
            .filter(item => item.student_count > 0)
            .sort((a, b) => b.student_count - a.student_count);
        
        const totalStudents = normalized.reduce((sum, item) => sum + item.student_count, 0);
        
        const content = `
            <div style="margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
                    <h4 style="color: #667eea; margin: 0;">
                        <i class="fas fa-chart-bar"></i> 學校統計
                    </h4>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <select id="newStudentViewSelect" onchange="changeNewStudentView(this.value)" style="padding: 8px 12px; border: 1px solid #d9d9d9; border-radius: 6px; background: #fff; color: #333; font-size: 14px; cursor: pointer;">
                            <option value="active" <?php echo $new_student_view === 'active' ? 'selected' : ''; ?>>新生資料</option>
                            <option value="previous" <?php echo $new_student_view === 'previous' ? 'selected' : ''; ?>>歷屆學生資料</option>
                        </select>
                        <?php if ($new_student_view === 'previous' && !empty($available_roc_years)): ?>
                            <select id="rocYearSelect" onchange="changeRocYear(this.value)" style="padding: 8px 12px; border: 1px solid #d9d9d9; border-radius: 6px; background: #fff; color: #333; font-size: 14px; cursor: pointer;">
                                <option value="0">全部學年</option>
                                <?php foreach ($available_roc_years as $roc_year): ?>
                                    <option value="<?php echo $roc_year; ?>" <?php echo $selected_roc_year == $roc_year ? 'selected' : ''; ?>>
                                        <?php echo $roc_year; ?>學年
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 10px; margin-bottom: 20px;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; text-align: center;">
                        <div>
                            <div style="font-size: 2.4em; font-weight: bold; margin-bottom: 5px;">${normalized.length}</div>
                            <div style="font-size: 1em; opacity: 0.9;">學校數</div>
                        </div>
                        <div>
                            <div style="font-size: 2.4em; font-weight: bold; margin-bottom: 5px;">${totalStudents}</div>
                            <div style="font-size: 1em; opacity: 0.9;">總學生數</div>
                        </div>
                    </div>
                </div>
                
                <div class="chart-card" style="display: flex; flex-direction: column; align-items: center;">
                    <div class="chart-title">各學校新生人數統計</div>
                    <div class="chart-container" style="width: 100%; display: flex; justify-content: center;">
                        <canvas id="newStudentSchoolChart"></canvas>
                    </div>
                </div>
                
                <div style="margin-top: 20px;">
                    <h5 style="color: #333; margin-bottom: 15px;">詳細數據</h5>
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse; background: white;">
                            <thead>
                                <tr style="background: #f8f9fa; border-bottom: 2px solid #dee2e6;">
                                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #495057;">學校名稱</th>
                                    <th style="padding: 12px; text-align: center; font-weight: 600; color: #495057;">學生人數</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${normalized.map((item, index) => `
                                    <tr style="border-bottom: 1px solid #e9ecef; ${index % 2 === 0 ? 'background: #f8f9fa;' : ''}">
                                        <td style="padding: 12px; font-weight: 500; color: #333;">${item.school_name}</td>
                                        <td style="padding: 12px; text-align: center; font-weight: bold; color: #667eea;">${item.student_count}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        `;
        
        document.getElementById('newstudentAnalyticsContent').innerHTML = content;
        
        const verticalTextPlugin = {
            id: 'verticalTextPlugin',
            afterDraw(chart) {
                const { ctx, chartArea } = chart;
                ctx.save();
                ctx.fillStyle = '#333';
                ctx.font = 'bold 18px Arial';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                const text = ['學', '生', '人', '數'];
                const x = chartArea.left - 40;
                const centerY = (chartArea.top + chartArea.bottom) / 2;
                const lineHeight = 22;
                text.forEach((char, i) => {
                    ctx.fillText(char, x, centerY + (i - (text.length - 1) / 2) * lineHeight);
                });
                ctx.restore();
            }
        };
        
        setTimeout(() => {
            const ctx = document.getElementById('newStudentSchoolChart');
            if (!ctx) return;
            
            if (window.newStudentSchoolChartInstance) {
                window.newStudentSchoolChartInstance.destroy();
            }
            
            const colors = ['#667eea', '#764ba2', '#f093fb', '#4facfe', '#43e97b', '#fa709a', '#fee140', '#30cfd0'];
            
            window.newStudentSchoolChartInstance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: normalized.map(item => item.school_name),
                    datasets: [{
                        label: '學生人數',
                        data: normalized.map(item => item.student_count),
                        backgroundColor: normalized.map((_, index) => colors[index % colors.length]),
                        borderColor: normalized.map((_, index) => colors[index % colors.length]),
                        borderWidth: 2,
                        borderRadius: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    layout: {
                        padding: {
                            left: 50
                        }
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            padding: 15,
                            titleFont: { size: 16, weight: 'bold' },
                            bodyFont: { size: 15 },
                            callbacks: {
                                label: function(context) {
                                    return `學生人數: ${context.parsed.y} 人`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1,
                                font: { size: 16 }
                            },
                            title: { display: false }
                        },
                        x: {
                            ticks: {
                                font: { size: 14 },
                                maxRotation: 45,
                                minRotation: 45
                            },
                            title: {
                                display: true,
                                text: '學校名稱',
                                font: { size: 18, weight: 'bold' },
                                padding: { top: 10, bottom: 0 }
                            }
                        }
                    }
                },
                plugins: [verticalTextPlugin]
            });
        }, 100);
    }
    
    // 顯示科系統計（所有科系新生入學人數）
    function showNewStudentDepartmentStats() {
        console.log('showNewStudentDepartmentStats 被調用');
        sessionStorage.setItem('lastNewStudentChartType', 'departmentStats');
        
        const departmentStats = <?php echo json_encode($new_student_department_stats); ?>;
        
        if (!departmentStats || departmentStats.length === 0) {
            document.getElementById('newstudentAnalyticsContent').innerHTML = `
                <div style="text-align: center; padding: 40px; color: #6c757d;">
                    <i class="fas fa-inbox fa-3x" style="margin-bottom: 16px;"></i>
                    <h4>暫無數據</h4>
                    <p>目前沒有科系統計數據</p>
                </div>
            `;
            return;
        }
        
        const normalized = (departmentStats || [])
            .map(item => ({
                department_name: item.department_name || '未填寫',
                student_count: parseInt(item.student_count || 0) || 0
            }))
            .filter(item => item.student_count > 0)
            .sort((a, b) => b.student_count - a.student_count);
        
        const totalStudents = normalized.reduce((sum, item) => sum + item.student_count, 0);
        
        const content = `
            <div style="margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
                    <h4 style="color: #667eea; margin: 0;">
                        <i class="fas fa-layer-group"></i> 科系統計
                    </h4>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <select id="newStudentViewSelect" onchange="changeNewStudentView(this.value)" style="padding: 8px 12px; border: 1px solid #d9d9d9; border-radius: 6px; background: #fff; color: #333; font-size: 14px; cursor: pointer;">
                            <option value="active" <?php echo $new_student_view === 'active' ? 'selected' : ''; ?>>新生資料</option>
                            <option value="previous" <?php echo $new_student_view === 'previous' ? 'selected' : ''; ?>>歷屆學生資料</option>
                        </select>
                        <?php if ($new_student_view === 'previous' && !empty($available_roc_years)): ?>
                            <select id="rocYearSelect" onchange="changeRocYear(this.value)" style="padding: 8px 12px; border: 1px solid #d9d9d9; border-radius: 6px; background: #fff; color: #333; font-size: 14px; cursor: pointer;">
                                <option value="0">全部學年</option>
                                <?php foreach ($available_roc_years as $roc_year): ?>
                                    <option value="<?php echo $roc_year; ?>" <?php echo $selected_roc_year == $roc_year ? 'selected' : ''; ?>>
                                        <?php echo $roc_year; ?>學年
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 10px; margin-bottom: 20px;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; text-align: center;">
                        <div>
                            <div style="font-size: 2.4em; font-weight: bold; margin-bottom: 5px;">${normalized.length}</div>
                            <div style="font-size: 1em; opacity: 0.9;">科系列表</div>
                        </div>
                        <div>
                            <div style="font-size: 2.4em; font-weight: bold; margin-bottom: 5px;">${totalStudents}</div>
                            <div style="font-size: 1em; opacity: 0.9;">總學生數</div>
                        </div>
                    </div>
                </div>
                
                <div class="chart-card" style="display: flex; flex-direction: column; align-items: center;">
                    <div class="chart-title">各科系新生人數統計</div>
                    <div class="chart-container" style="width: 100%; display: flex; justify-content: center;">
                        <canvas id="newStudentDepartmentChart"></canvas>
                    </div>
                </div>
                
                <div style="margin-top: 20px;">
                    <h5 style="color: #333; margin-bottom: 15px;">詳細數據</h5>
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse; background: white;">
                            <thead>
                                <tr style="background: #f8f9fa; border-bottom: 2px solid #dee2e6;">
                                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #495057;">科系名稱</th>
                                    <th style="padding: 12px; text-align: center; font-weight: 600; color: #495057;">學生人數</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${normalized.map((item, index) => `
                                    <tr style="border-bottom: 1px solid #e9ecef; ${index % 2 === 0 ? 'background: #f8f9fa;' : ''}">
                                        <td style="padding: 12px; font-weight: 500; color: #333;">${item.department_name}</td>
                                        <td style="padding: 12px; text-align: center; font-weight: bold; color: #667eea;">${item.student_count}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        `;
        
        document.getElementById('newstudentAnalyticsContent').innerHTML = content;
        
        const verticalTextPlugin = {
            id: 'verticalTextPlugin',
            afterDraw(chart) {
                const { ctx, chartArea } = chart;
                ctx.save();
                ctx.fillStyle = '#333';
                ctx.font = 'bold 18px Arial';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                const text = ['學', '生', '人', '數'];
                const x = chartArea.left - 40;
                const centerY = (chartArea.top + chartArea.bottom) / 2;
                const lineHeight = 22;
                text.forEach((char, i) => {
                    ctx.fillText(char, x, centerY + (i - (text.length - 1) / 2) * lineHeight);
                });
                ctx.restore();
            }
        };
        
        setTimeout(() => {
            const ctx = document.getElementById('newStudentDepartmentChart');
            if (!ctx) return;
            
            if (window.newStudentDepartmentChartInstance) {
                window.newStudentDepartmentChartInstance.destroy();
            }
            
            const colors = ['#667eea', '#764ba2', '#f093fb', '#4facfe', '#43e97b', '#fa709a', '#fee140', '#30cfd0'];
            
            window.newStudentDepartmentChartInstance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: normalized.map(item => item.department_name),
                    datasets: [{
                        label: '學生人數',
                        data: normalized.map(item => item.student_count),
                        backgroundColor: normalized.map((_, index) => colors[index % colors.length]),
                        borderColor: normalized.map((_, index) => colors[index % colors.length]),
                        borderWidth: 2,
                        borderRadius: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    layout: {
                        padding: {
                            left: 50
                        }
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            padding: 15,
                            titleFont: { size: 16, weight: 'bold' },
                            bodyFont: { size: 15 },
                            callbacks: {
                                label: function(context) {
                                    return `學生人數: ${context.parsed.y} 人`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1,
                                font: { size: 16 }
                            },
                            title: { display: false }
                        },
                        x: {
                            ticks: {
                                font: { size: 14 },
                                maxRotation: 45,
                                minRotation: 45
                            },
                            title: {
                                display: true,
                                text: '科系名稱',
                                font: { size: 18, weight: 'bold' },
                                padding: { top: 10, bottom: 0 }
                            }
                        }
                    }
                },
                plugins: [verticalTextPlugin]
            });
        }, 100);
    }
    
    // 清除新生統計圖表
    function clearNewStudentCharts() {
        console.log('clearNewStudentCharts 被調用');
        // 清除圖表實例
        if (window.departmentSchoolChartInstance) {
            window.departmentSchoolChartInstance.destroy();
            window.departmentSchoolChartInstance = null;
        }
        if (window.newStudentSchoolChartInstance) {
            window.newStudentSchoolChartInstance.destroy();
            window.newStudentSchoolChartInstance = null;
        }
        if (window.newStudentDepartmentChartInstance) {
            window.newStudentDepartmentChartInstance.destroy();
            window.newStudentDepartmentChartInstance = null;
        }
        document.getElementById('newstudentAnalyticsContent').innerHTML = `
            <div class="empty-state">
                <i class="fas fa-chart-line fa-3x" style="margin-bottom: 16px;"></i>
                <h4>選擇上方的統計類型來查看詳細分析</h4>
                <p>提供新生經營活動參與度、活動類型分布、時間趨勢等多維度統計</p>
            </div>
        `;
    }
    
    // 變更新生視圖類型（新生或歷屆學生）
    function changeNewStudentView(viewType) {
        const url = new URL(window.location.href);
        url.searchParams.set('new_student_view', viewType);
        // 清除學年度參數（如果從歷屆切換到新生）
        if (viewType === 'active') {
            url.searchParams.delete('roc_year');
        }
        // 重新載入頁面以更新數據
        window.location.href = url.toString();
    }
    
    // 變更學年度篩選
    function changeRocYear(rocYear) {
        const url = new URL(window.location.href);
        url.searchParams.set('new_student_view', 'previous');
        if (rocYear && rocYear !== '0') {
            url.searchParams.set('roc_year', rocYear);
        } else {
            url.searchParams.delete('roc_year');
        }
        // 重新載入頁面以更新數據
        window.location.href = url.toString();
    }
    
    function clearActivityCharts() {
        console.log('clearActivityCharts 被調用');
        
        // 清除所有Chart.js實例
        Chart.helpers.each(Chart.instances, function(instance) {
            if (instance.canvas.id.includes('teacherActivityChart') || 
                instance.canvas.id.includes('activityTypePieChart') ||
                instance.canvas.id.includes('monthStatsChart') ||
                instance.canvas.id.includes('dayStatsChart') ||
                instance.canvas.id.includes('schoolStatsChart') ||
                instance.canvas.id.includes('departmentOverviewChart') ||
                instance.canvas.id.includes('attendanceDateChart') ||
                instance.canvas.id.includes('attendanceSessionChart')) {
                instance.destroy();
            }
        });
        
        // 如果是學校行政人員，顯示全校科系招生總覽
        if (isSchoolAdmin) {
            showDepartmentOverviewStats();
        } else {
            // 否則顯示空狀態
        document.getElementById('activityAnalyticsContent').innerHTML = `
            <div class="empty-state">
                <i class="fas fa-chart-line fa-3x" style="margin-bottom: 16px;"></i>
                <h4>選擇上方的統計類型來查看詳細分析</h4>
                <p>提供教師活動參與度、活動類型分布、時間趨勢等多維度統計</p>
            </div>
        `;
        }
    }
    
    // 學校行政人員專用 - 科系招生總覽（自動顯示）
    function showDepartmentOverviewStats() {
        console.log('showDepartmentOverviewStats 被調用 - 顯示全部科系招生總覽');
        
        // 統計每個科系的活動數量
        const departmentStats = {};
        activityRecords.forEach(record => {
            const department = record.teacher_department || '未知科系';
            if (!departmentStats[department]) {
                departmentStats[department] = {
                    name: department,
                    totalActivities: 0,
                    teachers: new Set()
                };
            }
            departmentStats[department].totalActivities++;
            if (record.teacher_name) {
                departmentStats[department].teachers.add(record.teacher_name);
            }
        });
        
        const departmentStatsArray = Object.values(departmentStats).map(dept => ({
            name: dept.name,
            totalActivities: dept.totalActivities,
            teacherCount: dept.teachers.size
        })).sort((a, b) => b.totalActivities - a.totalActivities);
        
        const content = `
            <div style="margin-bottom: 20px;">
                <h4 style="color: #667eea; margin-bottom: 15px;">
                    <i class="fas fa-chart-bar"></i> 全校科系招生活動總覽
                    <span style="font-size: 0.8em; color: #999; margin-left: 10px;">（<?php echo $current_user === 'IMD' ? '資管科專屬視圖' : '學校行政人員專屬視圖'; ?>）</span>
                </h4>
                
                <div class="chart-card">
                    <div class="chart-title">各科系招生活動數量統計 <span style="font-size: 0.9em; color: #999;">（點擊科系查看該科系教師列表）</span></div>
                    <div class="chart-container">
                        <canvas id="departmentOverviewChart"></canvas>
                    </div>
                </div>
                
                <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 10px; margin-top: 20px;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; text-align: center;">
                        <div>
                            <div style="font-size: 2.5em; font-weight: bold; margin-bottom: 5px;">${departmentStatsArray.reduce((sum, d) => sum + d.totalActivities, 0)}</div>
                            <div style="font-size: 1em; opacity: 0.9;">總活動數</div>
                        </div>
                        <div>
                            <div style="font-size: 2.5em; font-weight: bold; margin-bottom: 5px;">${departmentStatsArray.reduce((sum, d) => sum + d.teacherCount, 0)}</div>
                            <div style="font-size: 1em; opacity: 0.9;">參與教師總數</div>
                        </div>
                        <div>
                            <div style="font-size: 2.5em; font-weight: bold; margin-bottom: 5px;">${departmentStatsArray.length}</div>
                            <div style="font-size: 1em; opacity: 0.9;">參與科系總數</div>
                        </div>
                    </div>
                </div>
                
                <!-- 科系教師列表顯示區域 -->
                <div id="departmentTeacherListContainer" style="margin-top: 20px;"></div>
            </div>
        `;
        
        document.getElementById('activityAnalyticsContent').innerHTML = content;
        
        // 創建圖表
        setTimeout(() => {
            const canvasElement = document.getElementById('departmentOverviewChart');
            if (!canvasElement) return;
            
            const ctx = canvasElement.getContext('2d');
            const colors = ['#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8', '#6f42c1', '#fd7e14', '#20c997'];
            
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: departmentStatsArray.map(dept => dept.name),
                    datasets: [{
                        label: '招生活動數量',
                        data: departmentStatsArray.map(dept => dept.totalActivities),
                        backgroundColor: departmentStatsArray.map((_, index) => colors[index % colors.length]),
                        borderColor: departmentStatsArray.map((_, index) => colors[index % colors.length]),
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    onClick: (event, elements) => {
                        if (elements.length > 0) {
                            const index = elements[0].index;
                            const departmentName = departmentStatsArray[index].name;
                            console.log(`點擊科系: ${departmentName}`);
                            showDepartmentTeacherList(departmentName);
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                afterLabel: function(context) {
                                    const index = context.dataIndex;
                                    return `參與教師：${departmentStatsArray[index].teacherCount} 人`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1,
                                font: {
                                    size: 13
                                }
                            },
                            title: {
                                display: true,
                                text: '活動數量',
                                font: {
                                    size: 15,
                                    weight: 'bold'
                                },
                                padding: {
                                    bottom: 10
                                },
                                align: 'center'
                            }
                        },
                        x: {
                            ticks: {
                                maxRotation: 45,
                                minRotation: 0,
                                color: '#2563eb',
                                font: {
                                    size: 15,
                                    weight: 'bold'
                                }
                            }
                        }
                    }
                }
            });
        }, 100);
    }
    
    // 顯示科系教師列表
    function showDepartmentTeacherList(departmentName) {
        console.log(`顯示 ${departmentName} 的教師列表`);
        
        // 統計該科系的教師資訊
        const teacherStats = {};
        
        activityRecords.forEach(record => {
            if (record.teacher_department === departmentName) {
                const teacherId = record.teacher_id;
                const teacherName = record.teacher_name;
                
                if (!teacherStats[teacherId]) {
                    teacherStats[teacherId] = {
                        id: teacherId,
                        name: teacherName,
                        department: departmentName,
                        activityCount: 0,
                        activities: []
                    };
                }
                
                teacherStats[teacherId].activityCount++;
                teacherStats[teacherId].activities.push({
                    school: record.school_name,
                    type: record.activity_type,
                    date: record.activity_date
                });
            }
        });
        
        const teacherList = Object.values(teacherStats).sort((a, b) => b.activityCount - a.activityCount);
        
        // 收集所有可用的科系（從所有記錄中）
        const allDepartments = new Set();
        activityRecords.forEach(record => {
            if (record.teacher_department) {
                allDepartments.add(record.teacher_department);
            }
        });
        const departmentOptions = Array.from(allDepartments).sort();
        
        const content = `
            <div style="background: white; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); overflow: hidden; margin-top: 20px;">
                <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; gap: 15px; flex-wrap: wrap;">
                        <h3 style="margin: 0; font-size: 1.3em; font-weight: 600;">
                            <i class="fas fa-users"></i> ${departmentName} - 教師列表 (共 <span id="deptTeacherCount">${teacherList.length}</span> 位)
                        </h3>
                        
                        <div style="display: flex; gap: 10px; align-items: center; flex: 1; max-width: 600px;">
                            <!-- 搜尋框 -->
                            <div style="position: relative; flex: 1;">
                                <i class="fas fa-search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: rgba(255,255,255,0.7);"></i>
                                <input type="text" 
                                       id="deptTeacherSearch" 
                                       placeholder="搜尋教師姓名..." 
                                       style="padding: 8px 12px 8px 35px; border: 2px solid rgba(255,255,255,0.3); border-radius: 20px; background: rgba(255,255,255,0.2); color: white; font-size: 14px; width: 100%;">
                                <style>
                                    #deptTeacherSearch::placeholder {
                                        color: white !important;
                                        opacity: 1;
                                    }
                                </style>
                            </div>
                            
                            <button onclick="document.getElementById('departmentTeacherListContainer').innerHTML = ''" 
                                    style="background: rgba(255,255,255,0.2); color: white; border: 1px solid white; padding: 8px 16px; border-radius: 20px; cursor: pointer; font-weight: 500; transition: all 0.3s; white-space: nowrap;">
                                <i class="fas fa-times"></i> 關閉
                            </button>
                        </div>
                    </div>
                </div>
                
                <div style="padding: 20px;">
                    
                    <div style="overflow-x: auto;">
                        <table class="table" id="deptTeacherTable" style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr>
                                    <th style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef; background: #f8f9fa; font-weight: 600; color: #495057;">教師姓名</th>
                                    <th style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef; background: #f8f9fa; font-weight: 600; color: #495057;">所屬系所</th>
                                    <th style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef; background: #f8f9fa; font-weight: 600; color: #495057;">紀錄筆數</th>
                                    <th style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef; background: #f8f9fa; font-weight: 600; color: #495057;">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${teacherList.map(teacher => `
                                    <tr style="transition: background 0.2s;" onmouseover="this.style.background='#f8f9fa'" onmouseout="this.style.background='white'">
                                        <td style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef;">${teacher.name}</td>
                                        <td style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef;">${teacher.department}</td>
                                        <td style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef;">${teacher.activityCount}</td>
                                        <td style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef;">
                                            <button onclick="showTeacherActivityDetails(${teacher.id}, '${teacher.name}')" 
                                                    class="btn-view"
                                                    style="background: #667eea; color: white; border: none; padding: 8px 16px; border-radius: 20px; cursor: pointer; font-size: 14px; transition: all 0.3s ease;">
                                                查看紀錄
                                            </button>
                                        </td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                    
                    <div style="margin-top: 20px; padding: 15px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 8px; color: white; text-align: center;">
                        <strong>
                            <i class="fas fa-chart-bar"></i> ${departmentName} 共有 <span id="deptTotalCount">${teacherList.length}</span> 位教師參與，累計 ${teacherList.reduce((sum, t) => sum + t.activityCount, 0)} 場活動
                        </strong>
                    </div>
                </div>
            </div>
        `;
        
        document.getElementById('departmentTeacherListContainer').innerHTML = content;
        
        // 添加搜尋和篩選功能
        setTimeout(() => {
            const searchInput = document.getElementById('deptTeacherSearch');
            const deptFilter = document.getElementById('deptFilter');
            const table = document.getElementById('deptTeacherTable');
            
            // 統一的篩選函數
            function applyDeptFilters() {
                if (!table) return;
                
                const searchValue = searchInput ? searchInput.value.toLowerCase() : '';
                const deptValue = deptFilter ? deptFilter.value : '';
                const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
                let visibleCount = 0;
                
                for (let i = 0; i < rows.length; i++) {
                    const cells = rows[i].getElementsByTagName('td');
                    const teacherName = cells[0] ? cells[0].textContent.toLowerCase() : '';
                    const department = cells[1] ? cells[1].textContent : '';
                    
                    let shouldShow = true;
                    
                    // 姓名篩選
                    if (searchValue && teacherName.indexOf(searchValue) === -1) {
                        shouldShow = false;
                    }
                    
                    // 科系篩選
                    if (deptValue && department !== deptValue) {
                        shouldShow = false;
                    }
                    
                    if (shouldShow) {
                        rows[i].style.display = '';
                        visibleCount++;
                    } else {
                        rows[i].style.display = 'none';
                    }
                }
                
                // 更新顯示數量
                const countElement = document.getElementById('deptTeacherCount');
                if (countElement) {
                    countElement.textContent = visibleCount;
                }
            }
            
            // 為搜尋框添加事件監聽器
            if (searchInput) {
                searchInput.addEventListener('keyup', applyDeptFilters);
                
                // 設置input樣式（placeholder顏色）
                searchInput.style.setProperty('color', 'white');
                searchInput.addEventListener('focus', function() {
                    this.style.background = 'rgba(255,255,255,0.3)';
                });
                searchInput.addEventListener('blur', function() {
                    this.style.background = 'rgba(255,255,255,0.2)';
                });
            }
            
            // 為科系下拉選單添加事件監聽器
            if (deptFilter) {
                deptFilter.addEventListener('change', applyDeptFilters);
            }
        }, 100);
        
        // 平滑滾動到教師列表
        document.getElementById('departmentTeacherListContainer').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    
    // 顯示教師詳細活動紀錄
// 顯示教師詳細活動紀錄
    function showTeacherActivityDetails(teacherId, teacherName) {
        console.log(`顯示教師 ${teacherName} 的詳細活動紀錄`);
        
        // 篩選該教師的所有活動
        const teacherActivities = activityRecords.filter(record => record.teacher_id == teacherId);
        
        if (teacherActivities.length === 0) {
            alert('查無該教師的活動紀錄');
            return;
        }
        
        const teacherDept = teacherActivities[0].teacher_department;
        
        const content = `
            <div style="background: white; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); overflow: hidden; margin-top: 20px;">
                <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="margin: 0; font-size: 1.3em; font-weight: 600;">
                        <i class="fas fa-clipboard-list"></i> ${teacherName} 的紀錄列表 (共 ${teacherActivities.length} 筆)
                    </h3>
                    <button onclick="document.getElementById('departmentTeacherListContainer').scrollIntoView({ behavior: 'smooth' }); showDepartmentTeacherList('${teacherDept}')" 
                            style="background: rgba(255,255,255,0.2); color: white; border: 1px solid white; padding: 8px 16px; border-radius: 5px; cursor: pointer; font-weight: 500; transition: all 0.3s;">
                        <i class="fas fa-arrow-left"></i> 返回教師列表
                    </button>
                </div>
                
                <div style="padding: 20px;">
                    <div style="overflow-x: auto;">
                        <table class="table" style="width: 100%; border-collapse: collapse; margin-top: 15px;">
                            <thead>
                                <tr>
                                    <th style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef; background: #f8f9fa; font-weight: 600; color: #495057;">活動日期</th>
                                    <th style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef; background: #f8f9fa; font-weight: 600; color: #495057;">學校名稱</th>
                                    <th style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef; background: #f8f9fa; font-weight: 600; color: #495057;">活動類型</th>
                                    <th style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef; background: #f8f9fa; font-weight: 600; color: #495057;">活動時間</th>
                                    <th style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef; background: #f8f9fa; font-weight: 600; color: #495057;">提交時間</th>
                                    <th style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef; background: #f8f9fa; font-weight: 600; color: #495057;">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${teacherActivities.map((activity, index) => {
                                    // 格式化提交時間
                                    let formattedCreatedAt = '-';
                                    if (activity.created_at) {
                                        const date = new Date(activity.created_at);
                                        const year = date.getFullYear();
                                        const month = String(date.getMonth() + 1).padStart(2, '0');
                                        const day = String(date.getDate()).padStart(2, '0');
                                        const hours = String(date.getHours()).padStart(2, '0');
                                        const minutes = String(date.getMinutes()).padStart(2, '0');
                                        formattedCreatedAt = `${year}/${month}/${day} ${hours}:${minutes}`;
                                    }
                                    
                                    // [修正] 轉換活動時間代碼為中文
                                    let activityTimeText = '未設定';
                                    if (activity.activity_time == 1) activityTimeText = '上班日';
                                    else if (activity.activity_time == 2) activityTimeText = '假日';

                                    // [修正] 顯示活動類型名稱而非ID (如果API有傳回 activity_type_name)
                                    let activityTypeName = activity.activity_type_name || activity.activity_type;
                                    
                                    return `
                                        <tr style="transition: background 0.2s;" onmouseover="this.style.background='#f8f9fa'" onmouseout="this.style.background='white'">
                                            <td style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef;">${activity.activity_date}</td>
                                            <td style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef;">${activity.school_name || activity.school}</td>
                                            <td style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef;">${activityTypeName}</td>
                                            <td style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef;">${activityTimeText}</td>
                                            <td style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef;">${formattedCreatedAt}</td>
                                            <td style="padding: 12px 15px; text-align: left; border-bottom: 1px solid #e9ecef;">
                                                <button class="btn-view" onclick='viewRecord(${JSON.stringify(activity)})'
                                                        style="background: #667eea; color: white; border: none; padding: 8px 16px; border-radius: 20px; cursor: pointer; font-size: 14px; transition: all 0.3s ease;">
                                                    查看
                                                </button>
                                            </td>
                                        </tr>
                                    `;
                                }).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        `;
        
        document.getElementById('departmentTeacherListContainer').innerHTML = content;
        document.getElementById('departmentTeacherListContainer').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    
    // 就讀意願統計 - 科系分布分析
    // 調整為：圖表仍顯示志願分布，但「科系詳細統計」改為顯示「科系名稱」與「分配到本系的人數」
    // 並以已分配科系（assigned_department）為基礎統計
    function showEnrollmentDepartmentStats() {
        console.log('showEnrollmentDepartmentStats 被調用');
        currentEnrollmentChartType = 'department';

        const choicesApiUrl = buildEnrollmentApiUrl('../../Topics-frontend/frontend/api/enrollment_stats_api.php', 'department');
        const assignedApiUrl = buildEnrollmentApiUrl('../../Topics-frontend/frontend/api/enrollment_stats_api.php', 'assigned_department');
        console.log('Choices API URL:', choicesApiUrl);
        console.log('Assigned API URL:', assignedApiUrl);

        // 顯示載入中提示
        document.getElementById('enrollmentAnalyticsContent').innerHTML = `
            <div style="text-align: center; padding: 40px;">
                <i class="fas fa-spinner fa-spin fa-3x" style="color: #667eea; margin-bottom: 16px;"></i>
                <h4>載入科系分布統計中...</h4>
            </div>
        `;

        Promise.all([
            // 志願分布資料（原本的 department 統計）
            fetch(choicesApiUrl).then(response => {
                console.log('Choices API 響應狀態:', response.status, response.statusText);
                if (!response.ok) {
                    throw new Error(`Choices HTTP ${response.status}: ${response.statusText}`);
                }
                return response.json();
            }),
            // 已分配科系統計（assigned_department）
            fetch(assignedApiUrl).then(response => {
                console.log('Assigned API 響應狀態:', response.status, response.statusText);
                if (!response.ok) {
                    throw new Error(`Assigned HTTP ${response.status}: ${response.statusText}`);
                }
                return response.json();
            })
        ])
        .then(([choicesDataRaw, assignedDataRaw]) => {
            console.log('Choices API 返回數據:', choicesDataRaw);
            console.log('Assigned API 返回數據:', assignedDataRaw);

            // 解析志願分布資料（供圓餅圖使用）
            let departmentData;
            if (Array.isArray(choicesDataRaw)) {
                departmentData = choicesDataRaw;
            } else if (choicesDataRaw.data && Array.isArray(choicesDataRaw.data)) {
                departmentData = choicesDataRaw.data;
            } else if (choicesDataRaw.error) {
                document.getElementById('enrollmentAnalyticsContent').innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #dc3545;">
                        <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                        <h4>數據載入失敗</h4>
                        <p>${choicesDataRaw.error}</p>
                    </div>
                `;
                return;
            } else {
                document.getElementById('enrollmentAnalyticsContent').innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #dc3545;">
                        <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                        <h4>數據格式錯誤</h4>
                        <p>無法識別科系分布 API 返回的數據格式</p>
                    </div>
                `;
                return;
            }

            // 解析已分配科系資料（供「科系詳細統計」使用）
            let assignedDeptData;
            if (Array.isArray(assignedDataRaw)) {
                assignedDeptData = assignedDataRaw;
            } else if (assignedDataRaw.data && Array.isArray(assignedDataRaw.data)) {
                assignedDeptData = assignedDataRaw.data;
            } else if (assignedDataRaw.error) {
                document.getElementById('enrollmentAnalyticsContent').innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #dc3545;">
                        <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                        <h4>數據載入失敗</h4>
                        <p>${assignedDataRaw.error}</p>
                    </div>
                `;
                return;
            } else {
                document.getElementById('enrollmentAnalyticsContent').innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #dc3545;">
                        <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                        <h4>數據格式錯誤</h4>
                        <p>無法識別已分配科系 API 返回的數據格式</p>
                    </div>
                `;
                return;
            }

            // 檢查資料是否為空
            if (!departmentData || !Array.isArray(departmentData) || departmentData.length === 0 ||
                !assignedDeptData || !Array.isArray(assignedDeptData) || assignedDeptData.length === 0) {
                document.getElementById('enrollmentAnalyticsContent').innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #6c757d;">
                        <i class="fas fa-inbox fa-3x" style="margin-bottom: 16px;"></i>
                        <h4>暫無數據</h4>
                        <p>目前沒有科系分布或已分配科系統計數據</p>
                    </div>
                `;
                return;
            }

            const totalAssigned = assignedDeptData.reduce((sum, d) => sum + (d.value || 0), 0);

            const content = `
                <div style="margin-bottom: 20px;">
                    <h4 style="color: #667eea; margin-bottom: 15px;">
                        <i class="fas fa-graduation-cap"></i> 科系分布分析
                    </h4>
                    
                    <div class="chart-card">
                        <div class="chart-title">科系選擇分布</div>
                        <div class="chart-container">
                            <canvas id="enrollmentDepartmentChart"></canvas>
                        </div>
                    </div>
                    
                    <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                        <h5 style="color: #333; margin-bottom: 15px;">科系詳細統計</h5>
                        
                        <!-- 改為顯示「總分配人數」 -->
                        <div style="background: white; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #667eea;">
                            <div style="font-weight: bold; color: #333; margin-bottom: 5px;">總分配人數</div>
                            <div style="font-size: 1.5em; font-weight: bold; color: #667eea;">${totalAssigned}人</div>
                        </div>
                        
                        <!-- 科系列表：顯示科系名稱與分配到本系人數 -->
                        <div style="background: white; border-radius: 8px; overflow: hidden;">
                            <table style="width: 100%; border-collapse: collapse;">
                                <thead>
                                    <tr style="background: #f8f9fa;">
                                        <th style="padding: 15px; text-align: left; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">科系名稱</th>
                                        <th style="padding: 15px; text-align: center; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">分配到本系人數</th>
                                        <th style="padding: 15px; text-align: center; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">占比</th>
                                        <th style="padding: 15px; text-align: center; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${assignedDeptData.map((item, index) => {
                                        const colors = ['#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8', '#6f42c1', '#fd7e14', '#20c997', '#e83e8c', '#6c757d'];
                                        const color = colors[index % colors.length];
                                        const value = item.value ?? 0;
                                        const percentage = totalAssigned > 0 ? ((value / totalAssigned) * 100).toFixed(1) : '0.0';
                                        const safeName = String(item.name || '未填寫').replace(/'/g, "\\'");
                                        return `
                                            <tr style="border-bottom: 1px solid #dee2e6;">
                                                <td style="padding: 15px; font-weight: 500; color: #333;">${item.name}</td>
                                                <td style="padding: 15px; text-align: center; font-weight: bold; color: #28a745;">${value}人</td>
                                                <td style="padding: 15px; text-align: center; font-weight: bold; color: #333;">${percentage}%</td>
                                                <td style="padding: 15px; text-align: center;">
                                                    <button onclick="showDepartmentStudents('${safeName}')" 
                                                            style="background: ${color}; color: white; border: none; padding: 8px 16px; border-radius: 20px; cursor: pointer; font-size: 14px; transition: all 0.3s ease;">
                                                        查看詳情
                                                    </button>
                                                </td>
                                            </tr>
                                        `;
                                    }).join('')}
                                </tbody>
                            </table>
                        </div>

                        <!-- 點「查看詳情」後的展開內容區塊 -->
                        <div id="departmentDetailContainer" style="margin-top: 24px;"></div>
                    </div>
                </div>
            `;

            document.getElementById('enrollmentAnalyticsContent').innerHTML = content;

            // 使用志願分布資料建立圓餅圖（維持原本科系選擇分布圖）
            setTimeout(() => {
                const canvasElement = document.getElementById('enrollmentDepartmentChart');
                if (!canvasElement) return;

                const ctx = canvasElement.getContext('2d');
                new Chart(ctx, {
                    type: 'pie',
                    data: {
                        labels: departmentData.map(item => item.name),
                        datasets: [{
                            data: departmentData.map(item => item.value),
                            backgroundColor: [
                                '#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8', '#6f42c1', '#fd7e14', '#20c997', '#e83e8c', '#6c757d'
                            ],
                            borderWidth: 2,
                            borderColor: '#fff'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    padding: 20,
                                    usePointStyle: true,
                                    font: { size: 16 }
                                }
                            },
                            tooltip: {
                                enabled: true,
                                callbacks: {
                                    label: function(context) {
                                        const label = context.label || '';
                                        const value = context.parsed;
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : '0.0';
                                        return `${label}: ${value}人 (${percentage}%)`;
                                    }
                                }
                            }
                        }
                    }
                });
            }, 100);
        })
        .catch(error => {
            console.error('載入科系統計數據失敗:', error);
            document.getElementById('enrollmentAnalyticsContent').innerHTML = `
                <div style="text-align: center; padding: 40px; color: #dc3545;">
                    <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                    <h4>數據載入失敗</h4>
                    <p>無法連接到統計API</p>
                    <p style="font-size: 0.9em; color: #999; margin-top: 10px;">錯誤: ${error.message}</p>
                </div>
            `;
        });
    }
    
function showEnrollmentSystemStats() {
    console.log('showEnrollmentSystemStats 啟動：開始抓取資料');
    
    const container = document.getElementById('enrollmentAnalyticsContent');
    if (!container) return;

    // 1. 嘗試建立 API URL (如果失敗則使用預設值)
    let apiUrl = '../../Topics-frontend/frontend/api/enrollment_stats_api.php?group_by=assigned_department';
    try {
        // 嘗試使用 helper 函式，但如果報錯就忽略
        if (typeof buildEnrollmentApiUrl === 'function') {
            apiUrl = buildEnrollmentApiUrl('../../Topics-frontend/frontend/api/enrollment_stats_api.php', 'assigned_department');
        }
    } catch (e) {
        console.warn('URL 建立失敗，使用預設路徑');
    }

    // 2. 清除舊圖表
    if (typeof Chart !== 'undefined') {
        Chart.helpers.each(Chart.instances, function(instance) {
            if (instance.canvas.id.includes('enrollmentSystemChart')) {
                instance.destroy();
            }
        });
    }

    // 3. 抓取資料
    fetch(apiUrl)
        .then(response => {
            if (!response.ok) throw new Error('網路回應不正常 (' + response.status + ')');
            return response.json();
        })
        .then(data => {
            // 解析資料結構
            let assignedDeptData = [];
            if (Array.isArray(data)) {
                assignedDeptData = data;
            } else if (data.data && Array.isArray(data.data)) {
                assignedDeptData = data.data;
            }

            // 如果沒資料
            if (!assignedDeptData || assignedDeptData.length === 0) {
                container.innerHTML = '<div style="text-align: center; padding: 40px; color: #888;">目前沒有統計數據</div>';
                return;
            }

            // 計算總數
            const totalAssigned = assignedDeptData.reduce((sum, d) => sum + (d.value || 0), 0);
            
            // 產生表格
            const tableHtml = `
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f8f9fa;">
                            <th style="padding: 12px 15px; text-align: left; border-bottom: 2px solid #dee2e6; color: #495057;">科系名稱</th>
                            <th style="padding: 12px 15px; text-align: center; border-bottom: 2px solid #dee2e6; color: #495057;">人數</th>
                            <th style="padding: 12px 15px; text-align: center; border-bottom: 2px solid #dee2e6; color: #495057;">佔比</th>
                            <th style="padding: 12px 15px; text-align: center; border-bottom: 2px solid #dee2e6; color: #495057;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${assignedDeptData.map((item, index) => {
                            const colors = ['#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8', '#6f42c1'];
                            const color = colors[index % colors.length];
                            const val = item.value || 0;
                            const pct = totalAssigned > 0 ? ((val / totalAssigned) * 100).toFixed(1) : '0.0';
                            const safeName = String(item.name || '').replace(/'/g, "\\'");
                            
                            return `
                                <tr style="border-bottom: 1px solid #eee;">
                                    <td style="padding: 12px 15px; color: #333;">${item.name}</td>
                                    <td style="padding: 12px 15px; text-align: center; font-weight: bold; color: ${color};">${val}</td>
                                    <td style="padding: 12px 15px; text-align: center; color: #666;">${pct}%</td>
                                    <td style="padding: 12px 15px; text-align: center;">
                                        <button onclick="showDepartmentStudents('${safeName}')" 
                                                style="background: ${color}; color: white; border: none; padding: 6px 14px; border-radius: 4px; cursor: pointer; font-size: 13px;">
                                            詳情
                                        </button>
                                    </td>
                                </tr>
                            `;
                        }).join('')}
                    </tbody>
                </table>
            `;

            // 更新畫面
            container.innerHTML = `
                <div style="margin-bottom: 20px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                        <h4 style="color: #667eea; margin: 0;"><i class="fas fa-chart-bar"></i> 各科分配人數總覽</h4>
                        <div style="display: flex; gap: 8px;">
                            <button onclick="toggleEnrollmentSystemView('table')" style="padding: 6px 12px; background: #667eea; color: white; border: none; border-radius: 4px; cursor: pointer;">表格</button>
                            <button onclick="toggleEnrollmentSystemView('chart')" style="padding: 6px 12px; background: #e9ecef; color: #495057; border: none; border-radius: 4px; cursor: pointer;">圖表</button>
                        </div>
                    </div>
                    <div id="enrollmentSystemTableView" style="background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border: 1px solid #eee;">
                        ${tableHtml}
                    </div>
                    <div id="enrollmentSystemChartView" style="display: none; background: white; padding: 20px; border-radius: 8px; border: 1px solid #eee;">
                        <div class="chart-container" style="height: 300px;"><canvas id="enrollmentSystemChart"></canvas></div>
                    </div>
                    <div id="departmentDetailContainer" style="margin-top: 20px;"></div>
                </div>
            `;
            
            // 存全域變數
            window.enrollmentSystemData = assignedDeptData;
        })
        .catch(err => {
            console.error('載入失敗:', err);
            container.innerHTML = `<div style="text-align:center; padding:30px; color:red;">無法載入數據: ${err.message}</div>`;
        });
}
    
    // 切換各科分配人數統計的表格/圖表視圖
    function toggleEnrollmentSystemView(viewType) {
        const tableView = document.getElementById('enrollmentSystemTableView');
        const chartView = document.getElementById('enrollmentSystemChartView');
        const tableBtn = document.getElementById('enrollmentSystemTableBtn');
        const chartBtn = document.getElementById('enrollmentSystemChartBtn');
        
        if (viewType === 'table') {
            tableView.style.display = 'block';
            chartView.style.display = 'none';
            tableBtn.style.background = '#667eea';
            tableBtn.style.color = 'white';
            chartBtn.style.background = '#e9ecef';
            chartBtn.style.color = '#495057';
        } else if (viewType === 'chart') {
            tableView.style.display = 'none';
            chartView.style.display = 'block';
            tableBtn.style.background = '#e9ecef';
            tableBtn.style.color = '#495057';
            chartBtn.style.background = '#667eea';
            chartBtn.style.color = 'white';
            
            // 創建長條圖（如果還沒有創建）
            setTimeout(() => {
                const chartCanvas = document.getElementById('enrollmentSystemChart');
                if (!chartCanvas) return;
                
                // 檢查是否已有圖表實例
                if (chartCanvas.chartInstance) {
                    chartCanvas.chartInstance.destroy();
                }
                
                const data = window.enrollmentSystemData || [];
                const ctx = chartCanvas.getContext('2d');
                chartCanvas.chartInstance = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: data.map(item => item.name),
                        datasets: [{
                            label: '分配人數',
                            data: data.map(item => item.value || 0),
                            backgroundColor: [
                                '#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8', '#6f42c1', '#fd7e14', '#20c997', '#e83e8c', '#6c757d'
                            ],
                            borderColor: [
                                '#5a6fd8', '#249a35', '#ffb900', '#c82333', '#138496', '#66389c', '#e67e22', '#18a968', '#d63384', '#5a6268'
                            ],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        indexAxis: 'y',
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                enabled: true,
                                callbacks: {
                                    label: function(context) {
                                        return '分配人數: ' + context.parsed.x + '人';
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {
                                beginAtZero: true,
                                ticks: {
                                    stepSize: 1
                                }
                            }
                        }
                    }
                });
            }, 100);
        }
    }
    
    function showEnrollmentGradeStats() {
        console.log('showEnrollmentGradeStats 被調用');
        
        // 從API獲取年級分布數據
        currentEnrollmentChartType = 'grade';
        fetch(buildEnrollmentApiUrl('../../Topics-frontend/frontend/api/enrollment_stats_api.php', 'grade'))
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    document.getElementById('enrollmentAnalyticsContent').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #dc3545;">
                            <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                            <h4>數據載入失敗</h4>
                            <p>${data.error}</p>
                        </div>
                    `;
                    return;
                }
                
                const content = `
                    <div style="margin-bottom: 20px;">
                        <h4 style="color: #667eea; margin-bottom: 15px;">
                            <i class="fas fa-users"></i> 年級分布分析
                        </h4>
                        
                        <div class="chart-card">
                            <div class="chart-title">年級分布統計</div>
                            <div class="chart-container">
                                <canvas id="enrollmentGradeChart"></canvas>
                            </div>
                        </div>
                        
                        <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                            <h5 style="color: #333; margin-bottom: 15px;">年級詳細統計</h5>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                                ${data.map((item, index) => {
                                    const colors = ['#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8'];
                                    const color = colors[index % colors.length];
                                    const total = data.reduce((sum, d) => sum + d.value, 0);
                                    const percentage = ((item.value / total) * 100).toFixed(1);
                                    return `
                                        <div style="background: white; padding: 15px; border-radius: 8px; border-left: 4px solid ${color};">
                                            <div style="font-weight: bold; color: #333; margin-bottom: 5px;">${item.name}</div>
                                            <div style="font-size: 1.5em; font-weight: bold; color: ${color};">${item.value}人</div>
                                            <div style="font-size: 0.9em; color: #666;">${percentage}%</div>
                                        </div>
                                    `;
                                }).join('')}
                            </div>
                        </div>
                    </div>
                `;
                
                document.getElementById('enrollmentAnalyticsContent').innerHTML = content;
                
                // 創建長條圖
                setTimeout(() => {
                    const canvasElement = document.getElementById('enrollmentGradeChart');
                    if (!canvasElement) return;
                    
                    const ctx = canvasElement.getContext('2d');
                    new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: data.map(item => item.name),
                            datasets: [{
                                label: '人數',
                                data: data.map(item => item.value),
                                backgroundColor: '#667eea',
                                borderColor: '#5a6fd8',
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        stepSize: 1
                                    }
                                }
                            }
                        }
                    });
                }, 100);
            })
            .catch(error => {
                console.error('載入年級統計數據失敗:', error);
                document.getElementById('enrollmentAnalyticsContent').innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #dc3545;">
                        <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                        <h4>數據載入失敗</h4>
                        <p>無法連接到統計API</p>
                    </div>
                `;
            });
    }
    
    function showEnrollmentMonthlyStats() {
        console.log('showEnrollmentMonthlyStats 被調用');
        
        // 從API獲取月度趨勢數據
        currentEnrollmentChartType = 'monthly';
        fetch(buildEnrollmentApiUrl('../../Topics-frontend/frontend/api/enrollment_stats_api.php', 'monthly'))
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    document.getElementById('enrollmentAnalyticsContent').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #dc3545;">
                            <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                            <h4>數據載入失敗</h4>
                            <p>${data.error}</p>
                        </div>
                    `;
                    return;
                }
                
                const content = `
                    <div style="margin-bottom: 20px;">
                        <h4 style="color: #667eea; margin-bottom: 15px;">
                            <i class="fas fa-calendar-alt"></i> 月度趨勢分析
                        </h4>
                        
                        <div class="chart-card">
                            <div class="chart-title">月度報名趨勢</div>
                            <div class="chart-container">
                                <canvas id="enrollmentMonthlyChart"></canvas>
                            </div>
                        </div>
                        
                        <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                            <h5 style="color: #333; margin-bottom: 15px;">月度詳細統計</h5>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                                ${data.map((item, index) => {
                                    const colors = ['#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8'];
                                    const color = colors[index % colors.length];
                                    const total = data.reduce((sum, d) => sum + d.value, 0);
                                    const percentage = ((item.value / total) * 100).toFixed(1);
                                    return `
                                        <div style="background: white; padding: 15px; border-radius: 8px; border-left: 4px solid ${color};">
                                            <div style="font-weight: bold; color: #333; margin-bottom: 5px;">${item.name}</div>
                                            <div style="font-size: 1.5em; font-weight: bold; color: ${color};">${item.value}人</div>
                                            <div style="font-size: 0.9em; color: #666;">${percentage}%</div>
                                        </div>
                                    `;
                                }).join('')}
                            </div>
                        </div>
                    </div>
                `;
                
                document.getElementById('enrollmentAnalyticsContent').innerHTML = content;
                
                // 創建線圖
                setTimeout(() => {
                    const canvasElement = document.getElementById('enrollmentMonthlyChart');
                    if (!canvasElement) return;
                    
                    const ctx = canvasElement.getContext('2d');
                    new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: data.map(item => item.name),
                            datasets: [{
                                label: '報名人數',
                                data: data.map(item => item.value),
                                borderColor: '#667eea',
                                backgroundColor: 'rgba(102, 126, 234, 0.1)',
                                borderWidth: 3,
                                fill: true,
                                tension: 0.4,
                                pointBackgroundColor: '#667eea',
                                pointBorderColor: '#fff',
                                pointBorderWidth: 2,
                                pointRadius: 6
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        stepSize: 1
                                    }
                                }
                            }
                        }
                    });
                }, 100);
            })
            .catch(error => {
                console.error('載入月度統計數據失敗:', error);
                document.getElementById('enrollmentAnalyticsContent').innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #dc3545;">
                        <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                        <h4>數據載入失敗</h4>
                        <p>無法連接到統計API</p>
                    </div>
                `;
            });
    }
    
    function showEnrollmentSchoolDepartmentStats() {
        console.log('showEnrollmentSchoolDepartmentStats 被調用');
        
        currentEnrollmentChartType = 'school_department';
        const apiUrl = buildEnrollmentApiUrl('../../Topics-frontend/frontend/api/enrollment_stats_api.php', 'school_department');
        console.log('API URL:', apiUrl);
        
        // 從API獲取國中選擇科系統計數據
        fetch(apiUrl)
            .then(response => {
                console.log('API 響應狀態:', response.status, response.statusText);
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                return response.json();
            })
            .then(data => {
                console.log('API 返回數據:', data);
                if (data.error) {
                    document.getElementById('enrollmentAnalyticsContent').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #dc3545;">
                            <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                            <h4>數據載入失敗</h4>
                            <p>${data.error}</p>
                            <p style="font-size: 0.9em; color: #999; margin-top: 10px;">請檢查瀏覽器控制台以獲取詳細錯誤信息</p>
                        </div>
                    `;
                    return;
                }
                
                if (!data || data.length === 0) {
                    document.getElementById('enrollmentAnalyticsContent').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #6c757d;">
                            <i class="fas fa-inbox fa-3x" style="margin-bottom: 16px;"></i>
                            <h4>暫無數據</h4>
                            <p>目前沒有國中選擇科系的統計數據</p>
                        </div>
                    `;
                    return;
                }
                
                // 收集所有縣市（只提取縣市部分，不包含學校名稱）
                const cities = new Set();
                data.forEach(school => {
                    if (school && school.school) {
                        const city = extractCityFromSchoolName(school.school);
                        if (city) {
                            cities.add(city);
                        }
                    }
                });
                const cityList = Array.from(cities).sort();
                
                console.log('提取的縣市列表:', cityList);
                console.log('學校數據範例:', data.slice(0, 5).map(s => ({ 
                    school: s.school, 
                    extractedCity: extractCityFromSchoolName(s.school) 
                })));
                
                console.log('提取的縣市列表:', cityList);
                console.log('學校數據範例:', data.slice(0, 3));
                
                // 儲存原始數據供篩選使用
                window.schoolDepartmentData = data;
                window.schoolDepartmentCityList = cityList;
                
                // 渲染內容（包含篩選控件）
                renderSchoolDepartmentContent(data, cityList);
            })
            .catch(error => {
                console.error('載入國中選擇科系統計數據失敗:', error);
                console.error('錯誤詳情:', {
                    message: error.message,
                    stack: error.stack,
                    apiUrl: apiUrl
                });
                document.getElementById('enrollmentAnalyticsContent').innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #dc3545;">
                        <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                        <h4>數據載入失敗</h4>
                        <p>無法連接到統計API</p>
                        <p style="font-size: 0.9em; color: #999; margin-top: 10px;">錯誤: ${error.message}</p>
                        <p style="font-size: 0.8em; color: #999; margin-top: 5px;">請檢查瀏覽器控制台 (F12) 以獲取詳細錯誤信息</p>
                    </div>
                `;
            });
    }
    
    // 渲染學校科系內容（支援篩選）
    function renderSchoolDepartmentContent(data, cityList, filteredData = null, savedState = null) {
        const displayData = filteredData || data;
        
        // 檢查是否已經有篩選控件容器，如果沒有則創建
        let filterContainer = document.getElementById('schoolDeptFilterContainer');
        let dataContainer = document.getElementById('schoolDeptDataContainer');
        
        // 如果是第一次渲染，創建完整的內容
        if (!filterContainer) {
            const fullContent = `
                <div style="margin-bottom: 20px;">
                    <h4 style="color: #667eea; margin-bottom: 15px;">
                        <i class="fas fa-school"></i> 國中選擇科系分析
                    </h4>
                    
                    <!-- 篩選控件容器（固定，不會被重新渲染） -->
                    <div id="schoolDeptFilterContainer" style="background: white; padding: 20px; border-radius: 10px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                        <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                            <div style="flex: 1; min-width: 200px;">
                                <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #495057; font-size: 14px;">
                                    <i class="fas fa-search"></i> 關鍵字搜尋
                                </label>
                                <input type="text" id="schoolDeptKeywordFilter" placeholder="搜尋學校名稱或科系..." 
                                       style="width: 100%; padding: 10px 12px; border: 2px solid #e9ecef; border-radius: 8px; font-size: 14px; transition: all 0.3s;"
                                       autocomplete="off"
                                       spellcheck="false"
                                       tabindex="0">
                            </div>
                            <div style="display: flex; gap: 10px; align-items: flex-end;">
                                <button id="exportSchoolDeptExcelBtn" 
                                        style="padding: 10px 20px; background: #28a745; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 14px; font-weight: 500; transition: all 0.3s;"
                                        onmouseover="this.style.background='#218838'"
                                        onmouseout="this.style.background='#28a745'"
                                        onclick="exportSchoolDepartmentToExcel()">
                                    <i class="fas fa-file-excel"></i> 匯出 Excel
                                </button>
                                <button id="resetSchoolDeptFilterBtn" 
                                        style="padding: 10px 20px; background: #6c757d; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 14px; font-weight: 500; transition: all 0.3s;"
                                        onmouseover="this.style.background='#5a6268'"
                                        onmouseout="this.style.background='#6c757d'">
                                    <i class="fas fa-redo"></i> 重置篩選
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 數據展示容器（會根據篩選結果更新） -->
                    <div id="schoolDeptDataContainer"></div>
                </div>
            `;
            
            document.getElementById('enrollmentAnalyticsContent').innerHTML = fullContent;
            
            // 初始化事件監聽器
            initializeSchoolDeptFilters(cityList);
        }
        
        // 只更新數據展示部分
        dataContainer = document.getElementById('schoolDeptDataContainer');
        if (dataContainer) {
            const dataContent = `
                <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 10px; margin-bottom: 20px;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; text-align: center;">
                        <div>
                            <div style="font-size: 2.5em; font-weight: bold; margin-bottom: 5px;">${displayData.length}</div>
                            <div style="font-size: 1em; opacity: 0.9;">參與國中數</div>
                        </div>
                        <div>
                            <div style="font-size: 2.5em; font-weight: bold; margin-bottom: 5px;">${displayData.reduce((sum, s) => sum + s.total_students, 0)}</div>
                            <div style="font-size: 1em; opacity: 0.9;">總選擇次數</div>
                        </div>
                    </div>
                </div>
                
                ${displayData.length === 0 ? `
                    <div style="text-align: center; padding: 40px; color: #6c757d; background: white; border-radius: 10px;">
                        <i class="fas fa-search fa-3x" style="margin-bottom: 16px; opacity: 0.3;"></i>
                        <h4>沒有符合篩選條件的資料</h4>
                        <p>請嘗試調整篩選條件</p>
                    </div>
                ` : `
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 20px;">
                        ${displayData.map((school, index) => {
                            // 使用更淺的顏色
                            const colors = ['#a8b5f0', '#7dd87d', '#ffd966', '#f5a5a5', '#7dd4e8', '#b8a5e8', '#ffb366', '#7dd4c8'];
                            const color = colors[index % colors.length];
                            
                            return `
                                <div style="background: white; box-shadow: 0 2px 10px rgba(0,0,0,0.1); overflow: hidden;">
                                    <div style="background: ${color}; color: white; padding: 15px 20px;">
                                        <h5 style="margin: 0; font-size: 1.1em; font-weight: 600; display: flex; justify-content: space-between; align-items: center;">
                                            <span><i class="fas fa-school"></i> ${school.school}</span>
                                            <span style="background: rgba(255,255,255,0.3); padding: 4px 12px; border-radius: 15px; font-size: 0.9em;">
                                                ${school.total_students}次選擇
                                            </span>
                                        </h5>
                                    </div>
                                    
                                    <div style="padding: 20px;">
                                        <div style="margin-bottom: 15px; font-size: 0.9em; color: #666;">
                                            共選擇 <strong style="color: ${color};">${school.departments.length}</strong> 個科系
                                        </div>
                                        
                                        <div style="max-height: 400px; overflow-y: auto;">
                                            <table style="width: 100%; border-collapse: collapse;">
                                                <thead>
                                                    <tr style="background: #f8f9fa; border-bottom: 2px solid #dee2e6;">
                                                        <th style="padding: 10px; text-align: left; font-weight: 600; color: #495057; font-size: 0.9em;">科系名稱</th>
                                                        <th style="padding: 10px; text-align: center; font-weight: 600; color: #495057; font-size: 0.9em;">總數</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    ${school.departments.map((dept, deptIndex) => {
                                                        return `
                                                            <tr style="border-bottom: 1px solid #e9ecef; ${deptIndex % 2 === 0 ? 'background: #f8f9fa;' : ''}">
                                                                <td style="padding: 12px 10px; font-weight: 500; color: #333;">${dept.name}</td>
                                                                <td style="padding: 12px 10px; text-align: center; font-weight: bold; color: ${color};">${dept.total}</td>
                                                            </tr>
                                                        `;
                                                    }).join('')}
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            `;
                        }).join('')}
                    </div>
                `}
            `;
            
            dataContainer.innerHTML = dataContent;
        }
    }
    
    // 初始化篩選控件的事件監聽器（只執行一次）
    function initializeSchoolDeptFilters(cityList) {
        setTimeout(() => {
            const keywordInput = document.getElementById('schoolDeptKeywordFilter');
            const resetBtn = document.getElementById('resetSchoolDeptFilterBtn');
            
            if (keywordInput) {
                // 確保輸入框可以正常輸入
                keywordInput.disabled = false;
                keywordInput.readOnly = false;
                keywordInput.style.pointerEvents = 'auto';
                keywordInput.style.userSelect = 'text';
                keywordInput.style.cursor = 'text';
                
                // 添加焦点和失焦样式处理
                keywordInput.addEventListener('focus', function() {
                    this.style.borderColor = '#667eea';
                    this.style.boxShadow = '0 0 0 3px rgba(102, 126, 234, 0.1)';
                }, false);
                
                keywordInput.addEventListener('blur', function() {
                    this.style.borderColor = '#e9ecef';
                    this.style.boxShadow = 'none';
                }, false);
                
                // 使用防抖函數來優化性能
                let filterTimeout;
                const triggerFilter = function() {
                    clearTimeout(filterTimeout);
                    filterTimeout = setTimeout(() => {
                        if (window.filterSchoolDepartment) {
                            window.filterSchoolDepartment();
                        }
                    }, 500); // 增加延遲時間，減少重新渲染頻率
                };
                
                // 只使用 input 事件，這是最可靠的事件，不會干擾輸入
                keywordInput.addEventListener('input', function(e) {
                    e.stopPropagation(); // 阻止事件冒泡
                    triggerFilter();
                }, false);
            }
            
            if (resetBtn) {
                resetBtn.addEventListener('click', function() {
                    if (window.resetSchoolDepartmentFilter) {
                        window.resetSchoolDepartmentFilter();
                    }
                }, false);
            }
        }, 100);
    }
    
    // 篩選學校科系資料（全局函數）
    window.filterSchoolDepartment = function() {
        if (!window.schoolDepartmentData || !window.schoolDepartmentCityList) {
            console.error('篩選失敗：缺少數據');
            return;
        }
        
        const keywordFilterEl = document.getElementById('schoolDeptKeywordFilter');
        
        if (!keywordFilterEl) {
            console.error('篩選失敗：找不到篩選控件');
            return;
        }
        
        const keywordFilter = keywordFilterEl.value.toLowerCase().trim() || '';
        
        console.log('篩選條件:', { keywordFilter, '數據總數': window.schoolDepartmentData.length });
        
        // 篩選資料
        const filteredData = [];
        
        for (let i = 0; i < window.schoolDepartmentData.length; i++) {
            const school = window.schoolDepartmentData[i];
            
            if (!school || !school.school) continue;
            
            // 關鍵字篩選（搜尋學校名稱或科系名稱）
            if (keywordFilter) {
                const schoolName = (school.school || '').toString();
                const schoolMatch = schoolName.toLowerCase().includes(keywordFilter);
                
                // 篩選科系列表，只保留符合關鍵字的科系
                const departments = Array.isArray(school.departments) ? school.departments : [];
                const filteredDepartments = departments.filter(dept => {
                    if (!dept || !dept.name) return false;
                    const deptName = dept.name.toString().toLowerCase();
                    return deptName.includes(keywordFilter);
                });
                
                console.log(`學校: ${schoolName}, 關鍵字: ${keywordFilter}, 學校匹配: ${schoolMatch}, 科系匹配數: ${filteredDepartments.length}`);
                
                // 如果學校名稱和科系都不符合，則過濾掉整個學校
                if (!schoolMatch && filteredDepartments.length === 0) {
                    continue; // 不符合關鍵字篩選，跳過
                }
                
                // 如果學校名稱符合，保留所有科系；如果只有科系符合，只保留符合的科系
                if (schoolMatch) {
                    // 學校名稱符合，保留所有科系
                    filteredData.push(school);
                } else {
                    // 只有科系符合，只保留符合的科系
                    const newSchool = {
                        school: school.school,
                        departments: filteredDepartments,
                        total_students: filteredDepartments.reduce((sum, dept) => sum + (dept.total || 0), 0)
                    };
                    filteredData.push(newSchool);
                }
            } else {
                // 沒有關鍵字篩選，直接加入
                filteredData.push(school);
            }
        }
        
        console.log('篩選結果:', filteredData.length, '所學校');
        
        // 只更新數據展示部分，不重新渲染輸入框
        if (typeof renderSchoolDepartmentContent === 'function') {
            renderSchoolDepartmentContent(window.schoolDepartmentData, window.schoolDepartmentCityList, filteredData);
        } else {
            console.error('renderSchoolDepartmentContent 函數不存在');
        }
    };
    
    // 為了向後兼容，也保留原來的函數名
    function filterSchoolDepartment() {
        window.filterSchoolDepartment();
    }
    
    // 重置篩選（全局函數）
    window.resetSchoolDepartmentFilter = function() {
        const keywordFilter = document.getElementById('schoolDeptKeywordFilter');
        
        if (keywordFilter) keywordFilter.value = '';
        
        // 重新渲染原始資料
        if (window.schoolDepartmentData && window.schoolDepartmentCityList) {
            if (typeof renderSchoolDepartmentContent === 'function') {
                renderSchoolDepartmentContent(window.schoolDepartmentData, window.schoolDepartmentCityList);
            } else {
                console.error('renderSchoolDepartmentContent 函數不存在');
            }
        }
    };
    
    // 為了向後兼容，也保留原來的函數名
    function resetSchoolDepartmentFilter() {
        window.resetSchoolDepartmentFilter();
    }
    
    // 匯出國中選擇科系統計資料為 Excel
    function exportSchoolDepartmentToExcel() {
        console.log('開始匯出 Excel');
        
        // 獲取當前顯示的資料（如果有篩選，使用篩選後的資料；否則使用原始資料）
        let exportData = window.schoolDepartmentData || [];
        
        // 檢查是否有篩選條件
        const keywordFilter = document.getElementById('schoolDeptKeywordFilter');
        if (keywordFilter && keywordFilter.value.trim()) {
            // 如果有篩選，需要重新應用篩選邏輯來獲取當前顯示的資料
            const keyword = keywordFilter.value.toLowerCase().trim();
            exportData = window.schoolDepartmentData.filter(school => {
                if (!school || !school.school) return false;
                
                const schoolName = school.school.toString().toLowerCase();
                const schoolMatch = schoolName.includes(keyword);
                
                const departments = Array.isArray(school.departments) ? school.departments : [];
                const filteredDepartments = departments.filter(dept => {
                    if (!dept || !dept.name) return false;
                    return dept.name.toString().toLowerCase().includes(keyword);
                });
                
                return schoolMatch || filteredDepartments.length > 0;
            }).map(school => {
                const schoolName = school.school.toString().toLowerCase();
                const schoolMatch = schoolName.includes(keyword);
                
                if (schoolMatch) {
                    return school; // 學校名稱符合，保留所有科系
                } else {
                    // 只有科系符合，只保留符合的科系
                    const departments = Array.isArray(school.departments) ? school.departments : [];
                    const filteredDepartments = departments.filter(dept => {
                        if (!dept || !dept.name) return false;
                        return dept.name.toString().toLowerCase().includes(keyword);
                    });
                    return {
                        school: school.school,
                        departments: filteredDepartments,
                        total_students: filteredDepartments.reduce((sum, dept) => sum + (dept.total || 0), 0)
                    };
                }
            });
        }
        
        if (!exportData || exportData.length === 0) {
            alert('目前沒有可匯出的資料');
            return;
        }
        
        // 準備 Excel 資料
        // 第一行：標題
        const excelData = [
            ['學校名稱', '科系名稱', '選擇次數']
        ];
        
        // 遍歷每個學校和科系
        exportData.forEach(school => {
            if (school.departments && school.departments.length > 0) {
                school.departments.forEach((dept, index) => {
                    excelData.push([
                        index === 0 ? school.school : '', // 只在第一行顯示學校名稱
                        dept.name || '',
                        dept.total || 0
                    ]);
                });
            } else {
                // 如果沒有科系資料，至少顯示學校名稱
                excelData.push([
                    school.school || '',
                    '',
                    0
                ]);
            }
        });
        
        // 添加統計行
        excelData.push([]); // 空行
        excelData.push(['統計', '', '']);
        excelData.push(['參與國中數', '', exportData.length]);
        excelData.push(['總選擇次數', '', exportData.reduce((sum, s) => sum + (s.total_students || 0), 0)]);
        
        // 創建工作表
        const ws = XLSX.utils.aoa_to_sheet(excelData);
        
        // 設置列寬
        ws['!cols'] = [
            { wch: 30 }, // 學校名稱
            { wch: 25 }, // 科系名稱
            { wch: 12 }  // 選擇次數
        ];
        
        // 創建工作簿
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, '國中選擇科系統計');
        
        // 生成檔案名稱（包含日期時間）
        const now = new Date();
        const dateStr = now.getFullYear() + 
                       String(now.getMonth() + 1).padStart(2, '0') + 
                       String(now.getDate()).padStart(2, '0') + '_' +
                       String(now.getHours()).padStart(2, '0') + 
                       String(now.getMinutes()).padStart(2, '0');
        const fileName = `國中選擇科系統計_${dateStr}.xlsx`;
        
        // 匯出檔案
        XLSX.writeFile(wb, fileName);
        
        console.log('Excel 匯出完成:', fileName);
    }
    
    function clearEnrollmentCharts() {
        console.log('clearEnrollmentCharts 被調用');
        
        // 清除所有就讀意願相關的Chart.js實例
        Chart.helpers.each(Chart.instances, function(instance) {
            if (instance.canvas.id.includes('enrollmentSystemChart') ||
                instance.canvas.id.includes('enrollmentGradeChart') ||
                instance.canvas.id.includes('enrollmentGenderChart') ||
                instance.canvas.id.includes('enrollmentIdentityChart') ||
                instance.canvas.id.includes('enrollmentMonthlyChart')) {
                instance.destroy();
            }
        });
        
        // 重新顯示各科分配人數總覽
        showEnrollmentSystemStats();
    }
    
    // 顯示續招報名科系學生詳情
    function showContinuedAdmissionDepartmentStudents(departmentName) {
        console.log('顯示續招報名科系學生詳情:', departmentName);

        // 顯示載入中
        const loadingContent = `
            <div style="background: white; border-radius: 10px; padding: 20px; max-width: 800px; max-height: 600px; overflow-y: auto;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #dee2e6; padding-bottom: 15px;">
                    <h4 style="margin: 0; color: #333;">
                        <i class="fas fa-users"></i> ${departmentName} - 學生名單
                    </h4>
                    <button onclick="closeStudentModal()" style="background: #dc3545; color: white; border: none; padding: 8px 16px; border-radius: 5px; cursor: pointer;">
                        <i class="fas fa-times"></i> 關閉
                    </button>
                </div>
                <div style="text-align: center; padding: 40px;">
                    <i class="fas fa-spinner fa-spin fa-2x" style="color: #667eea; margin-bottom: 15px;"></i>
                    <p>載入學生資料中...</p>
                </div>
            </div>
        `;

        showStudentModal(loadingContent);

        // 從API獲取該科系的學生資料
        fetch('../../Topics-frontend/frontend/api/continued_admission_department_students_api.php?department=' + encodeURIComponent(departmentName))
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    const errorContent = `
                        <div style="background: white; border-radius: 10px; padding: 20px; max-width: 800px; max-height: 600px; overflow-y: auto;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #dee2e6; padding-bottom: 15px;">
                                <h4 style="margin: 0; color: #333;">
                                    <i class="fas fa-users"></i> ${departmentName} - 學生名單
                                </h4>
                                <button onclick="closeStudentModal()" style="background: #dc3545; color: white; border: none; padding: 8px 16px; border-radius: 5px; cursor: pointer;">
                                    <i class="fas fa-times"></i> 關閉
                                </button>
                            </div>
                            <div style="text-align: center; padding: 40px; color: #dc3545;">
                                <i class="fas fa-exclamation-triangle fa-2x" style="margin-bottom: 15px;"></i>
                                <p>載入學生資料失敗: ${data.error}</p>
                            </div>
                        </div>
                    `;
                    document.getElementById('studentModal').innerHTML = errorContent;
                    return;
                }

                // 創建模態視窗內容
                const modalContent = `
                    <div style="background: white; border-radius: 10px; padding: 20px; max-width: 800px; max-height: 600px; overflow-y: auto;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #dee2e6; padding-bottom: 15px;">
                            <h4 style="margin: 0; color: #333;">
                                <i class="fas fa-users"></i> ${departmentName} - 學生名單
                            </h4>
                            <button onclick="closeStudentModal()" style="background: #dc3545; color: white; border: none; padding: 8px 16px; border-radius: 5px; cursor: pointer;">
                                <i class="fas fa-times"></i> 關閉
                            </button>
                        </div>

                        <div style="margin-bottom: 15px;">
                            <span style="background: #667eea; color: white; padding: 5px 12px; border-radius: 15px; font-size: 14px;">
                                共 ${data.length} 位學生
                            </span>
                        </div>

                        <div style="background: #f8f9fa; border-radius: 8px; overflow: hidden;">
                            <table style="width: 100%; border-collapse: collapse;">
                                <thead>
                                    <tr style="background: #e9ecef;">
                                        <th style="padding: 12px; text-align: left; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">姓名</th>
                                        <th style="padding: 12px; text-align: left; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">學校</th>
                                        <th style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">年級</th>
                                        <th style="padding: 12px; text-align: left; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">聯絡電話</th>
                                        <th style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">報名時間</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${data.map((student, index) => `
                                        <tr style="border-bottom: 1px solid #dee2e6;">
                                            <td style="padding: 12px; font-weight: 500; color: #333;">${student.name || '未填寫'}</td>
                                            <td style="padding: 12px; color: #666;">${student.school || '未填寫'}</td>
                                            <td style="padding: 12px; text-align: center; color: #666;">${student.grade || '未填寫'}</td>
                                            <td style="padding: 12px; color: #666;">
                                                ${student.phone1 ? `<div style="margin-bottom: 2px;"><i class="fas fa-phone" style="color: #667eea; margin-right: 5px;"></i>${student.phone1}</div>` : ''}
                                                ${student.phone2 ? `<div><i class="fas fa-phone" style="color: #667eea; margin-right: 5px;"></i>${student.phone2}</div>` : ''}
                                                ${!student.phone1 && !student.phone2 ? '未填寫' : ''}
                                            </td>
                                            <td style="padding: 12px; text-align: center; color: #666; font-size: 0.9em;">${student.created_at ? new Date(student.created_at).toLocaleDateString('zh-TW') : '未填寫'}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;

                // 更新模態視窗內容
                document.getElementById('studentModal').innerHTML = modalContent;
            })
            .catch(error => {
                console.error('載入學生資料失敗:', error);
                const errorContent = `
                    <div style="background: white; border-radius: 10px; padding: 20px; max-width: 800px; max-height: 600px; overflow-y: auto;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #dee2e6; padding-bottom: 15px;">
                            <h4 style="margin: 0; color: #333;">
                                <i class="fas fa-users"></i> ${departmentName} - 學生名單
                            </h4>
                            <button onclick="closeStudentModal()" style="background: #dc3545; color: white; border: none; padding: 8px 16px; border-radius: 5px; cursor: pointer;">
                                <i class="fas fa-times"></i> 關閉
                            </button>
                        </div>
                        <div style="text-align: center; padding: 40px; color: #dc3545;">
                            <i class="fas fa-exclamation-triangle fa-2x" style="margin-bottom: 15px;"></i>
                            <p>載入學生資料失敗，請稍後再試</p>
                        </div>
                    </div>
                `;
                document.getElementById('studentModal').innerHTML = errorContent;
            });
    }

    // 顯示科系「招生詳情」圖表（展開在同一區塊，不使用視窗）
    // 1. 科系招生總覽：已報名 / 已報到 / 放棄 / 尚在追蹤
    // 2. 這些國中有幾人分配到本系
    function showDepartmentStudents(departmentName) {
        console.log('顯示科系招生詳情 (展開):', departmentName);

        const container = document.getElementById('departmentDetailContainer');
        if (!container) {
            console.warn('找不到 departmentDetailContainer 容器');
            return;
        }

        // 顯示載入中
        container.innerHTML = `
            <div style="background: white; border-radius: 10px; padding: 16px 18px; border: 1px solid #e1e4eb;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; border-bottom: 1px solid #f0f0f0; padding-bottom: 10px;">
                    <h5 style="margin: 0; color: #333;">
                        <i class="fas fa-chart-bar"></i> ${departmentName} - 招生詳情
                    </h5>
                    <button type="button"
                            onclick="document.getElementById('departmentDetailContainer').innerHTML='';"
                            style="background: #f5f5f5; color: #666; border: 1px solid #d9d9d9; padding: 4px 10px; border-radius: 14px; font-size: 12px; cursor: pointer;">
                        <i class="fas fa-chevron-up"></i> 收合
                    </button>
                </div>
                <div style="text-align: center; padding: 24px 10px;">
                    <i class="fas fa-spinner fa-spin fa-2x" style="color: #667eea; margin-bottom: 10px;"></i>
                    <p style="margin: 0; color: #555;">載入科系統計資料中...</p>
                </div>
            </div>
        `;

        // 從 API 取得該科系的統計資料（以 assigned_department 為基準），若有選屆別一併傳入
        let detailUrl = '../../Topics-frontend/frontend/api/enrollment_department_detail_stats_api.php?department=' + encodeURIComponent(departmentName);
        const rocSel = document.getElementById('enrollmentRocYearSelect');
        if (rocSel && rocSel.value !== '') {
            detailUrl += '&roc_year=' + encodeURIComponent(rocSel.value);
        }
        fetch(detailUrl)
            .then(response => response.json())
            .then(data => {
                if (!data || data.success === false || data.error) {
                    const msg = data && data.error ? data.error : '無法載入科系統計資料';
                    container.innerHTML = `
                        <div style="background: white; border-radius: 10px; padding: 16px 18px; border: 1px solid #ffe3e3;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                <h5 style="margin: 0; color: #c53030;">
                                    <i class="fas fa-exclamation-triangle"></i> ${departmentName} - 招生詳情
                                </h5>
                                <button type="button"
                                        onclick="document.getElementById('departmentDetailContainer').innerHTML='';"
                                        style="background: #fff1f0; color: #c53030; border: 1px solid #ffa39e; padding: 2px 10px; border-radius: 14px; font-size: 12px; cursor: pointer;">
                                    收合
                                </button>
                            </div>
                            <p style="margin: 0; color: #c53030; font-size: 13px;">載入科系統計資料失敗：${msg}</p>
                        </div>
                    `;
                    return;
                }

                const summary = data.status_summary || {};
                const schools = data.schools || [];

                const totalAssigned = summary.total_assigned || 0;
                const applied     = summary.applied || 0;
                const checkedIn   = summary.checked_in || 0;
                const declined    = summary.declined || 0;
                const tracking    = summary.tracking || 0;

                // 修改 detailContent 的生成邏輯
const detailContent = `
    <div style="margin-top: 8px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h4 style="margin: 0; color: #333;">
                <i class="fas fa-chart-bar"></i> ${departmentName} - 招生詳情
                <span style="font-size: 0.75em; color: #888; font-weight: normal; margin-left: 8px;">（分配到本系總人數 ${totalAssigned} 人）</span>
            </h4>
            <button type="button"
                    onclick="document.getElementById('departmentDetailContainer').innerHTML='';"
                    style="background: #f5f5f5; color: #666; border: 1px solid #d9d9d9; padding: 6px 14px; border-radius: 6px; font-size: 13px; cursor: pointer;">
                <i class="fas fa-chevron-up"></i> 收合
            </button>
        </div>

        <div class="dept-tabs">
            <button type="button" class="dept-tab-btn active" onclick="switchDeptTab(this, 'tab-overview')">
                <i class="fas fa-chart-pie"></i> 目前招生狀況
            </button>
            <button type="button" class="dept-tab-btn" onclick="switchDeptTab(this, 'tab-sources')">
                <i class="fas fa-school"></i> 生源學校分析
            </button>
            <button type="button" class="dept-tab-btn" onclick="switchDeptTab(this, 'tab-grade')">
                <i class="fas fa-school"></i> 年級分布圖
            </button>
        </div>

        <div id="tab-overview" class="dept-tab-content active">
            <div class="chart-card">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div class="chart-title" style="text-align: left; margin-bottom: 0;">
                        目前招生進度統計
                    </div>
                    <div style="display: inline-flex; gap: 8px;">
                        <button id="deptOverviewBarBtn" type="button" style="padding: 6px 14px; border-radius: 6px; border: 1px solid #667eea; background: #667eea; color: #fff; font-size: 13px; cursor: pointer;">長條圖</button>
                        <button id="deptOverviewPieBtn" type="button" style="padding: 6px 14px; border-radius: 6px; border: 1px solid #667eea; background: #fff; color: #667eea; font-size: 13px; cursor: pointer;">圓餅圖</button>
                    </div>
                </div>
                <div class="chart-container">
                    <canvas id="deptOverviewChart"></canvas>
                </div>
            </div>
        </div>

        <div id="tab-sources" class="dept-tab-content">
            <div class="chart-card">
                 <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <div class="chart-title" style="text-align: left; margin: 0; font-size: 20px; font-weight: bold;">生源學校分佈</div>
                    
                    ${(schools && schools.length > 0) ? `
                    <div style="display: inline-flex; gap: 10px;">
                        <button id="deptSchoolChartBtn" type="button" style="padding: 8px 16px; border-radius: 4px; border: 1px solid #ccc; background: #fff; color: #333; font-size: 15px; cursor: pointer;">
                            <i class="fas fa-chart-bar"></i> 圖表
                        </button>
                        <button id="deptSchoolTableBtn" type="button" style="padding: 8px 16px; border-radius: 4px; border: 1px solid #333; background: #333; color: #fff; font-size: 15px; cursor: pointer;">
                            <i class="fas fa-table"></i> 表格
                        </button>
                    </div>` : ''}
                </div>

                ${(schools && schools.length > 0) ? `
                <div id="deptSchoolChartWrap" style="display: none;">
                    <div class="chart-container"><canvas id="deptSchoolChart"></canvas></div>
                </div>

                <div id="deptSchoolTableWrap" style="display: block;">
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse; background: #fff;">
                            <thead>
                                <tr style="border-bottom: 2px solid #333;">
                                    <th style="padding: 15px 10px; text-align: left; font-size: 18px; color: #000; font-weight: bold; width: 60%;">國中名稱</th>
                                    <th style="padding: 15px 10px; text-align: center; font-size: 18px; color: #000; font-weight: bold; width: 20%;">人數</th>
                                    <th style="padding: 15px 10px; text-align: center; font-size: 18px; color: #000; font-weight: bold; width: 20%;">來源</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${schools.map(s => {
                                    const sourceData = encodeURIComponent(JSON.stringify(s.sources || []));
                                    const schoolNameEnc = encodeURIComponent(s.name);

                                    return `
                                    <tr style="border-bottom: 1px solid #eee; height: 60px;">
                                        <td style="padding: 10px; font-size: 18px; color: #333;">
                                            ${(s.name || '未填寫')}
                                        </td>
                                        
                                        <td style="padding: 10px; text-align: center; font-size: 20px; font-weight: bold; color: #000;">
                                            ${s.count}
                                        </td>
                                        
                                        <td style="padding: 10px; text-align: center;">
                                            <button type="button" 
                                                    onclick="showSourceDetail('${schoolNameEnc}', '${sourceData}')"
                                                    style="border: none; background: transparent; color: #666; font-size: 18px; cursor: pointer; text-decoration: underline;">
                                                查看
                                            </button>
                                        </td>
                                    </tr>`;
                                }).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>` : '<div style="text-align: center; color: #999; padding: 40px; font-size: 18px;">目前沒有資料</div>'}
            </div>
        </div>

        <div id="sourceDetailModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; justify-content: center; align-items: center;">
            <div style="background: #fff; width: 90%; max-width: 400px; border-radius: 8px; padding: 0; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                <div style="padding: 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
                    <h3 id="modalSchoolName" style="margin: 0; font-size: 20px; color: #333;"></h3>
                    <button onclick="document.getElementById('sourceDetailModal').style.display='none'" style="border: none; background: none; font-size: 28px; cursor: pointer; color: #999;">&times;</button>
                </div>
                <div id="modalContent" style="padding: 25px; max-height: 400px; overflow-y: auto;"></div>
            </div>
        </div>

        <div id="sourceDetailModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; justify-content: center; align-items: center;">
            <div style="background: #fff; width: 90%; max-width: 400px; border-radius: 12px; padding: 0; box-shadow: 0 10px 25px rgba(0,0,0,0.2);">
                <div style="padding: 15px 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
                    <h5 id="modalSchoolName" style="margin: 0; font-size: 18px; color: #333;">來源詳情</h5>
                    <button onclick="document.getElementById('sourceDetailModal').style.display='none'" style="border: none; background: none; font-size: 24px; cursor: pointer; color: #999;">&times;</button>
                </div>
                <div id="modalContent" style="padding: 20px; max-height: 400px; overflow-y: auto;"></div>
                <div style="padding: 15px 20px; border-top: 1px solid #eee; text-align: right;">
                    <button onclick="document.getElementById('sourceDetailModal').style.display='none'" style="padding: 8px 20px; background: #667eea; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-size: 15px;">關閉</button>
                </div>
            </div>
        </div>

        <div id="sourceDetailModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; justify-content: center; align-items: center;">
            <div style="background: #fff; width: 90%; max-width: 400px; border-radius: 12px; padding: 0; box-shadow: 0 10px 25px rgba(0,0,0,0.2); animation: fadeIn 0.2s;">
                <div style="padding: 15px 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
                    <h5 id="modalSchoolName" style="margin: 0; font-size: 16px; color: #333;">來源詳情</h5>
                    <button onclick="document.getElementById('sourceDetailModal').style.display='none'" style="border: none; background: none; font-size: 20px; cursor: pointer; color: #999;">&times;</button>
                </div>
                <div id="modalContent" style="padding: 20px; max-height: 400px; overflow-y: auto;">
                    </div>
                <div style="padding: 12px 20px; border-top: 1px solid #eee; text-align: right;">
                    <button onclick="document.getElementById('sourceDetailModal').style.display='none'" style="padding: 6px 16px; background: #667eea; color: #fff; border: none; border-radius: 6px; cursor: pointer;">關閉</button>
                </div>
            </div>
        </div>

        <div id="tab-grade" class="dept-tab-content">
            <div class="chart-card">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div class="chart-title" style="text-align: left; margin-bottom: 0;">
                        分配到本科學生的年級分布
                    </div>
                    ${(data.grades && data.grades.length > 0) ? `
                    <div style="display: inline-flex; gap: 8px;">
                    <button id="deptGradeTableBtn" type="button" style="padding: 6px 14px; border-radius: 6px; border: 1px solid #667eea; background: #667eea; color: #fff; font-size: 13px; cursor: pointer;">表格</button>    
                    <button id="deptGradeChartBtn" type="button" style="padding: 6px 14px; border-radius: 6px; border: 1px solid #667eea; background: #fff; color: #667eea; font-size: 13px; cursor: pointer;">圖表</button>
                        </div>
                    ` : ''}
                </div>
                
                ${(data.grades && data.grades.length > 0) ? `
                <div id="deptGradeChartWrap" style="display: none;">
                    <div class="chart-container">
                        <canvas id="deptGradeChart"></canvas>
                    </div>
                </div>
                <div id="deptGradeTableWrap" style="display: block;">
                    <div style="overflow-x: auto; margin-top: 10px;">
                        <table style="width: 100%; border-collapse: collapse; background: #fff;">
                            <thead>
                                <tr style="background: #f8f9fa;">
                                    <th style="padding: 12px 15px; text-align: left; border-bottom: 2px solid #dee2e6; font-weight: 600; color: #495057;">年級</th>
                                    <th style="padding: 12px 15px; text-align: center; border-bottom: 2px solid #dee2e6; font-weight: 600; color: #495057;">分配人數</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${data.grades.map((g, i) => `
                                <tr style="border-bottom: 1px solid #eee;">
                                    <td style="padding: 12px 15px; color: #333;">${(g.grade || '未填寫')}</td>
                                    <td style="padding: 12px 15px; text-align: center; font-weight: bold; color: #667eea;">${g.count || 0} 人</td>
                                </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
                ` : '<div style="text-align: center; color: #999; padding: 20px;">目前沒有年級分布資料</div>'}
            </div>
        </div>
    </div>
`;

                // 更新展開內容
                container.innerHTML = detailContent;

                // 捲動到展開區塊
                container.scrollIntoView({ behavior: 'smooth', block: 'start' });

                // 建立圖表
                setTimeout(() => {
                    // 年級分布圖表
                    const gradeCanvas = document.getElementById('deptGradeChart');
                    if (gradeCanvas && window.Chart && data.grades && data.grades.length > 0) {
                        const ctx3 = gradeCanvas.getContext('2d');
                        const labels3 = data.grades.map(g => g.grade || '未填寫');
                        const values3 = data.grades.map(g => g.count || 0);
                        const colors3 = ['#667eea', '#28a745', '#fa8c16', '#8c8c8c', '#ff7875'];

                        new Chart(ctx3, {
                            type: 'bar',
                            data: {
                                labels: labels3,
                                datasets: [{
                                    label: '分配人數',
                                    data: values3,
                                    backgroundColor: '#667eeaCC',
                                    borderColor: '#667eea',
                                    borderWidth: 1
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                scales: {
                                    x: {
                                        ticks: {
                                            font: { size: 12 }
                                        }
                                    },
                                    y: {
                                        beginAtZero: true,
                                        ticks: { stepSize: 1, font: { size: 12 } }
                                    }
                                },
                                plugins: {
                                    legend: { display: false },
                                    tooltip: {
                                        callbacks: {
                                            label: function(context) {
                                                const label = context.label || '';
                                                const value = context.parsed.y;
                                                return `${label}: ${value}人`;
                                            }
                                        }
                                    }
                                }
                            }
                        });
                    }

                    // 年級分布：圖表 / 表格切換
                    const deptGradeTableWrap = document.getElementById('deptGradeTableWrap');
                    const deptGradeChartWrap = document.getElementById('deptGradeChartWrap');
                    
                    const deptGradeChartBtn = document.getElementById('deptGradeChartBtn');
                    const deptGradeTableBtn = document.getElementById('deptGradeTableBtn');
                    if (deptGradeChartWrap && deptGradeTableWrap && deptGradeChartBtn && deptGradeTableBtn) {
                        deptGradeChartBtn.addEventListener('click', () => {
                            deptGradeChartWrap.style.display = 'block';
                            deptGradeTableWrap.style.display = 'none';
                            deptGradeChartBtn.style.background = '#667eea';
                            deptGradeChartBtn.style.color = '#fff';
                            deptGradeTableBtn.style.background = '#fff';
                            deptGradeTableBtn.style.color = '#667eea';
                        });
                        deptGradeTableBtn.addEventListener('click', () => {
                            deptGradeTableWrap.style.display = 'block';
                            deptGradeChartWrap.style.display = 'none';
                            deptGradeTableBtn.style.background = '#667eea';
                            deptGradeTableBtn.style.color = '#fff';
                            deptGradeChartBtn.style.background = '#fff';
                            deptGradeChartBtn.style.color = '#667eea';
                        });
                    }
                    // 科系招生總覽圖表
                    const overviewCanvas = document.getElementById('deptOverviewChart');
                    if (overviewCanvas && window.Chart) {
                        const ctx = overviewCanvas.getContext('2d');
                        let overviewChart = null;

                        const labels = ['已報名', '已報到', '放棄', '尚在追蹤'];
                        const values = [applied, checkedIn, declined, tracking];
                        const colors = ['#667eea', '#28a745', '#8c8c8c', '#fa8c16'];

                        function renderOverviewChart(type) {
                            if (overviewChart) {
                                overviewChart.destroy();
                            }
                            overviewChart = new Chart(ctx, {
                                type: type,
                                data: {
                                    labels: labels,
                                    datasets: [{
                                        label: '人數',
                                        data: values,
                                        backgroundColor: type === 'pie' ? colors : colors.map(c => c + 'CC'),
                                        borderColor: type === 'pie' ? '#fff' : colors,
                                        borderWidth: type === 'pie' ? 2 : 1
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    plugins: {
                                        legend: {
                                            display: type === 'pie',
                                            position: 'bottom'
                                        },
                                        tooltip: {
                                            callbacks: {
                                                label: function(context) {
                                                    const label = context.label || '';
                                                    const value = type === 'bar' ? (context.parsed && context.parsed.y) : (context.parsed ?? 0);
                                                    const num = Number(value) || 0;
                                                    const total = values.reduce((a, b) => a + b, 0);
                                                    const pct = total > 0 ? ((num / total) * 100).toFixed(1) : '0.0';
                                                    return type === 'pie'
                                                        ? `${label}: ${num}人 (${pct}%)`
                                                        : `${label}: ${num}人`;
                                                }
                                            }
                                        }
                                    },
                                    scales: type === 'bar' ? {
                                        y: {
                                            beginAtZero: true,
                                            ticks: { stepSize: 1 }
                                        }
                                    } : {}
                                }
                            });
                        }

                        // 預設顯示長條圖
                        renderOverviewChart('bar');

                        const barBtn = document.getElementById('deptOverviewBarBtn');
                        const pieBtn = document.getElementById('deptOverviewPieBtn');
                        if (barBtn && pieBtn) {
                            barBtn.addEventListener('click', () => {
                                barBtn.style.background = '#667eea';
                                barBtn.style.color = '#fff';
                                pieBtn.style.background = '#fff';
                                pieBtn.style.color = '#667eea';
                                renderOverviewChart('bar');
                            });
                            pieBtn.addEventListener('click', () => {
                                pieBtn.style.background = '#667eea';
                                pieBtn.style.color = '#fff';
                                barBtn.style.background = '#fff';
                                barBtn.style.color = '#667eea';
                                renderOverviewChart('pie');
                            });
                        }
                    }

                    // 來源國中分布圖表（顯示國中名稱，與其他統計圖一致大小）
                    const schoolCanvas = document.getElementById('deptSchoolChart');
                    if (schoolCanvas && window.Chart && schools && schools.length > 0) {
                        const ctx2 = schoolCanvas.getContext('2d');
                        const labels2 = schools.map(s => s.name || '未填寫');
                        const values2 = schools.map(s => s.count || 0);

                        new Chart(ctx2, {
                            type: 'bar',
                            data: {
                                labels: labels2,
                                datasets: [{
                                    label: '分配人數',
                                    data: values2,
                                    backgroundColor: '#667eeaCC',
                                    borderColor: '#667eea',
                                    borderWidth: 1
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                scales: {
                                    x: {
                                        ticks: {
                                            font: { size: 12 },
                                            autoSkip: false,
                                            maxRotation: 45,
                                            minRotation: 45
                                        }
                                    },
                                    y: {
                                        beginAtZero: true,
                                        ticks: { stepSize: 1, font: { size: 12 } }
                                    }
                                },
                                plugins: {
                                    legend: { display: false },
                                    tooltip: {
                                        callbacks: {
                                            label: function(context) {
                                                const label = context.label || '';
                                                const value = context.parsed.y;
                                                return `${label}: ${value}人`;
                                            }
                                        }
                                    }
                                }
                            }
                        });
                    }

                    // 國中分布：圖表 / 表格切換
                    const deptSchoolTableWrap = document.getElementById('deptSchoolTableWrap');
                    const deptSchoolChartWrap = document.getElementById('deptSchoolChartWrap');
                    
                    const deptSchoolChartBtn = document.getElementById('deptSchoolChartBtn');
                    const deptSchoolTableBtn = document.getElementById('deptSchoolTableBtn');
                    if (deptSchoolChartWrap && deptSchoolTableWrap && deptSchoolChartBtn && deptSchoolTableBtn) {
                        deptSchoolChartBtn.addEventListener('click', () => {
                            deptSchoolChartWrap.style.display = 'block';
                            deptSchoolTableWrap.style.display = 'none';
                            deptSchoolChartBtn.style.background = '#667eea';
                            deptSchoolChartBtn.style.color = '#fff';
                            deptSchoolTableBtn.style.background = '#fff';
                            deptSchoolTableBtn.style.color = '#667eea';
                        });
                        deptSchoolTableBtn.addEventListener('click', () => {
                            deptSchoolTableWrap.style.display = 'block';
                            deptSchoolChartWrap.style.display = 'none';
                            deptSchoolTableBtn.style.background = '#667eea';
                            deptSchoolTableBtn.style.color = '#fff';
                            deptSchoolChartBtn.style.background = '#fff';
                            deptSchoolChartBtn.style.color = '#667eea';
                        });
                    }
                }, 100);
            })
            .catch(error => {
                console.error('載入科系統計資料失敗:', error);
                container.innerHTML = `
                    <div style="background: white; border-radius: 10px; padding: 16px 18px; border: 1px solid #ffe3e3;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <h5 style="margin: 0; color: #c53030;">
                                <i class="fas fa-exclamation-triangle"></i> ${departmentName} - 招生詳情
                            </h5>
                            <button type="button"
                                    onclick="document.getElementById('departmentDetailContainer').innerHTML='';"
                                    style="background: #fff1f0; color: #c53030; border: 1px solid #ffa39e; padding: 2px 10px; border-radius: 14px; font-size: 12px; cursor: pointer;">
                                收合
                            </button>
                        </div>
                        <p style="margin: 0; color: #c53030; font-size: 13px;">載入科系統計資料失敗，請稍後再試</p>
                    </div>
                `;
            });
    }
    
// 全域函式：切換科系詳情的 Tab
window.switchDeptTab = function(btn, targetId) {
    // 1. 移除所有按鈕的 active 狀態
    const tabsContainer = btn.parentElement;
    const buttons = tabsContainer.querySelectorAll('.dept-tab-btn');
    buttons.forEach(b => b.classList.remove('active'));
    
    // 2. 設定當前按鈕為 active
    btn.classList.add('active');
    
    // 3. 隱藏所有內容區塊
    // 這裡我們假設 tab content 是按鈕容器的兄弟元素的兄弟元素...
    // 或者更安全的方式：在該 detailContainer 內查找
    const detailContainer = document.getElementById('departmentDetailContainer');
    const contents = detailContainer.querySelectorAll('.dept-tab-content');
    contents.forEach(c => c.classList.remove('active'));
    
    // 4. 顯示目標區塊
    const target = document.getElementById(targetId);
    if (target) {
        target.classList.add('active');
    }
};
// 顯示來源詳情 Modal
window.showSourceDetail = function(schoolNameEnc, sourceDataEnc) {
    const schoolName = decodeURIComponent(schoolNameEnc);
    const sources = JSON.parse(decodeURIComponent(sourceDataEnc));
    
    // 設定標題
    document.getElementById('modalSchoolName').innerText = schoolName;
    
    let html = '';
    if (sources && sources.length > 0) {
        html += '<ul style="list-style: none; padding: 0; margin: 0;">';
        sources.forEach((src, index) => {
            // 判斷是否為最後一項 (最後一項不要底線)
            const borderStyle = (index === sources.length - 1) ? '' : 'border-bottom: 1px solid #f0f0f0;';
            
            html += `
            <li style="padding: 16px 8px; ${borderStyle} display: flex; justify-content: space-between; align-items: center;">
                <span style="font-size: 18px; color: #333; font-weight: 500;">
                    ${src.name}
                </span>
                
                <span style="font-size: 20px; font-weight: bold; color: #333;">
                    ${src.count} <span style="font-size: 14px; font-weight: normal; color: #888;">人</span>
                </span>
            </li>`;
        });
        html += '</ul>';
    } else {
        html = '<div style="text-align: center; color: #999; padding: 40px; font-size: 18px;">無來源紀錄</div>';
    }
    
    document.getElementById('modalContent').innerHTML = html;
    
    // 顯示 Modal
    document.getElementById('sourceDetailModal').style.display = 'flex';
};

// 點擊 Modal 背景關閉
document.getElementById('sourceDetailModal').addEventListener('click', function(e) {
    if (e.target === this) {
        this.style.display = 'none';
    }
});

    // 生成模擬學生資料
    function generateMockStudents(departmentName) {
        const studentCounts = {
            '嬰幼兒保育科': 5,
            '護理科': 4,
            '無特定': 1,
            '資訊管理科': 1,
            '應用外語科': 1,
            '視光科': 1,
            '企業管理科': 1
        };
        
        const count = studentCounts[departmentName] || 1;
        const students = [];
        
        const names = ['張小明', '李美華', '王大雄', '陳小芳', '林志強', '黃淑芬', '劉建國', '吳雅婷'];
        const schools = ['台北市立第一中學', '新北市立第二中學', '桃園市立第三中學', '台中市立第四中學', '台南市立第五中學'];
        const grades = ['一年級', '二年級', '三年級'];
        
        for (let i = 0; i < count; i++) {
            students.push({
                name: names[i] || `學生${i + 1}`,
                school: schools[i % schools.length],
                grade: grades[i % grades.length],
                created_at: new Date(Date.now() - Math.random() * 30 * 24 * 60 * 60 * 1000).toLocaleDateString('zh-TW')
            });
        }
        
        return students;
    }
    
    // 顯示學生模態視窗
    function showStudentModal(content) {
        // 創建模態視窗背景
        const modal = document.createElement('div');
        modal.id = 'studentModal';
        modal.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 10000;
        `;
        
        modal.innerHTML = content;
        document.body.appendChild(modal);
        
        // 點擊背景關閉模態視窗
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeStudentModal();
            }
        });
    }
    
    // 關閉學生模態視窗
    function closeStudentModal() {
        const modal = document.getElementById('studentModal');
        if (modal) {
            modal.remove();
        }
    }
    
    // 續招報名統計 - 性別分布分析
    function showContinuedAdmissionGenderStats() {
        console.log('showContinuedAdmissionGenderStats 被調用');
        
        fetch(buildApiUrl('../../Topics-frontend/frontend/api/continued_admission_stats_api.php', 'gender'))
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    document.getElementById('continuedAdmissionAnalyticsContent').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #dc3545;">
                            <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                            <h4>數據載入失敗</h4>
                            <p>${data.error}</p>
                        </div>
                    `;
                    return;
                }
                
                const content = `
                    <div style="margin-bottom: 20px;">
                        <h4 style="color: #667eea; margin-bottom: 15px;">
                            <i class="fas fa-venus-mars"></i> 性別分布分析
                        </h4>
                        
                        <div class="chart-card">
                            <div class="chart-title">性別分布統計</div>
                            <div class="chart-container">
                                <canvas id="continuedAdmissionGenderChart"></canvas>
                            </div>
                        </div>
                        
                        <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                            <h5 style="color: #333; margin-bottom: 15px;">性別詳細統計</h5>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                                ${data.map((item, index) => {
                                    const colors = ['#667eea', '#e91e63'];
                                    const color = colors[index % colors.length];
                                    const total = data.reduce((sum, d) => sum + d.value, 0);
                                    const percentage = ((item.value / total) * 100).toFixed(1);
                                    return `
                                        <div style="background: white; padding: 15px; border-radius: 8px; border-left: 4px solid ${color};">
                                            <div style="font-weight: bold; color: #333; margin-bottom: 5px;">${item.name}</div>
                                            <div style="font-size: 1.5em; font-weight: bold; color: ${color};">${item.value}人</div>
                                            <div style="font-size: 0.9em; color: #666;">${percentage}%</div>
                                        </div>
                                    `;
                                }).join('')}
                            </div>
                        </div>
                    </div>
                `;
                
                document.getElementById('continuedAdmissionAnalyticsContent').innerHTML = content;
                
                // 創建圓餅圖
                setTimeout(() => {
                    const canvasElement = document.getElementById('continuedAdmissionGenderChart');
                    if (!canvasElement) return;
                    
                    const ctx = canvasElement.getContext('2d');
                    new Chart(ctx, {
                        type: 'pie',
                        data: {
                            labels: data.map(item => item.name),
                            datasets: [{
                                data: data.map(item => item.value),
                                backgroundColor: ['#667eea', '#e91e63'],
                                borderWidth: 2,
                                borderColor: '#fff'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: {
                                        padding: 20,
                                        usePointStyle: true,
                                        font: { size: 16 }
                                    }
                                },
                                tooltip: {
                                    enabled: true,
                                    callbacks: {
                                        label: function(context) {
                                            const label = context.label || '';
                                            const value = context.parsed;
                                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                            const percentage = ((value / total) * 100).toFixed(1);
                                            return `${label}: ${value}人 (${percentage}%)`;
                                        }
                                    }
                                }
                            }
                        }
                    });
                }, 100);
            })
            .catch(error => {
                console.error('載入性別統計數據失敗:', error);
                document.getElementById('continuedAdmissionAnalyticsContent').innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #dc3545;">
                        <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                        <h4>數據載入失敗</h4>
                        <p>無法連接到統計API</p>
                    </div>
                `;
            });
    }
    
    // 續招報名統計 - 縣市分布分析
    function showContinuedAdmissionCityStats() {
        console.log('showContinuedAdmissionCityStats 被調用');
        
        fetch(buildApiUrl('../../Topics-frontend/frontend/api/continued_admission_stats_api.php', 'school_city'))
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    document.getElementById('continuedAdmissionAnalyticsContent').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #dc3545;">
                            <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                            <h4>數據載入失敗</h4>
                            <p>${data.error}</p>
                        </div>
                    `;
                    return;
                }
                
                const content = `
                    <div style="margin-bottom: 20px;">
                        <h4 style="color: #667eea; margin-bottom: 15px;">
                            <i class="fas fa-map-marker-alt"></i> 縣市分布分析
                        </h4>
                        
                        <div class="chart-card">
                            <div class="chart-title">就讀縣市分布</div>
                            <div class="chart-container">
                                <canvas id="continuedAdmissionCityChart"></canvas>
                            </div>
                        </div>
                        
                        <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                            <h5 style="color: #333; margin-bottom: 15px;">縣市詳細統計</h5>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                                ${data.map((item, index) => {
                                    const colors = ['#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8'];
                                    const color = colors[index % colors.length];
                                    const total = data.reduce((sum, d) => sum + d.value, 0);
                                    const percentage = ((item.value / total) * 100).toFixed(1);
                                    return `
                                        <div style="background: white; padding: 15px; border-radius: 8px; border-left: 4px solid ${color};">
                                            <div style="font-weight: bold; color: #333; margin-bottom: 5px;">${item.name}</div>
                                            <div style="font-size: 1.5em; font-weight: bold; color: ${color};">${item.value}人</div>
                                            <div style="font-size: 0.9em; color: #666;">${percentage}%</div>
                                        </div>
                                    `;
                                }).join('')}
                            </div>
                        </div>
                    </div>
                `;
                
                document.getElementById('continuedAdmissionAnalyticsContent').innerHTML = content;
                
                // 創建長條圖
                setTimeout(() => {
                    const canvasElement = document.getElementById('continuedAdmissionCityChart');
                    if (!canvasElement) return;
                    
                    const ctx = canvasElement.getContext('2d');
                    new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: data.map(item => item.name),
                            datasets: [{
                                label: '報名人數',
                                data: data.map(item => item.value),
                                backgroundColor: '#667eea',
                                borderColor: '#5a6fd8',
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        stepSize: 1
                                    }
                                }
                            }
                        }
                    });
                }, 100);
            })
            .catch(error => {
                console.error('載入縣市統計數據失敗:', error);
                document.getElementById('continuedAdmissionAnalyticsContent').innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #dc3545;">
                        <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                        <h4>數據載入失敗</h4>
                        <p>無法連接到統計API</p>
                    </div>
                `;
            });
    }
    
// 續招報名統計 - 志願選擇分析
function showContinuedAdmissionChoicesStats() {
    console.log('showContinuedAdmissionChoicesStats 被調用');
    
    // 我們同時呼叫兩個 API：
    // 1. choices: 取得科系分布 (含所有志願，用於圖表)
    // 2. overview: 取得總覽數據 (含正確的總報名人數)
    Promise.all([
        fetch(buildApiUrl('../../Topics-frontend/frontend/api/continued_admission_stats_api.php', 'choices')).then(res => res.json()),
        fetch(buildApiUrl('../../Topics-frontend/frontend/api/continued_admission_stats_api.php', 'overview')).then(res => res.json())
    ])
    .then(([data, overviewData]) => {
        // 錯誤處理
        if (data.error) {
            document.getElementById('continuedAdmissionAnalyticsContent').innerHTML = `
                <div style="text-align: center; padding: 40px; color: #dc3545;">
                    <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                    <h4>數據載入失敗</h4>
                    <p>${data.error}</p>
                </div>
            `;
            return;
        }
        
        // 【修正重點】使用 overview API 回傳的真實學生人數，而不是將科系加總
        const realTotalStudents = overviewData.total_applications || 0;
        
        // 用於計算百分比的分母 (看您希望百分比是 "佔總志願數" 還是 "佔總人數")
        // 這裡維持 "佔總志願數" 以配合圓餅圖的邏輯 (總和 100%)
        const totalChoicesCount = data.reduce((sum, d) => sum + d.value, 0);

        const content = `
            <div style="margin-bottom: 20px;">
                <h4 style="color: #667eea; margin-bottom: 15px;">
                    <i class="fas fa-list-ol"></i> 志願選擇分析
                </h4>
                
                <div class="chart-card">
                    <div class="chart-title">科系志願選擇分布</div>
                    <div class="chart-container">
                        <canvas id="continuedAdmissionChoicesChart"></canvas>
                    </div>
                </div>
                
                <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                    <h5 style="color: #333; margin-bottom: 15px;">科系詳細統計</h5>
                    
                    <div style="background: white; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #667eea;">
                        <div style="font-weight: bold; color: #333; margin-bottom: 5px;">總報名人數 (實際學生數)</div>
                        <div style="font-size: 1.5em; font-weight: bold; color: #667eea;">${realTotalStudents}人</div>
                    </div>
                    
                    <div style="background: white; border-radius: 8px; overflow: hidden;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background: #f8f9fa;">
                                    <th style="padding: 15px; text-align: left; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">科系名稱</th>
                                    <th style="padding: 15px; text-align: center; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">選擇人數</th>
                                    <th style="padding: 15px; text-align: center; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">志願佔比</th>
                                    <th style="padding: 15px; text-align: center; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${data.map((item, index) => {
                                    const colors = ['#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8', '#6f42c1', '#fd7e14', '#20c997', '#e83e8c', '#6c757d'];
                                    const color = colors[index % colors.length];
                                    // 計算百分比 (這裡維持以總志願數為分母，若要改以人數為分母請改用 realTotalStudents)
                                    const percentage = totalChoicesCount > 0 ? ((item.value / totalChoicesCount) * 100).toFixed(1) : 0;
                                    return `
                                        <tr style="border-bottom: 1px solid #dee2e6;">
                                            <td style="padding: 15px; font-weight: 500; color: #333;">${item.name}</td>
                                            <td style="padding: 15px; text-align: center; font-weight: bold; color: #333;">${item.value}人</td>
                                            <td style="padding: 15px; text-align: center; color: #666;">${percentage}%</td>
                                            <td style="padding: 15px; text-align: center;">
                                                <button onclick="showContinuedAdmissionDepartmentStudents('${item.name}')"
                                                        style="background: ${color}; color: white; border: none; padding: 8px 16px; border-radius: 20px; cursor: pointer; font-size: 14px; transition: all 0.3s ease;">
                                                    查看詳情
                                                </button>
                                            </td>
                                        </tr>
                                    `;
                                }).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        `;
        
        document.getElementById('continuedAdmissionAnalyticsContent').innerHTML = content;
        
        // 創建圓餅圖
        setTimeout(() => {
            const canvasElement = document.getElementById('continuedAdmissionChoicesChart');
            if (!canvasElement) return;
            
            const ctx = canvasElement.getContext('2d');
            new Chart(ctx, {
                type: 'pie',
                data: {
                    labels: data.map(item => item.name),
                    datasets: [{
                        data: data.map(item => item.value),
                        backgroundColor: [
                            '#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8'
                        ],
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 20,
                                usePointStyle: true,
                                font: { size: 16 }
                            }
                        },
                        tooltip: {
                            enabled: true,
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.parsed;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = ((value / total) * 100).toFixed(1);
                                    return `${label}: ${value}次 (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
        }, 100);
    })
    .catch(error => {
        console.error('載入志願統計數據失敗:', error);
        document.getElementById('continuedAdmissionAnalyticsContent').innerHTML = `
            <div style="text-align: center; padding: 40px; color: #dc3545;">
                <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                <h4>數據載入失敗</h4>
                <p>無法連接到統計API</p>
            </div>
        `;
    });
}
    
    // 續招報名統計 - 月度趨勢分析
    function showContinuedAdmissionMonthlyStats() {
        console.log('showContinuedAdmissionMonthlyStats 被調用');
        
        fetch(buildApiUrl('../../Topics-frontend/frontend/api/continued_admission_stats_api.php', 'monthly'))
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    document.getElementById('continuedAdmissionAnalyticsContent').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #dc3545;">
                            <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                            <h4>數據載入失敗</h4>
                            <p>${data.error}</p>
                        </div>
                    `;
                    return;
                }
                
                const content = `
                    <div style="margin-bottom: 20px;">
                        <h4 style="color: #667eea; margin-bottom: 15px;">
                            <i class="fas fa-calendar-alt"></i> 月度趨勢分析
                        </h4>
                        
                        <div class="chart-card">
                            <div class="chart-title">月度報名趨勢</div>
                            <div class="chart-container">
                                <canvas id="continuedAdmissionMonthlyChart"></canvas>
                            </div>
                        </div>
                        
                        <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                            <h5 style="color: #333; margin-bottom: 15px;">月度詳細統計</h5>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                                ${data.map((item, index) => {
                                    const colors = ['#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8'];
                                    const color = colors[index % colors.length];
                                    const total = data.reduce((sum, d) => sum + d.value, 0);
                                    const percentage = ((item.value / total) * 100).toFixed(1);
                                    return `
                                        <div style="background: white; padding: 15px; border-radius: 8px; border-left: 4px solid ${color};">
                                            <div style="font-weight: bold; color: #333; margin-bottom: 5px;">${item.name}</div>
                                            <div style="font-size: 1.5em; font-weight: bold; color: ${color};">${item.value}人</div>
                                            <div style="font-size: 0.9em; color: #666;">${percentage}%</div>
                                        </div>
                                    `;
                                }).join('')}
                            </div>
                        </div>
                    </div>
                `;
                
                document.getElementById('continuedAdmissionAnalyticsContent').innerHTML = content;
                
                // 創建線圖
                setTimeout(() => {
                    const canvasElement = document.getElementById('continuedAdmissionMonthlyChart');
                    if (!canvasElement) return;
                    
                    const ctx = canvasElement.getContext('2d');
                    new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: data.map(item => item.name),
                            datasets: [{
                                label: '報名人數',
                                data: data.map(item => item.value),
                                borderColor: '#667eea',
                                backgroundColor: 'rgba(102, 126, 234, 0.1)',
                                borderWidth: 3,
                                fill: true,
                                tension: 0.4,
                                pointBackgroundColor: '#667eea',
                                pointBorderColor: '#fff',
                                pointBorderWidth: 2,
                                pointRadius: 6
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        stepSize: 1
                                    }
                                }
                            }
                        }
                    });
                }, 100);
            })
            .catch(error => {
                console.error('載入月度統計數據失敗:', error);
                document.getElementById('continuedAdmissionAnalyticsContent').innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #dc3545;">
                        <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                        <h4>數據載入失敗</h4>
                        <p>無法連接到統計API</p>
                    </div>
                `;
            });
    }
    
    // 續招報名統計 - 審核狀態分析
    function showContinuedAdmissionStatusStats() {
        console.log('showContinuedAdmissionStatusStats 被調用');
        
        fetch(buildApiUrl('../../Topics-frontend/frontend/api/continued_admission_stats_api.php', 'status'))
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    document.getElementById('continuedAdmissionAnalyticsContent').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #dc3545;">
                            <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                            <h4>數據載入失敗</h4>
                            <p>${data.error}</p>
                        </div>
                    `;
                    return;
                }
                
                const content = `
                    <div style="margin-bottom: 20px;">
                        <h4 style="color: #667eea; margin-bottom: 15px;">
                            <i class="fas fa-clipboard-check"></i> 審核狀態分析
                        </h4>
                        
                        <div class="chart-card">
                            <div class="chart-title">審核狀態分布</div>
                            <div class="chart-container">
                                <canvas id="continuedAdmissionStatusChart"></canvas>
                            </div>
                        </div>
                        
                        <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                            <h5 style="color: #333; margin-bottom: 15px;">狀態詳細統計</h5>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                                ${data.map((item, index) => {
                                    const colors = ['#ffc107', '#28a745', '#dc3545'];
                                    const color = colors[index % colors.length];
                                    const total = data.reduce((sum, d) => sum + d.value, 0);
                                    const percentage = ((item.value / total) * 100).toFixed(1);
                                    return `
                                        <div style="background: white; padding: 15px; border-radius: 8px; border-left: 4px solid ${color};">
                                            <div style="font-weight: bold; color: #333; margin-bottom: 5px;">${item.name}</div>
                                            <div style="font-size: 1.5em; font-weight: bold; color: ${color};">${item.value}人</div>
                                            <div style="font-size: 0.9em; color: #666;">${percentage}%</div>
                                        </div>
                                    `;
                                }).join('')}
                            </div>
                        </div>
                    </div>
                `;
                
                document.getElementById('continuedAdmissionAnalyticsContent').innerHTML = content;
                
                // 創建圓餅圖
                setTimeout(() => {
                    const canvasElement = document.getElementById('continuedAdmissionStatusChart');
                    if (!canvasElement) return;
                    
                    const ctx = canvasElement.getContext('2d');
                    new Chart(ctx, {
                        type: 'doughnut',
                        data: {
                            labels: data.map(item => item.name),
                            datasets: [{
                                data: data.map(item => item.value),
                                backgroundColor: ['#ffc107', '#28a745', '#dc3545'],
                                borderWidth: 2,
                                borderColor: '#fff'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: {
                                        padding: 20,
                                        usePointStyle: true,
                                        font: { size: 16 }
                                    }
                                },
                                tooltip: {
                                    enabled: true,
                                    callbacks: {
                                        label: function(context) {
                                            const label = context.label || '';
                                            const value = context.parsed;
                                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                            const percentage = ((value / total) * 100).toFixed(1);
                                            return `${label}: ${value}人 (${percentage}%)`;
                                        }
                                    }
                                }
                            }
                        }
                    });
                }, 100);
            })
            .catch(error => {
                console.error('載入狀態統計數據失敗:', error);
                document.getElementById('continuedAdmissionAnalyticsContent').innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #dc3545;">
                        <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                        <h4>數據載入失敗</h4>
                        <p>無法連接到統計API</p>
                    </div>
                `;
            });
    }

    // 續招報名統計 - 科系名額與錄取狀態分析
    function showContinuedAdmissionQuotaStats() {
        console.log('showContinuedAdmissionQuotaStats 被調用');

        fetch(buildApiUrl('../../Topics-frontend/frontend/api/continued_admission_stats_api.php', 'department_quota_status'))
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    document.getElementById('continuedAdmissionAnalyticsContent').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #dc3545;">
                            <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                            <h4>數據載入失敗</h4>
                            <p>${data.error}</p>
                        </div>
                    `;
                    return;
                }

                if (!Array.isArray(data) || data.length === 0) {
                    document.getElementById('continuedAdmissionAnalyticsContent').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #666;">
                            <i class="fas fa-info-circle fa-3x" style="margin-bottom: 16px;"></i>
                            <h4>目前沒有科系名額資料</h4>
                            <p>請先在名額管理中設定各科系錄取名額</p>
                        </div>
                    `;
                    return;
                }

                const content = `
                    <div style="margin-bottom: 20px;">
                        <h4 style="color: #667eea; margin-bottom: 15px;">
                            <i class="fas fa-chart-bar"></i> 錄取名額與錄取狀態分析
                        </h4>

                        <div class="chart-card">
                            <div class="chart-title">各科系名額與錄取結果</div>
                            <div class="chart-container">
                                <canvas id="continuedAdmissionQuotaChart"></canvas>
                            </div>
                        </div>

                        <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                            <h5 style="color: #333; margin-bottom: 15px;">科系詳細統計</h5>
                            <div style="background: white; border-radius: 8px; overflow: hidden;">
                                <table style="width: 100%; border-collapse: collapse;">
                                    <thead>
                                        <tr style="background: #f8f9fa;">
                                            <th style="padding: 12px; text-align: left; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">科系</th>
                                            <th style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">錄取名額</th>
                                            <th style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">正取</th>
                                            <th style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">備取</th>
                                            <th style="padding: 12px; text-align: center; border-bottom: 1px solid #dee2e6; font-weight: 600; color: #495057;">不錄取</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${data.map(item => `
                                            <tr style="border-bottom: 1px solid #dee2e6;">
                                                <td style="padding: 12px; font-weight: 500; color: #333;">${item.department_name}</td>
                                                <td style="padding: 12px; text-align: center; font-weight: bold; color: #667eea;">${item.total_quota}</td>
                                                <td style="padding: 12px; text-align: center; color: #28a745; font-weight: 600;">${item.approved_count}</td>
                                                <td style="padding: 12px; text-align: center; color: #17a2b8; font-weight: 600;">${item.waitlist_count}</td>
                                                <td style="padding: 12px; text-align: center; color: #dc3545; font-weight: 600;">${item.rejected_count}</td>
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                `;

                document.getElementById('continuedAdmissionAnalyticsContent').innerHTML = content;

                setTimeout(() => {
                    const canvasElement = document.getElementById('continuedAdmissionQuotaChart');
                    if (!canvasElement) return;

                    const ctx = canvasElement.getContext('2d');
                    new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: data.map(item => item.department_name),
                            datasets: [
                                {
                                    label: '錄取名額',
                                    data: data.map(item => item.total_quota),
                                    backgroundColor: 'rgba(102, 126, 234, 0.35)',
                                    borderColor: '#667eea',
                                    borderWidth: 1
                                },
                                {
                                    label: '正取',
                                    data: data.map(item => item.approved_count),
                                    backgroundColor: '#28a745'
                                },
                                {
                                    label: '備取',
                                    data: data.map(item => item.waitlist_count),
                                    backgroundColor: '#17a2b8'
                                },
                                {
                                    label: '不錄取',
                                    data: data.map(item => item.rejected_count),
                                    backgroundColor: '#dc3545'
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom'
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        precision: 0
                                    }
                                }
                            }
                        }
                    });
                }, 100);
            })
            .catch(error => {
                console.error('載入科系名額統計數據失敗:', error);
                document.getElementById('continuedAdmissionAnalyticsContent').innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #dc3545;">
                        <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                        <h4>數據載入失敗</h4>
                        <p>無法連接到統計API</p>
                    </div>
                `;
            });
    }
    
    function clearContinuedAdmissionCharts() {
        console.log('clearContinuedAdmissionCharts 被調用');
        
        // 清除所有續招報名相關的Chart.js實例，但保留志願選擇分析
        Chart.helpers.each(Chart.instances, function(instance) {
            if (instance.canvas.id.includes('continuedAdmissionGenderChart') || 
                instance.canvas.id.includes('continuedAdmissionCityChart') ||
                instance.canvas.id.includes('continuedAdmissionMonthlyChart') ||
                instance.canvas.id.includes('continuedAdmissionStatusChart') ||
                instance.canvas.id.includes('continuedAdmissionQuotaChart')) {
                instance.destroy();
            }
        });
        
        // 重新顯示志願選擇分析，確保它始終顯示
        showContinuedAdmissionChoicesStats();
    }
    
    // 五專入學說明會統計 - 年級分布分析
    function showAdmissionGradeStats() {
        console.log('showAdmissionGradeStats 被調用');
        
        fetch(buildApiUrl('../../Topics-frontend/frontend/api/admission_stats_api.php', 'grade'))
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    document.getElementById('admissionAnalyticsContent').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #dc3545;">
                            <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                            <h4>數據載入失敗</h4>
                            <p>${data.error}</p>
                        </div>
                    `;
                    return;
                }
                
                const content = `
                    <div style="margin-bottom: 20px;">
                        <h4 style="color: #667eea; margin-bottom: 15px;">
                            <i class="fas fa-users"></i> 年級分布分析
                        </h4>
                        
                        <div class="chart-card">
                            <div class="chart-title">年級分布統計</div>
                            <div class="chart-container">
                                <canvas id="admissionGradeChart"></canvas>
                            </div>
                        </div>
                        
                        <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                            <h5 style="color: #333; margin-bottom: 15px;">年級詳細統計</h5>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                                ${data.map((item, index) => {
                                    const colors = ['#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8'];
                                    const color = colors[index % colors.length];
                                    const total = data.reduce((sum, d) => sum + d.value, 0);
                                    const percentage = ((item.value / total) * 100).toFixed(1);
                                    return `
                                        <div style="background: white; padding: 15px; border-radius: 8px; border-left: 4px solid ${color};">
                                            <div style="font-weight: bold; color: #333; margin-bottom: 5px;">${item.name}</div>
                                            <div style="font-size: 1.5em; font-weight: bold; color: ${color};">${item.value}人</div>
                                            <div style="font-size: 0.9em; color: #666;">${percentage}%</div>
                                        </div>
                                    `;
                                }).join('')}
                            </div>
                        </div>
                    </div>
                `;
                
                document.getElementById('admissionAnalyticsContent').innerHTML = content;
                
                // 創建長條圖
                setTimeout(() => {
                    const canvasElement = document.getElementById('admissionGradeChart');
                    if (!canvasElement) return;
                    
                    const ctx = canvasElement.getContext('2d');
                    new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: data.map(item => item.name),
                            datasets: [{
                                label: '報名人數',
                                data: data.map(item => item.value),
                                backgroundColor: '#667eea',
                                borderColor: '#5a6fd8',
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        stepSize: 1
                                    }
                                }
                            }
                        }
                    });
                }, 100);
            })
            .catch(error => {
                console.error('載入年級統計數據失敗:', error);
                document.getElementById('admissionAnalyticsContent').innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #dc3545;">
                        <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                        <h4>數據載入失敗</h4>
                        <p>無法連接到統計API</p>
                    </div>
                `;
            });
    }
    
    // 五專入學說明會統計 - 學校分布分析
    function showAdmissionSchoolStats() {
        console.log('showAdmissionSchoolStats 被調用');
        
        fetch(buildApiUrl('../../Topics-frontend/frontend/api/admission_stats_api.php', 'school'))
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    document.getElementById('admissionAnalyticsContent').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #dc3545;">
                            <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                            <h4>數據載入失敗</h4>
                            <p>${data.error}</p>
                        </div>
                    `;
                    return;
                }
                
                const content = `
                    <div style="margin-bottom: 20px;">
                        <h4 style="color: #667eea; margin-bottom: 15px;">
                            <i class="fas fa-school"></i> 學校分布分析
                        </h4>
                        
                        <div class="chart-card">
                            <div class="chart-title">學校報名人數分布</div>
                            <div class="chart-container">
                                <canvas id="admissionSchoolChart"></canvas>
                            </div>
                        </div>
                        
                        <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                            <h5 style="color: #333; margin-bottom: 15px;">學校詳細統計</h5>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                                ${data.map((item, index) => {
                                    const colors = ['#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8'];
                                    const color = colors[index % colors.length];
                                    const total = data.reduce((sum, d) => sum + d.value, 0);
                                    const percentage = ((item.value / total) * 100).toFixed(1);
                                    return `
                                        <div style="background: white; padding: 15px; border-radius: 8px; border-left: 4px solid ${color};">
                                            <div style="font-weight: bold; color: #333; margin-bottom: 5px;">${item.name}</div>
                                            <div style="font-size: 1.5em; font-weight: bold; color: ${color};">${item.value}人</div>
                                            <div style="font-size: 0.9em; color: #666;">${percentage}%</div>
                                        </div>
                                    `;
                                }).join('')}
                            </div>
                        </div>
                    </div>
                `;
                
                document.getElementById('admissionAnalyticsContent').innerHTML = content;
                
                // 創建長條圖
                setTimeout(() => {
                    const canvasElement = document.getElementById('admissionSchoolChart');
                    if (!canvasElement) return;
                    
                    const ctx = canvasElement.getContext('2d');
                    new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: data.map(item => item.name),
                            datasets: [{
                                label: '報名人數',
                                data: data.map(item => item.value),
                                backgroundColor: '#667eea',
                                borderColor: '#5a6fd8',
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        stepSize: 1
                                    }
                                }
                            }
                        }
                    });
                }, 100);
            })
            .catch(error => {
                console.error('載入學校統計數據失敗:', error);
                document.getElementById('admissionAnalyticsContent').innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #dc3545;">
                        <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                        <h4>數據載入失敗</h4>
                        <p>無法連接到統計API</p>
                    </div>
                `;
            });
    }
    
    // 五專入學說明會統計 - 場次分布分析
    function showAdmissionSessionStats() {
        console.log('showAdmissionSessionStats 被調用');
        
        fetch(buildApiUrl('../../Topics-frontend/frontend/api/admission_stats_api.php', 'session'))
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    document.getElementById('admissionAnalyticsContent').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #dc3545;">
                            <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                            <h4>數據載入失敗</h4>
                            <p>${data.error}</p>
                        </div>
                    `;
                    return;
                }
                
                const content = `
                    <div style="margin-bottom: 20px;">
                        <h4 style="color: #667eea; margin-bottom: 15px;">
                            <i class="fas fa-calendar-alt"></i> 場次分布分析
                        </h4>
                        
                        <div class="chart-card">
                            <div class="chart-title">場次報名人數分布</div>
                            <div class="chart-container">
                                <canvas id="admissionSessionChart"></canvas>
                            </div>
                        </div>
                        
                        <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                            <h5 style="color: #333; margin-bottom: 15px;">場次詳細統計</h5>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                                ${data.map((item, index) => {
                                    const colors = ['#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8'];
                                    const color = colors[index % colors.length];
                                    const total = data.reduce((sum, d) => sum + d.value, 0);
                                    const percentage = ((item.value / total) * 100).toFixed(1);
                                    return `
                                        <div style="background: white; padding: 15px; border-radius: 8px; border-left: 4px solid ${color};">
                                            <div style="font-weight: bold; color: #333; margin-bottom: 5px;">${item.name}</div>
                                            <div style="font-size: 1.5em; font-weight: bold; color: ${color};">${item.value}人</div>
                                            <div style="font-size: 0.9em; color: #666;">${percentage}%</div>
                                        </div>
                                    `;
                                }).join('')}
                            </div>
                        </div>
                    </div>
                `;
                
                document.getElementById('admissionAnalyticsContent').innerHTML = content;
                
                // 創建圓餅圖
                setTimeout(() => {
                    const canvasElement = document.getElementById('admissionSessionChart');
                    if (!canvasElement) return;
                    
                    const ctx = canvasElement.getContext('2d');
                    new Chart(ctx, {
                        type: 'pie',
                        data: {
                            labels: data.map(item => item.name),
                            datasets: [{
                                data: data.map(item => item.value),
                                backgroundColor: [
                                    '#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8'
                                ],
                                borderWidth: 2,
                                borderColor: '#fff'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: {
                                        padding: 20,
                                        usePointStyle: true,
                                        font: { size: 16 }
                                    }
                                },
                                tooltip: {
                                    enabled: true,
                                    callbacks: {
                                        label: function(context) {
                                            const label = context.label || '';
                                            const value = context.parsed;
                                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                            const percentage = ((value / total) * 100).toFixed(1);
                                            return `${label}: ${value}人 (${percentage}%)`;
                                        }
                                    }
                                }
                            }
                        }
                    });
                }, 100);
            })
            .catch(error => {
                console.error('載入場次統計數據失敗:', error);
                document.getElementById('admissionAnalyticsContent').innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #dc3545;">
                        <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                        <h4>數據載入失敗</h4>
                        <p>無法連接到統計API</p>
                    </div>
                `;
            });
    }
    
    // 五專入學說明會統計 - 課程選擇分析
    function showAdmissionCourseStats() {
        console.log('showAdmissionCourseStats 被調用');
        
        fetch(buildApiUrl('../../Topics-frontend/frontend/api/admission_stats_api.php', 'course'))
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    document.getElementById('admissionAnalyticsContent').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #dc3545;">
                            <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                            <h4>數據載入失敗</h4>
                            <p>${data.error}</p>
                        </div>
                    `;
                    return;
                }
                
                const content = `
                    <div style="margin-bottom: 20px;">
                        <h4 style="color: #667eea; margin-bottom: 15px;">
                            <i class="fas fa-book-open"></i> 課程選擇分析
                        </h4>
                        
                        <div class="chart-card">
                            <div class="chart-title">體驗課程選擇分布</div>
                            <div class="chart-container">
                                <canvas id="admissionCourseChart"></canvas>
                            </div>
                        </div>
                        
                        <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                            <h5 style="color: #333; margin-bottom: 15px;">課程詳細統計</h5>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
                                ${data.map((item, index) => {
                                    const colors = ['#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8'];
                                    const color = colors[index % colors.length];
                                    const total = data.reduce((sum, d) => sum + d.value, 0);
                                    const percentage = ((item.value / total) * 100).toFixed(1);
                                    return `
                                        <div style="background: white; padding: 15px; border-radius: 8px; border-left: 4px solid ${color};">
                                            <div style="font-weight: bold; color: #333; margin-bottom: 10px;">${item.name}</div>
                                            <div style="font-size: 1.5em; font-weight: bold; color: ${color}; margin-bottom: 5px;">${item.value}次</div>
                                            <div style="font-size: 0.9em; color: #666; margin-bottom: 8px;">${percentage}%</div>
                                            <div style="display: flex; justify-content: space-between; font-size: 0.8em;">
                                                <span style="color: #28a745;">第一選擇: ${item.first_choice || 0}</span>
                                                <span style="color: #ffc107;">第二選擇: ${item.second_choice || 0}</span>
                                            </div>
                                        </div>
                                    `;
                                }).join('')}
                            </div>
                        </div>
                    </div>
                `;
                
                document.getElementById('admissionAnalyticsContent').innerHTML = content;
                
                // 創建圓餅圖
                setTimeout(() => {
                    const canvasElement = document.getElementById('admissionCourseChart');
                    if (!canvasElement) return;
                    
                    const ctx = canvasElement.getContext('2d');
                    new Chart(ctx, {
                        type: 'pie',
                        data: {
                            labels: data.map(item => item.name),
                            datasets: [{
                                data: data.map(item => item.value),
                                backgroundColor: [
                                    '#667eea', '#28a745', '#ffc107', '#dc3545', '#17a2b8'
                                ],
                                borderWidth: 2,
                                borderColor: '#fff'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: {
                                        padding: 20,
                                        usePointStyle: true,
                                        font: { size: 16 }
                                    }
                                },
                                tooltip: {
                                    enabled: true,
                                    callbacks: {
                                        label: function(context) {
                                            const label = context.label || '';
                                            const value = context.parsed;
                                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                            const percentage = ((value / total) * 100).toFixed(1);
                                            return `${label}: ${value}次 (${percentage}%)`;
                                        }
                                    }
                                }
                            }
                        }
                    });
                }, 100);
            })
            .catch(error => {
                console.error('載入課程統計數據失敗:', error);
                document.getElementById('admissionAnalyticsContent').innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #dc3545;">
                        <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                        <h4>數據載入失敗</h4>
                        <p>無法連接到統計API</p>
                    </div>
                `;
            });
    }
    
    // 五專入學說明會統計 - 資訊接收分析
    function showAdmissionReceiveInfoStats() {
        console.log('showAdmissionReceiveInfoStats 被調用');
        
        fetch(buildApiUrl('../../Topics-frontend/frontend/api/admission_stats_api.php', 'receive_info'))
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    document.getElementById('admissionAnalyticsContent').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #dc3545;">
                            <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                            <h4>數據載入失敗</h4>
                            <p>${data.error}</p>
                        </div>
                    `;
                    return;
                }
                
                const content = `
                    <div style="margin-bottom: 20px;">
                        <h4 style="color: #667eea; margin-bottom: 15px;">
                            <i class="fas fa-envelope"></i> 資訊接收分析
                        </h4>
                        
                        <div class="chart-card">
                            <div class="chart-title">資訊接收意願分布</div>
                            <div class="chart-container">
                                <canvas id="admissionReceiveInfoChart"></canvas>
                            </div>
                        </div>
                        
                        <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin-top: 20px;">
                            <h5 style="color: #333; margin-bottom: 15px;">資訊接收詳細統計</h5>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                                ${data.map((item, index) => {
                                    const colors = ['#28a745', '#dc3545', '#6c757d'];
                                    const color = colors[index % colors.length];
                                    const total = data.reduce((sum, d) => sum + d.value, 0);
                                    const percentage = ((item.value / total) * 100).toFixed(1);
                                    return `
                                        <div style="background: white; padding: 15px; border-radius: 8px; border-left: 4px solid ${color};">
                                            <div style="font-weight: bold; color: #333; margin-bottom: 5px;">${item.name}</div>
                                            <div style="font-size: 1.5em; font-weight: bold; color: ${color};">${item.value}人</div>
                                            <div style="font-size: 0.9em; color: #666;">${percentage}%</div>
                                        </div>
                                    `;
                                }).join('')}
                            </div>
                        </div>
                    </div>
                `;
                
                document.getElementById('admissionAnalyticsContent').innerHTML = content;
                
                // 創建圓餅圖
                setTimeout(() => {
                    const canvasElement = document.getElementById('admissionReceiveInfoChart');
                    if (!canvasElement) return;
                    
                    const ctx = canvasElement.getContext('2d');
                    new Chart(ctx, {
                        type: 'doughnut',
                        data: {
                            labels: data.map(item => item.name),
                            datasets: [{
                                data: data.map(item => item.value),
                                backgroundColor: ['#28a745', '#dc3545', '#6c757d'],
                                borderWidth: 2,
                                borderColor: '#fff'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: {
                                        padding: 20,
                                        usePointStyle: true,
                                        font: { size: 16 }
                                    }
                                },
                                tooltip: {
                                    enabled: true,
                                    callbacks: {
                                        label: function(context) {
                                            const label = context.label || '';
                                            const value = context.parsed;
                                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                            const percentage = ((value / total) * 100).toFixed(1);
                                            return `${label}: ${value}人 (${percentage}%)`;
                                        }
                                    }
                                }
                            }
                        }
                    });
                }, 100);
            })
            .catch(error => {
                console.error('載入資訊接收統計數據失敗:', error);
                document.getElementById('admissionAnalyticsContent').innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #dc3545;">
                        <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px;"></i>
                        <h4>數據載入失敗</h4>
                        <p>無法連接到統計API</p>
                    </div>
                `;
            });
    }
    
    function clearAdmissionCharts() {
        console.log('clearAdmissionCharts 被調用');
        
        // 清除所有五專入學說明會相關的Chart.js實例
        Chart.helpers.each(Chart.instances, function(instance) {
            if (instance.canvas.id.includes('admissionGradeChart') || 
                instance.canvas.id.includes('admissionSchoolChart') ||
                instance.canvas.id.includes('admissionSessionChart') ||
                instance.canvas.id.includes('attendanceSessionChart') ||
                instance.canvas.id.includes('attendanceRateChart') ||
                instance.canvas.id.includes('admissionCourseChart') ||
                instance.canvas.id.includes('admissionMonthlyChart') ||
                instance.canvas.id.includes('admissionReceiveInfoChart')) {
                instance.destroy();
            }
        });
        
        // 清除全局圖表實例
        if (window.attendanceSessionChartInstance) {
            window.attendanceSessionChartInstance.destroy();
            window.attendanceSessionChartInstance = null;
        }
        if (window.attendanceRateChartInstance) {
            window.attendanceRateChartInstance.destroy();
            window.attendanceRateChartInstance = null;
        }
        
        // 清除原始數據
        window.attendanceStatsRawData = null;
        
        document.getElementById('admissionAnalyticsContent').innerHTML = `
            <div class="empty-state">
                <i class="fas fa-graduation-cap fa-3x" style="margin-bottom: 16px;"></i>
                <h4>選擇上方的統計類型來查看詳細分析</h4>
                <p>提供出席統計、年級分布、學校分布、場次分布、課程選擇、資訊接收等多維度統計</p>
            </div>
        `;
    }
    
    // ========== 其他必要函數 ==========
    
    // 搜尋功能
    document.addEventListener('DOMContentLoaded', function() {
        console.log('DOM已載入，開始初始化...');
        
        // 檢查是否有新生統計的篩選條件，如果有則自動顯示圖表
        const urlParams = new URLSearchParams(window.location.search);
        const newStudentView = urlParams.get('new_student_view');
        const rocYear = urlParams.get('roc_year');
        
        // 如果有篩選條件，自動顯示圖表
        if (newStudentView) {
            console.log('檢測到新生統計篩選條件，自動顯示圖表');
            setTimeout(() => {
                // 檢查最後點擊的圖表類型（從 sessionStorage 獲取）
                const lastChartType = sessionStorage.getItem('lastNewStudentChartType');
                if (lastChartType === 'schoolChart' && typeof showNewStudentSchoolChart === 'function') {
                    showNewStudentSchoolChart();
                } else if (lastChartType === 'departmentStats' && typeof showNewStudentDepartmentStats === 'function') {
                    showNewStudentDepartmentStats();
                } else if (lastChartType === 'schoolStats' && typeof showNewStudentSchoolStats === 'function') {
                    showNewStudentSchoolStats();
                } else {
                    // 默認顯示學校來源統計
                    if (typeof showNewStudentSchoolStats === 'function') {
                        showNewStudentSchoolStats();
                    }
                }
            }, 500);
        }
        
        // 如果是學校行政人員且在教師列表視圖，自動顯示科系招生總覽
        if (isSchoolAdmin && isTeacherListView) {
            console.log('學校行政人員登入，自動顯示科系招生總覽');
            setTimeout(() => {
                showDepartmentOverviewStats();
            }, 500);
        }
        
        // 載入就讀意願屆別選單選項
        loadEnrollmentRocYearOptions();
        // 自動顯示就讀意願統計的各科分配人數總覽
// 頁面載入後立即執行
document.addEventListener('DOMContentLoaded', function() {
    // 稍微延遲 50ms 確保 HTML 元素已渲染
    setTimeout(() => {
        // 1. 嘗試切換到第一個 Tab (會觸發顯示)
        const firstTab = document.querySelector('.dept-tabs .dept-tab-btn');
        if (firstTab) {
            firstTab.click();
        } else {
            // 2. 如果沒有 Tab，直接呼叫函式
            if (typeof showEnrollmentSystemStats === 'function') {
                showEnrollmentSystemStats();
            }
        }
    }, 50); 
});
        
        // 自動顯示續招報名統計的志願選擇分析
        setTimeout(() => {
            showContinuedAdmissionChoicesStats();
        }, 1500);
        
        // 初始化完成
        console.log('統計分析系統初始化完成');
        
        const searchInput = document.getElementById('searchInput');
        const teacherNameFilter = document.getElementById('teacherNameFilter');
        const departmentFilter = document.getElementById('departmentFilter');
        const table = document.getElementById('recordsTable');
        const rows = table ? table.getElementsByTagName('tbody')[0].getElementsByTagName('tr') : [];

        // 統一的篩選函數
        function applyFilters() {
            const searchValue = searchInput ? searchInput.value.toLowerCase() : '';
            const teacherNameValue = teacherNameFilter ? teacherNameFilter.value : '';
            const departmentValue = departmentFilter ? departmentFilter.value : '';
            let visibleCount = 0;

                for (let i = 0; i < rows.length; i++) {
                    const cells = rows[i].getElementsByTagName('td');
                let shouldShow = true;

                    if (isTeacherListView) {
                    const teacherName = cells[0] ? (cells[0].textContent || cells[0].innerText) : '';
                    const department = cells[1] ? (cells[1].textContent || cells[1].innerText) : '';
                    
                    // 搜尋框篩選（同時搜尋姓名和系所）
                    if (searchValue) {
                        const searchText = teacherName + department;
                        if (searchText.toLowerCase().indexOf(searchValue) === -1) {
                            shouldShow = false;
                        }
                    }
                    
                    // 教師姓名下拉選單篩選
                    if (teacherNameValue && teacherName !== teacherNameValue) {
                        shouldShow = false;
                    }
                    
                    // 科系下拉選單篩選
                    if (departmentValue && department !== departmentValue) {
                        shouldShow = false;
                    }
                    } else {
                    // 詳細記錄視圖的搜尋
                    const schoolName = cells[1] ? (cells[1].textContent || cells[1].innerText) : '';
                    const activityType = cells[2] ? (cells[2].textContent || cells[2].innerText) : '';
                    const searchText = schoolName + activityType;
                    
                    if (searchValue && searchText.toLowerCase().indexOf(searchValue) === -1) {
                        shouldShow = false;
                    }
                }

                if (shouldShow) {
                        rows[i].style.display = "";
                    visibleCount++;
                    } else {
                        rows[i].style.display = "none";
                    }
                }
            
            // 更新顯示的教師數量
            const totalCountElement = document.getElementById('totalTeacherCount');
            if (totalCountElement) {
                totalCountElement.textContent = visibleCount;
            }
        }

        // 為各個篩選控制項添加事件監聽器
        if (searchInput) {
            searchInput.addEventListener('keyup', applyFilters);
        }
        
        if (teacherNameFilter) {
            teacherNameFilter.addEventListener('change', applyFilters);
        }
        
        if (departmentFilter) {
            departmentFilter.addEventListener('change', applyFilters);
        }
    });
    
    // 重置篩選
    function resetFilters() {
        const searchInput = document.getElementById('searchInput');
        const teacherNameFilter = document.getElementById('teacherNameFilter');
        const departmentFilter = document.getElementById('departmentFilter');
        
        if (searchInput) searchInput.value = '';
        if (teacherNameFilter) teacherNameFilter.value = '';
        if (departmentFilter) departmentFilter.value = '';
        
        // 重新應用篩選（實際上是清除所有篩選）
        const table = document.getElementById('recordsTable');
        if (table) {
            const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
            let totalCount = 0;
            for (let i = 0; i < rows.length; i++) {
                rows[i].style.display = "";
                totalCount++;
            }
            
            const totalCountElement = document.getElementById('totalTeacherCount');
            if (totalCountElement) {
                totalCountElement.textContent = totalCount;
            }
        }
    }

    // 查看記錄詳情
    function viewRecord(record) {
        const modalBody = document.getElementById('viewModalBody');
        
        // [修正] 轉換活動時間為中文
        let activityTimeText = '未設定';
        if (record.activity_time == 1) activityTimeText = '上班日';
        else if (record.activity_time == 2) activityTimeText = '假日';

        // [修正] 優先使用中文活動類型名稱
        let activityTypeName = record.activity_type_name || record.activity_type || 'N/A';

        let content = `
            <p><strong>活動日期:</strong> ${record.activity_date || 'N/A'}</p>
            <p><strong>教師姓名:</strong> ${record.teacher_name || 'N/A'}</p>
            <p><strong>所屬系所:</strong> ${record.teacher_department || 'N/A'}</p>
            <p><strong>學校名稱:</strong> ${record.school_name || 'N/A'}</p>
            <p><strong>活動類型:</strong> ${activityTypeName}</p>
            <p><strong>活動時間:</strong> ${activityTimeText}</p>
            <p><strong>提交時間:</strong> ${new Date(record.created_at).toLocaleString()}</p>
            <hr>
            <p><strong>聯絡窗口:</strong> ${record.contact_person || '未填寫'}</p>
            <p><strong>聯絡電話:</strong> ${record.contact_phone || '未填寫'}</p>
            <p><strong>參與對象:</strong> ${record.participants || '未填寫'}</p>
        `;
        
        if (record.notes) {
            content += `<hr><p><strong>備註:</strong></p><p style="white-space: pre-wrap;">${record.notes}</p>`;
        }
        
        modalBody.innerHTML = content;
        document.getElementById('viewModal').style.display = 'block';
    }

    // 關閉Modal
    function closeModal(modalId) {
        document.getElementById(modalId).style.display = 'none';
    }

    // 點擊Modal外部關閉
    window.onclick = function(event) {
        const modals = document.getElementsByClassName('modal');
        for (let modal of modals) {
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        }
    }
    
    <?php if ($teacher_id > 0): ?>
    // 教師詳情視圖的展開/收合和分頁功能
    let currentOpenDetailId = null;
    let currentPage = 1;
    let itemsPerPage = 10;
    let allRows = [];
    let filteredRows = [];
    
    // 展開/收合詳細資訊
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
    
    // 初始化分頁
    function initPagination() {
        const table = document.getElementById('recordsTable');
        if (!table) return;
        
        const tbody = table.getElementsByTagName('tbody')[0];
        if (!tbody) return;
        
        allRows = Array.from(tbody.getElementsByTagName('tr')).filter(row => !row.classList.contains('detail-row'));
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
        
        // 隱藏所有行（包括詳細行）
        allRows.forEach(row => {
            row.style.display = 'none';
            const detailRow = row.nextElementSibling;
            if (detailRow && detailRow.classList.contains('detail-row')) {
                detailRow.style.display = 'none';
            }
        });
        
        if (itemsPerPage === 'all' || itemsPerPage >= totalItems) {
            // 顯示所有過濾後的行
            filteredRows.forEach(row => {
                row.style.display = '';
            });
            
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
        document.getElementById('pageInfo').innerHTML = 
            `顯示第 <span id="currentRange">${document.getElementById('currentRange').textContent}</span> 筆，共 ${totalItems} 筆`;
        
        // 更新上一頁/下一頁按鈕
        document.getElementById('prevPage').disabled = currentPage === 1;
        document.getElementById('nextPage').disabled = currentPage >= totalPages || totalPages <= 1;
        
        // 更新頁碼按鈕
        updatePageNumbers(totalPages);
    }

    function updatePageNumbers(totalPages) {
        const pageNumbers = document.getElementById('pageNumbers');
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

    // 檢查是否從出席紀錄管理頁面進入，如果是則自動顯示圖表並滾動
    document.addEventListener('DOMContentLoaded', function() {
        const urlParams = new URLSearchParams(window.location.search);
        const viewMode = urlParams.get('view');
        
        if (viewMode === 'attendance') {
            // 自動顯示出席統計圖表
            setTimeout(() => {
                if (typeof showAttendanceStats === 'function') {
                    showAttendanceStats();
                }
                // 滾動到五專入學說明會統計分析位置
                setTimeout(() => {
                    const admissionSection = document.getElementById('admissionStatsSection');
                    if (admissionSection) {
                        admissionSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                }, 800);
            }, 300);
        }
    });
    
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('searchInput');
        const table = document.getElementById('recordsTable');

        if (searchInput && table) {
            searchInput.addEventListener('keyup', function() {
                const filter = searchInput.value.toLowerCase();
                const tbody = table.getElementsByTagName('tbody')[0];
                
                if (!tbody) return;
                
                allRows = Array.from(tbody.getElementsByTagName('tr')).filter(row => !row.classList.contains('detail-row'));
                
                filteredRows = allRows.filter(row => {
                    const cells = row.getElementsByTagName('td');
                    for (let j = 0; j < cells.length; j++) {
                        const cellText = cells[j].textContent || cells[j].innerText;
                        if (cellText.toLowerCase().indexOf(filter) > -1) {
                            return true;
                        }
                    }
                    return false;
                });
                
                currentPage = 1;
                updatePagination();
            });
        }
        
        // 初始化分頁
        initPagination();
    });
    
    // 將函數暴露到全局作用域
    window.toggleDetail = toggleDetail;
    window.changeItemsPerPage = changeItemsPerPage;
    window.changePage = changePage;
    window.goToPage = goToPage;
    <?php endif; ?>
    </script>
    <?php if ($teacher_id > 0): ?>
        </div>
        </div>
    </div>
    <?php endif; ?>
</body>
</html>