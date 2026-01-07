<?php
require_once __DIR__ . '/session_config.php';

checkBackendLogin();


// 引入資料庫設定
require_once '../../Topics-frontend/frontend/config.php';

// 獲取使用者資訊
$username = isset($_SESSION['username']) ? $_SESSION['username'] : '';
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$is_imd = ($username === 'IMD'); // 保留用於向後兼容

// 審核結果：改為純自動顯示（不可手動更改）
// 僅指定帳號（username=12）且角色為招生中心（STA）可「查看」審核結果
$can_view_review_result = ($username === '12' && $user_role === 'STA');

// 手機比對：只取數字
function digits_only($s) {
    return preg_replace('/\D+/', '', (string)$s);
}

// 文字正規化：去空白（含全形空白）、統一大小寫
function normalize_text($s) {
    $t = trim((string)$s);
    // 去除半形/全形空白
    $t = str_replace([" ", "　", "\t", "\r", "\n"], "", $t);
    // 英文轉小寫（中文不受影響）
    $t = mb_strtolower($t, 'UTF-8');
    return $t;
}

// 手機正規化：只取數字，若長度 > 10 則取末 10 碼（處理 +886 等格式）
function normalize_phone($s) {
    $d = digits_only($s);
    if (strlen($d) > 10) {
        $d = substr($d, -10);
    }
    return $d;
}

// 學校比對：正規化後「完全相等」或「互相包含」視為相同（處理市立/縣立/括號等差異）
function school_matches($a, $b) {
    $na = normalize_text($a);
    $nb = normalize_text($b);
    if ($na === '' || $nb === '') return false;
    if ($na === $nb) return true;
    return (mb_strpos($na, $nb) !== false) || (mb_strpos($nb, $na) !== false);
}

// 確保 application_statuses 中存在指定的狀態 code（避免 status 外鍵寫入失敗）
// $needed 格式：['通過' => ['code'=>'AP','name'=>'通過','order'=>90], ...]
function ensure_application_status_codes($conn, $needed) {
    if (!$conn || empty($needed) || !is_array($needed)) return;

    // 檢查表是否存在
    $t = $conn->query("SHOW TABLES LIKE 'application_statuses'");
    if (!$t || $t->num_rows <= 0) return;

    // 取得欄位資訊（不同資料庫版本可能欄位略有差異）
    $cols = [];
    $cr = $conn->query("SHOW COLUMNS FROM application_statuses");
    if ($cr) {
        while ($row = $cr->fetch_assoc()) {
            $cols[] = $row['Field'];
        }
    }
    $has_code = in_array('code', $cols, true);
    if (!$has_code) return;
    $has_name = in_array('name', $cols, true);
    $has_order = in_array('display_order', $cols, true);

    $stmt_check = $conn->prepare("SELECT code FROM application_statuses WHERE code = ? LIMIT 1");
    if (!$stmt_check) return;

    $stmt_ins = null;
    if ($has_name && $has_order) {
        $stmt_ins = $conn->prepare("INSERT INTO application_statuses (code, name, display_order) VALUES (?, ?, ?)");
    } elseif ($has_name) {
        $stmt_ins = $conn->prepare("INSERT INTO application_statuses (code, name) VALUES (?, ?)");
    } else {
        $stmt_ins = $conn->prepare("INSERT INTO application_statuses (code) VALUES (?)");
    }
    if (!$stmt_ins) {
        $stmt_check->close();
        return;
    }

    foreach ($needed as $meta) {
        $code = trim((string)($meta['code'] ?? ''));
        if ($code === '') continue;

        // 已存在就跳過
        $stmt_check->bind_param('s', $code);
        if ($stmt_check->execute()) {
            $res = $stmt_check->get_result();
            if ($res && $res->num_rows > 0) {
                continue;
            }
        }

        // 不存在就新增（忽略重複鍵等錯誤）
        try {
            if ($has_name && $has_order) {
                $name = (string)($meta['name'] ?? $code);
                $order = (int)($meta['order'] ?? 0);
                $stmt_ins->bind_param('ssi', $code, $name, $order);
                @$stmt_ins->execute();
            } elseif ($has_name) {
                $name = (string)($meta['name'] ?? $code);
                $stmt_ins->bind_param('ss', $code, $name);
                @$stmt_ins->execute();
            } else {
                $stmt_ins->bind_param('s', $code);
                @$stmt_ins->execute();
            }
        } catch (Exception $e) {
            // ignore
        }
    }

    $stmt_check->close();
    $stmt_ins->close();
}

