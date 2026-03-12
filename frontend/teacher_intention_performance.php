<?php
/**
 * 教師經營成效分析（僅主任可見）
 * 區塊一：成效數據總覽
 * 區塊二：成功經營統計（入學成果）
 * 區塊三：未成功入學原因分析
 * 區塊四：低意願卻入學的學生
 */
require_once __DIR__ . '/session_config.php';
checkBackendLogin();

$config_path = '../../Topics-frontend/frontend/config.php';
if (!file_exists($config_path)) {
    $alt_paths = [
        __DIR__ . '/../../Topics-frontend/frontend/config.php',
        dirname(__DIR__) . '/../Topics-frontend/frontend/config.php'
    ];
    foreach ($alt_paths as $p) {
        if (file_exists($p)) { $config_path = $p; break; }
    }
}
if (!file_exists($config_path)) {
    die('找不到設定檔');
}
require_once $config_path;
require_once __DIR__ . '/includes/intention_change_log.php';

$user_role = $_SESSION['role'] ?? '';
$user_id = (int)($_SESSION['user_id'] ?? 0);
$role_map = ['主任' => 'DI', 'director' => 'DI', 'DI' => 'DI'];
$user_role = $role_map[$user_role] ?? $user_role;
$is_director = ($user_role === 'DI');

if (!$is_director || $user_id <= 0) {
    header('Location: index.php');
    exit;
}

$conn = getDatabaseConnection();
// 統一連線 collation，避免 new_student_basic_info (general_ci) 與其他表 (unicode_ci) 比對時報錯
$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
ensureIntentionChangeLogTable($conn);

// 主任科系（與 enrollment_list 一致）
$user_department_code = null;
$table_check = $conn->query("SHOW TABLES LIKE 'director'");
if ($table_check && $table_check->num_rows > 0) {
    $st = $conn->prepare("SELECT department FROM director WHERE user_id = ?");
    if ($st) {
        $st->bind_param("i", $user_id);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        if ($r && !empty(trim($r['department'] ?? ''))) $user_department_code = trim($r['department']);
        $st->close();
    }
}
if (!$user_department_code) {
    $st = $conn->prepare("SELECT department FROM teacher WHERE user_id = ?");
    if ($st) {
        $st->bind_param("i", $user_id);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        if ($r && !empty(trim($r['department'] ?? ''))) $user_department_code = trim($r['department']);
        $st->close();
    }
}

$current_month = (int)date('m');
$current_year = (int)date('Y');
$this_year_grad = ($current_month >= 8) ? $current_year + 1 : $current_year;

// 分屆：與 activity_records 就讀意願統計分析一致，使用民國學年度（屆別）
// roc_year=0 或未帶 = 當年度(應屆+國二+國一)；roc_year>0 = 單屆，graduation_year = roc_year + 1912
$selected_roc_year = isset($_GET['roc_year']) ? (int)$_GET['roc_year'] : 0;
$current_roc_year = $this_year_grad - 1912; // 國三應屆對應的民國學年（如 2026→114）

$dept_where = " AND (ec1.department_code = ? OR ec2.department_code = ? OR ec3.department_code = ? OR ei.assigned_department = ?)";
$bind_dept = [$user_department_code, $user_department_code, $user_department_code, $user_department_code];

if ($selected_roc_year > 0) {
    $year_where = " AND ei.graduation_year = ?";
    $bind_year = [$selected_roc_year + 1912];
    $bind_types = "ssssi";
} else {
    $year_where = " AND ei.graduation_year IN (?, ?, ?)";
    $bind_year = [$this_year_grad, $this_year_grad + 1, $this_year_grad + 2];
    $bind_types = "ssssiii";
}
$bind_params = array_merge($bind_dept, $bind_year);

// 可用屆別列表（民國學年），與 activity_records 就讀意願統計分析一致：114學年、113學年…
$history_years = [];
$base_from = "
FROM enrollment_intention ei
LEFT JOIN enrollment_choices ec1 ON ei.id = ec1.enrollment_id AND ec1.choice_order = 1
LEFT JOIN enrollment_choices ec2 ON ei.id = ec2.enrollment_id AND ec2.choice_order = 2
LEFT JOIN enrollment_choices ec3 ON ei.id = ec3.enrollment_id AND ec3.choice_order = 3
";
$scope_where = " WHERE 1=1 " . $dept_where . $year_where . " AND ei.assigned_teacher_id IS NOT NULL ";

// 成功經營統計／招生績效圖：只算「應屆」（已可入學的那屆），國二國一不算進成功入學
if ($selected_roc_year > 0) {
    $scope_where_perf = $scope_where;
    $bind_params_perf = $bind_params;
    $bind_types_perf = $bind_types;
} else {
    $year_where_perf = " AND ei.graduation_year = ?";
    $bind_year_perf = [$this_year_grad];
    $bind_types_perf = "ssssi";
    $scope_where_perf = " WHERE 1=1 " . $dept_where . $year_where_perf . " AND ei.assigned_teacher_id IS NOT NULL ";
    $bind_params_perf = array_merge($bind_dept, $bind_year_perf);
}

$perm_where_years = " WHERE 1=1 " . $dept_where . " AND ei.graduation_year IS NOT NULL AND ei.graduation_year < ?";
$years_sql = "SELECT DISTINCT (ei.graduation_year - 1) AS academic_year_start " . $base_from . $perm_where_years . " ORDER BY academic_year_start DESC";
$st_y = $conn->prepare($years_sql);
if ($st_y) {
    $st_y->bind_param("ssssi", ...array_merge($bind_dept, [$this_year_grad]));
    $st_y->execute();
    $res_y = $st_y->get_result();
    while ($row_y = $res_y->fetch_assoc()) {
        if (!empty($row_y['academic_year_start'])) {
            $history_years[] = (int)$row_y['academic_year_start'];
        }
    }
    $st_y->close();
}
// 轉成民國學年度並確保目前屆在列表中（與 activity_records 一致）
$available_roc_years = array_map(function ($start) { return (int)$start - 1911; }, $history_years);
if (!in_array($current_roc_year, $available_roc_years, true)) {
    $available_roc_years[] = $current_roc_year;
}
rsort($available_roc_years);

