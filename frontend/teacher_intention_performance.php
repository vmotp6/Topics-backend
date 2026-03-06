<?php
/**
 * 教師經營成效分析（僅主任可見）
 * 區塊一：成效數據總覽
 * 區塊二：成功經營統計（入學成果）
 * 區塊三：教師轉換排行榜
 * 區塊四：意願異動明細與原因
 * 區塊五：低意願卻入學的學生
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

$dept_where = " AND (ec1.department_code = ? OR ec2.department_code = ? OR ec3.department_code = ? OR ei.assigned_department = ?)";
$year_where = " AND ei.graduation_year IN (?, ?, ?)";
$bind_dept = [$user_department_code, $user_department_code, $user_department_code, $user_department_code];
$bind_year = [$this_year_grad, $this_year_grad + 1, $this_year_grad + 2];

$base_from = "
FROM enrollment_intention ei
LEFT JOIN enrollment_choices ec1 ON ei.id = ec1.enrollment_id AND ec1.choice_order = 1
LEFT JOIN enrollment_choices ec2 ON ei.id = ec2.enrollment_id AND ec2.choice_order = 2
LEFT JOIN enrollment_choices ec3 ON ei.id = ec3.enrollment_id AND ec3.choice_order = 3
";
$scope_where = " WHERE 1=1 " . $dept_where . $year_where . " AND ei.assigned_teacher_id IS NOT NULL ";

// 區塊一：總分配人數
$sql_total = "SELECT COUNT(DISTINCT ei.id) AS cnt " . $base_from . $scope_where;
$st = $conn->prepare($sql_total);
$st->bind_param("ssssiii", ...array_merge($bind_dept, $bind_year));
$st->execute();
$total_assigned = (int)($st->get_result()->fetch_assoc()['cnt'] ?? 0);
$st->close();

// 尚未聯絡數（有分配但無聯絡紀錄）
$sql_uncontacted = "SELECT COUNT(DISTINCT ei.id) AS cnt " . $base_from . "
LEFT JOIN (SELECT enrollment_id FROM enrollment_contact_logs GROUP BY enrollment_id) c ON c.enrollment_id = ei.id
" . str_replace("AND ei.assigned_teacher_id IS NOT NULL", "AND ei.assigned_teacher_id IS NOT NULL AND c.enrollment_id IS NULL", $scope_where);
$st = $conn->prepare($sql_uncontacted);
$st->bind_param("ssssiii", ...array_merge($bind_dept, $bind_year));
$st->execute();
$uncontacted = (int)($st->get_result()->fetch_assoc()['cnt'] ?? 0);
$st->close();

// 意願提升數：目前為高意願 且 曾有一筆變動為 (低/中/初次→高)
$sql_up = "SELECT COUNT(DISTINCT ei.id) AS cnt " . $base_from . "
INNER JOIN (SELECT DISTINCT enrollment_id FROM " . INTENTION_CHANGE_LOG_TABLE . " 
    WHERE new_level = 'high' AND (old_level IS NULL OR old_level IN ('low','medium'))) ch ON ch.enrollment_id = ei.id
" . $scope_where . " AND ei.intention_level = 'high'";
$st = $conn->prepare($sql_up);
$st->bind_param("ssssiii", ...array_merge($bind_dept, $bind_year));
$st->execute();
$upgraded_count = (int)($st->get_result()->fetch_assoc()['cnt'] ?? 0);
$st->close();

// 意願下降數：目前為低意願 且 曾有一筆變動為 (高→中/低)
$sql_down = "SELECT COUNT(DISTINCT ei.id) AS cnt " . $base_from . "
INNER JOIN (SELECT DISTINCT enrollment_id FROM " . INTENTION_CHANGE_LOG_TABLE . " 
    WHERE old_level = 'high' AND new_level IN ('low','medium')) ch ON ch.enrollment_id = ei.id
" . $scope_where . " AND ei.intention_level = 'low'";
$st = $conn->prepare($sql_down);
$st->bind_param("ssssiii", ...array_merge($bind_dept, $bind_year));
$st->execute();
$downgraded_count = (int)($st->get_result()->fetch_assoc()['cnt'] ?? 0);
$st->close();

// 區塊二：教師轉換排行榜（只統計有分配的名單範圍內的老師）
$sql_teachers = "SELECT ei.assigned_teacher_id AS teacher_id, u.name AS teacher_name, u.username AS teacher_username,
    COUNT(DISTINCT ei.id) AS assigned_count
" . $base_from . "
LEFT JOIN user u ON ei.assigned_teacher_id = u.id
" . $scope_where . "
GROUP BY ei.assigned_teacher_id, u.name, u.username
ORDER BY assigned_count DESC";
$st = $conn->prepare($sql_teachers);
$st->bind_param("ssssiii", ...array_merge($bind_dept, $bind_year));
$st->execute();
$teacher_rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

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
        " . $scope_where . "
        AND " . $ns_t('status') . " IN ('', '在學')
        GROUP BY ei.assigned_teacher_id
    ";
    $st_ts = $conn->prepare($sql_teacher_success);
    if ($st_ts) {
        $st_ts->bind_param("ssssiii", ...array_merge($bind_dept, $bind_year));
        $st_ts->execute();
        $ts_rows = $st_ts->get_result()->fetch_all(MYSQLI_ASSOC);
        $st_ts->close();
        foreach ($ts_rows as $row) {
            $teacher_success_map[(int)$row['teacher_id']] = (int)$row['success_count'];
        }
    }
}

$teacher_stats = [];
foreach ($teacher_rows as $tr) {
    $tid = (int)$tr['teacher_id'];
    $up_sql = "SELECT COUNT(DISTINCT enrollment_id) AS c FROM " . INTENTION_CHANGE_LOG_TABLE . " 
        WHERE teacher_id = ? AND new_level = 'high' AND (old_level IS NULL OR old_level IN ('low','medium'))";
    $down_sql = "SELECT COUNT(DISTINCT enrollment_id) AS c FROM " . INTENTION_CHANGE_LOG_TABLE . " 
        WHERE teacher_id = ? AND old_level = 'high' AND new_level IN ('low','medium')";
    $up_st = $conn->prepare($up_sql); $up_st->bind_param("i", $tid); $up_st->execute();
    $up_c = (int)($up_st->get_result()->fetch_assoc()['c'] ?? 0); $up_st->close();
    $down_st = $conn->prepare($down_sql); $down_st->bind_param("i", $tid); $down_st->execute();
    $down_c = (int)($down_st->get_result()->fetch_assoc()['c'] ?? 0); $down_st->close();
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
        'upgrade_count' => $up_c,
        'downgrade_count' => $down_c
    ];
}

// 區塊三：意願異動明細（有發生過變動的學生，含初始/目前意願、最新聯絡紀錄）
$filter_type = $_GET['filter'] ?? 'all'; // all | upgrade | downgrade
$detail_where = " WHERE ei.assigned_teacher_id IS NOT NULL AND (ec1.department_code = ? OR ec2.department_code = ? OR ec3.department_code = ? OR ei.assigned_department = ?) " . $year_where;
$detail_join = "
INNER JOIN (SELECT enrollment_id,
    SUBSTRING_INDEX(GROUP_CONCAT(IFNULL(old_level, new_level) ORDER BY changed_at ASC), ',', 1) AS first_old,
    MAX(changed_at) AS last_change_at
    FROM " . INTENTION_CHANGE_LOG_TABLE . " GROUP BY enrollment_id) ch ON ch.enrollment_id = ei.id
LEFT JOIN user u ON ei.assigned_teacher_id = u.id
LEFT JOIN (SELECT c.enrollment_id, c.notes FROM enrollment_contact_logs c INNER JOIN (
    SELECT enrollment_id, MAX(id) AS mid FROM enrollment_contact_logs GROUP BY enrollment_id
) t ON c.enrollment_id = t.enrollment_id AND c.id = t.mid) latest_log ON latest_log.enrollment_id = ei.id
";
$sql_detail = "SELECT ei.id, ei.name AS student_name, ei.intention_level AS current_level,
    ch.first_old AS initial_level, latest_log.notes AS latest_contact_notes,
    u.name AS teacher_name, u.username AS teacher_username
" . $base_from . $detail_join . $detail_where;
if ($filter_type === 'upgrade') {
    $sql_detail .= " AND ei.intention_level = 'high' AND EXISTS (SELECT 1 FROM " . INTENTION_CHANGE_LOG_TABLE . " x WHERE x.enrollment_id = ei.id AND x.new_level = 'high' AND (x.old_level IS NULL OR x.old_level IN ('low','medium')))";
} elseif ($filter_type === 'downgrade') {
    $sql_detail .= " AND ei.intention_level = 'low' AND EXISTS (SELECT 1 FROM " . INTENTION_CHANGE_LOG_TABLE . " x WHERE x.enrollment_id = ei.id AND x.old_level = 'high' AND x.new_level IN ('low','medium'))";
}
$sql_detail .= " ORDER BY ch.last_change_at DESC";
$st = $conn->prepare($sql_detail);
$st->bind_param("ssssiii", ...array_merge($bind_dept, $bind_year));
$st->execute();
$detail_rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

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
        $st_low->bind_param("ssssiii", ...array_merge($bind_dept, $bind_year));
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

$page_title = '教師經營成效分析';
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
</style>
</head>
<body>
<div class="dashboard">
    <?php include 'sidebar.php'; ?>
    <div class="main-content">
        <?php include 'header.php'; ?>
        <div class="content">
            <div class="page-controls">
                <div class="breadcrumb"><a href="index.php">首頁</a> / <?php echo htmlspecialchars($page_title); ?></div>
            </div>

            <h2 style="margin-bottom: 20px;"><?php echo htmlspecialchars($page_title); ?></h2>

            <!-- 區塊一：成效數據總覽 -->
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
            </div>

            <!-- 區塊二：成功經營統計（入學成果） -->
            <div class="card" style="margin-bottom: 24px;">
                <h3 style="margin: 0 0 16px 0; font-size: 18px;">成功經營統計（入學成果）</h3>
                <table class="perf-table">
                    <thead>
                        <tr>
                            <th>教師姓名</th>
                            <th>分配人數</th>
                            <th>成功入學數</th>
                            <th>未成功入學數</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($teacher_stats as $ts): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($ts['teacher_name']); ?></td>
                            <td><?php echo $ts['assigned_count']; ?></td>
                            <td><?php echo $ts['success_enrolled_count']; ?></td>
                            <td><?php echo $ts['failed_enrolled_count']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($teacher_stats)): ?>
                        <tr><td colspan="4" style="text-align:center; color:#999;">尚無分配資料</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- 區塊三：教師轉換排行榜 -->
            <div class="card" style="margin-bottom: 24px;">
                <h3 style="margin: 0 0 16px 0; font-size: 18px;">教師轉換排行榜</h3>
                <table class="perf-table">
                    <thead>
                        <tr>
                            <th>教師姓名</th>
                            <th>分配人數</th>
                            <th>成功提升數 (低/中➔高)</th>
                            <th>意願流失數 (高➔低)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($teacher_stats as $ts): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($ts['teacher_name']); ?></td>
                            <td><?php echo $ts['assigned_count']; ?></td>
                            <td><?php echo $ts['upgrade_count']; ?></td>
                            <td><?php echo $ts['downgrade_count']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($teacher_stats)): ?>
                        <tr><td colspan="4" style="text-align:center; color:#999;">尚無分配資料</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <!-- 區塊三：低意願卻入學的學生 -->
            <div class="card" style="margin-top: 24px;">
                <h3 style="margin: 0 0 16px 0; font-size: 18px;">低意願卻入學的學生</h3>
                <p style="margin: 0 0 12px 0; font-size: 13px; color:#666;">
                    條件：在就讀意願名單中「目前意願為低」，但在新生基本資料中出現為本系新生（姓名＋國中＋電話皆對得上）。
                </p>
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
                        <tr><td colspan="4" style="text-align:center; color:#999;">目前尚未發現「低意願卻入學」的學生</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <!-- 區塊四：意願異動明細與原因 -->
            <div class="card">
                <h3 style="margin: 0 0 16px 0; font-size: 18px;">意願異動明細與原因</h3>
                <div style="margin-bottom: 12px;">
                    <label>篩選：</label>
                    <select id="filterType" onchange="window.location.href='teacher_intention_performance.php?filter='+this.value">
                        <option value="all" <?php echo $filter_type === 'all' ? 'selected' : ''; ?>>全部異動</option>
                        <option value="upgrade" <?php echo $filter_type === 'upgrade' ? 'selected' : ''; ?>>只看意願提升</option>
                        <option value="downgrade" <?php echo $filter_type === 'downgrade' ? 'selected' : ''; ?>>只看意願下降</option>
                    </select>
                </div>
                <table class="perf-table">
                    <thead>
                        <tr>
                            <th>學生姓名</th>
                            <th>負責教師</th>
                            <th>初始意願</th>
                            <th>目前意願</th>
                            <th>關鍵轉折原因 (最新聯絡紀錄)</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($detail_rows as $dr): 
                            $teacher_display = $dr['teacher_name'] ?: $dr['teacher_username'] ?: '—';
                            $notes = $dr['latest_contact_notes'] ?? '';
                            $initial = levelLabel($dr['initial_level']);
                            $current = levelLabel($dr['current_level']);
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($dr['student_name']); ?></td>
                            <td><?php echo htmlspecialchars($teacher_display); ?></td>
                            <td><?php echo htmlspecialchars($initial); ?></td>
                            <td><?php echo htmlspecialchars($current); ?></td>
                            <td><div class="detail-notes"><?php echo nl2br(htmlspecialchars($notes ?: '—')); ?></div></td>
                            <td>
                                <button type="button" class="btn-view" onclick="openContactLogModal(<?php echo (int)$dr['id']; ?>, '<?php echo htmlspecialchars(addslashes($dr['student_name'])); ?>')">
                                    <i class="fas fa-list"></i> 查看完整聯絡軌跡
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($detail_rows)): ?>
                        <tr><td colspan="6" style="text-align:center; color:#999;">尚無意願異動明細</td></tr>
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

<script>
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
</script>
<?php $conn->close(); ?>
</body>
</html>
