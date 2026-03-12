<?php
/**
 * 教師經營成效：取得某老師「成功入學」學生明細（主任用）
 * GET: teacher_id, roc_year (民國學年，0=當年度應屆)
 */
require_once __DIR__ . '/session_config.php';

header('Content-Type: application/json; charset=utf-8');

checkBackendLogin();

$user_role = $_SESSION['role'] ?? '';
$user_id = (int)($_SESSION['user_id'] ?? 0);
$role_map = ['主任' => 'DI', 'director' => 'DI', 'DI' => 'DI'];
$user_role = $role_map[$user_role] ?? $user_role;
if ($user_role !== 'DI' || $user_id <= 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '僅主任可查詢']);
    exit;
}

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
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '找不到設定檔']);
    exit;
}
require_once $config_path;

$teacher_id = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;
$roc_year = isset($_GET['roc_year']) ? (int)$_GET['roc_year'] : 0;
if ($teacher_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '缺少或不合法的 teacher_id']);
    exit;
}

try {
    $conn = getDatabaseConnection();
    $conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

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
    if (!$user_department_code) {
        echo json_encode(['success' => true, 'teacher_name' => '', 'students' => []]);
        exit;
    }

    $current_month = (int)date('m');
    $current_year = (int)date('Y');
    $this_year_grad = ($current_month >= 8) ? $current_year + 1 : $current_year;

    $dept_where = " AND (ec1.department_code = ? OR ec2.department_code = ? OR ec3.department_code = ? OR ei.assigned_department = ?)";
    $bind_dept = [$user_department_code, $user_department_code, $user_department_code, $user_department_code];

    if ($roc_year > 0) {
        $year_where_perf = " AND ei.graduation_year = ?";
        $bind_year_perf = [$roc_year + 1912];
        $bind_types_perf = "ssssi";
    } else {
        $year_where_perf = " AND ei.graduation_year = ?";
        $bind_year_perf = [$this_year_grad];
        $bind_types_perf = "ssssi";
    }
    $scope_where_perf = " WHERE 1=1 " . $dept_where . $year_where_perf . " AND ei.assigned_teacher_id IS NOT NULL AND ei.assigned_teacher_id = ? ";
    $bind_params_perf = array_merge($bind_dept, $bind_year_perf, [$teacher_id]);
    $bind_types_perf .= "i";

    $base_from = "
FROM enrollment_intention ei
LEFT JOIN enrollment_choices ec1 ON ei.id = ec1.enrollment_id AND ec1.choice_order = 1
LEFT JOIN enrollment_choices ec2 ON ei.id = ec2.enrollment_id AND ec2.choice_order = 2
LEFT JOIN enrollment_choices ec3 ON ei.id = ec3.enrollment_id AND ec3.choice_order = 3
";

    $t_nsbi = $conn->query("SHOW TABLES LIKE 'new_student_basic_info'");
    $has_nsbi = $t_nsbi && $t_nsbi->num_rows > 0;
    $students = [];
    $teacher_name = '';

    if ($has_nsbi) {
        $ns_t = function($col) { return "CONVERT(COALESCE(ns.{$col},'') USING utf8mb4) COLLATE utf8mb4_unicode_ci"; };
        $t_sd = $conn->query("SHOW TABLES LIKE 'school_data'");
        $table_school = ($t_sd && $t_sd->num_rows > 0) ? 'school_data' : null;
        $school_join = '';
        $school_display_join = '';
        $junior_high_select = "ei.junior_high";
        $school_cond = "(" . $ns_t('previous_school') . " = CONVERT(COALESCE(ei.junior_high,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci OR ns.previous_school IS NULL OR TRIM(" . $ns_t('previous_school') . ") = '')";
        if ($table_school) {
            $school_join = " LEFT JOIN " . $table_school . " sd_t ON sd_t.school_code = ei.junior_high ";
            $school_display_join = " LEFT JOIN " . $table_school . " sd_display ON sd_display.school_code = ei.junior_high ";
            $junior_high_select = "COALESCE(sd_display.name, ei.junior_high) AS junior_high";
            $school_cond = "(" . $ns_t('previous_school') . " = CONVERT(COALESCE(ei.junior_high,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci
            OR (sd_t.school_code IS NOT NULL AND " . $ns_t('previous_school') . " = CONVERT(COALESCE(sd_t.name,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci)
            OR ns.previous_school IS NULL OR TRIM(" . $ns_t('previous_school') . ") = '')";
        }
        $sql = "
            SELECT
                ei.id AS enrollment_id,
                ei.name AS student_name,
                " . $junior_high_select . ",
                ei.phone1,
                ei.phone2,
                ns.student_no,
                ns.class_name,
                ns.created_at AS enrolled_at
            " . $base_from . "
            " . $school_join . "
            " . $school_display_join . "
            INNER JOIN new_student_basic_info ns
                ON TRIM(" . $ns_t('student_name') . ") = TRIM(CONVERT(COALESCE(ei.name,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci)
               AND (" . $school_cond . ")
               AND (
                    (ei.phone1 IS NOT NULL AND ei.phone1 <> '' AND TRIM(REPLACE(REPLACE(REPLACE(REPLACE(" . $ns_t('mobile') . ", ' ', ''), '-', ''), '(', ''), ')', '')) = TRIM(REPLACE(REPLACE(REPLACE(REPLACE(CONVERT(COALESCE(ei.phone1,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci, ' ', ''), '-', ''), '(', ''), ')', '')))
                 OR (ei.phone2 IS NOT NULL AND ei.phone2 <> '' AND TRIM(REPLACE(REPLACE(REPLACE(REPLACE(" . $ns_t('mobile') . ", ' ', ''), '-', ''), '(', ''), ')', '')) = TRIM(REPLACE(REPLACE(REPLACE(REPLACE(CONVERT(COALESCE(ei.phone2,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci, ' ', ''), '-', ''), '(', ''), ')', '')))
               )
            " . $scope_where_perf . "
            AND " . $ns_t('status') . " IN ('', '在學')
            ORDER BY ei.name
        ";
        $st = $conn->prepare($sql);
        if ($st) {
            $st->bind_param($bind_types_perf, ...$bind_params_perf);
            $st->execute();
            $students = $st->get_result()->fetch_all(MYSQLI_ASSOC);
            $st->close();
        }
        $st_u = $conn->prepare("SELECT name, username FROM user WHERE id = ?");
        if ($st_u) {
            $st_u->bind_param("i", $teacher_id);
            $st_u->execute();
            $ru = $st_u->get_result()->fetch_assoc();
            $teacher_name = $ru ? ($ru['name'] ?: $ru['username'] ?: '') : '';
            $st_u->close();
        }
    }

    echo json_encode([
        'success' => true,
        'teacher_name' => $teacher_name,
        'students' => $students
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '系統錯誤', 'error' => $e->getMessage()]);
}
