<?php
require_once __DIR__ . '/session_config.php';

checkBackendLogin();


// 引入資料庫設定
require_once '../../Topics-frontend/frontend/config.php';
// 引入審核結果通知（通過/不通過）郵件功能
require_once __DIR__ . '/recommendation_review_email.php';

// 獲取使用者資訊
$username = isset($_SESSION['username']) ? $_SESSION['username'] : '';
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$is_imd = ($username === 'IMD'); // 保留用於向後兼容

// 審核結果：改為純自動顯示（不可手動更改）
// 僅指定帳號（username=12）且角色為招生中心（STA）可「查看」審核結果
$can_view_review_result = ($username === '12' && $user_role === 'STA');

// 學生興趣：CSV(code,code,...) -> 名稱列表
function format_student_interest_display($interest_codes_csv, $departments_map) {
    $raw = trim((string)$interest_codes_csv);
    if ($raw === '') return '';
    $codes = array_values(array_filter(array_map('trim', explode(',', $raw)), function($v){ return $v !== ''; }));
    if (empty($codes)) return '';
    $names = [];
    foreach ($codes as $c) {
        $names[] = $departments_map[$c] ?? $c;
    }
    // 去重但保留順序
    $seen = [];
    $out = [];
    foreach ($names as $n) {
        if (isset($seen[$n])) continue;
        $seen[$n] = true;
        $out[] = $n;
    }
    return implode('、', $out);
}

function interest_contains_code($interest_codes_csv, $code) {
    $raw = trim((string)$interest_codes_csv);
    $code = trim((string)$code);
    if ($raw === '' || $code === '') return false;
    $parts = array_values(array_filter(array_map('trim', explode(',', $raw)), function($v){ return $v !== ''; }));
    if (empty($parts)) return false;
    return in_array($code, $parts, true);
}

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

function excel_col_letter($index) {
    $index = (int)$index + 1;
    $letters = '';
    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $letters = chr(65 + $mod) . $letters;
        $index = (int)(($index - 1) / 26);
    }
    return $letters;
}

function excel_xml_escape($value) {
    $value = (string)$value;
    return str_replace(['&', '<', '>', '"', "'"], ['&amp;', '&lt;', '&gt;', '&quot;', '&apos;'], $value);
}