// 判斷用戶角色
$allowed_center_roles = ['ADM', 'STA'];
$is_admin_or_staff = in_array($user_role, $allowed_center_roles);
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

// 判斷是否為招生中心/行政人員
$is_admission_center = $is_admin_or_staff && !$is_department_user;

// 檢查是否有 recommender 和 recommended 表
$has_recommender_table = false;
$has_recommended_table = false;

// 設置頁面標題
$page_title = '被推薦人資訊';
$current_page = 'admission_recommend_list';

// 建立資料庫連接
try {
    $conn = getDatabaseConnection();
} catch (Exception $e) {
    die("資料庫連接失敗: " . $e->getMessage());
}

// （已移除）審核結果手動更新入口

// 獲取所有招生推薦資料
try {
    // 先檢查表是否存在
    $table_check = $conn->query("SHOW TABLES LIKE 'admission_recommendations'");
    if (!$table_check || $table_check->num_rows == 0) {
        throw new Exception("資料表 'admission_recommendations' 不存在");
    }
    
    // 檢查是否有 recommender 和 recommended 表
    $table_check_recommender = $conn->query("SHOW TABLES LIKE 'recommender'");
    $has_recommender_table = $table_check_recommender && $table_check_recommender->num_rows > 0;
    
    $table_check_recommended = $conn->query("SHOW TABLES LIKE 'recommended'");
    $has_recommended_table = $table_check_recommended && $table_check_recommended->num_rows > 0;
    
    // 檢查字段是否存在（先檢查，因為 WHERE 條件需要用到）
    $has_assigned_department = false;
    $has_assigned_teacher_id = false;
    $has_status = false;
    $has_enrollment_status = false;
    $has_review_result = false;
    
    $columns_to_check = ['assigned_department', 'assigned_teacher_id', 'status', 'enrollment_status', 'review_result'];
    foreach ($columns_to_check as $column) {
        $column_check = $conn->query("SHOW COLUMNS FROM admission_recommendations LIKE '$column'");
        if ($column_check && $column_check->num_rows > 0) {
            // 字段存在
            if ($column === 'assigned_department') {
                $has_assigned_department = true;
            } elseif ($column === 'assigned_teacher_id') {
                $has_assigned_teacher_id = true;
            } elseif ($column === 'status') {
                $has_status = true;
            } elseif ($column === 'enrollment_status') {
                $has_enrollment_status = true;
            } elseif ($column === 'review_result') {
                $has_review_result = true;
            }
        } else {
            // 字段不存在，動態添加
            try {
                if ($column === 'assigned_department') {
                    $conn->query("ALTER TABLE admission_recommendations ADD COLUMN assigned_department VARCHAR(50) DEFAULT NULL");
                    $has_assigned_department = true;
                } elseif ($column === 'assigned_teacher_id') {
                    $conn->query("ALTER TABLE admission_recommendations ADD COLUMN assigned_teacher_id INT DEFAULT NULL");
                    $has_assigned_teacher_id = true;
                } elseif ($column === 'status') {
                    $conn->query("ALTER TABLE admission_recommendations ADD COLUMN status VARCHAR(20) DEFAULT 'pending'");
                    $has_status = true;
                } elseif ($column === 'enrollment_status') {
                    $conn->query("ALTER TABLE admission_recommendations ADD COLUMN enrollment_status VARCHAR(20) DEFAULT NULL");
                    $has_enrollment_status = true;
                } elseif ($column === 'review_result') {
                    $conn->query("ALTER TABLE admission_recommendations ADD COLUMN review_result VARCHAR(20) DEFAULT NULL");
                    $has_review_result = true;
                }
            } catch (Exception $e) {
                error_log("添加字段 $column 失敗: " . $e->getMessage());
            }
        }
    }
    
    // 根據用戶角色過濾資料
    // 學校行政人員（ADM/STA）可以看到所有資料
    // 科系主任（DI）只能看到自己科系的資料
    $where_clause = "";
    if ($is_director && !empty($user_department_code)) {
        // 主任只能看到學生興趣是自己科系的記錄，或已被分配給自己科系的記錄
        // student_interest 是科系代碼（如 'IM', 'AF'），需要與 departments 表關聯
        if ($has_assigned_department) {
            $where_clause = " WHERE (ar.student_interest = ? OR ar.assigned_department = ?)";
        } else {
            $where_clause = " WHERE ar.student_interest = ?";
        }
    }
    
    // 構建SQL查詢 - 根據資料庫實際結構
    // 根據資料庫結構，admission_recommendations 表有 status 和 enrollment_status，但沒有 assigned_department 和 assigned_teacher_id
    $assigned_fields = "NULL as assigned_department, NULL as assigned_teacher_id,";
    $teacher_joins = "";
    $teacher_name_field = "'' as teacher_name";
    $teacher_username_field = "'' as teacher_username";
    
    $status_field = $has_status ? "COALESCE(ar.status, 'pending')" : "'pending'";
    $enrollment_status_field = $has_enrollment_status ? "COALESCE(ar.enrollment_status, '未入學')" : "'未入學'";
    $review_result_field = $has_review_result ? "COALESCE(ar.review_result, '')" : "''";
    
    if ($has_recommender_table && $has_recommended_table) {
        // 使用新的表結構：recommender 和 recommended 表
        // 使用 LEFT JOIN 確保即使沒有對應的推薦人或被推薦人記錄，也能顯示主表記錄
        // 添加 JOIN 來獲取學校、年級、科系的名稱
        $sql = "SELECT 
            ar.id,
            COALESCE(rec.name, '') as recommender_name,
            COALESCE(rec.id, '') as recommender_student_id,
            COALESCE(rec.grade, '') as recommender_grade_code,
            COALESCE(rec_grade.name, '') as recommender_grade,
            COALESCE(rec.department, '') as recommender_department_code,
            COALESCE(rec_dept.name, '') as recommender_department,
            COALESCE(rec.phone, '') as recommender_phone,
            COALESCE(rec.email, '') as recommender_email,
            COALESCE(red.name, '') as student_name,
            COALESCE(red.school, '') as student_school_code,
            COALESCE(school.name, '') as student_school,
            COALESCE(red.grade, '') as student_grade_code,
            COALESCE(red_grade.name, '') as student_grade,
            COALESCE(red.phone, '') as student_phone,
            COALESCE(red.email, '') as student_email,
            COALESCE(red.line_id, '') as student_line_id,
            ar.recommendation_reason,
            COALESCE(ar.student_interest, '') as student_interest_code,
            COALESCE(interest_dept.name, '') as student_interest,
            ar.additional_info,
            $status_field as status,
            $enrollment_status_field as enrollment_status,
            $review_result_field as review_result,
            ar.proof_evidence,
            $assigned_fields
            $teacher_name_field,
            $teacher_username_field,
            ar.created_at,
            ar.updated_at
            FROM admission_recommendations ar
            LEFT JOIN recommender rec ON ar.id = rec.recommendations_id
            LEFT JOIN recommended red ON ar.id = red.recommendations_id
            LEFT JOIN identity_options rec_grade ON rec.grade = rec_grade.code
            LEFT JOIN departments rec_dept ON rec.department = rec_dept.code
            LEFT JOIN identity_options red_grade ON red.grade = red_grade.code
            LEFT JOIN school_data school ON red.school = school.school_code
            LEFT JOIN departments interest_dept ON ar.student_interest = interest_dept.code";
        
        if (!empty($where_clause)) {
            $sql .= " " . $where_clause;
        }
        $sql .= " ORDER BY ar.created_at DESC";
    } else {
        // 如果沒有 recommender 和 recommended 表，只查詢主表
        // 仍然需要 JOIN departments 來獲取 student_interest 的名稱
        $sql = "SELECT 
            ar.id,
            '' as recommender_name,
            '' as recommender_student_id,
            '' as recommender_grade,
            '' as recommender_department,
            '' as recommender_phone,
            '' as recommender_email,
            '' as student_name,
            '' as student_school,
            '' as student_grade,
            '' as student_phone,
            '' as student_email,
            '' as student_line_id,
            ar.recommendation_reason,
            COALESCE(ar.student_interest, '') as student_interest_code,
            COALESCE(interest_dept.name, '') as student_interest,
            ar.additional_info,
            $status_field as status,
            $enrollment_status_field as enrollment_status,
            $review_result_field as review_result,
            ar.proof_evidence,
            $assigned_fields
            $teacher_name_field,
            $teacher_username_field,
            ar.created_at,
            ar.updated_at
            FROM admission_recommendations ar
            LEFT JOIN departments interest_dept ON ar.student_interest = interest_dept.code";
        
        if (!empty($where_clause)) {
            $sql .= " " . $where_clause;
        }
        $sql .= " ORDER BY ar.created_at DESC";
    }
    
    // 調試：記錄 SQL 查詢和表檢查結果
    error_log("招生推薦查詢 - has_recommender_table: " . ($has_recommender_table ? 'true' : 'false') . ", has_recommended_table: " . ($has_recommended_table ? 'true' : 'false'));
    error_log("where_clause: " . $where_clause);
    error_log("is_director: " . ($is_director ? 'true' : 'false') . ", user_department_code: " . ($user_department_code ?? 'null'));
    error_log("is_admin_or_staff: " . ($is_admin_or_staff ? 'true' : 'false'));
    error_log("SQL: " . $sql);
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $error_msg = "SQL 準備失敗: " . $conn->error . " (SQL: " . $sql . ")";
        error_log($error_msg);
        throw new Exception($error_msg);
    }
    
    // 如果是主任，綁定參數
    if ($is_director && !empty($user_department_code) && !empty($where_clause)) {
        if ($has_assigned_department) {
            // 有 assigned_department 欄位，需要綁定兩個參數
            $stmt->bind_param("ss", $user_department_code, $user_department_code);
        } else {
            // 沒有 assigned_department 欄位，只需要綁定一個參數
            $stmt->bind_param("s", $user_department_code);
        }
        error_log("綁定參數: user_department_code = " . $user_department_code . ", has_assigned_department = " . ($has_assigned_department ? 'true' : 'false'));
    }
    
    if (!$stmt->execute()) {
        $error_msg = "SQL 執行失敗: " . $stmt->error . " (SQL: " . $sql . ")";
        error_log($error_msg);
        throw new Exception($error_msg);
    }
    
    $result = $stmt->get_result();
    $recommendations = $result->fetch_all(MYSQLI_ASSOC);

    // -------------------------------------------------------------
    // 自動審核比對：recommended(name/school/phone) vs new_student_basic_info(student_name/previous_school/mobile)
    // 規則：3個欄位都對上 => 通過；對上 1~2 個 => 需人工確認；0 個 => 不通過
    // 若使用者已手動填寫 review_result（非空），則以手動為準，不覆蓋。
    // -------------------------------------------------------------
    $has_nsbi_table = false;
    try {
        $t = $conn->query("SHOW TABLES LIKE 'new_student_basic_info'");
        $has_nsbi_table = ($t && $t->num_rows > 0);
    } catch (Exception $e) {
        $has_nsbi_table = false;
    }

    $stmt_nsbi_by_name = null;
    $stmt_nsbi_by_phone = null;
    if ($has_nsbi_table) {
        $stmt_nsbi_by_name = $conn->prepare("SELECT student_name, previous_school, mobile FROM new_student_basic_info WHERE student_name = ? LIMIT 30");
        $stmt_nsbi_by_phone = $conn->prepare("SELECT student_name, previous_school, mobile FROM new_student_basic_info WHERE mobile LIKE ? LIMIT 30");
    }

    // -------------------------------------------------------------
    // 審核結果寫回 admission_recommendations.status（status 有外鍵到 application_statuses）
    // 這裡使用 application_statuses.code 的代碼風格（PE/AP/RE...）：
    // 通過 => AP、不通過 => RE、需人工確認 => MC
    // -------------------------------------------------------------
    $review_status_map = [
        '通過' => ['code' => 'AP', 'name' => '通過', 'order' => 90],
        '不通過' => ['code' => 'RE', 'name' => '不通過', 'order' => 91],
        '需人工確認' => ['code' => 'MC', 'name' => '需人工確認', 'order' => 92],
    ];
    ensure_application_status_codes($conn, $review_status_map);
    $stmt_update_status = $conn->prepare("UPDATE admission_recommendations SET status = ? WHERE id = ?");

    // -------------------------------------------------------------
    // 同名去重提示：若多人填寫相同「被推薦人姓名」，以 created_at 最早者為優先
    // 其餘較晚建立者在審核結果顯示紅字提示
    // -------------------------------------------------------------
    $earliest_by_name = []; // key => ['ts' => int, 'id' => int]
    foreach ($recommendations as $tmp) {
        $nm = trim((string)($tmp['student_name'] ?? ''));
        if ($nm === '') continue;
        $key = normalize_text($nm);
        if ($key === '') continue;
        $ts = strtotime((string)($tmp['created_at'] ?? ''));
        if ($ts === false) $ts = PHP_INT_MAX;
        $idv = (int)($tmp['id'] ?? 0);

        if (!isset($earliest_by_name[$key])) {
            $earliest_by_name[$key] = ['ts' => $ts, 'id' => $idv];
            continue;
        }
        $cur = $earliest_by_name[$key];
        if ($ts < $cur['ts'] || ($ts === $cur['ts'] && $idv < $cur['id'])) {
            $earliest_by_name[$key] = ['ts' => $ts, 'id' => $idv];
        }
    }

    foreach ($recommendations as &$it) {
        // 沒有 recommended / 沒有 nsbi 表，就不做比對（留空由前端顯示未填寫）
        if (!$has_recommended_table || !$has_nsbi_table) {
            $it['auto_review_result'] = '';
            continue;
        }

        $name = trim((string)($it['student_name'] ?? ''));
        // 學校：同時保留 code 與 name，優先用 code 比對（對應 new_student_basic_info.previous_school 常存學校代碼）
        $school_code = trim((string)($it['student_school_code'] ?? ''));
        $school_name = trim((string)($it['student_school'] ?? '')); // 透過 school_data 轉成名稱（若有）
        $phoneDigits = normalize_phone($it['student_phone'] ?? '');

        $bestScore = 0;
        $candidates = [];
        $bestMatch = [
            'name' => false,
            'school' => false,
            'phone' => false,
            'nsbi_student_name' => '',
            'nsbi_previous_school' => '',
            'nsbi_mobile' => ''
        ];

        // 1) 先用姓名找候選
        if ($stmt_nsbi_by_name && $name !== '') {
            $stmt_nsbi_by_name->bind_param('s', $name);
            if ($stmt_nsbi_by_name->execute()) {
                $r = $stmt_nsbi_by_name->get_result();
                if ($r) {
                    while ($row = $r->fetch_assoc()) {
                        $candidates[] = $row;
                    }
                }
            }
        }

        // 2) 再用手機找候選（用末 10 碼做 LIKE，避免 +886、破折號等格式差異）
        if ($stmt_nsbi_by_phone && $phoneDigits !== '') {
            $like = '%' . $phoneDigits . '%';
            $stmt_nsbi_by_phone->bind_param('s', $like);
            if ($stmt_nsbi_by_phone->execute()) {
                $r2 = $stmt_nsbi_by_phone->get_result();
                if ($r2) {
                    while ($row = $r2->fetch_assoc()) {
                        $candidates[] = $row;
                    }
                }
            }
        }

        foreach ($candidates as $cand) {
            $score = 0;

            $cand_name = trim((string)($cand['student_name'] ?? ''));
            $cand_school = trim((string)($cand['previous_school'] ?? '')); // 可能是學校代碼或學校名稱（依前台儲存方式）
            $cand_phone = normalize_phone($cand['mobile'] ?? '');

            $m_name = ($name !== '' && normalize_text($cand_name) === normalize_text($name));
            // 學校比對：
            // 1) 若雙方都有 code，直接比 code（最準）
            // 2) 否則用名稱（包含/相等）比對（向後相容）
            $m_school = false;
            if ($school_code !== '' && $cand_school !== '' && $cand_school === $school_code) {
                $m_school = true;
            } elseif ($school_name !== '' && $cand_school !== '' && school_matches($cand_school, $school_name)) {
                $m_school = true;
            }
            $m_phone = ($phoneDigits !== '' && $cand_phone !== '' && $cand_phone === $phoneDigits);

            if ($m_name) $score++;
            if ($m_school) $score++;
            if ($m_phone) $score++;

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = [
                    'name' => $m_name,
                    'school' => $m_school,
                    'phone' => $m_phone,
                    'nsbi_student_name' => $cand_name,
                    'nsbi_previous_school' => $cand_school,
                    'nsbi_mobile' => $cand['mobile'] ?? ''
                ];
            }
            if ($bestScore === 3) break;
        }

        if ($bestScore === 3) {
            $it['auto_review_result'] = '通過';
        } elseif ($bestScore >= 1) {
            $it['auto_review_result'] = '需人工確認';
        } else {
            $it['auto_review_result'] = '不通過';
        }

        // debug：告訴你是哪個欄位沒對上（用於畫面提示）
        $it['auto_review_match_name'] = $bestMatch['name'] ? 1 : 0;
        $it['auto_review_match_school'] = $bestMatch['school'] ? 1 : 0;
        $it['auto_review_match_phone'] = $bestMatch['phone'] ? 1 : 0;
        $it['auto_review_nsbi_student_name'] = $bestMatch['nsbi_student_name'];
        $it['auto_review_nsbi_previous_school'] = $bestMatch['nsbi_previous_school'];
        $it['auto_review_nsbi_mobile'] = $bestMatch['nsbi_mobile'];

        // 同名較晚建立者：標記提示（只做顯示，不改變自動審核判定）
        $nm2 = trim((string)($it['student_name'] ?? ''));
        $key2 = $nm2 !== '' ? normalize_text($nm2) : '';
        $it['duplicate_note'] = 0;
        if ($key2 !== '' && isset($earliest_by_name[$key2])) {
            $ear = $earliest_by_name[$key2];
            $ts2 = strtotime((string)($it['created_at'] ?? ''));
            if ($ts2 === false) $ts2 = PHP_INT_MAX;
            $id2 = (int)($it['id'] ?? 0);
            // 非最早者都標記
            if ($id2 !== (int)$ear['id']) {
                if ($ts2 > (int)$ear['ts'] || ($ts2 === (int)$ear['ts'] && $id2 > (int)$ear['id'])) {
                    $it['duplicate_note'] = 1;
                }
            }
        }

        // 規則：出現「此被推薦人先前已有人填寫」者，審核結果一律為不通過
        if (!empty($it['duplicate_note'])) {
            $it['auto_review_result'] = '不通過';
        }

        // 將審核結果寫回 admission_recommendations.status（對應 application_statuses.code）
        $auto_review = trim((string)($it['auto_review_result'] ?? ''));
        if ($auto_review === '人工確認') $auto_review = '需人工確認';
        if ($auto_review !== '' && isset($review_status_map[$auto_review])) {
            $desired_status_code = (string)$review_status_map[$auto_review]['code'];
            $current_status_code = trim((string)($it['status'] ?? ''));
            $rid = (int)($it['id'] ?? 0);

            $it['auto_review_status_code'] = $desired_status_code; // 方便除錯/前端需要時可用
            if ($rid > 0 && $stmt_update_status && $desired_status_code !== '' && $current_status_code !== $desired_status_code) {
                $stmt_update_status->bind_param('si', $desired_status_code, $rid);
                // 若外鍵狀態不存在會更新失敗；上方已確保 application_statuses 有該 code
                @$stmt_update_status->execute();
                $it['status'] = $desired_status_code; // 同步本次畫面資料
            }
        }
    }
    unset($it);

    if ($stmt_nsbi_by_name) $stmt_nsbi_by_name->close();
    if ($stmt_nsbi_by_phone) $stmt_nsbi_by_phone->close();
    if (isset($stmt_update_status) && $stmt_update_status) $stmt_update_status->close();
    
    // 調試信息：記錄查詢結果數量
    error_log("招生推薦查詢結果: " . count($recommendations) . " 筆記錄");
    
    // 如果查詢結果為空，但資料庫中有記錄，嘗試簡單查詢
    if (empty($recommendations)) {
        $simple_check = $conn->query("SELECT COUNT(*) as total FROM admission_recommendations");
        if ($simple_check) {
            $count_row = $simple_check->fetch_assoc();
            $total_count = $count_row['total'] ?? 0;
            error_log("admission_recommendations 表總記錄數: " . $total_count);
            if ($total_count > 0) {
                error_log("警告：資料庫中有 " . $total_count . " 筆記錄，但查詢結果為空。可能是 JOIN 條件或 WHERE 條件有問題。");
                // 嘗試執行最簡單的查詢來測試
                $test_sql = "SELECT ar.id FROM admission_recommendations ar LIMIT 1";
                $test_result = $conn->query($test_sql);
                if ($test_result && $test_result->num_rows > 0) {
                    error_log("簡單查詢成功，問題可能在複雜的 JOIN 或欄位選擇");
                } else {
                    error_log("簡單查詢也失敗，可能是資料庫連接問題");
                }
            }
        }
    }
    
    // 調試信息：檢查總數（僅在開發環境顯示）
    if (isset($_GET['debug']) && $_GET['debug'] == '1') {
        $count_sql = "SELECT COUNT(*) as total FROM admission_recommendations";
        $count_result = $conn->query($count_sql);
        if ($count_result) {
            $count_row = $count_result->fetch_assoc();
            error_log("招生推薦總數: " . $count_row['total'] . " (當前用戶: " . $username . ", 角色: " . $user_role . ", 科系: " . ($user_department_code ?? '無') . ")");
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

// 獲取老師列表（用於分配功能）
$teachers = [];
$is_department_user = false; // 預設為 false，如果需要可以根據實際需求設定
$is_admission_center = false; // 預設為 false，如果需要可以根據實際需求設定
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

        .table-search {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .search-btn {
            padding: 8px 12px;
            border: 1px solid #1890ff;
            background: #1890ff;
            color: #fff;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 700;
            transition: all 0.2s;
        }
        .search-btn:hover {
            filter: brightness(0.95);
        }
        .search-btn.secondary {
            border-color: #d9d9d9;
            background: #fff;
            color: #595959;
        }
        .search-btn.secondary:hover {
            border-color: #1890ff;
            color: #1890ff;
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

        .search-select {
            padding: 8px 12px;
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            font-size: 14px;
            background: #fff;
            cursor: pointer;
            transition: all 0.3s;
            width: 170px;
        }

        .search-select:focus {
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

        /* 審核結果 badge */
        .review-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 6px 10px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 800;
            color: #fff;
            line-height: 1;
            white-space: nowrap;
        }
        .review-badge.pass { background: #52c41a; }
        .review-badge.fail { background: #cf1322; }
        .review-badge.manual { background: #1677ff; }
        .review-badge.empty { background: #8c8c8c; }
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
                            <?php if ($can_view_review_result): ?>
                                <select id="reviewResultFilter" class="search-select" title="審核結果篩選">
                                    <option value="">審核結果：全部</option>
                                    <option value="通過">通過</option>
                                    <option value="不通過">不通過</option>
                                    <option value="需人工確認">需人工確認</option>
                                </select>
                            <?php endif; ?>
                            <button type="button" id="btnQuery" class="search-btn">查詢</button>
                            <button type="button" id="btnClear" class="search-btn secondary">清除</button>
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
                                        <?php if ($can_view_review_result): ?>
                                            <th>審核結果</th>
                                        <?php endif; ?>
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
                                    <?php
                                        $row_review = isset($item['auto_review_result']) ? trim((string)$item['auto_review_result']) : '';
                                        if ($row_review === '人工確認') $row_review = '需人工確認';
                                    ?>
                                    <tr data-review-result="<?php echo htmlspecialchars($row_review); ?>">
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
                                        <?php if ($can_view_review_result): ?>
                                            <td>
                                                <?php
                                                    $auto_review = isset($item['auto_review_result']) ? trim((string)$item['auto_review_result']) : '';
                                                    if ($auto_review === '人工確認') $auto_review = '需人工確認';
                                                    $display_review = ($auto_review === '') ? '未填寫' : $auto_review;

                                                    $badge_class = 'review-badge';
                                                    if ($display_review === '通過') {
                                                        $badge_class .= ' pass';
                                                    } elseif ($display_review === '不通過') {
                                                        $badge_class .= ' fail';
                                                    } elseif ($display_review === '需人工確認') {
                                                        $badge_class .= ' manual';
                                                    } else {
                                                        $badge_class .= ' empty';
                                                    }

                                                    $m1 = (int)($item['auto_review_match_name'] ?? 0);
                                                    $m2 = (int)($item['auto_review_match_school'] ?? 0);
                                                    $m3 = (int)($item['auto_review_match_phone'] ?? 0);
                                                    $debug_short = '姓名' . ($m1 ? '✓' : '✗') . ' / 學校' . ($m2 ? '✓' : '✗') . ' / 手機' . ($m3 ? '✓' : '✗');

                                                    $dbg_nsbi_name = (string)($item['auto_review_nsbi_student_name'] ?? '');
                                                    $dbg_nsbi_school = (string)($item['auto_review_nsbi_previous_school'] ?? '');
                                                    $dbg_nsbi_mobile = (string)($item['auto_review_nsbi_mobile'] ?? '');
                                                    $dbg_title = "比對：" . $debug_short
                                                        . "\nrecommended："
                                                        . "\n- name=" . ($item['student_name'] ?? '')
                                                        . "\n- school_code=" . ($item['student_school_code'] ?? '')
                                                        . "\n- school_name=" . ($item['student_school'] ?? '')
                                                        . "\n- phone=" . ($item['student_phone'] ?? '')
                                                        . "\nnew_student_basic_info（候選）："
                                                        . "\n- student_name=" . $dbg_nsbi_name
                                                        . "\n- previous_school=" . $dbg_nsbi_school
                                                        . "\n- mobile=" . $dbg_nsbi_mobile;
                                                ?>
                                                <span class="info-value" title="<?php echo htmlspecialchars($dbg_title); ?>">
                                                    <span class="<?php echo htmlspecialchars($badge_class); ?>"><?php echo htmlspecialchars($display_review); ?></span>
                                                    <span style="color:#8c8c8c; font-size:12px; margin-left:6px;">(<?php echo htmlspecialchars($debug_short); ?>)</span>
                                                    <?php if (!empty($item['duplicate_note'])): ?>
                                                        <div style="margin-top:6px; color:#cf1322; font-size:12px; font-weight:900;">
                                                            此被推薦人先前已有人填寫
                                                        </div>
                                                    <?php endif; ?>
                                                </span>
                                            </td>
                                        <?php endif; ?>
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
                                        <?php
                                            $detail_colspan = ($is_admission_center || $is_department_user) ? 8 : 7;
                                            if (!$can_view_review_result) $detail_colspan -= 1;
                                        ?>
                                        <td colspan="<?php echo (int)$detail_colspan; ?>" style="padding: 20px; background: #f9f9f9; border: 2px solid #b3d9ff; border-radius: 4px;">
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
        const reviewFilter = document.getElementById('reviewResultFilter');
        const btnQuery = document.getElementById('btnQuery');
        const btnClear = document.getElementById('btnClear');
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

            function applyFilters() {
                const filterText = (searchInput.value || '').toLowerCase();
                const reviewVal = (reviewFilter && reviewFilter.value) ? reviewFilter.value : '';

                if (!tbody) return;

                filteredRows = allRows.filter(row => {
                    // 1) 審核結果篩選（用 data-review-result）
                    if (reviewVal) {
                        const rr = row.dataset ? (row.dataset.reviewResult || '') : '';
                        if (rr !== reviewVal) return false;
                    }

                    // 2) 關鍵字搜尋（全欄位文字）
                    if (!filterText) return true;

                    const cells = row.getElementsByTagName('td');
                    for (let j = 0; j < cells.length; j++) {
                        const cellText = cells[j].textContent || cells[j].innerText || '';
                        if (cellText.toLowerCase().indexOf(filterText) > -1) {
                            return true;
                        }
                    }
                    return false;
                });

                currentPage = 1;
                updatePagination();
            }

            // 改成「按查詢」才套用條件；輸入框按 Enter 也可查詢
            if (btnQuery) btnQuery.addEventListener('click', applyFilters);
            if (searchInput) {
                searchInput.addEventListener('keyup', function(e) {
                    if (e.key === 'Enter') applyFilters();
                });
            }

            // 清除：重置條件並顯示全部
            if (btnClear) {
                btnClear.addEventListener('click', function() {
                    if (searchInput) searchInput.value = '';
                    if (reviewFilter) reviewFilter.value = '';
                    applyFilters();
                });
            }
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

    // （已移除）審核結果手動更新 JS

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