// 區塊一：總分配人數
$sql_total = "SELECT COUNT(DISTINCT ei.id) AS cnt " . $base_from . $scope_where;
$st = $conn->prepare($sql_total);
$st->bind_param($bind_types, ...$bind_params);
$st->execute();
$total_assigned = (int)($st->get_result()->fetch_assoc()['cnt'] ?? 0);
$st->close();

// 尚未聯絡數（有分配但無聯絡紀錄）
$sql_uncontacted = "SELECT COUNT(DISTINCT ei.id) AS cnt " . $base_from . "
LEFT JOIN (SELECT enrollment_id FROM enrollment_contact_logs GROUP BY enrollment_id) c ON c.enrollment_id = ei.id
" . str_replace("AND ei.assigned_teacher_id IS NOT NULL", "AND ei.assigned_teacher_id IS NOT NULL AND c.enrollment_id IS NULL", $scope_where);
$st = $conn->prepare($sql_uncontacted);
$st->bind_param($bind_types, ...$bind_params);
$st->execute();
$uncontacted = (int)($st->get_result()->fetch_assoc()['cnt'] ?? 0);
$st->close();

// 意願提升數：目前為高意願 且 曾有一筆變動為 (低/中/初次→高)
$sql_up = "SELECT COUNT(DISTINCT ei.id) AS cnt " . $base_from . "
INNER JOIN (SELECT DISTINCT enrollment_id FROM " . INTENTION_CHANGE_LOG_TABLE . " 
    WHERE new_level = 'high' AND (old_level IS NULL OR old_level IN ('low','medium'))) ch ON ch.enrollment_id = ei.id
" . $scope_where . " AND ei.intention_level = 'high'";
$st = $conn->prepare($sql_up);
$st->bind_param($bind_types, ...$bind_params);
$st->execute();
$upgraded_count = (int)($st->get_result()->fetch_assoc()['cnt'] ?? 0);
$st->close();

// 意願下降數：目前為低意願 且 曾有一筆變動為 (高→中/低)
$sql_down = "SELECT COUNT(DISTINCT ei.id) AS cnt " . $base_from . "
INNER JOIN (SELECT DISTINCT enrollment_id FROM " . INTENTION_CHANGE_LOG_TABLE . " 
    WHERE old_level = 'high' AND new_level IN ('low','medium')) ch ON ch.enrollment_id = ei.id
" . $scope_where . " AND ei.intention_level = 'low'";
$st = $conn->prepare($sql_down);
$st->bind_param($bind_types, ...$bind_params);
$st->execute();
$downgraded_count = (int)($st->get_result()->fetch_assoc()['cnt'] ?? 0);
$st->close();

// 績效／成功經營用教師名單：當年度時只算應屆，單屆時同主 scope
$sql_teachers_perf = "SELECT ei.assigned_teacher_id AS teacher_id, u.name AS teacher_name, u.username AS teacher_username,
    COUNT(DISTINCT ei.id) AS assigned_count
" . $base_from . "
LEFT JOIN user u ON ei.assigned_teacher_id = u.id
" . $scope_where_perf . "
GROUP BY ei.assigned_teacher_id, u.name, u.username
ORDER BY assigned_count DESC";
$st_p = $conn->prepare($sql_teachers_perf);
$st_p->bind_param($bind_types_perf, ...$bind_params_perf);
$st_p->execute();
$teacher_rows_perf = $st_p->get_result()->fetch_all(MYSQLI_ASSOC);
$st_p->close();