function build_simple_xlsx($rows, $output_path) {
    if (!class_exists('ZipArchive')) return false;
    $zip = new ZipArchive();
    if ($zip->open($output_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return false;

    $sheet_rows = '';
    $row_num = 1;
    foreach ($rows as $row) {
        $sheet_rows .= '<row r="' . $row_num . '">';
        $col_num = 0;
        foreach ($row as $cell) {
            $col = excel_col_letter($col_num);
            $cell_ref = $col . $row_num;
            $text = excel_xml_escape($cell);
            $sheet_rows .= '<c r="' . $cell_ref . '" t="inlineStr"><is><t xml:space="preserve">' . $text . '</t></is></c>';
            $col_num++;
        }
        $sheet_rows .= '</row>';
        $row_num++;
    }

    $sheet_xml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>' . $sheet_rows . '</sheetData>'
        . '</worksheet>';

    $workbook_xml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $rels_xml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" '
        . 'Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $workbook_rels_xml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" '
        . 'Target="worksheets/sheet1.xml"/>'
        . '</Relationships>';

    $content_types_xml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '</Types>';

    $zip->addFromString('[Content_Types].xml', $content_types_xml);
    $zip->addFromString('_rels/.rels', $rels_xml);
    $zip->addFromString('xl/workbook.xml', $workbook_xml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbook_rels_xml);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet_xml);
    $zip->close();
    return true;
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

// 判斷用戶角色（使用 session_config.php helper，支援中文/代碼角色）
$is_admin_or_staff = (isAdmin() || isStaff());
$is_director = isDirector();
$is_teacher_user = isTeacher();
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
$can_show_review_result_column = ($can_view_review_result || $is_teacher_user);
$can_use_bulk_gmail_send = !$is_teacher_user;

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
    
    // 檢查是否有 recommender / recommended / recommendation_approval_links 表
    $table_check_recommender = $conn->query("SHOW TABLES LIKE 'recommender'");
    $has_recommender_table = $table_check_recommender && $table_check_recommender->num_rows > 0;
    
    $table_check_recommended = $conn->query("SHOW TABLES LIKE 'recommended'");
    $has_recommended_table = $table_check_recommended && $table_check_recommended->num_rows > 0;

    $table_check_approval = $conn->query("SHOW TABLES LIKE 'recommendation_approval_links'");
    $has_approval_links_table = $table_check_approval && $table_check_approval->num_rows > 0;
    
    // 檢查字段是否存在（先檢查，因為 WHERE 條件需要用到）
    $has_assigned_department = false;
    $has_assigned_teacher_id = false;
    $has_status = false;
    $has_enrollment_status = false;
    $has_review_result = false;
    $has_academic_year = false;
    
    $columns_to_check = ['assigned_department', 'assigned_teacher_id', 'status', 'enrollment_status', 'review_result', 'academic_year'];
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
            } elseif ($column === 'academic_year') {
                $has_academic_year = true;
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
                } elseif ($column === 'academic_year') {
                    // 學年度（民國年）：用 created_at 判斷
                    // 113：2024/08/01-2025/07/31；114：2025/08/01-2026/07/31（以每年 8/1 切換）
                    $conn->query("ALTER TABLE admission_recommendations ADD COLUMN academic_year INT(3) DEFAULT NULL COMMENT '學年度(民國年，例如113/114)'");
                    $has_academic_year = true;
                    // 回填既有資料（只回填 academic_year 為 NULL 的記錄）
                    @$conn->query("UPDATE admission_recommendations
                        SET academic_year = CASE
                            WHEN created_at IS NULL THEN NULL
                            WHEN MONTH(created_at) >= 8 THEN YEAR(created_at) - 1911
                            ELSE YEAR(created_at) - 1912
                        END
                        WHERE academic_year IS NULL");
                }
            } catch (Exception $e) {
                error_log("添加字段 $column 失敗: " . $e->getMessage());
            }
        }
    }

    // 取得 admission_recommendations 的實際欄位（用於向後相容：有些資料可能仍存在主表而非 recommender/recommended）
    $ar_columns = [];
    try {
        $cr = $conn->query("SHOW COLUMNS FROM admission_recommendations");
        if ($cr) {
            while ($row = $cr->fetch_assoc()) {
                $ar_columns[] = $row['Field'];
            }
        }
    } catch (Exception $e) {
        $ar_columns = [];
    }
    $ar_has = function($col) use ($ar_columns) {
        return in_array($col, $ar_columns, true);
    };
    $ar_has_recommender_name = $ar_has('recommender_name');
    $ar_has_recommender_student_id = $ar_has('recommender_student_id');
    $ar_has_recommender_grade_code = $ar_has('recommender_grade_code');
    $ar_has_recommender_grade = $ar_has('recommender_grade');
    $ar_has_recommender_department_code = $ar_has('recommender_department_code');
    $ar_has_recommender_department = $ar_has('recommender_department');
    $ar_has_recommender_phone = $ar_has('recommender_phone');
    $ar_has_recommender_email = $ar_has('recommender_email');

    $ar_has_student_name = $ar_has('student_name');
    $ar_has_student_school_code = $ar_has('student_school_code');
    $ar_has_student_school = $ar_has('student_school');
    $ar_has_student_grade_code = $ar_has('student_grade_code');
    $ar_has_student_grade = $ar_has('student_grade');
    $ar_has_student_phone = $ar_has('student_phone');
    $ar_has_student_email = $ar_has('student_email');
    $ar_has_student_line_id = $ar_has('student_line_id');
    
    // 根據用戶角色過濾資料
    // 學校行政人員（ADM/STA）可以看到所有資料
    // 科系主任（DI）只能看到自己科系的資料
    $where_clause = "";
    if ($is_director && !empty($user_department_code)) {
        // 主任只能看到學生興趣是自己科系的記錄，或已被分配給自己科系的記錄
        // student_interest 可能是單一 code 或 CSV（多選）
        if ($has_assigned_department) {
            $where_clause = " WHERE (FIND_IN_SET(?, ar.student_interest) OR ar.assigned_department = ?)";
        } else {
            $where_clause = " WHERE FIND_IN_SET(?, ar.student_interest)";
        }
    }

    // 顯示模式：預設顯示全部（'all'），view 參數可用於審核狀態篩選
    $view_mode = $_GET['view'] ?? 'all';
    $status_condition_sql = '';
    
    if ($view_mode === '') {
        $view_mode = 'all';
    }
    if ($view_mode === 'fail') {
        $status_condition_sql = "(ar.status = 'RE' OR ar.status = 'rejected')";
    } elseif ($view_mode === 'manual') {
        $status_condition_sql = "(ar.status = 'MC' OR ar.status = '需人工審查')";
    } elseif ($view_mode === 'pass') {
        $status_condition_sql = "(ar.status = 'AP' OR ar.status = 'approved')";
    } elseif ($view_mode === 'empty') {
        $status_condition_sql = "(ar.status IS NULL OR ar.status = '' OR ar.status = 'pending')";
    } else {
        // 'all' -> 不加入狀態過濾
        $status_condition_sql = '';
    }

    // 注意：不要在 SQL 階段過濾狀態，改為在 PHP 計算 auto_review_result 後再過濾
    // 這能避免 database 中 status 欄位未同步或使用不同表示法時查詢結果為空的問題。
    
    // 構建SQL查詢 - 根據資料庫實際結構
    // 根據資料庫結構，admission_recommendations 表有 status 和 enrollment_status，但沒有 assigned_department 和 assigned_teacher_id
    $assigned_fields = "NULL as assigned_department, NULL as assigned_teacher_id,";
    $teacher_joins = "";
    $teacher_name_field = "'' as teacher_name";
    $teacher_username_field = "'' as teacher_username";
    
    $status_field = $has_status ? "COALESCE(ar.status, 'pending')" : "'pending'";
    $enrollment_status_field = $has_enrollment_status ? "COALESCE(ar.enrollment_status, '未入學')" : "'未入學'";
    $review_result_field = $has_review_result ? "COALESCE(ar.review_result, '')" : "''";
    $academic_year_field = $has_academic_year ? "ar.academic_year" : "NULL";
    
    $approval_status_field = $has_approval_links_table ? "COALESCE(ral.status, '') as director_review_status," : "'' as director_review_status,";
    $approval_join = $has_approval_links_table
        ? "LEFT JOIN (
            SELECT r1.*
            FROM recommendation_approval_links r1
            INNER JOIN (
                SELECT recommendation_id, MAX(id) AS max_id
                FROM recommendation_approval_links
                GROUP BY recommendation_id
            ) r2 ON r1.id = r2.max_id
        ) ral ON ral.recommendation_id = ar.id"
        : "";
    
    if ($has_recommender_table && $has_recommended_table) {
        // 向後相容：如果 recommender/recommended 沒有資料，fallback 到 admission_recommendations
        $rec_name_expr = "COALESCE(rec.name, " . ($ar_has_recommender_name ? "ar.recommender_name" : "''") . ", '')";
        $rec_sid_expr = "COALESCE(rec.id, " . ($ar_has_recommender_student_id ? "ar.recommender_student_id" : "''") . ", '')";
        $rec_grade_code_expr = "COALESCE(rec.grade, " . ($ar_has_recommender_grade_code ? "ar.recommender_grade_code" : "''") . ", '')";
        $rec_dept_code_expr = "COALESCE(rec.department, " . ($ar_has_recommender_department_code ? "ar.recommender_department_code" : "''") . ", '')";
        $rec_phone_expr = "COALESCE(rec.phone, " . ($ar_has_recommender_phone ? "ar.recommender_phone" : "''") . ", '')";
        $rec_email_expr = "COALESCE(rec.email, " . ($ar_has_recommender_email ? "ar.recommender_email" : "''") . ", '')";
        $rec_grade_join_key = $ar_has_recommender_grade_code ? "COALESCE(rec.grade, ar.recommender_grade_code)" : "rec.grade";
        $rec_dept_join_key = $ar_has_recommender_department_code ? "COALESCE(rec.department, ar.recommender_department_code)" : "rec.department";
        $rec_grade_name_expr = "COALESCE(rec_grade.name, " . ($ar_has_recommender_grade ? "ar.recommender_grade" : "''") . ", '')";
        $rec_dept_name_expr = "COALESCE(rec_dept.name, " . ($ar_has_recommender_department ? "ar.recommender_department" : "''") . ", '')";

        $stu_name_expr = "COALESCE(red.name, " . ($ar_has_student_name ? "ar.student_name" : "''") . ", '')";
        $stu_school_code_expr = "COALESCE(red.school, " . ($ar_has_student_school_code ? "ar.student_school_code" : "''") . ", '')";
        $stu_grade_code_expr = "COALESCE(red.grade, " . ($ar_has_student_grade_code ? "ar.student_grade_code" : "''") . ", '')";
        $stu_phone_expr = "COALESCE(red.phone, " . ($ar_has_student_phone ? "ar.student_phone" : "''") . ", '')";
        $stu_email_expr = "COALESCE(red.email, " . ($ar_has_student_email ? "ar.student_email" : "''") . ", '')";
        $stu_line_expr = "COALESCE(red.line_id, " . ($ar_has_student_line_id ? "ar.student_line_id" : "''") . ", '')";
        $stu_school_join_key = $ar_has_student_school_code ? "COALESCE(red.school, ar.student_school_code)" : "red.school";
        $stu_grade_join_key = $ar_has_student_grade_code ? "COALESCE(red.grade, ar.student_grade_code)" : "red.grade";
        $stu_school_name_expr = "COALESCE(school.name, " . ($ar_has_student_school ? "ar.student_school" : "''") . ", '')";
        $stu_grade_name_expr = "COALESCE(red_grade.name, " . ($ar_has_student_grade ? "ar.student_grade" : "''") . ", '')";

        // 使用新的表結構：recommender 和 recommended 表
        // 使用 LEFT JOIN 確保即使沒有對應的推薦人或被推薦人記錄，也能顯示主表記錄
        // 添加 JOIN 來獲取學校、年級、科系的名稱
        $sql = "SELECT 
            ar.id,
            $rec_name_expr as recommender_name,
            $rec_sid_expr as recommender_student_id,
            $rec_grade_code_expr as recommender_grade_code,
            $rec_grade_name_expr as recommender_grade,
            $rec_dept_code_expr as recommender_department_code,
            $rec_dept_name_expr as recommender_department,
            $rec_phone_expr as recommender_phone,
            $rec_email_expr as recommender_email,
            $stu_name_expr as student_name,
            $stu_school_code_expr as student_school_code,
            $stu_school_name_expr as student_school,
            $stu_grade_code_expr as student_grade_code,
            $stu_grade_name_expr as student_grade,
            $stu_phone_expr as student_phone,
            $stu_email_expr as student_email,
            $stu_line_expr as student_line_id,
            ar.recommendation_reason,
            COALESCE(ar.student_interest, '') as student_interest_code,
            COALESCE(interest_dept.name, '') as student_interest,
            ar.additional_info,
            $status_field as status,
            $enrollment_status_field as enrollment_status,
            $review_result_field as review_result,
            $approval_status_field
            ar.proof_evidence,
            $assigned_fields
            $teacher_name_field,
            $teacher_username_field,
            $academic_year_field as academic_year,
            ar.created_at,
            ar.updated_at
            FROM admission_recommendations ar
            LEFT JOIN recommender rec ON ar.id = rec.recommendations_id
            LEFT JOIN recommended red ON ar.id = red.recommendations_id
            LEFT JOIN identity_options rec_grade ON $rec_grade_join_key = rec_grade.code
            LEFT JOIN departments rec_dept ON $rec_dept_join_key = rec_dept.code
            LEFT JOIN identity_options red_grade ON $stu_grade_join_key = red_grade.code
            LEFT JOIN school_data school ON $stu_school_join_key = school.school_code
            LEFT JOIN departments interest_dept ON ar.student_interest = interest_dept.code
            $approval_join";
        
        if (!empty($where_clause)) {
            $sql .= " " . $where_clause;
        }
        $sql .= " ORDER BY ar.created_at DESC";
    } else {
        // 若 recommender/recommended 其中一張表不存在，改用 admission_recommendations 主表欄位（向後相容）
        $sql = "SELECT 
            ar.id,
            " . ($ar_has_recommender_name ? "COALESCE(ar.recommender_name,'')" : "''") . " as recommender_name,
            " . ($ar_has_recommender_student_id ? "COALESCE(ar.recommender_student_id,'')" : "''") . " as recommender_student_id,
            " . ($ar_has_recommender_grade ? "COALESCE(ar.recommender_grade,'')" : ($ar_has_recommender_grade_code ? "COALESCE(ar.recommender_grade_code,'')" : "''")) . " as recommender_grade,
            " . ($ar_has_recommender_department ? "COALESCE(ar.recommender_department,'')" : ($ar_has_recommender_department_code ? "COALESCE(ar.recommender_department_code,'')" : "''")) . " as recommender_department,
            " . ($ar_has_recommender_phone ? "COALESCE(ar.recommender_phone,'')" : "''") . " as recommender_phone,
            " . ($ar_has_recommender_email ? "COALESCE(ar.recommender_email,'')" : "''") . " as recommender_email,
            " . ($ar_has_student_name ? "COALESCE(ar.student_name,'')" : "''") . " as student_name,
            " . ($ar_has_student_school ? "COALESCE(ar.student_school,'')" : ($ar_has_student_school_code ? "COALESCE(ar.student_school_code,'')" : "''")) . " as student_school,
            " . ($ar_has_student_grade ? "COALESCE(ar.student_grade,'')" : ($ar_has_student_grade_code ? "COALESCE(ar.student_grade_code,'')" : "''")) . " as student_grade,
            " . ($ar_has_student_phone ? "COALESCE(ar.student_phone,'')" : "''") . " as student_phone,
            " . ($ar_has_student_email ? "COALESCE(ar.student_email,'')" : "''") . " as student_email,
            " . ($ar_has_student_line_id ? "COALESCE(ar.student_line_id,'')" : "''") . " as student_line_id,
            ar.recommendation_reason,
            COALESCE(ar.student_interest, '') as student_interest_code,
            COALESCE(interest_dept.name, '') as student_interest,
            ar.additional_info,
            $status_field as status,
            $enrollment_status_field as enrollment_status,
            $review_result_field as review_result,
            $approval_status_field
            ar.proof_evidence,
            $assigned_fields
            $teacher_name_field,
            $teacher_username_field,
            $academic_year_field as academic_year,
            ar.created_at,
            ar.updated_at
            FROM admission_recommendations ar
            LEFT JOIN departments interest_dept ON ar.student_interest = interest_dept.code
            $approval_join";
        
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

    // 教師僅可查看「自己推薦」的資料
    // 比對來源：session username/name + teacher 表常見識別欄位（若存在）
    if ($is_teacher_user) {
        $teacher_identity_candidates = [];
        $teacher_identity_candidates[] = trim((string)$username);
        $teacher_identity_candidates[] = trim((string)($_SESSION['name'] ?? ''));

        try {
            if ($user_id > 0) {
                $teacher_cols = [];
                $tc = $conn->query("SHOW COLUMNS FROM teacher");
                if ($tc) {
                    while ($crow = $tc->fetch_assoc()) {
                        $teacher_cols[] = (string)$crow['Field'];
                    }
                }
                if (!empty($teacher_cols)) {
                    $pick = [];
                    $common = ['name', 'teacher_id', 'employee_no', 'teacher_no', 'number', 'username', 'id', 'user_id'];
                    foreach ($common as $c) {
                        if (in_array($c, $teacher_cols, true)) $pick[] = $c;
                    }
                    if (!empty($pick)) {
                        $sql_pick = "SELECT " . implode(', ', $pick) . " FROM teacher WHERE user_id = ? LIMIT 1";
                        $stmt_teacher = $conn->prepare($sql_pick);
                        if ($stmt_teacher) {
                            $stmt_teacher->bind_param("i", $user_id);
                            if ($stmt_teacher->execute()) {
                                $res_teacher = $stmt_teacher->get_result();
                                if ($res_teacher && ($teacher_row = $res_teacher->fetch_assoc())) {
                                    foreach ($pick as $k) {
                                        $v = trim((string)($teacher_row[$k] ?? ''));
                                        if ($v !== '') $teacher_identity_candidates[] = $v;
                                    }
                                }
                            }
                            $stmt_teacher->close();
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log('讀取 teacher 身分資訊失敗: ' . $e->getMessage());
        }

        $teacher_identity_norm = [];
        foreach ($teacher_identity_candidates as $v) {
            $nv = normalize_text($v);
            if ($nv !== '') $teacher_identity_norm[$nv] = true;
        }

        $filtered_teacher = [];
        foreach ($recommendations as $rec_item) {
            $rec_sid_text = normalize_text((string)($rec_item['recommender_student_id'] ?? ''));
            $rec_name_text = normalize_text((string)($rec_item['recommender_name'] ?? ''));
            $match_sid = ($rec_sid_text !== '' && isset($teacher_identity_norm[$rec_sid_text]));
            $match_name = ($rec_name_text !== '' && isset($teacher_identity_norm[$rec_name_text]));
            if ($match_sid || $match_name) {
                $filtered_teacher[] = $rec_item;
            }
        }
        $recommendations = $filtered_teacher;
    }

    // 取得 departments 對照（用於 student_interest CSV 顯示成名稱）
    $departments_map = [];
    try {
        $dres = $conn->query("SELECT code, name FROM departments");
        if ($dres) {
            while ($dr = $dres->fetch_assoc()) {
                $departments_map[$dr['code']] = $dr['name'];
            }
        }
    } catch (Exception $e) {
        $departments_map = [];
    }
    foreach ($recommendations as &$r0) {
        $csv = $r0['student_interest_code'] ?? '';
        $disp = format_student_interest_display($csv, $departments_map);
        if ($disp !== '') {
            $r0['student_interest'] = $disp;
        }
    }
    unset($r0);

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
    // 2026-01 起：審核結果改由招生中心「手動」下拉選單設定（通過/不通過/需人工審查）
    // 系統不再自動判斷、也不再自動寫回 admission_recommendations.status
    // -------------------------------------------------------------
    $stmt_update_status = null;

    // -------------------------------------------------------------
    // 重複推薦提示：姓名 + 連絡電話 + 就讀學校 都相同才視為重複
    // 以 created_at 最早者為第一筆，其餘皆顯示「此被推薦人先前已有人填寫」
    // -------------------------------------------------------------
    $earliest_by_key = []; // key => ['ts' => int, 'id' => int]
    $dup_count_by_key = []; // key => int
    foreach ($recommendations as $tmp) {
        $nm = trim((string)($tmp['student_name'] ?? ''));
        $phone = normalize_phone($tmp['student_phone'] ?? '');
        $school_code = trim((string)($tmp['student_school_code'] ?? ''));
        $school_name = trim((string)($tmp['student_school'] ?? ''));
        if ($nm === '' || $phone === '' || ($school_code === '' && $school_name === '')) continue;
        $school_key = ($school_code !== '') ? $school_code : normalize_text($school_name);
        $key = normalize_text($nm) . '|' . $phone . '|' . $school_key;
        if ($key === '||') continue;

        if (!isset($dup_count_by_key[$key])) $dup_count_by_key[$key] = 0;
        $dup_count_by_key[$key] += 1;

        $ts = strtotime((string)($tmp['created_at'] ?? ''));
        if ($ts === false) $ts = PHP_INT_MAX;
        $idv = (int)($tmp['id'] ?? 0);

        if (!isset($earliest_by_key[$key])) {
            $earliest_by_key[$key] = ['ts' => $ts, 'id' => $idv];
            continue;
        }
        $cur = $earliest_by_key[$key];
        // 以時間/ID 最早者為第一筆
        if ($ts < $cur['ts'] || ($ts === $cur['ts'] && $idv < $cur['id'])) {
            $earliest_by_key[$key] = ['ts' => $ts, 'id' => $idv];
        }
    }

    // -------------------------------------------------------------
    // 提示條件（不做自動判斷）：重複推薦 / 已填就讀意願 / 學生狀態(休學/退學)
    // -------------------------------------------------------------
    $has_enroll_table = false;
    try {
        $t3 = $conn->query("SHOW TABLES LIKE 'enrollment_intention'");
        $has_enroll_table = ($t3 && $t3->num_rows > 0);
    } catch (Exception $e) {
        $has_enroll_table = false;
    }

    $stmt_enroll_by_phone = null;
    $stmt_enroll_by_name = null;
    if ($has_enroll_table) {
        // enrollment_intention 常見欄位：name、phone1
        $stmt_enroll_by_name = $conn->prepare("SELECT 1 FROM enrollment_intention WHERE name = ? LIMIT 1");
        $stmt_enroll_by_phone = $conn->prepare("
            SELECT 1 FROM enrollment_intention
            WHERE REPLACE(REPLACE(REPLACE(REPLACE(phone1,'-',''),' ',''),'(',''),')','') LIKE ?
            LIMIT 1
        ");
    }

    $stmt_nsbi_status_by_phone = null;
    $stmt_nsbi_status_by_name = null;
    if ($has_nsbi_table) {
        $stmt_nsbi_status_by_phone = $conn->prepare("SELECT COALESCE(status,'') AS status FROM new_student_basic_info WHERE mobile LIKE ? ORDER BY id DESC LIMIT 1");
        $stmt_nsbi_status_by_name = $conn->prepare("SELECT COALESCE(status,'') AS status FROM new_student_basic_info WHERE student_name = ? ORDER BY id DESC LIMIT 1");
    }

    $cache_enroll = [];
    $cache_nsbi_status = [];

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

        $it['nsbi_found'] = !empty($candidates) ? 1 : 0;
        if (!empty($candidates)) {
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
            } elseif ($bestScore === 2) {
                $it['auto_review_result'] = '需人工確認';
            } else {
                $it['auto_review_result'] = '不通過';
            }
        } else {
            $it['auto_review_result'] = '';
        }

        // debug：告訴你是哪個欄位沒對上（用於畫面提示）
        $it['auto_review_match_name'] = $bestMatch['name'] ? 1 : 0;
        $it['auto_review_match_school'] = $bestMatch['school'] ? 1 : 0;
        $it['auto_review_match_phone'] = $bestMatch['phone'] ? 1 : 0;
        $it['auto_review_nsbi_student_name'] = $bestMatch['nsbi_student_name'];
        $it['auto_review_nsbi_previous_school'] = $bestMatch['nsbi_previous_school'];
        $it['auto_review_nsbi_mobile'] = $bestMatch['nsbi_mobile'];

        // 同名重複：非第一筆都標記提示（只做顯示，不改變自動審核判定）
        $nm2 = trim((string)($it['student_name'] ?? ''));
        $ph2 = normalize_phone($it['student_phone'] ?? '');
        $sc2 = trim((string)($it['student_school_code'] ?? ''));
        $sn2 = trim((string)($it['student_school'] ?? ''));
        $key2 = ($nm2 !== '' && $ph2 !== '' && ($sc2 !== '' || $sn2 !== ''))
            ? (normalize_text($nm2) . '|' . $ph2 . '|' . (($sc2 !== '') ? $sc2 : normalize_text($sn2)))
            : '';
        $it['duplicate_note'] = 0;
        $it['duplicate_count'] = 1;
        if ($key2 !== '' && isset($earliest_by_key[$key2])) {
            $ear = $earliest_by_key[$key2];
            $id2 = (int)($it['id'] ?? 0);
            // 非第一筆都標記
            if ($id2 > 0 && $id2 !== (int)$ear['id']) $it['duplicate_note'] = 1;
        }
        if ($key2 !== '' && isset($dup_count_by_key[$key2])) {
            $it['duplicate_count'] = (int)$dup_count_by_key[$key2];
        }

        // --- 三項提示 ---
        $it['review_hints'] = [];
        if ((int)($it['duplicate_count'] ?? 1) > 1) {
            $it['review_hints'][] = '此被推薦人已被人推薦';
        }

        // (2) 已填寫就讀意願表單
        $has_enroll = false;
        $nm3 = trim((string)($it['student_name'] ?? ''));
        $ph3 = normalize_phone($it['student_phone'] ?? '');
        $enroll_key = ($ph3 !== '') ? ('P:' . $ph3) : (($nm3 !== '') ? ('N:' . normalize_text($nm3)) : '');
        if ($enroll_key !== '' && isset($cache_enroll[$enroll_key])) {
            $has_enroll = (bool)$cache_enroll[$enroll_key];
        } else {
            if ($stmt_enroll_by_phone && $ph3 !== '') {
                $like = '%' . $ph3 . '%';
                $stmt_enroll_by_phone->bind_param('s', $like);
                if (@$stmt_enroll_by_phone->execute()) {
                    $r = $stmt_enroll_by_phone->get_result();
                    $has_enroll = ($r && $r->num_rows > 0);
                }
            }
            if (!$has_enroll && $stmt_enroll_by_name && $nm3 !== '') {
                $stmt_enroll_by_name->bind_param('s', $nm3);
                if (@$stmt_enroll_by_name->execute()) {
                    $r2 = $stmt_enroll_by_name->get_result();
                    $has_enroll = ($r2 && $r2->num_rows > 0);
                }
            }
            if ($enroll_key !== '') $cache_enroll[$enroll_key] = $has_enroll ? 1 : 0;
        }
        $it['has_enrollment_intention'] = $has_enroll ? 1 : 0;
        if ($has_enroll) {
            $it['review_hints'][] = '此被推薦人已填寫過就讀意願表單';
        }

        // (3) 學生狀態：休學/退學 => 無獎金
        $nsbi_status = '';
        $nsbi_key = ($ph3 !== '') ? ('P:' . $ph3) : (($nm3 !== '') ? ('N:' . normalize_text($nm3)) : '');
        if ($nsbi_key !== '' && isset($cache_nsbi_status[$nsbi_key])) {
            $nsbi_status = (string)$cache_nsbi_status[$nsbi_key];
        } else {
            if ($stmt_nsbi_status_by_phone && $ph3 !== '') {
                $like2 = '%' . $ph3 . '%';
                $stmt_nsbi_status_by_phone->bind_param('s', $like2);
                if (@$stmt_nsbi_status_by_phone->execute()) {
                    $rr = $stmt_nsbi_status_by_phone->get_result();
                    if ($rr && ($row = $rr->fetch_assoc())) {
                        $nsbi_status = trim((string)($row['status'] ?? ''));
                    }
                }
            }
            if ($nsbi_status === '' && $stmt_nsbi_status_by_name && $nm3 !== '') {
                $stmt_nsbi_status_by_name->bind_param('s', $nm3);
                if (@$stmt_nsbi_status_by_name->execute()) {
                    $rr2 = $stmt_nsbi_status_by_name->get_result();
                    if ($rr2 && ($row2 = $rr2->fetch_assoc())) {
                        $nsbi_status = trim((string)($row2['status'] ?? ''));
                    }
                }
            }
            if ($nsbi_key !== '') $cache_nsbi_status[$nsbi_key] = $nsbi_status;
        }
        $it['student_status'] = $nsbi_status;
        $it['no_bonus'] = in_array($nsbi_status, ['休學', '退學'], true) ? 1 : 0;
        if ((int)$it['no_bonus'] === 1) {
            $it['review_hints'][] = '學生狀態為' . $nsbi_status . '，無獎金';
        }

        // 2026-01 起：審核結果不再由系統自動判斷或寫回，
        // 僅由招生中心手動選擇（通過/不通過/需人工審查）。
    }
    unset($it);

    if ($stmt_nsbi_by_name) $stmt_nsbi_by_name->close();
    if ($stmt_nsbi_by_phone) $stmt_nsbi_by_phone->close();
    if (isset($stmt_update_status) && $stmt_update_status) $stmt_update_status->close();

    if ($stmt_enroll_by_phone) $stmt_enroll_by_phone->close();
    if ($stmt_enroll_by_name) $stmt_enroll_by_name->close();
    if ($stmt_nsbi_status_by_phone) $stmt_nsbi_status_by_phone->close();
    if ($stmt_nsbi_status_by_name) $stmt_nsbi_status_by_name->close();
    
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
    
    // --- 根據 view_mode 在 PHP 層級過濾資料 ---
    $get_review_label = function($status_code) {
        $st = trim((string)$status_code);
        if ($st === '' || $st === 'pending') return '尚未審核';
        if (mb_strpos($st, '審核完成') !== false) return '審核完成（可發獎金）';
        if (mb_strpos($st, '科主任審核未通過') !== false) return '科主任審核未通過';
        if (mb_strpos($st, '科主任審核中') !== false) return '科主任審核中';
        if (mb_strpos($st, '初審未通過（待科主任審核）') !== false) return '初審未通過（待科主任審核）';
        if (mb_strpos($st, '已通過初審') !== false) return '已通過初審（待科主任審核）';
        if (mb_strpos($st, '初審未通過') !== false) return '初審未通過';
        if (mb_strpos($st, '尚未審核') !== false) return '尚未審核';
        if ($st === 'APD') return '審核完成（可發獎金）';
        if ($st === 'APDR') return '科主任審核未通過';
        if ($st === 'AP') return '已通過初審（待科主任審核）';
        if ($st === 'MC') return '初審未通過（待科主任審核）';
        if ($st === 'RE') return '初審未通過';
        return '尚未審核';
    };
    $get_teacher_final_review_label = function($review_label) {
        $lbl = trim((string)$review_label);
        if ($lbl === '審核完成（可發獎金）') return '通過';
        if ($lbl === '科主任審核未通過' || $lbl === '初審未通過') return '不通過';
        return '審核中';
    };
    if (isset($view_mode) && $view_mode !== 'all') {
        $filtered = [];
        foreach ($recommendations as $rec) {
            $label = $get_review_label($rec['status'] ?? '');

            if ($view_mode === 'unreviewed' && $label === '尚未審核') $filtered[] = $rec;
            if ($view_mode === 'director_pending' && ($label === '已通過初審（待科主任審核）' || $label === '初審未通過（待科主任審核）')) $filtered[] = $rec;
            if ($view_mode === 'rejected' && ($label === '初審未通過' || $label === '科主任審核未通過')) $filtered[] = $rec;
            if ($view_mode === 'director_in_progress' && $label === '科主任審核中') $filtered[] = $rec;
            if ($view_mode === 'approved_bonus' && $label === '審核完成（可發獎金）') $filtered[] = $rec;
        }
        $recommendations = $filtered;
    }

    if (isset($_GET['export_bonus']) && $view_mode === 'approved_bonus') {
        $rows = [[
            '推薦編號',
            '審核結果',
            '被推薦人姓名',
            '就讀學校',
            '年級',
            '電子郵件',
            '聯絡電話',
            'LINE ID',
            '學生興趣',
            '推薦理由',
            '其他補充資訊',
            '證明文件',
            '推薦時間',
            '推薦人姓名',
            '推薦人學號',
            '推薦人年級',
            '推薦人科系',
            '推薦人聯絡電話',
            '推薦人電子郵件',
        ]];
        foreach ($recommendations as $rec) {
            $rows[] = [
                (string)($rec['id'] ?? ''),
                $get_review_label($rec['status'] ?? ''),
                (string)($rec['student_name'] ?? ''),
                (string)($rec['student_school'] ?? ''),
                (string)($rec['student_grade'] ?? ''),
                (string)($rec['student_email'] ?? ''),
                (string)($rec['student_phone'] ?? ''),
                (string)($rec['student_line_id'] ?? ''),
                (string)($rec['student_interest'] ?? ''),
                (string)($rec['recommendation_reason'] ?? ''),
                (string)($rec['additional_info'] ?? ''),
                (string)($rec['proof_evidence'] ?? ''),
                (string)($rec['created_at'] ?? ''),
                (string)($rec['recommender_name'] ?? ''),
                (string)($rec['recommender_student_id'] ?? ''),
                (string)($rec['recommender_grade'] ?? ''),
                (string)($rec['recommender_department'] ?? ''),
                (string)($rec['recommender_phone'] ?? ''),
                (string)($rec['recommender_email'] ?? ''),
            ];
        }

        $filename_base = '可發送獎金名單_' . date('Ymd_His');
        if (class_exists('ZipArchive')) {
            $tmp = tempnam(sys_get_temp_dir(), 'bonus_export_');
            $xlsx_path = $tmp . '.xlsx';
            @rename($tmp, $xlsx_path);
            $built = build_simple_xlsx($rows, $xlsx_path);
            if ($built && file_exists($xlsx_path)) {
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . $filename_base . '.xlsx"');
                header('Content-Length: ' . filesize($xlsx_path));
                readfile($xlsx_path);
                @unlink($xlsx_path);
                exit;
            }
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename_base . '.csv"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }

    // --- 相同推薦人清單（姓名 + 學號/教師編號 都一致才算） ---
    $dup_recommenders = []; // key => ['name'=>..., 'id'=>..., 'count'=>int]
    foreach ($recommendations as $rec) {
        $rname = trim((string)($rec['recommender_name'] ?? ''));
        $rid = trim((string)($rec['recommender_student_id'] ?? ''));
        if ($rname === '' || $rid === '') continue;
        $key = normalize_text($rname) . '|' . $rid;
        if (!isset($dup_recommenders[$key])) {
            $dup_recommenders[$key] = ['name' => $rname, 'id' => $rid, 'count' => 0];
        }
        $dup_recommenders[$key]['count'] += 1;
    }
    // 只保留重複（>=2）
    $dup_recommenders = array_filter($dup_recommenders, function($v) {
        return isset($v['count']) && (int)$v['count'] >= 2;
    });
    // 依名稱排序
    if (!empty($dup_recommenders)) {
        uasort($dup_recommenders, function($a, $b) {
            return strcmp((string)$a['name'], (string)$b['name']);
        });
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

// -----------------------------
// 獎金：顯示「已發送」狀態/金額（以及提供發送按鈕）
// 規則：同名且通過者，獎金依人數平分（由 send_bonus.php 計算並寫入 amount）
// -----------------------------
$can_send_bonus = (isStaff() || isAdmin());
$bonus_sent_map = []; // recommendation_id => ['sent_at'=>..., 'sent_by'=>..., 'amount'=>...]
try {
    if (isset($conn) && $conn) {
        $tb = $conn->query("SHOW TABLES LIKE 'bonus_send_logs'");
        if ($tb && $tb->num_rows > 0 && !empty($recommendations)) {
            // 舊表補欄位（向後相容）
            $c = $conn->query("SHOW COLUMNS FROM bonus_send_logs LIKE 'amount'");
            if ($c && $c->num_rows == 0) {
                @$conn->query("ALTER TABLE bonus_send_logs ADD COLUMN amount INT NOT NULL DEFAULT 1500 AFTER recommender_student_id");
            }

            $ids = [];
            foreach ($recommendations as $r) {
                $rid = (int)($r['id'] ?? 0);
                if ($rid > 0) $ids[] = $rid;
            }
            $ids = array_values(array_unique($ids));

            if (!empty($ids)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $sql = "SELECT recommendation_id, sent_at, sent_by, COALESCE(amount, 1500) AS amount
                        FROM bonus_send_logs
                        WHERE recommendation_id IN ($placeholders)";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $types = str_repeat('i', count($ids));
                    $params = array_merge([$types], $ids);
                    $refs = [];
                    foreach ($params as $k => $v) $refs[$k] = &$params[$k];
                    call_user_func_array([$stmt, 'bind_param'], $refs);
                    if ($stmt->execute()) {
                        $res = $stmt->get_result();
                        if ($res) {
                            while ($row = $res->fetch_assoc()) {
                                $rid = (int)($row['recommendation_id'] ?? 0);
                                if ($rid <= 0) continue;
                                $bonus_sent_map[$rid] = [
                                    'sent_at' => (string)($row['sent_at'] ?? ''),
                                    'sent_by' => (string)($row['sent_by'] ?? ''),
                                    'amount' => (int)($row['amount'] ?? 1500),
                                ];
                            }
                        }
                    }
                    $stmt->close();
                }
            }
        }
    }
} catch (Exception $e) {
    // ignore
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

        .search-label {
            font-size: 14px;
            font-weight: 700;
            color: #595959;
            white-space: nowrap;
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
        .review-badge.pass { background: #73d13d; }
        .review-badge.fail { background: #ff7875; }
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
        .btn-view.active {
            background: #1890ff;
            color: #fff;
            box-shadow: 0 1px 0 rgba(0,0,0,0.04) inset;
        }
        button.btn-view {
            font-family: inherit;
        }
        /* 寄送 Gmail 按鈕樣式 */
        #gmailSendToggle {
            background: #fff;
            color: #262626;
            border-color: #91d5ff;
            padding: 8px 16px;
            font-size: 15px;
            border-radius: 6px;
        }
        #gmailSendToggle:hover {
            background: #f5faff;
            color: #262626;
            border-color: #69c0ff;
        }
        #gmailSendToggle .gmail-icon {
            width: 18px;
            height: 18px;
            margin-left: 6px;
            vertical-align: -3px;
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
        /* 查看審核結果視窗：放大字型與尺寸、取消粗體 */
        #reviewCriteriaModal .modal-content {
            max-width: 640px;
        }
        #reviewCriteriaModal .modal-header h3 {
            font-size: 20px;
            font-weight: 400;
        }
        #reviewCriteriaModal .modal-body p {
            font-size: 18px;
            font-weight: 400;
        }
        #reviewCriteriaModal #reviewCriteriaList div {
            font-size: 16px;
            font-weight: 400;
        }
        .review-progress {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin: 8px 0 16px;
        }
        .review-progress-step {
            padding: 6px 10px;
            border-radius: 0;
            font-size: 15px;
            border: none;
            color: #8c8c8c;
            background: #fafafa;
            white-space: nowrap;
        }
        .review-progress-step.active {
            background: #91d5ff;
            color: #fff;
        }
        .review-progress-step.done {
            background: #b7eb8f;
            color: #fff;
        }
        .review-progress-arrow {
            color: #262626;
            font-size: 20px;
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
        .gmail-preview-item {
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 14px;
            margin-bottom: 16px;
            background: #fafafa;
        }
        .gmail-preview-title {
            font-weight: 700;
            margin-bottom: 10px;
            color: var(--text-color);
        }
        .gmail-preview-field {
            margin-bottom: 10px;
        }
        .gmail-preview-field label {
            display: block;
            font-size: 13px;
            color: #666;
            margin-bottom: 6px;
        }
        .gmail-preview-field input[type="text"],
        .gmail-preview-field textarea {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            font-size: 14px;
            box-sizing: border-box;
        }
        .gmail-preview-field textarea {
            min-height: 160px;
            resize: vertical;
        }
        .gmail-attachments {
            display: flex;
            flex-direction: column;
            gap: 8px;
            font-size: 14px;
            color: #595959;
        }
        .gmail-file-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-top: 6px;
        }
        .gmail-file-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .gmail-file-remove {
            border: none;
            background: #ff4d4f;
            color: #fff;
            border-radius: 4px;
            padding: 2px 6px;
            cursor: pointer;
            font-size: 12px;
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
                        <?php
                            // 科系篩選選項（departments.code）
                            $interest_options = (isset($departments_map) && is_array($departments_map)) ? $departments_map : [];
                            if (!empty($interest_options)) {
                                ksort($interest_options);
                            }

                            // 學年度篩選選項（民國年，以每年 8/1 切換）
                            $y_now = (int)date('Y');
                            $m_now = (int)date('n');
                            $current_ay_roc = ($m_now >= 8) ? ($y_now - 1911) : ($y_now - 1912);
                            $year_options = [$current_ay_roc, $current_ay_roc - 1];
                            $year_options = array_values(array_unique(array_filter($year_options, function($v){ return is_int($v) && $v > 0; })));
                            rsort($year_options);
                        ?>

                        <?php if (!$is_teacher_user): ?>
                        <span class="search-label">科系篩選</span>
                        <select id="interestFilter" class="search-select" title="依學生興趣(科系)篩選">
                            <option value="">全部科系</option>
                            <?php foreach ($interest_options as $code => $name): ?>
                                <option value="<?php echo htmlspecialchars((string)$code); ?>"><?php echo htmlspecialchars((string)$name); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>

                        <span class="search-label">年度篩選</span>
                        <select id="academicYearFilter" class="search-select" title="依學年度篩選">
                            <option value="">全部學年度</option>
                            <?php foreach ($year_options as $yy): ?>
                                <option value="<?php echo htmlspecialchars((string)$yy); ?>"><?php echo htmlspecialchars((string)$yy); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <input type="text" id="searchInput" class="search-input" placeholder="搜尋被推薦人姓名、學校或電話...">

                        <?php if (!empty($can_send_bonus)): ?>
                            <a class="btn-view" href="bonus_center.php" style="margin-left: 10px;">
                                <i class="fas fa-gift"></i> 獎金專區
                            </a>
                            <a class="btn-view" href="bonus_send_list.php" style="margin-left: 8px;">
                                <i class="fas fa-receipt"></i> 已發送獎金名單
                            </a>
                            <a class="btn-view" href="admission_recommend_list.php?view=approved_bonus&export_bonus=1" style="margin-left: 8px;">
                                <i class="fas fa-file-excel"></i> 匯出可發送獎金名單
                            </a>
                        <?php endif; ?>
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
                                    // 不輸出空結果提示，保留頁面上方的篩選按鈕以供使用者操作
                                } else {
                                    // 資料表不存在時也不輸出提示（如需顯示錯誤，可在 debug 模式下啟用）
                                }
                            } catch (Exception $e) {
                                // 顯示錯誤信息
                                echo "<div style='background: #fff2f0; border: 1px solid #ffccc7; padding: 16px; margin: 16px; border-radius: 4px;'>";
                                echo "<p><strong>錯誤：</strong>" . htmlspecialchars($e->getMessage()) . "</p>";
                                echo "</div>";
                            }
                        }
                        ?>
                        <?php
                            // view_mode：下拉選單顯示用（空字串視同 all，向後相容）
                            $view_mode_ui = ($view_mode === '' || $view_mode === null) ? 'all' : (string)$view_mode;
                        ?>
                        <?php if (!$is_teacher_user): ?>
                        <div style="display:flex; align-items:center; gap:12px; margin-bottom:12px; margin-left: 55px; margin-top: 15px; flex-wrap: wrap;">
                            <span class="search-label">審核狀態</span>
                            <select id="viewModeSelect" class="search-select" title="審核狀態篩選">
                                <option value="all" <?php echo ($view_mode_ui === 'all') ? 'selected' : ''; ?>>顯示全部</option>
                                <option value="unreviewed" <?php echo ($view_mode_ui === 'unreviewed') ? 'selected' : ''; ?>>尚未審核</option>
                                <option value="director_pending" <?php echo ($view_mode_ui === 'director_pending') ? 'selected' : ''; ?>>待主任審核</option>
                                <option value="rejected" <?php echo ($view_mode_ui === 'rejected') ? 'selected' : ''; ?>>未通過審核</option>
                                <option value="director_in_progress" <?php echo ($view_mode_ui === 'director_in_progress') ? 'selected' : ''; ?>>科主任審核中</option>
                                <option value="approved_bonus" <?php echo ($view_mode_ui === 'approved_bonus') ? 'selected' : ''; ?>>通過(可發獎金)</option>
                            </select>
                            <button type="button" class="btn-view" id="gmailSendToggle">
                                寄送gmail
                                <img class="gmail-icon" src="https://upload.wikimedia.org/wikipedia/commons/7/7e/Gmail_icon_%282020%29.svg" alt="Gmail">
                            </button>
                            <button type="button" class="btn-view" id="gmailSendConfirm" style="display:none;">發送</button>
                            <button type="button" class="btn-view" id="gmailSendCancel" style="display:none;">取消</button>
                        </div>
                        <?php endif; ?>
                        <?php if (empty($recommendations)): ?>
                            <div class="empty-state" style="margin-left:55px; margin-top:24px; text-align:center;">
                                <i class="fas fa-inbox fa-3x" style="color:#8c8c8c; display:block; margin:0 auto 10px;"></i>
                                <p style="color:#8c8c8c; font-size:14px;">目前尚無人工審核資料。</p>
                            </div>
                        <?php else: ?>
                            <table class="table" id="recommendationTable">
                                <thead>
                                    <tr>
                                        <th class="gmail-select-cell" style="display:none; width:48px;">
                                            <input type="checkbox" id="gmailSelectAll" title="全選">
                                        </th>
                                        <th>ID</th>
                                        <th>被推薦人姓名</th>
                                        <th>學校</th>
                                        <th>年級</th>
                                        <th>學生興趣</th>
                                        <?php if ($can_show_review_result_column): ?>
                                            <th>審核結果</th>
                                        <?php endif; ?>
                                        <!-- <th>狀態</th> -->
                                        <!-- <th>入學狀態</th> -->
                                        <?php if ($is_admission_center && !$is_teacher_user): ?>
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
                                        $current_status_code = isset($item['status']) ? trim((string)$item['status']) : '';
                                        $row_review = $get_review_label($current_status_code);
                                    ?>
                                    <tr data-review-result="<?php echo htmlspecialchars($row_review); ?>"
                                        data-student-interest="<?php echo htmlspecialchars((string)($item['student_interest_code'] ?? '')); ?>"
                                        data-recommender-dept="<?php echo htmlspecialchars((string)($item['recommender_department_code'] ?? '')); ?>"
                                        data-academic-year="<?php echo htmlspecialchars((string)($item['academic_year'] ?? '')); ?>">
                                        <td class="gmail-select-cell" style="display:none;">
                                            <input type="checkbox" class="gmail-select-row" value="<?php echo (int)$item['id']; ?>">
                                        </td>
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
                                        <?php if ($can_show_review_result_column): ?>
                                            <td>
                                                <?php
                                                    $current_status = isset($item['status']) ? trim((string)$item['status']) : '';
                                                    $display_review = $get_review_label($current_status);
                                                    if ($is_teacher_user && !$can_view_review_result) {
                                                        $display_review = $get_teacher_final_review_label($display_review);
                                                    }
                                                    $is_unset_status = ($display_review === '尚未審核');

                                                    $dup_count = (int)($item['duplicate_count'] ?? 1);
                                                    $has_enroll = (int)($item['has_enrollment_intention'] ?? 0);
                                                    $student_status = (string)($item['student_status'] ?? '');
                                                    $no_bonus = (int)($item['no_bonus'] ?? 0);
                                                ?>
                                                <div class="info-value">
                                                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                                        <?php
                                                            $badge_class = 'review-badge';
                                                            if ($display_review === '審核完成（可發獎金）' || $display_review === '已通過初審（待科主任審核）' || $display_review === '通過') $badge_class .= ' pass';
                                                            elseif ($display_review === '初審未通過' || $display_review === '科主任審核未通過' || $display_review === '不通過') $badge_class .= ' fail';
                                                            elseif ($display_review === '初審未通過（待科主任審核）' || $display_review === '科主任審核中' || $display_review === '審核中') $badge_class .= ' manual';
                                                            else $badge_class .= ' empty';
                                                        ?>
                                                        <span class="<?php echo htmlspecialchars($badge_class); ?>"><?php echo htmlspecialchars($display_review); ?></span>
                                                        <?php if ($can_view_review_result): ?>
                                                            <button
                                                                type="button"
                                                                class="btn-view"
                                                                style="padding:6px 10px;"
                                                                onclick="openReviewCriteriaModal(
                                                                    <?php echo (int)$item['id']; ?>,
                                                                    '<?php echo htmlspecialchars((string)$item['student_name']); ?>',
                                                                    '<?php echo htmlspecialchars($display_review); ?>',
                                                                    <?php echo ($dup_count > 1) ? 'true' : 'false'; ?>,
                                                                    <?php echo ($has_enroll ? 'true' : 'false'); ?>,
                                                                    '<?php echo htmlspecialchars($student_status); ?>',
                                                                    <?php echo ($no_bonus ? 'true' : 'false'); ?>,
                                                                    <?php echo (int)($item['nsbi_found'] ?? 0); ?>,
                                                                    <?php echo (int)($item['auto_review_match_name'] ?? 0); ?>,
                                                                    <?php echo (int)($item['auto_review_match_school'] ?? 0); ?>,
                                                                    <?php echo (int)($item['auto_review_match_phone'] ?? 0); ?>,
                                                                    <?php echo htmlspecialchars(json_encode((string)($item['proof_evidence'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>
                                                                )"
                                                            >
                                                                查看審核結果
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>

                                                </div>
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
                                        <?php if ($is_admission_center && !$is_teacher_user): ?>
                                        <td>
                                            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
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
                                                <button
                                                    class="btn-view"
                                                    style="background: #1890ff; color: white; border-color: #1890ff;"
                                                    onclick="openAssignRecommendationDepartmentModal(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['student_name']); ?>', '<?php echo htmlspecialchars($item['assigned_department'] ?? ''); ?>')"
                                                >
                                                    <i class="fas fa-building"></i> <?php echo !empty($item['assigned_department']) ? '重新分配' : '分配'; ?>
                                                </button>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                                <button type="button" 
                                                   class="btn-view" 
                                                   id="detail-btn-<?php echo $item['id']; ?>"
                                                   onclick="toggleDetail(<?php echo $item['id']; ?>)">
                                                    <i class="fas fa-eye"></i> <span class="btn-text">查看詳情</span>
                                                </button>
                                                <?php
                                                    $rid = (int)($item['id'] ?? 0);
                                                    $current_status = isset($item['status']) ? trim((string)$item['status']) : '';
                                                    $no_bonus = ((int)($item['no_bonus'] ?? 0) === 1);
                                                    $is_approved_for_bonus = in_array($current_status, ['APD'], true);
                                                    $bonus_sent = ($rid > 0 && isset($bonus_sent_map[$rid]));
                                                    $bonus_sent_amount = $bonus_sent ? (int)($bonus_sent_map[$rid]['amount'] ?? 1500) : 0;
                                                    $bonus_sent_at = $bonus_sent ? (string)($bonus_sent_map[$rid]['sent_at'] ?? '') : '';
                                                    // 審核結果改成下拉選單後，不再顯示「修改結果」按鈕（避免混淆）
                                                    $show_update_btn = false;
                                                ?>

                                                <?php if (!empty($can_send_bonus) && $is_approved_for_bonus && !$no_bonus): ?>
                                                    <?php if ($bonus_sent): ?>
                                                        <span class="btn-view" style="background:#f6ffed; border-color:#b7eb8f; color:#389e0d; cursor: default;">
                                                            <i class="fas fa-check-circle"></i> 已發送 $<?php echo number_format((int)$bonus_sent_amount); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <button type="button"
                                                            class="btn-view"
                                                            style="background:#52c41a; color:white; border-color:#52c41a;"
                                                            onclick="sendBonus(<?php echo (int)$rid; ?>, this)">
                                                            <i class="fas fa-coins"></i> 發送獎金
                                                        </button>
                                                    <?php endif; ?>
                                                <?php endif; ?>

                                                <?php if ($show_update_btn): ?>
                                                <button type="button" 
                                                   class="btn-view" 
                                                   style="background: #1677ff; color: white; border-color: #1677ff;"
                                                   onclick="openUpdateReviewResultModal(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['student_name']); ?>')">
                                                    <i class="fas fa-edit"></i> 修改結果
                                                </button>
                                                <?php endif; ?>
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
                                                <?php 
                                                    // 檢查是否需要顯示修改結果按鈕（僅在審核結果為需人工確認時）
                                                    $rid = (int)($item['id'] ?? 0);
                                                    $current_status = isset($item['status']) ? trim((string)$item['status']) : '';
                                                    $auto_review = isset($item['auto_review_result']) ? trim((string)$item['auto_review_result']) : '';
                                                    if ($auto_review === '人工確認') $auto_review = '需人工確認';
                                                    $is_approved_for_bonus = in_array($current_status, ['APD'], true);
                                                    $bonus_sent = ($rid > 0 && isset($bonus_sent_map[$rid]));
                                                    $bonus_sent_amount = $bonus_sent ? (int)($bonus_sent_map[$rid]['amount'] ?? 1500) : 0;
                                                    $no_bonus = ((int)($item['no_bonus'] ?? 0) === 1);
                                                    // 審核結果改成下拉選單後，不再顯示「修改結果」按鈕（避免混淆）
                                                    $show_update_btn = false;
                                                ?>

                                                <?php if (!empty($can_send_bonus) && $is_approved_for_bonus && !$no_bonus): ?>
                                                    <?php if ($bonus_sent): ?>
                                                        <span class="btn-view" style="background:#f6ffed; border-color:#b7eb8f; color:#389e0d; cursor: default;">
                                                            <i class="fas fa-check-circle"></i> 已發送 $<?php echo number_format((int)$bonus_sent_amount); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <button type="button"
                                                            class="btn-view"
                                                            style="background:#52c41a; color:white; border-color:#52c41a;"
                                                            onclick="sendBonus(<?php echo (int)$rid; ?>, this)">
                                                            <i class="fas fa-coins"></i> 發送獎金
                                                        </button>
                                                    <?php endif; ?>
                                                <?php endif; ?>

                                                <?php if ($show_update_btn): ?>
                                                <button type="button" 
                                                   class="btn-view" 
                                                   style="background: #1677ff; color: white; border-color: #1677ff;"
                                                   onclick="openUpdateReviewResultModal(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['student_name']); ?>')">
                                                    <i class="fas fa-edit"></i> 修改結果
                                                </button>
                                                <?php endif; ?>
                                                <button class="btn-view" style="background: #1890ff; color: white; border-color: #1890ff;" onclick="openAssignRecommendationModal(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['student_name']); ?>', <?php echo !empty($item['assigned_teacher_id']) ? $item['assigned_teacher_id'] : 'null'; ?>)">
                                                    <i class="fas fa-user-plus"></i> <?php echo !empty($item['assigned_teacher_id']) ? '重新分配' : '分配'; ?>
                                                </button>
                                            </div>
                                        </td>
                                        <?php else: ?>
                                        <td>
                                            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                                <button type="button" 
                                                   class="btn-view" 
                                                   id="detail-btn-<?php echo $item['id']; ?>"
                                                   onclick="toggleDetail(<?php echo $item['id']; ?>)">
                                                    <i class="fas fa-eye"></i> <span class="btn-text">查看詳情</span>
                                                </button>
                                                <?php 
                                                    // 檢查是否需要顯示修改結果按鈕（僅在審核結果為需人工確認時）
                                                    $rid = (int)($item['id'] ?? 0);
                                                    $current_status = isset($item['status']) ? trim((string)$item['status']) : '';
                                                    $auto_review = isset($item['auto_review_result']) ? trim((string)$item['auto_review_result']) : '';
                                                    if ($auto_review === '人工確認') $auto_review = '需人工確認';
                                                    $is_approved_for_bonus = in_array($current_status, ['APD'], true);
                                                    $bonus_sent = ($rid > 0 && isset($bonus_sent_map[$rid]));
                                                    $bonus_sent_amount = $bonus_sent ? (int)($bonus_sent_map[$rid]['amount'] ?? 1500) : 0;
                                                    $no_bonus = ((int)($item['no_bonus'] ?? 0) === 1);
                                                    // 審核結果改成下拉選單後，不再顯示「修改結果」按鈕（避免混淆）
                                                    $show_update_btn = false;
                                                ?>

                                                <?php if (!empty($can_send_bonus) && $is_approved_for_bonus && !$no_bonus): ?>
                                                    <?php if ($bonus_sent): ?>
                                                        <span class="btn-view" style="background:#f6ffed; border-color:#b7eb8f; color:#389e0d; cursor: default;">
                                                            <i class="fas fa-check-circle"></i> 已發送 $<?php echo number_format((int)$bonus_sent_amount); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <button type="button"
                                                            class="btn-view"
                                                            style="background:#52c41a; color:white; border-color:#52c41a;"
                                                            onclick="sendBonus(<?php echo (int)$rid; ?>, this)">
                                                            <i class="fas fa-coins"></i> 發送獎金
                                                        </button>
                                                    <?php endif; ?>
                                                <?php endif; ?>

                                                <?php if ($show_update_btn): ?>
                                                <button type="button" 
                                                   class="btn-view" 
                                                   style="background: #1677ff; color: white; border-color: #1677ff;"
                                                   onclick="openUpdateReviewResultModal(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['student_name']); ?>')">
                                                    <i class="fas fa-edit"></i> 修改結果
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                    <tr id="detail-<?php echo $item['id']; ?>" class="detail-row" style="display: none;">
                                        <?php
                                            $detail_colspan = (($is_admission_center && !$is_teacher_user) || $is_department_user) ? 9 : 8;
                                            if (!$can_show_review_result_column) $detail_colspan -= 1;
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

    <!-- 修改審核結果彈出視窗 -->
    <?php if ($can_view_review_result): ?>
    <div id="updateReviewResultModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>修改審核結果</h3>
                <span class="close" onclick="closeUpdateReviewResultModal()">&times;</span>
            </div>
            <div class="modal-body">
                <p>被推薦人：<span id="updateReviewResultStudentName"></span></p>
                <div class="teacher-list">
                    <h4>選擇審核結果：</h4>
                    <div class="teacher-options">
                        <label class="teacher-option">
                            <input type="radio" name="review_result" value="通過">
                            <div class="teacher-info">
                                <strong>通過</strong>
                                <span class="teacher-dept">該被推薦人符合推薦條件</span>
                            </div>
                        </label>
                        <label class="teacher-option">
                            <input type="radio" name="review_result" value="不通過">
                            <div class="teacher-info">
                                <strong>不通過</strong>
                                <span class="teacher-dept">該被推薦人不符合推薦條件</span>
                            </div>
                        </label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-cancel" onclick="closeUpdateReviewResultModal()">取消</button>
                <button class="btn-confirm" onclick="updateReviewResult()">確認修改</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 查看審核結果彈出視窗 -->
    <?php if ($can_view_review_result): ?>
    <div id="reviewCriteriaModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>查看審核結果</h3>
                <span class="close" onclick="closeReviewCriteriaModal()">&times;</span>
            </div>
            <div class="modal-body">
                <p>被推薦人：<span id="reviewCriteriaStudentName"></span></p>
                <div id="reviewCriteriaList" style="display:flex; flex-direction:column; gap:8px;"></div>
                <div id="reviewCriteriaSelectWrap" style="margin-top:16px;">
                    <label for="reviewCriteriaSelect" style="display:block; margin-bottom:6px;">請選擇審核結果</label>
                    <select id="reviewCriteriaSelect" class="search-select" style="min-width: 200px;">
                        <option value="" selected>請選擇</option>
                        <option value="通過">通過</option>
                        <option value="不通過">不通過</option>
                        <option value="需人工審查">需人工審查</option>
                    </select>
                </div>
                <div id="reviewCriteriaSelectedWrap" style="margin-top:16px; display:none;">
                    <span>審核結果為：</span>
                    <span class="review-badge" id="reviewCriteriaSelectedBadge"></span>
                </div>
                <div style="margin-top:16px; font-size:16px; color:#595959;">流程進度</div>
                <div id="reviewProgressBar" class="review-progress" style="margin-top:8px;"></div>
            </div>
            <div class="modal-footer">
                <button class="btn-cancel" onclick="closeReviewCriteriaModal()">取消</button>
                <button class="btn-confirm" onclick="confirmReviewCriteriaUpdate()">確定</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Gmail 預覽彈出視窗 -->
    <div id="gmailPreviewModal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 900px;">
            <div class="modal-header">
                <h3>寄送 Gmail 預覽</h3>
                <span class="close" onclick="closeGmailPreviewModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div id="gmailPreviewList"></div>
            </div>
            <div class="modal-footer">
                <button class="btn-cancel" onclick="closeGmailPreviewModal()">取消</button>
                <button class="btn-confirm" id="gmailPreviewSend">確認發送</button>
            </div>
        </div>
    </div>

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
        const interestFilter = document.getElementById('interestFilter');
        const academicYearFilter = document.getElementById('academicYearFilter');
        const viewModeSelect = document.getElementById('viewModeSelect');
        const gmailSendToggle = document.getElementById('gmailSendToggle');
        const gmailSendConfirm = document.getElementById('gmailSendConfirm');
        const gmailSendCancel = document.getElementById('gmailSendCancel');
        const gmailSelectAll = document.getElementById('gmailSelectAll');
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
                const interestVal = (interestFilter && interestFilter.value) ? interestFilter.value : '';
                const yearVal = (academicYearFilter && academicYearFilter.value) ? academicYearFilter.value : '';
                if (!tbody) return;

                filteredRows = allRows.filter(row => {
                    // 1) 審核結果篩選（用 data-review-result）
                    if (reviewVal) {
                        const rr = row.dataset ? (row.dataset.reviewResult || '') : '';
                        if (rr !== reviewVal) return false;
                    }

                    // 2) 推薦人科系（recommender_department_code）篩選
                    if (interestVal) {
                        const deptCode = row.dataset ? (row.dataset.recommenderDept || '') : '';
                        if (String(deptCode) !== String(interestVal)) return false;
                    }

                    // 3) 學年度（academic_year）篩選
                    if (yearVal) {
                        const yy = row.dataset ? (row.dataset.academicYear || '') : '';
                        if (String(yy) !== String(yearVal)) return false;
                    }

                    // 4) 關鍵字搜尋（全欄位文字）
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
            // 下拉選單：變更即套用
            if (interestFilter) interestFilter.addEventListener('change', applyFilters);
            if (academicYearFilter) academicYearFilter.addEventListener('change', applyFilters);

            // 清除：重置條件並顯示全部
            if (btnClear) {
                btnClear.addEventListener('click', function() {
                    if (searchInput) searchInput.value = '';
                    if (reviewFilter) reviewFilter.value = '';
                    if (interestFilter) interestFilter.value = '';
                    if (academicYearFilter) academicYearFilter.value = '';
                    applyFilters();
                });
            }
        }

        // 審核狀態（view=all/pass/manual/fail）：用 URL 參數切換（後端會依 view_mode 載入資料）
        if (viewModeSelect) {
            viewModeSelect.addEventListener('change', function() {
                try {
                    const v = (viewModeSelect.value || 'all');
                    const url = new URL(window.location.href);
                    url.searchParams.set('view', v);
                    // 切換 view 後回到第一頁（若有 hash 或其他參數也會保留）
                    window.location.href = url.toString();
                } catch (e) {
                    // 若瀏覽器不支援 URL 物件，退回最簡單導向
                    window.location.href = '?view=' + encodeURIComponent(viewModeSelect.value || 'all');
                }
            });
        }

        const isManualGmailEligibleRow = (row) => {
            if (!row) return false;
            const review = String((row.dataset && row.dataset.reviewResult) ? row.dataset.reviewResult : '').trim();
            return review === '已通過初審（待科主任審核）' || review === '初審未通過（待科主任審核）';
        };

        const setGmailMode = (enabled) => {
            const cells = document.querySelectorAll('.gmail-select-cell');
            cells.forEach(cell => {
                cell.style.display = enabled ? 'table-cell' : 'none';
            });
            if (gmailSendConfirm) gmailSendConfirm.style.display = enabled ? 'inline-block' : 'none';
            if (gmailSendCancel) gmailSendCancel.style.display = enabled ? 'inline-block' : 'none';
            if (enabled) {
                const rows = allRows.filter(r => r.style.display !== 'none');
                rows.forEach(row => {
                    const chk = row.querySelector('.gmail-select-row');
                    if (!chk) return;
                    const canSend = isManualGmailEligibleRow(row);
                    chk.disabled = !canSend;
                    chk.checked = false;
                    chk.title = canSend ? '' : '此狀態為系統自動寄送，不需人工寄送';
                });
            }
            if (!enabled) {
                const rowChecks = document.querySelectorAll('.gmail-select-row');
                rowChecks.forEach(chk => {
                    chk.checked = false;
                    chk.disabled = false;
                    chk.title = '';
                });
                if (gmailSelectAll) gmailSelectAll.checked = false;
            }
        };

        if (gmailSendToggle) {
            gmailSendToggle.addEventListener('click', function() {
                const interestVal = (interestFilter && interestFilter.value) ? interestFilter.value : '';
                if (interestVal) {
                    const ids = collectVisibleGmailIds();
                    if (ids.length === 0) {
                        alert('目前篩選結果沒有可寄送的資料');
                        return;
                    }
                    setGmailMode(false);
                    openGmailPreviewModal(ids, true);
                    return;
                }
                const isEnabled = gmailSendConfirm && gmailSendConfirm.style.display !== 'none';
                setGmailMode(!isEnabled);
            });
        }

        if (gmailSendCancel) {
            gmailSendCancel.addEventListener('click', function() {
                setGmailMode(false);
            });
        }

        if (gmailSelectAll) {
            gmailSelectAll.addEventListener('change', function() {
                const rows = allRows.filter(r => r.style.display !== 'none');
                rows.forEach(row => {
                    const chk = row.querySelector('.gmail-select-row');
                    if (chk && !chk.disabled) chk.checked = gmailSelectAll.checked;
                });
            });
        }

        const collectVisibleGmailIds = () => {
            if (filteredRows && filteredRows.length > 0) {
                return filteredRows
                    .filter(r => r.style.display !== 'none' && isManualGmailEligibleRow(r))
                    .map(r => (r.querySelector('.gmail-select-row') || {}).value || '')
                    .filter(Boolean);
            }
            if (!table) return [];
            const bodyRows = Array.from(table.querySelectorAll('tbody tr'));
            return bodyRows
                .filter(r => !r.classList.contains('detail-row') && r.style.display !== 'none' && isManualGmailEligibleRow(r))
                .map(r => (r.querySelector('.gmail-select-row') || {}).value || '')
                .filter(Boolean);
        };

        if (gmailSendConfirm) {
            gmailSendConfirm.addEventListener('click', function() {
                const checked = Array.from(document.querySelectorAll('.gmail-select-row:checked')).filter(chk => !chk.disabled);
                const ids = checked.map(chk => String(chk.value)).filter(Boolean);
                if (ids.length === 0) {
                    alert('請先勾選要寄送的資料');
                    return;
                }
                openGmailPreviewModal(ids);
            });
        }
    });

    let gmailPreviewIds = [];
    let gmailPreviewFiles = {};
    let gmailPreviewSingle = false;

    function htmlToText(html) {
        const div = document.createElement('div');
        div.innerHTML = html || '';
        return (div.textContent || div.innerText || '').trim();
    }

    function renderGmailPreviewItem(container, email, index) {
        const item = document.createElement('div');
        item.className = 'gmail-preview-item';
        item.dataset.index = String(index);
        item.dataset.recKey = email.rec_key || '';

        const title = document.createElement('div');
        title.className = 'gmail-preview-title';
        const recName = email.rec_name || '';
        const recSid = email.rec_sid ? `（${email.rec_sid}）` : '';
        title.textContent = `推薦人：${recName}${recSid}`;
        item.appendChild(title);

        const subjectWrap = document.createElement('div');
        subjectWrap.className = 'gmail-preview-field';
        subjectWrap.innerHTML = '<label>主旨</label>';
        const subjectInput = document.createElement('input');
        subjectInput.type = 'text';
        subjectInput.className = 'gmail-subject';
        subjectInput.value = email.subject || '';
        subjectWrap.appendChild(subjectInput);
        item.appendChild(subjectWrap);

        const bodyWrap = document.createElement('div');
        bodyWrap.className = 'gmail-preview-field';
        bodyWrap.innerHTML = '<label>信件內容（HTML）</label>';
        const bodyInput = document.createElement('textarea');
        bodyInput.className = 'gmail-body';
        bodyInput.value = email.body || '';
        bodyWrap.appendChild(bodyInput);
        item.appendChild(bodyWrap);

        const attachWrap = document.createElement('div');
        attachWrap.className = 'gmail-attachments';
        const includeLabel = document.createElement('label');
        const includeCheckbox = document.createElement('input');
        includeCheckbox.type = 'checkbox';
        includeCheckbox.className = 'gmail-include-generated';
        includeCheckbox.checked = !!email.include_generated;
        includeLabel.appendChild(includeCheckbox);
        const attachmentExt = email.attachment_ext || (email.xlsx_supported === false ? 'xls' : 'xlsx');
        includeLabel.appendChild(document.createTextNode(' 附加系統 ' + (attachmentExt.toUpperCase()) + '：' + (email.attachment_name || ('推薦內容.' + attachmentExt))));
        if (email.xlsx_supported === false) {
            const warn = document.createElement('span');
            warn.style.color = '#fa8c16';
            warn.style.marginLeft = '8px';
            warn.textContent = '（伺服器未啟用 ZipArchive，改附 Excel .xls）';
            includeLabel.appendChild(warn);
        }
        attachWrap.appendChild(includeLabel);

        const fileInput = document.createElement('input');
        fileInput.type = 'file';
        fileInput.multiple = true;
        fileInput.className = 'gmail-file-input';
        attachWrap.appendChild(fileInput);

        const fileList = document.createElement('div');
        fileList.className = 'gmail-file-list';
        attachWrap.appendChild(fileList);

        if (!gmailPreviewFiles[index]) gmailPreviewFiles[index] = [];

        const renderFileList = () => {
            fileList.innerHTML = '';
            (gmailPreviewFiles[index] || []).forEach((file, i) => {
                const row = document.createElement('div');
                row.className = 'gmail-file-item';
                const nameSpan = document.createElement('span');
                nameSpan.textContent = file.name;
                const rm = document.createElement('button');
                rm.type = 'button';
                rm.className = 'gmail-file-remove';
                rm.textContent = '移除';
                rm.addEventListener('click', () => {
                    gmailPreviewFiles[index].splice(i, 1);
                    renderFileList();
                });
                row.appendChild(nameSpan);
                row.appendChild(rm);
                fileList.appendChild(row);
            });
        };

        fileInput.addEventListener('change', (e) => {
            const files = Array.from(e.target.files || []);
            if (files.length > 0) {
                gmailPreviewFiles[index] = gmailPreviewFiles[index].concat(files);
                renderFileList();
            }
            fileInput.value = '';
        });

        item.appendChild(attachWrap);
        container.appendChild(item);
    }

    function openGmailPreviewModal(ids, singleMail) {
        const modal = document.getElementById('gmailPreviewModal');
        const list = document.getElementById('gmailPreviewList');
        if (!modal || !list) return;

        gmailPreviewIds = ids || [];
        gmailPreviewFiles = {};
        gmailPreviewSingle = !!singleMail;
        list.innerHTML = '<div style="color:#666;">載入中...</div>';
        modal.style.display = 'flex';

                fetch('send_recommendation_gmail.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=preview&ids=' + encodeURIComponent(gmailPreviewIds.join(',')) + '&single_mail=' + (gmailPreviewSingle ? '1' : '0'),
            credentials: 'same-origin'
        })
        .then(r => r.json())
        .then(data => {
            if (!data || !data.success) {
                throw new Error((data && data.message) ? data.message : '載入預覽失敗');
            }
            list.innerHTML = '';
            const emails = data.emails || [];
            if (emails.length === 0) {
                list.innerHTML = '<div style="color:#666;">沒有可寄送的資料（僅待科主任審核可人工寄送）。審核完成（可發獎金）由系統自動寄送。</div>';
                return;
            }
            emails.forEach((email, idx) => {
                renderGmailPreviewItem(list, email, idx);
            });
        })
        .catch(err => {
            list.innerHTML = '<div style="color:#cf1322;">載入失敗：' + (err && err.message ? err.message : '未知錯誤') + '</div>';
        });
    }

    function closeGmailPreviewModal() {
        const modal = document.getElementById('gmailPreviewModal');
        if (modal) modal.style.display = 'none';
        const list = document.getElementById('gmailPreviewList');
        if (list) list.innerHTML = '';
        gmailPreviewIds = [];
        gmailPreviewFiles = {};
        gmailPreviewSingle = false;
    }

    const gmailPreviewSend = document.getElementById('gmailPreviewSend');
    if (gmailPreviewSend) {
        gmailPreviewSend.addEventListener('click', function() {
            const list = document.getElementById('gmailPreviewList');
            if (!list) return;
            const items = Array.from(list.querySelectorAll('.gmail-preview-item'));
            if (items.length === 0) {
                alert('沒有可寄送的內容');
                return;
            }

            const emails = items.map(item => {
                const idx = parseInt(item.dataset.index, 10);
                const recKey = item.dataset.recKey || '';
                const subject = (item.querySelector('.gmail-subject') || {}).value || '';
                const body = (item.querySelector('.gmail-body') || {}).value || '';
                const includeGenerated = (item.querySelector('.gmail-include-generated') || {}).checked;
                const altBody = body;
                return {
                    rec_key: recKey,
                    subject,
                    body,
                    alt_body: altBody,
                    include_generated: !!includeGenerated
                };
            });

            const fd = new FormData();
            fd.append('action', 'send_custom');
            fd.append('ids', (gmailPreviewIds || []).join(','));
            fd.append('emails', JSON.stringify(emails));
            fd.append('single_mail', gmailPreviewSingle ? '1' : '0');

            Object.keys(gmailPreviewFiles).forEach(idx => {
                (gmailPreviewFiles[idx] || []).forEach(file => {
                    fd.append(`custom_files[${idx}][]`, file, file.name);
                });
            });

            fetch('send_recommendation_gmail.php', {
                method: 'POST',
                body: fd,
                    credentials: 'same-origin'
                })
                .then(r => r.json())
                .then(data => {
                    if (!data || !data.success) {
                        throw new Error((data && data.message) ? data.message : '寄送失敗');
                    }
                    alert(data.message || '寄送完成');
                closeGmailPreviewModal();
                })
                .catch(err => {
                    alert('寄送失敗：' + (err && err.message ? err.message : '未知錯誤'));
                });
            });
    }

    const gmailPreviewModal = document.getElementById('gmailPreviewModal');
    if (gmailPreviewModal) {
        gmailPreviewModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeGmailPreviewModal();
            }
        });
    }
    
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

    // 修改審核結果相關變數
    let currentUpdateReviewResultId = null;

    // 開啟修改審核結果彈出視窗
    function openUpdateReviewResultModal(recommendationId, studentName) {
        currentUpdateReviewResultId = recommendationId;
        document.getElementById('updateReviewResultStudentName').textContent = studentName;
        document.getElementById('updateReviewResultModal').style.display = 'flex';
        
        // 清除之前的選擇
        const radioButtons = document.querySelectorAll('input[name="review_result"]');
        radioButtons.forEach(radio => {
            radio.checked = false;
        });
    }

    // 關閉修改審核結果彈出視窗
    function closeUpdateReviewResultModal() {
        document.getElementById('updateReviewResultModal').style.display = 'none';
        currentUpdateReviewResultId = null;
    }

    // 更新審核結果
    function updateReviewResult() {
        const selectedResult = document.querySelector('input[name="review_result"]:checked');
        
        if (!selectedResult) {
            alert('請選擇審核結果');
            return;
        }

        const reviewResult = selectedResult.value;
        
        // 發送AJAX請求
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'update_review_result.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4) {
                if (xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            alert('審核結果更新成功！');
                            closeUpdateReviewResultModal();
                            location.reload();
                        } else {
                            alert('更新失敗：' + (response.message || '未知錯誤'));
                        }
                    } catch (e) {
                        alert('回應格式錯誤：' + xhr.responseText);
                    }
                } else {
                    alert('請求失敗，狀態碼：' + xhr.status);
                }
            }
        };
        
        xhr.send('recommendation_id=' + encodeURIComponent(currentUpdateReviewResultId) + 
                 '&review_result=' + encodeURIComponent(reviewResult));
    }

    // 查看審核結果（彈出視窗顯示三項條件 + 審核結果下拉）
    let currentReviewCriteriaId = null;
    let currentReviewCriteriaValue = '';
    function openReviewCriteriaModal(recommendationId, studentName, currentReview, hasDuplicate, hasEnrollment, studentStatus, noBonus, nsbiFound, matchName, matchSchool, matchPhone, proofEvidence) {
        const modal = document.getElementById('reviewCriteriaModal');
        const nameEl = document.getElementById('reviewCriteriaStudentName');
        const listEl = document.getElementById('reviewCriteriaList');
        const progressEl = document.getElementById('reviewProgressBar');
        const selectEl = document.getElementById('reviewCriteriaSelect');
        const selectWrap = document.getElementById('reviewCriteriaSelectWrap');
        const selectedWrap = document.getElementById('reviewCriteriaSelectedWrap');
        const selectedBadge = document.getElementById('reviewCriteriaSelectedBadge');
        if (!modal || !nameEl || !listEl || !selectEl || !selectWrap || !selectedWrap || !selectedBadge) return;

        currentReviewCriteriaId = recommendationId;
        currentReviewCriteriaValue = '';
        nameEl.textContent = studentName || '';
        listEl.innerHTML = '';
        if (progressEl) progressEl.innerHTML = '';

        const addLine = (text, isFail, colorOverride) => {
            const div = document.createElement('div');
            div.textContent = text;
            div.style.fontWeight = '400';
            div.style.fontSize = '16px';
            if (colorOverride) {
                div.style.color = colorOverride;
            } else {
                div.style.color = isFail ? '#cf1322' : '#389e0d';
            }
            listEl.appendChild(div);
        };

        const renderProgress = (statusLabel) => {
            if (!progressEl) return;
            const steps = [
                '招生中心尚未審核',
                '初審通過待科主任審核',
                '科主任審核中',
                '科主任審核通過\\不通過',
                '可發放獎金'
            ];
            if ((statusLabel || '').trim() === '初審未通過') {
                steps[1] = '初審不通過待科主任審核';
            }
            if ((statusLabel || '').trim() === '審核完成（可發獎金）') {
                steps[3] = '科主任審核通過';
            } else if ((statusLabel || '').trim() === '科主任審核未通過') {
                steps[3] = '科主任審核不通過';
            }
            let activeIndex = 0;
            let doneIndex = -1;

            switch ((statusLabel || '').trim()) {
                case '尚未審核':
                    activeIndex = 0; doneIndex = -1; break;
                case '已通過初審（待科主任審核）':
                    activeIndex = 1; doneIndex = 0; break;
                case '初審未通過（待科主任審核）':
                    activeIndex = 1; doneIndex = 0; break;
                case '科主任審核中':
                    activeIndex = 2; doneIndex = 1; break;
                case '科主任審核未通過':
                    activeIndex = 3; doneIndex = 2; break;
                case '審核完成（可發獎金）':
                    activeIndex = -1; doneIndex = 4; break;
                case '初審未通過':
                    activeIndex = 1; doneIndex = 0; break;
                default:
                    activeIndex = 0; doneIndex = -1; break;
            }

            steps.forEach((label, idx) => {
                const step = document.createElement('div');
                step.className = 'review-progress-step';
                if (idx <= doneIndex) step.classList.add('done');
                if (idx === activeIndex) step.classList.add('active');
                step.textContent = label;
                progressEl.appendChild(step);
                if (idx < steps.length - 1) {
                    const arrow = document.createElement('span');
                    arrow.className = 'review-progress-arrow';
                    arrow.textContent = '→';
                    progressEl.appendChild(arrow);
                }
            });
        };

        const normalizeFilePath = (raw) => {
            const v = String(raw || '').replace(/\\/g, '/').replace(/^\/+/, '');
            return v;
        };

        // 1) 重複推薦
        if (hasDuplicate) {
            addLine('此被推薦人已被人推薦', true);
        } else {
            addLine('未發現重複推薦', false);
        }

        // 2) 已填寫就讀意願表單
        if (hasEnrollment) {
            addLine('此被推薦人已填寫過就讀意願表單', true);
        } else {
            addLine('未填寫就讀意願表單', false);
        }

        // 3) 學生狀態
        if (noBonus) {
            const statusText = studentStatus ? ('學生狀態為' + studentStatus + '，無獎金') : '學生狀態為休學/退學，無獎金';
            addLine(statusText, true);
        } else if (!studentStatus) {
            addLine('學生狀態：學生尚未入學', true);
        } else {
            addLine('學生狀態正常（可發獎金）', false);
        }

        // 4) 新生基本資料比對（姓名/學校/電話）
        if (!nsbiFound) {
            addLine('新生基本資料未建立，未進行姓名/學校/電話比對', false, '#595959');
        } else {
            addLine('姓名比對：' + (matchName ? '一致' : '不一致'), !matchName);
            addLine('學校比對：' + (matchSchool ? '一致' : '不一致'), !matchSchool);
            addLine('電話比對：' + (matchPhone ? '一致' : '不一致'), !matchPhone);
        }

        // 5) 證明文件
        if (proofEvidence) {
            const div = document.createElement('div');
            div.style.fontWeight = '400';
            div.style.fontSize = '16px';
            div.style.color = '#595959';
            div.appendChild(document.createTextNode('證明文件：'));
            const a = document.createElement('a');
            const path = normalizeFilePath(proofEvidence);
            a.href = encodeURI('/Topics-frontend/frontend/' + path);
            a.target = '_blank';
            a.rel = 'noopener';
            a.style.color = '#1890ff';
            a.style.textDecoration = 'none';
            a.textContent = '查看文件';
            div.appendChild(a);
            listEl.appendChild(div);
        } else {
            addLine('證明文件：無', false, '#8c8c8c');
        }

        const normalized = (currentReview || '').trim();
        renderProgress(normalized);
        if (normalized === '尚未審核') {
            // 僅尚未審核可調整
            currentReviewCriteriaValue = '';
            selectEl.value = '';
            selectWrap.style.display = 'block';
            selectedWrap.style.display = 'none';
            selectedBadge.className = 'review-badge';
            selectedBadge.textContent = '';
        } else if (
            normalized === '已通過初審（待科主任審核）' ||
            normalized === '初審未通過' ||
            normalized === '科主任審核中' ||
            normalized === '科主任審核未通過' ||
            normalized === '審核完成（可發獎金）'
        ) {
            // 以上狀態不可再更改
            currentReviewCriteriaValue = normalized;
            selectEl.value = '';
            selectWrap.style.display = 'none';
            selectedWrap.style.display = 'block';
            let badgeClass = 'review-badge';
            if (normalized === '審核完成（可發獎金）' || normalized === '已通過初審（待科主任審核）') badgeClass += ' pass';
            else if (normalized === '初審未通過' || normalized === '科主任審核未通過') badgeClass += ' fail';
            else badgeClass += ' manual';
            selectedBadge.className = badgeClass;
            selectedBadge.textContent = normalized;
        } else {
            selectEl.value = '';
            selectWrap.style.display = 'block';
            selectedWrap.style.display = 'none';
            selectedBadge.className = 'review-badge';
            selectedBadge.textContent = '';
        }

        modal.style.display = 'flex';
    }

    function closeReviewCriteriaModal() {
        const modal = document.getElementById('reviewCriteriaModal');
        if (modal) modal.style.display = 'none';
        currentReviewCriteriaId = null;
        currentReviewCriteriaValue = '';
    }

    function confirmReviewCriteriaUpdate() {
        const selectEl = document.getElementById('reviewCriteriaSelect');
        if (!selectEl) return;
        const reviewResult = selectEl.value;
        if (!reviewResult) {
            alert('請選擇審核結果');
            return;
        }
        if (!currentReviewCriteriaId) return;
        if (reviewResult === currentReviewCriteriaValue) {
            alert('目前已是該審核結果，如需修改請選擇其他選項');
            return;
        }
        if (!confirm('確認要設定為「' + reviewResult + '」？')) return;

        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'update_review_result.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4) {
                if (xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            alert('審核結果更新成功！');
                            closeReviewCriteriaModal();
                            location.reload();
                        } else {
                            const msg = (response.message || '').trim();
                            if (msg.indexOf('沒有記錄被更新') > -1) {
                                closeReviewCriteriaModal();
                                location.reload();
                            } else {
                                alert('更新失敗：' + (response.message || '未知錯誤'));
                            }
                        }
                    } catch (e) {
                        alert('回應格式錯誤：' + xhr.responseText);
                    }
                } else {
                    alert('請求失敗，狀態碼：' + xhr.status);
                }
            }
        };

        xhr.send('recommendation_id=' + encodeURIComponent(currentReviewCriteriaId) +
                 '&review_result=' + encodeURIComponent(reviewResult));
    }

    // 發送獎金（同名且通過者由後端自動平分）
    function sendBonus(recommendationId, btnEl) {
        const rid = parseInt(recommendationId || 0, 10) || 0;
        if (!rid) return;
        if (!confirm('確認要發送此筆獎金？（同名且通過者會自動平分）')) return;

        if (btnEl) {
            btnEl.disabled = true;
            btnEl.style.opacity = '0.7';
        }

        fetch('send_bonus.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'recommendation_id=' + encodeURIComponent(String(rid)),
            credentials: 'same-origin'
        })
        .then(r => r.json())
        .then(data => {
            if (!data || !data.success) {
                throw new Error((data && data.message) ? data.message : '發送失敗');
            }

            const amount = (data.amount !== undefined && data.amount !== null) ? parseInt(data.amount, 10) : null;
            const splitCount = (data.split_count !== undefined && data.split_count !== null) ? parseInt(data.split_count, 10) : null;
            const studentName = (data.student_name || '').trim();

            let msg = '獎金已標記為發送';
            if (amount !== null && !isNaN(amount)) {
                msg += `：$${amount.toLocaleString()}`;
            }
            if (splitCount && splitCount > 1) {
                msg += `（同名通過共 ${splitCount} 人，已自動平分）`;
            }
            if (studentName) {
                msg += `\n被推薦人：${studentName}`;
            }

            alert(msg);
            // 直接刷新讓「已發送」狀態與金額顯示更新
            location.reload();
        })
        .catch(err => {
            alert('發送失敗：' + (err && err.message ? err.message : '未知錯誤'));
            if (btnEl) {
                btnEl.disabled = false;
                btnEl.style.opacity = '';
            }
        });
    }

    // 點擊修改審核結果彈出視窗外部關閉
    const updateReviewResultModal = document.getElementById('updateReviewResultModal');
    if (updateReviewResultModal) {
        updateReviewResultModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeUpdateReviewResultModal();
            }
        });
    }

    // 點擊查看審核結果彈出視窗外部關閉
    const reviewCriteriaModal = document.getElementById('reviewCriteriaModal');
    if (reviewCriteriaModal) {
        reviewCriteriaModal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeReviewCriteriaModal();
            }
        });
    }
    </script>
</body>
</html>