$teacher_success_map = [];
try {
    $t_ts = $conn->query("SHOW TABLES LIKE 'new_student_basic_info'");
    $has_nsbi_teacher = $t_ts && $t_ts->num_rows > 0;
} catch (Exception $e) {
    $has_nsbi_teacher = false;
}
if ($has_nsbi_teacher) {
    $ns_t = function($col) { return "CONVERT(COALESCE(ns.{$col},'') USING utf8mb4) COLLATE utf8mb4_unicode_ci"; };
    $t_sd_t = $conn->query("SHOW TABLES LIKE 'school_data'");
    $table_school_t = ($t_sd_t && $t_sd_t->num_rows > 0) ? 'school_data' : null;
    $school_join_t = '';
    $school_cond_t = "(" . $ns_t('previous_school') . " = CONVERT(COALESCE(ei.junior_high,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci OR ns.previous_school IS NULL OR TRIM(" . $ns_t('previous_school') . ") = '')";
    if ($table_school_t) {
        $school_join_t = " LEFT JOIN " . $table_school_t . " sd_t ON sd_t.school_code = ei.junior_high ";
        $school_cond_t = "(" . $ns_t('previous_school') . " = CONVERT(COALESCE(ei.junior_high,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci
            OR (sd_t.school_code IS NOT NULL AND " . $ns_t('previous_school') . " = CONVERT(COALESCE(sd_t.name,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci)
            OR ns.previous_school IS NULL OR TRIM(" . $ns_t('previous_school') . ") = '')";
    }
    $sql_teacher_success = "
        SELECT 
            ei.assigned_teacher_id AS teacher_id,
            COUNT(DISTINCT ei.id) AS success_count
        " . $base_from . "
        " . $school_join_t . "
        INNER JOIN new_student_basic_info ns
            ON TRIM(" . $ns_t('student_name') . ") = TRIM(CONVERT(COALESCE(ei.name,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci)
           AND (" . $school_cond_t . ")
           AND (
                (ei.phone1 IS NOT NULL AND ei.phone1 <> '' AND TRIM(REPLACE(REPLACE(REPLACE(REPLACE(" . $ns_t('mobile') . ", ' ', ''), '-', ''), '(', ''), ')', '')) = TRIM(REPLACE(REPLACE(REPLACE(REPLACE(CONVERT(COALESCE(ei.phone1,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci, ' ', ''), '-', ''), '(', ''), ')', '')))
             OR (ei.phone2 IS NOT NULL AND ei.phone2 <> '' AND TRIM(REPLACE(REPLACE(REPLACE(REPLACE(" . $ns_t('mobile') . ", ' ', ''), '-', ''), '(', ''), ')', '')) = TRIM(REPLACE(REPLACE(REPLACE(REPLACE(CONVERT(COALESCE(ei.phone2,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci, ' ', ''), '-', ''), '(', ''), ')', '')))
           )
        " . $scope_where_perf . "
        AND " . $ns_t('status') . " IN ('', '在學')
        GROUP BY ei.assigned_teacher_id
    ";
    $st_ts = $conn->prepare($sql_teacher_success);
    if ($st_ts) {
        $st_ts->bind_param($bind_types_perf, ...$bind_params_perf);
        $st_ts->execute();
        $ts_rows = $st_ts->get_result()->fetch_all(MYSQLI_ASSOC);
        $st_ts->close();
        foreach ($ts_rows as $row) {
            $teacher_success_map[(int)$row['teacher_id']] = (int)$row['success_count'];
        }
    }
}

// 績效圖＋成功經營表：只算應屆（當年度時）或該屆的分配／成功入學
$teacher_stats = [];
foreach ($teacher_rows_perf as $tr) {
    $tid = (int)$tr['teacher_id'];
    $assigned = (int)$tr['assigned_count'];
    $success = $teacher_success_map[$tid] ?? 0;
    if ($success < 0) $success = 0;
    if ($success > $assigned) $success = $assigned;
    $failed = max(0, $assigned - $success);
    $teacher_stats[] = [
        'teacher_id' => $tid,
        'teacher_name' => $tr['teacher_name'] ?: $tr['teacher_username'] ?: '未知',
        'assigned_count' => $assigned,
        'success_enrolled_count' => $success,
        'failed_enrolled_count' => $failed,
    ];
}

// 區塊三：未成功入學原因分析（所有確定未入學的學生，不分負責老師，統計為什麼不入學）
$loss_total_count = 0;
$loss_with_reason = 0;
$loss_detail_rows = [];
$loss_reason_chart = [];

$loss_join = "
LEFT JOIN user u_loss ON ei.assigned_teacher_id = u_loss.id
LEFT JOIN (SELECT c.enrollment_id, c.notes FROM enrollment_contact_logs c INNER JOIN (
    SELECT enrollment_id, MAX(id) AS mid FROM enrollment_contact_logs GROUP BY enrollment_id
) t ON c.enrollment_id = t.enrollment_id AND c.id = t.mid) latest_log_loss ON latest_log_loss.enrollment_id = ei.id
";

if ($has_nsbi_teacher) {
    // 未成功入學 = 在 scope_where_perf 內但 LEFT JOIN new_student_basic_info 對不到（ns.id IS NULL）
    $not_enrolled_join_ns = "
    LEFT JOIN new_student_basic_info ns ON TRIM(" . $ns_t('student_name') . ") = TRIM(CONVERT(COALESCE(ei.name,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci)
       AND (" . $school_cond_t . ")
       AND (
            (ei.phone1 IS NOT NULL AND ei.phone1 <> '' AND TRIM(REPLACE(REPLACE(REPLACE(REPLACE(" . $ns_t('mobile') . ", ' ', ''), '-', ''), '(', ''), ')', '')) = TRIM(REPLACE(REPLACE(REPLACE(REPLACE(CONVERT(COALESCE(ei.phone1,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci, ' ', ''), '-', ''), '(', ''), ')', '')))
         OR (ei.phone2 IS NOT NULL AND ei.phone2 <> '' AND TRIM(REPLACE(REPLACE(REPLACE(REPLACE(" . $ns_t('mobile') . ", ' ', ''), '-', ''), '(', ''), ')', '')) = TRIM(REPLACE(REPLACE(REPLACE(REPLACE(CONVERT(COALESCE(ei.phone2,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci, ' ', ''), '-', ''), '(', ''), ')', '')))
       )
       AND " . $ns_t('status') . " IN ('', '在學')
    ";
    $not_enrolled_where = $scope_where_perf . " AND ns.id IS NULL ";

    // 未入學總數、有填寫原因人數（統計為什麼不入學）
    $sql_loss_totals = "SELECT
        COUNT(DISTINCT ei.id) AS total,
        SUM(CASE WHEN latest_log_loss.notes IS NOT NULL AND TRIM(latest_log_loss.notes) <> '' THEN 1 ELSE 0 END) AS with_reason
    " . $base_from . $school_join_t . $not_enrolled_join_ns . $loss_join . $not_enrolled_where;
    $st_lt = $conn->prepare($sql_loss_totals);
    $st_lt->bind_param($bind_types_perf, ...$bind_params_perf);
    $st_lt->execute();
    $loss_totals_row = $st_lt->get_result()->fetch_assoc();
    $st_lt->close();
    $loss_total_count = (int)($loss_totals_row['total'] ?? 0);
    $loss_with_reason = (int)($loss_totals_row['with_reason'] ?? 0);

    // 所有未入學明細（含代表原因），不分老師、依學生姓名排序
    $sql_loss_detail = "SELECT ei.id, ei.name AS student_name, ei.intention_level AS current_level,
        latest_log_loss.notes AS latest_contact_notes,
        u_loss.name AS teacher_name, u_loss.username AS teacher_username
    " . $base_from . $school_join_t . $not_enrolled_join_ns . $loss_join . $not_enrolled_where . "
    ORDER BY ei.name";
    $st_ld = $conn->prepare($sql_loss_detail);
    $st_ld->bind_param($bind_types_perf, ...$bind_params_perf);
    $st_ld->execute();
    $loss_detail_rows = $st_ld->get_result()->fetch_all(MYSQLI_ASSOC);
    $st_ld->close();

    // 依關鍵字掃描聯絡紀錄，統計未入學原因分類（一筆只歸一類，依關鍵字先後匹配）
    $loss_reason_keywords = [
        '選讀他校' => ['選讀他校', '他校', '其他學校', '選別校', '去他校', '就讀他校', '改選', '別校'],
        '交通因素' => ['交通', '距離', '太遠', '通勤'],
        '家庭因素' => ['家庭', '家長', '父母', '家人'],
        '經濟因素' => ['經濟', '學費', '費用'],
        '興趣志趣' => ['興趣', '志趣', '想讀', '不想讀'],
        '成績分數' => ['成績', '分數', '會考'],
        '私校/高職/高中' => ['私校', '高職', '五專', '高中', '綜合高中'],
    ];
    $loss_reason_counts = array_fill_keys(array_keys($loss_reason_keywords), 0);
    $loss_reason_counts['其他'] = 0;
    $loss_reason_counts['未填寫'] = 0;
    foreach ($loss_detail_rows as $row) {
        $notes = trim((string)($row['latest_contact_notes'] ?? ''));
        if ($notes === '') {
            $loss_reason_counts['未填寫']++;
            continue;
        }
        $matched = false;
        foreach ($loss_reason_keywords as $category => $keywords) {
            foreach ($keywords as $kw) {
                if (mb_strpos($notes, $kw) !== false) {
                    $loss_reason_counts[$category]++;
                    $matched = true;
                    break;
                }
            }
            if ($matched) break;
        }
        if (!$matched) {
            $loss_reason_counts['其他']++;
        }
    }
    // 只保留有筆數的分類，供長條圖使用（依筆數由多到少排序）
    $loss_reason_chart = [];
    foreach ($loss_reason_counts as $label => $cnt) {
        if ($cnt > 0) {
            $loss_reason_chart[] = ['label' => $label, 'count' => $cnt];
        }
    }
    usort($loss_reason_chart, function ($a, $b) { return $b['count'] - $a['count']; });
}

// 區塊四：低意願卻入學的學生（比對 new_student_basic_info）
$low_intent_enrolled_rows = [];
try {
    $t = $conn->query("SHOW TABLES LIKE 'new_student_basic_info'");
    $has_nsbi_table = $t && $t->num_rows > 0;
} catch (Exception $e) {
    $has_nsbi_table = false;
}

if ($has_nsbi_table) {
    // 就讀意願名單「目前意願為低」且出現在新生基本資料（姓名＋國中＋電話）
    // new_student_basic_info 表為 utf8mb4_general_ci，其他表為 utf8mb4_unicode_ci → 用 CONVERT 統一再比對
    $ns = function($col) { return "CONVERT(COALESCE(ns.{$col},'') USING utf8mb4) COLLATE utf8mb4_unicode_ci"; };
    $t_sd = $conn->query("SHOW TABLES LIKE 'school_data'");
    $table_school = ($t_sd && $t_sd->num_rows > 0) ? 'school_data' : null;
    $school_join = '';
    $school_cond = "(" . $ns('previous_school') . " = CONVERT(COALESCE(ei.junior_high,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci OR ns.previous_school IS NULL OR TRIM(" . $ns('previous_school') . ") = '')";
    if ($table_school) {
        $school_join = " LEFT JOIN " . $table_school . " sd_low ON sd_low.school_code = ei.junior_high ";
        $school_cond = "(" . $ns('previous_school') . " = CONVERT(COALESCE(ei.junior_high,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci
            OR (sd_low.school_code IS NOT NULL AND " . $ns('previous_school') . " = CONVERT(COALESCE(sd_low.name,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci)
            OR ns.previous_school IS NULL OR TRIM(" . $ns('previous_school') . ") = '')";
    }
    $sql_low_enrolled = "
        SELECT 
            ei.id AS enrollment_id,
            ei.name AS student_name,
            ei.junior_high,
            ei.phone1,
            ei.phone2,
            COALESCE(ch.first_old, ei.intention_level) AS initial_level,
            ei.intention_level AS current_level,
            u.name AS teacher_name,
            u.username AS teacher_username,
            ns.student_no,
            ns.class_name,
            ns.created_at AS enrolled_at
        " . $base_from . "
        LEFT JOIN (
            SELECT enrollment_id,
                   SUBSTRING_INDEX(GROUP_CONCAT(IFNULL(old_level, new_level) ORDER BY changed_at ASC), ',', 1) AS first_old
            FROM " . INTENTION_CHANGE_LOG_TABLE . "
            GROUP BY enrollment_id
        ) ch ON ch.enrollment_id = ei.id
        LEFT JOIN user u ON ei.assigned_teacher_id = u.id
        " . $school_join . "
        INNER JOIN new_student_basic_info ns
            ON TRIM(" . $ns('student_name') . ") = TRIM(CONVERT(COALESCE(ei.name,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci)
           AND (" . $school_cond . ")
           AND (
                (ei.phone1 IS NOT NULL AND ei.phone1 <> '' AND TRIM(REPLACE(REPLACE(REPLACE(REPLACE(" . $ns('mobile') . ", ' ', ''), '-', ''), '(', ''), ')', '')) = TRIM(REPLACE(REPLACE(REPLACE(REPLACE(CONVERT(COALESCE(ei.phone1,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci, ' ', ''), '-', ''), '(', ''), ')', '')))
             OR (ei.phone2 IS NOT NULL AND ei.phone2 <> '' AND TRIM(REPLACE(REPLACE(REPLACE(REPLACE(" . $ns('mobile') . ", ' ', ''), '-', ''), '(', ''), ')', '')) = TRIM(REPLACE(REPLACE(REPLACE(REPLACE(CONVERT(COALESCE(ei.phone2,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci, ' ', ''), '-', ''), '(', ''), ')', '')))
           )
        " . $scope_where . "
        AND ei.intention_level = 'low'
        AND " . $ns('status') . " IN ('', '在學')
        ORDER BY ns.created_at DESC
    ";
    $st_low = $conn->prepare($sql_low_enrolled);
    if ($st_low) {
        $st_low->bind_param($bind_types, ...$bind_params);
        $st_low->execute();
        $low_intent_enrolled_rows = $st_low->get_result()->fetch_all(MYSQLI_ASSOC);
        $st_low->close();
    }
}

function levelLabel($code) {
    if (!$code) return '—';
    if ($code === 'high') return '高意願';
    if ($code === 'medium') return '中意願';
    if ($code === 'low') return '低意願';
    return $code;
}

function formatAcademicYearLabel($startYear, $with_gregorian = true) {
    $startYear = (int)$startYear;
    if ($startYear <= 0) return '未提供';
    $minguo = $startYear - 1911;
    $endYear = $startYear + 1;
    if ($with_gregorian) {
        return $minguo . " 學年 (" . $startYear . "-" . $endYear . ")";
    }
    return $minguo . " 學年";
}

$page_title = '教師經營成效分析';
$base_url_params = [];
if ($selected_roc_year > 0) {
    $base_url_params['roc_year'] = $selected_roc_year;
}
$base_url = 'teacher_intention_performance.php' . (empty($base_url_params) ? '' : '?' . http_build_query($base_url_params));
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - 後台</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    .perf-card { display: inline-block; min-width: 180px; background: #fff; border: 1px solid #e8e8e8; border-radius: 12px; padding: 20px; margin: 0 12px 12px 0; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
    .perf-card .num { font-size: 28px; font-weight: 700; color: #333; }
    .perf-card .label { font-size: 13px; color: #666; margin-top: 4px; }
    .perf-card.upgrade .num { color: #52c41a; }
    .perf-card.downgrade .num { color: #ff4d4f; }
    .perf-table { width: 100%; border-collapse: collapse; font-size: 14px; }
    .perf-table th, .perf-table td { padding: 10px 12px; border: 1px solid #e8e8e8; text-align: left; }
    .perf-table th { background: #fafafa; font-weight: 600; }
    .perf-table tr:hover { background: #fafafa; }
    .detail-notes { max-width: 400px; white-space: pre-wrap; word-break: break-word; font-size: 13px; color: #333; }
    .modal { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,.5); align-items: center; justify-content: center; }
    .modal-content { background: #fff; border-radius: 12px; max-width: 560px; width: 90%; max-height: 85vh; overflow: hidden; display: flex; flex-direction: column; }
    .modal-header { padding: 16px 20px; border-bottom: 1px solid #e8e8e8; display: flex; justify-content: space-between; align-items: center; }
    .modal-body { padding: 20px; overflow-y: auto; }
    .modal .close { cursor: pointer; font-size: 24px; color: #666; }
    .card { background: #fff; border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,.06); border: 1px solid #f0f0f0; padding: 20px; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f2f5; margin: 0; }
    .content { padding: 24px; }
    .breadcrumb a { color: #1890ff; text-decoration: none; }
    .page-controls { margin-bottom: 16px; }
    .year-select-wrap { margin-top: 12px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .year-select-wrap label { font-weight: 600; color: #333; }
    .year-select-wrap select { padding: 8px 12px; border: 1px solid #d9d9d9; border-radius: 6px; font-size: 14px; min-width: 200px; }
    .perf-view-tabs { display: flex; gap: 0; margin-bottom: 16px; border-bottom: 1px solid #e8e8e8; }
    .perf-view-tabs .tab { padding: 10px 20px; cursor: pointer; font-size: 14px; color: #666; border-bottom: 2px solid transparent; margin-bottom: -1px; }
    .perf-view-tabs .tab:hover { color: #1890ff; }
    .perf-view-tabs .tab.active { color: #1890ff; font-weight: 600; border-bottom-color: #1890ff; }
    .perf-view-panel { display: none; }
    .perf-view-panel.active { display: block; }
    .loss-view-panel { display: none; }
    .loss-view-panel.active { display: block; }
</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<div class="dashboard">
    <?php include 'sidebar.php'; ?>
    <div class="main-content">
        <?php include 'header.php'; ?>
        <div class="content">
            <div class="page-controls">
                <div class="breadcrumb"><a href="index.php">首頁</a> / <?php echo htmlspecialchars($page_title); ?></div>
                <div class="year-select-wrap">
                    <label for="rocYearSelect"><i class="fas fa-graduation-cap" style="color: #1890ff;"></i> 屆別：</label>
                    <select id="rocYearSelect" onchange="var v=this.value; window.location.href=(v==='' ? 'teacher_intention_performance.php' : 'teacher_intention_performance.php?roc_year='+v);">
                        <option value="" <?php echo $selected_roc_year <= 0 ? 'selected' : ''; ?>>當年度</option>
                        <?php foreach ($available_roc_years as $roc_year): ?>
                        <option value="<?php echo (int)$roc_year; ?>" <?php echo $selected_roc_year === (int)$roc_year ? 'selected' : ''; ?>><?php echo (int)$roc_year; ?>學年</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <!-- 區塊一：成效數據總覽 
            <div class="card" style="margin-bottom: 24px;">
                <h3 style="margin: 0 0 16px 0; font-size: 18px;">成效數據總覽</h3>
                <div>
                    <div class="perf-card">
                        <div class="num"><?php echo $total_assigned; ?></div>
                        <div class="label">總分配人數</div>
                    </div>
                    <div class="perf-card upgrade">
                        <div class="num"><i class="fas fa-arrow-up" style="margin-right: 6px;"></i><?php echo $upgraded_count; ?></div>
                        <div class="label">意願提升數 (低/中 ➔ 高)</div>
                    </div>
                    <div class="perf-card downgrade">
                        <div class="num"><i class="fas fa-arrow-down" style="margin-right: 6px;"></i><?php echo $downgraded_count; ?></div>
                        <div class="label">意願下降數 (高 ➔ 低)</div>
                    </div>
                    <div class="perf-card">
                        <div class="num"><?php echo $uncontacted; ?></div>
                        <div class="label">尚未聯絡數</div>
                    </div>
                </div>
            </div>-->
            <!-- 教師招生績效比較圖 + 成功經營統計（合併，預設圖表、可切表格，可篩選老師） -->
            <div class="card" style="margin-bottom: 24px;">
                <h3 style="margin: 0 0 16px 0; font-size: 18px;">
                    <i class="fas fa-chart-bar" style="color: #1890ff;"></i> 教師經營名單成功入學統計
                </h3>
                <div style="margin: 8px 0 12px 0; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                    <label for="teacherFilter" style="font-size:13px; color:#555;">教師篩選：</label>
                    <select id="teacherFilter" onchange="filterTeacherPerf(this.value)" style="padding:4px 8px; border-radius:4px; border:1px solid #d9d9d9; font-size:13px; min-width:160px;">
                        <option value="">全部老師</option>
                        <?php foreach ($teacher_stats as $ts): ?>
                        <option value="<?php echo (int)$ts['teacher_id']; ?>"><?php echo htmlspecialchars($ts['teacher_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="perf-view-tabs">
                    <span class="tab active" data-panel="chart" onclick="switchPerfView('chart')"><i class="fas fa-chart-bar"></i> 圖表</span>
                    <span class="tab" data-panel="table" onclick="switchPerfView('table')"><i class="fas fa-table"></i> 表格</span>
                </div>
                <div id="perfChartPanel" class="perf-view-panel active">
                    <div style="position: relative; height: 300px; width: 100%;">
                        <canvas id="performanceChart"></canvas>
                    </div>
                </div>
                <div id="perfTablePanel" class="perf-view-panel">
                    <table class="perf-table">
                        <thead>
                            <tr>
                                <th>教師姓名</th>
                                <th>分配人數</th>
                                <th>成功入學數</th>
                                <th>入學成功率</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($teacher_stats as $ts): ?>
                            <?php
                                $assigned_cnt = (int)($ts['assigned_count'] ?? 0);
                                $success_cnt = (int)($ts['success_enrolled_count'] ?? 0);
                                $rate = $assigned_cnt > 0 ? round($success_cnt / $assigned_cnt * 100, 1) : 0;
                            ?>
                            <tr data-teacher-id="<?php echo (int)$ts['teacher_id']; ?>">
                                <td><?php echo htmlspecialchars($ts['teacher_name']); ?></td>
                                <td><?php echo $assigned_cnt; ?></td>
                                <td><?php echo $success_cnt; ?></td>
                                <td><?php echo $rate; ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($teacher_stats)): ?>
                            <tr><td colspan="4" style="text-align:center; color:#999;">尚無分配資料</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 區塊三：未成功入學原因分析（所有確定未入學的學生，統計為什麼不入學，可切換圖表／表格） -->
            <div class="card" style="margin-bottom: 24px;">
                <h3 style="margin: 0 0 16px 0; font-size: 18px;">未成功入學原因分析</h3>
                <?php if (!$has_nsbi_teacher): ?>
                <p style="margin: 0; font-size: 13px; color:#999;">尚無新生基本資料，無法統計未入學。</p>
                <?php else: ?>
                <div class="perf-view-tabs loss-view-tabs">
                    <span class="tab active" data-panel="loss-chart" onclick="switchLossView('chart')"><i class="fas fa-chart-bar"></i> 圖表</span>
                    <span class="tab" data-panel="loss-table" onclick="switchLossView('table')"><i class="fas fa-table"></i> 表格</span>
                </div>
                <div id="lossChartPanel" class="loss-view-panel active">
                    <div style="position: relative; height: 280px; width: 100%; margin-bottom: 20px;">
                        <canvas id="lossReasonChart"></canvas>
                    </div>
                </div>
                <div id="lossTablePanel" class="loss-view-panel">
                    <table class="perf-table">
                        <thead>
                            <tr>
                                <th>未入學原因分類</th>
                                <th>人數</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($loss_reason_chart as $item): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($item['label']); ?></td>
                                <td><?php echo (int)($item['count'] ?? 0); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($loss_reason_chart)): ?>
                            <tr><td colspan="2" style="text-align:center; color:#999;">尚無未入學原因資料</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
            <!-- 區塊四：低意願卻入學的學生 -->
            <div class="card" style="margin-top: 24px;">
                <h3 style="margin: 0 0 16px 0; font-size: 18px;">低意願卻入學的學生</h3>
                <table class="perf-table">
                    <thead>
                        <tr>
                            <th>學生姓名</th>
                            <th>原就讀國中</th>
                            <th>聯絡電話</th>
                            <th>初始意願</th>
                            <th>目前意願</th>
                            <th>新生班級／學號</th>
                            <th>負責教師</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($low_intent_enrolled_rows as $row): 
                            $teacher_display = $row['teacher_name'] ?: $row['teacher_username'] ?: '—';
                            $initial = levelLabel($row['initial_level']);
                            $current = levelLabel($row['current_level']);
                            $phones = [];
                            if (!empty($row['phone1'])) $phones[] = $row['phone1'];
                            if (!empty($row['phone2']) && $row['phone2'] !== $row['phone1']) $phones[] = $row['phone2'];
                            $phone_display = $phones ? implode(' / ', $phones) : '—';
                            $class_no = '';
                            if (!empty($row['class_name'])) $class_no .= $row['class_name'];
                            if (!empty($row['student_no'])) {
                                if ($class_no !== '') $class_no .= '／';
                                $class_no .= $row['student_no'];
                            }
                            if ($class_no === '') $class_no = '—';
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['student_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['junior_high'] ?: '—'); ?></td>
                            <td><?php echo htmlspecialchars($phone_display); ?></td>
                            <td><?php echo htmlspecialchars($initial); ?></td>
                            <td><?php echo htmlspecialchars($current); ?></td>
                            <td><?php echo htmlspecialchars($class_no); ?></td>
                            <td><?php echo htmlspecialchars($teacher_display); ?></td>
                            <td>
                                <button type="button" class="btn-view" onclick="openContactLogModal(<?php echo (int)$row['enrollment_id']; ?>, '<?php echo htmlspecialchars(addslashes($row['student_name'])); ?>')">
                                    <i class="fas fa-list"></i> 查看聯絡紀錄
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($low_intent_enrolled_rows)): ?>
                        <tr><td colspan="8" style="text-align:center; color:#999;">目前尚未發現「低意願卻入學」的學生</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- 聯絡紀錄 Modal（僅檢視） -->
<div id="contactLogModal" class="modal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>聯絡紀錄 - <span id="contactLogStudentName"></span></h3>
            <span class="close" onclick="document.getElementById('contactLogModal').style.display='none'">&times;</span>
        </div>
        <div class="modal-body">
            <div id="contactLogList">載入中...</div>
        </div>
    </div>
</div>

<!-- 成功入學學生明細 Modal -->
<div id="successStudentsModal" class="modal" style="display:none;">
    <div class="modal-content" style="max-width: 720px;">
        <div class="modal-header">
            <h3> <span id="successModalTitle">成功入學學生明細</span></h3>
            <span class="close" onclick="document.getElementById('successStudentsModal').style.display='none'">&times;</span>
        </div>
        <div class="modal-body">
            <div id="successStudentsList">載入中...</div>
        </div>
    </div>
</div>

<script>
function switchPerfView(panel) {
    var tabs = document.querySelectorAll('.perf-view-tabs .tab');
    var chartPanel = document.getElementById('perfChartPanel');
    var tablePanel = document.getElementById('perfTablePanel');
    tabs.forEach(function(t) { t.classList.toggle('active', t.getAttribute('data-panel') === panel); });
    chartPanel.classList.toggle('active', panel === 'chart');
    tablePanel.classList.toggle('active', panel === 'table');
}
function switchLossView(panel) {
    var tabs = document.querySelectorAll('.loss-view-tabs .tab');
    var chartPanel = document.getElementById('lossChartPanel');
    var tablePanel = document.getElementById('lossTablePanel');
    tabs.forEach(function(t) { t.classList.toggle('active', t.getAttribute('data-panel') === 'loss-' + panel); });
    chartPanel.classList.toggle('active', panel === 'chart');
    tablePanel.classList.toggle('active', panel === 'table');
}
function openContactLogModal(enrollmentId, studentName) {
    document.getElementById('contactLogStudentName').textContent = studentName || '未知';
    document.getElementById('contactLogModal').style.display = 'flex';
    var list = document.getElementById('contactLogList');
    list.innerHTML = '載入中...';
    fetch('get_contact_logs.php?enrollment_id=' + encodeURIComponent(enrollmentId))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success && data.logs && data.logs.length > 0) {
                list.innerHTML = data.logs.map(function(log) {
                    var notes = log.notes || log.result || '';
                    var date = (log.contact_date || '').match(/^(\d{4}-\d{2}-\d{2})/) ? RegExp.$1 : '—';
                    var method = log.method || '其他';
                    return '<div style="border-bottom:1px solid #eee; padding:12px 0;">' +
                        '<div style="color:#666; font-size:12px;">' + date + ' · ' + method + '</div>' +
                        '<div style="white-space:pre-wrap; margin-top:6px; font-size:14px;">' + (notes ? notes.replace(/</g,'&lt;').replace(/>/g,'&gt;') : '無') + '</div></div>';
                }).join('');
            } else {
                list.innerHTML = '<p style="color:#999;">尚無聯絡紀錄</p>';
            }
        })
        .catch(function() { list.innerHTML = '<p style="color:red;">載入失敗</p>'; });
}
//1. 將 PHP 算好的資料轉換給 JavaScript 使用
const teacherStats = <?php echo json_encode($teacher_stats); ?>;
const selectedRocYear = <?php echo (int)$selected_roc_year; ?>;
let perfChart = null;

function getFilteredTeacherStats(teacherId) {
    if (!teacherId) return teacherStats;
    var tid = parseInt(teacherId, 10);
    return teacherStats.filter(function(ts) { return parseInt(ts.teacher_id, 10) === tid; });
}

function buildPerfChartData(teacherId) {
    var filtered = getFilteredTeacherStats(teacherId);
    var labels = filtered.map(function(ts) { return ts.teacher_name; });
    var assignedData = filtered.map(function(ts) { return ts.assigned_count; });
    var successData = filtered.map(function(ts) { return ts.success_enrolled_count; });
    return { labels, assignedData, successData };
}

function renderPerfChart(teacherId) {
    const canvas = document.getElementById('performanceChart');
    if (!canvas) return;
    if (!teacherStats || teacherStats.length === 0) {
        canvas.parentElement.innerHTML = '<p style="text-align:center; color:#999; line-height:300px;">尚無資料可供繪圖</p>';
        return;
    }
    const ctx = canvas.getContext('2d');
    const dataObj = buildPerfChartData(teacherId);
    if (perfChart) {
        perfChart.data.labels = dataObj.labels;
        perfChart.data.datasets[0].data = dataObj.assignedData;
        perfChart.data.datasets[1].data = dataObj.successData;
        perfChart.update();
        return;
    }
    perfChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: dataObj.labels,
            datasets: [
                {
                    label: '分配學生人數 (基數)',
                    data: dataObj.assignedData,
                    backgroundColor: 'rgba(201, 203, 207, 0.6)', // 淺灰色
                    borderColor: 'rgb(201, 203, 207)',
                    borderWidth: 1,
                    borderRadius: 4
                },
                {
                    label: '成功入學人數 (戰果)',
                    data: dataObj.successData,
                    backgroundColor: 'rgba(54, 162, 235, 0.8)', // 亮藍色
                    borderColor: 'rgb(54, 162, 235)',
                    borderWidth: 1,
                    borderRadius: 4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            onClick: function(event, elements) {
                if (elements.length === 0) return;
                var el = elements[0];
                var dsIdx = el.datasetIndex;
                var idx = el.index;
                var filterVal = document.getElementById('teacherFilter').value;
                var filtered = getFilteredTeacherStats(filterVal || null);
                var teacher = filtered[idx];
                if (teacher && dsIdx === 1) {
                    openSuccessModal(teacher.teacher_id, teacher.teacher_name);
                }
            },
            plugins: {
                legend: { position: 'top' },
                tooltip: {
                    callbacks: {
                        // 在提示框自動幫主任算出轉換率
                        afterLabel: function(context) {
                            if (context.datasetIndex === 1) { // 只有成功入學顯示轉換率
                                const assigned = dataObj.assignedData[context.dataIndex];
                                const success = context.raw;
                                const rate = assigned > 0 ? ((success / assigned) * 100).toFixed(1) : 0;
                                return `入學轉換率: ${rate}%`;
                            }
                            return null;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { stepSize: 1 } // 因為人數是整數
                }
            }
        }
    });
}

function filterTeacherPerf(teacherId) {
    renderPerfChart(teacherId || null);
    var rows = document.querySelectorAll('#perfTablePanel tbody tr[data-teacher-id]');
    rows.forEach(function(row) {
        if (!teacherId) {
            row.style.display = '';
        } else {
            var tid = parseInt(row.getAttribute('data-teacher-id'), 10);
            row.style.display = (tid === parseInt(teacherId, 10)) ? '' : 'none';
        }
    });
}

function openSuccessModal(teacherId, teacherName) {
    document.getElementById('successModalTitle').textContent = (teacherName || '老師') + ' - 成功入學學生明細';
    document.getElementById('successStudentsModal').style.display = 'flex';
    var listEl = document.getElementById('successStudentsList');
    listEl.innerHTML = '載入中...';
    var url = 'get_teacher_performance_students.php?teacher_id=' + encodeURIComponent(teacherId) + '&roc_year=' + encodeURIComponent(selectedRocYear);
    fetch(url)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) {
                listEl.innerHTML = '<p style="color:#ff4d4f;">' + (data.message || '載入失敗') + '</p>';
                return;
            }
            var students = data.students || [];
            if (students.length === 0) {
                listEl.innerHTML = '<p style="color:#999;">尚無成功入學學生資料</p>';
                return;
            }
            var rows = students.map(function(s) {
                var phones = [];
                if (s.phone1) phones.push(s.phone1);
                if (s.phone2 && s.phone2 !== s.phone1) phones.push(s.phone2);
                var phoneStr = phones.length ? phones.join(' / ') : '—';
                var classNo = [s.class_name, s.student_no].filter(Boolean).join('／') || '—';
                var enrolledAt = s.enrolled_at ? s.enrolled_at.replace(/^(\d{4}-\d{2}-\d{2}).*/, '$1') : '—';
                return '<tr><td>' + (s.student_name ? s.student_name.replace(/</g,'&lt;') : '—') + '</td><td>' + (s.junior_high ? s.junior_high.replace(/</g,'&lt;') : '—') + '</td><td>' + phoneStr.replace(/</g,'&lt;') + '</td><td>' + classNo.replace(/</g,'&lt;') + '</td></tr>';
            }).join('');
            listEl.innerHTML = '<table class="perf-table"><thead><tr><th>學生姓名</th><th>原就讀國中</th><th>聯絡電話</th><th>新生班級／學號</th></tr></thead><tbody>' + rows + '</tbody></table>';
        })
        .catch(function() {
            listEl.innerHTML = '<p style="color:#ff4d4f;">載入失敗</p>';
        });
}

// 初始渲染全部老師的績效圖
renderPerfChart(null);

// 未入學原因統計長條圖（依關鍵字掃描結果）
const lossReasonChartData = <?php echo json_encode($loss_reason_chart); ?>;
const lossReasonCanvas = document.getElementById('lossReasonChart');
if (lossReasonCanvas) {
    if (lossReasonChartData.length > 0) {
        const labels = lossReasonChartData.map(function(r) { return r.label; });
        const counts = lossReasonChartData.map(function(r) { return r.count; });
        const colors = ['rgba(255, 99, 132, 0.7)', 'rgba(54, 162, 235, 0.7)', 'rgba(255, 206, 86, 0.7)', 'rgba(75, 192, 192, 0.7)', 'rgba(153, 102, 255, 0.7)', 'rgba(255, 159, 64, 0.7)', 'rgba(201, 203, 207, 0.7)', 'rgba(0, 0, 0, 0.2)'];
        new Chart(lossReasonCanvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: '人數',
                    data: counts,
                    backgroundColor: labels.map(function(_, i) { return colors[i % colors.length]; }),
                    borderColor: 'rgba(0,0,0,0.1)',
                    borderWidth: 1,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {}
                },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1 } }
                }
            }
        });
    } else {
        lossReasonCanvas.parentElement.innerHTML = '<p style="text-align:center; color:#999; line-height:280px;">尚無未入學原因資料可繪圖</p>';
    }
}
</script>
<?php $conn->close(); ?>
</body>
</html>
