<?php
/**
 * 畢業生大學類型統計 API（依屆別）- 後台同源版
 * 供 activity_records 切換屆別時取得資料，不重新整理頁面。
 */
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../session_config.php';
checkBackendLogin();

require_once __DIR__ . '/../../../Topics-frontend/frontend/config.php';
if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USERNAME') || !defined('DB_PASSWORD') || !defined('DB_CHARSET')) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '資料庫配置未定義']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

function getGraduateInputRangeByGraduationRocYear($graduation_roc_year) {
    $enroll_roc_year = (int)$graduation_roc_year - 5;
    $start_west = $enroll_roc_year + 1911;
    $end_west = $enroll_roc_year + 1912;
    return [
        'start' => sprintf('%04d-07-01 00:00:00', $start_west),
        'end' => sprintf('%04d-08-01 23:59:59', $end_west)
    ];
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USERNAME, DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $roc_year = isset($_GET['roc_year']) ? (int)$_GET['roc_year'] : 0;
    $department_filter = isset($_GET['department']) ? trim((string)$_GET['department']) : '';

    $current_roc_year = (int)date('Y') - 1911;
    if ($roc_year <= 0) {
        $roc_year = $current_roc_year;
    }

    $range = getGraduateInputRangeByGraduationRocYear($roc_year);
    $year_start = $range['start'];
    $year_end = $range['end'];

    $graduate_university_stats_by_class = ['both' => [], 'xiao' => [], 'zhong' => []];

    $table_ns = $pdo->query("SHOW TABLES LIKE 'new_student_basic_info'");
    $table_ut = $pdo->query("SHOW TABLES LIKE 'university_types'");
    if ($table_ns && $table_ns->rowCount() > 0 && $table_ut && $table_ut->rowCount() > 0) {
        $class_modes = [
            'both' => "(s.class_name LIKE '%孝%' OR s.class_name LIKE '%忠%')",
            'xiao' => "s.class_name LIKE '%孝%'",
            'zhong' => "s.class_name LIKE '%忠%'"
        ];
        foreach ($class_modes as $mode => $class_where) {
            $sql = "SELECT COALESCE(u.type_name, '未填寫') AS type_name, COUNT(*) AS cnt
                    FROM new_student_basic_info s
                    LEFT JOIN university_types u ON TRIM(UPPER(TRIM(COALESCE(s.university,'')))) = TRIM(UPPER(u.type_code))
                    WHERE s.created_at >= ? AND s.created_at <= ? AND $class_where
                      AND TRIM(COALESCE(s.university, '')) <> ''
                    GROUP BY u.type_code, u.type_name
                    ORDER BY (CASE WHEN u.id IS NULL THEN 999 ELSE u.id END), cnt DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$year_start, $year_end]);
            $rows = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rows[] = ['type_name' => (string)($row['type_name'] ?? '未填寫'), 'cnt' => (int)($row['cnt'] ?? 0)];
            }
            if (empty($rows)) {
                $sql_no_date = "SELECT COALESCE(u.type_name, '未填寫') AS type_name, COUNT(*) AS cnt
                    FROM new_student_basic_info s
                    LEFT JOIN university_types u ON TRIM(UPPER(TRIM(COALESCE(s.university,'')))) = TRIM(UPPER(u.type_code))
                    WHERE $class_where AND TRIM(COALESCE(s.university, '')) <> ''
                    GROUP BY u.type_code, u.type_name
                    ORDER BY (CASE WHEN u.id IS NULL THEN 999 ELSE u.id END), cnt DESC";
                $stmt2 = $pdo->query($sql_no_date);
                while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
                    $rows[] = ['type_name' => (string)($row['type_name'] ?? '未填寫'), 'cnt' => (int)($row['cnt'] ?? 0)];
                }
            }
            $graduate_university_stats_by_class[$mode] = $rows;
        }
    }
    $graduate_university_stats = $graduate_university_stats_by_class['both'];

    $per_class_stats_list = [];
    $table_ns2 = $pdo->query("SHOW TABLES LIKE 'new_student_basic_info'");
    if ($table_ns2 && $table_ns2->rowCount() > 0) {
        $has_uni_school = $pdo->query("SHOW COLUMNS FROM new_student_basic_info LIKE 'university_school'")->rowCount() > 0;
        $has_uni_dept = $pdo->query("SHOW COLUMNS FROM new_student_basic_info LIKE 'university_dept'")->rowCount() > 0;
        $per_select_school = $has_uni_school ? "ns.university_school" : "'' AS university_school";
        $per_select_dept = $has_uni_dept ? "ns.university_dept" : "'' AS university_dept";

        $per_dept_col = null;
        foreach (['department_id', 'department', 'department_code', 'dept_code', 'dept'] as $dc) {
            if ($pdo->query("SHOW COLUMNS FROM new_student_basic_info LIKE '$dc'")->rowCount() > 0) {
                $per_dept_col = $dc;
                break;
            }
        }
        $per_select_dept_col = $per_dept_col ? "ns.{$per_dept_col}" : "''";
        $per_dept_join = '';
        $per_dept_where = '';
        $per_select_dept_name = "'' AS dept_display";
        if ($per_dept_col) {
            if ($pdo->query("SHOW TABLES LIKE 'departments'")->rowCount() > 0) {
                $per_dept_join = " LEFT JOIN departments pd ON ns.{$per_dept_col} COLLATE utf8mb4_unicode_ci = pd.code COLLATE utf8mb4_unicode_ci ";
                $per_select_dept_name = "COALESCE(NULLIF(TRIM(pd.name),''), ns.{$per_dept_col}, '') AS dept_display";
            }
            if ($department_filter !== '') {
                $per_dept_where = " AND (ns.{$per_dept_col} = :dept OR COALESCE(pd.name,'') = :dept2)";
            }
        }

        $per_sql = "SELECT ns.class_name, ns.university, $per_select_school, $per_select_dept, $per_select_dept_col AS dept_val, $per_select_dept_name 
                    FROM new_student_basic_info ns $per_dept_join 
                    WHERE (ns.class_name LIKE '%孝%' OR ns.class_name LIKE '%忠%') AND ns.created_at >= :start AND ns.created_at <= :end $per_dept_where";
        $stmt = $pdo->prepare($per_sql);
        $stmt->bindValue(':start', $year_start);
        $stmt->bindValue(':end', $year_end);
        if ($department_filter !== '' && $per_dept_where !== '') {
            $stmt->bindValue(':dept', $department_filter);
            $stmt->bindValue(':dept2', $department_filter);
        }
        $stmt->execute();
        $per_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($per_rows)) {
            $per_sql_fallback = "SELECT ns.class_name, ns.university, $per_select_school, $per_select_dept, $per_select_dept_col AS dept_val, $per_select_dept_name 
                                FROM new_student_basic_info ns $per_dept_join 
                                WHERE (ns.class_name LIKE '%孝%' OR ns.class_name LIKE '%忠%') $per_dept_where";
            $stmt2 = $pdo->prepare($per_sql_fallback);
            if ($department_filter !== '' && $per_dept_where !== '') {
                $stmt2->bindValue(':dept', $department_filter);
                $stmt2->bindValue(':dept2', $department_filter);
            }
            $stmt2->execute();
            $per_rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        }

        $per_class_stats = [];
        $per_class_top_schools = [];
        foreach ($per_rows as $prow) {
            $cls = trim($prow['class_name'] ?? '') ?: '未分類';
            $dept_val = trim((string)($prow['dept_val'] ?? ''));
            $dept_display = trim((string)($prow['dept_display'] ?? '')) ?: $dept_val;
            $group_key = $dept_val . '|' . $cls;
            $per_class_stats[$group_key] = $per_class_stats[$group_key] ?? ['total' => 0, 'national' => 0, 'dept_display' => $dept_display, 'class_name' => $cls];
            $per_class_stats[$group_key]['total']++;
            $uni_type = strtoupper(trim((string)($prow['university'] ?? '')));
            if ($uni_type === 'NATIONAL' || mb_strpos((string)($prow['university'] ?? ''), '國立') !== false) {
                $per_class_stats[$group_key]['national']++;
            }
            $school = trim((string)($prow['university_school'] ?? ''));
            $dept = trim((string)($prow['university_dept'] ?? ''));
            if ($school !== '' && $dept !== '') {
                $school_dept_key = $school . '||' . $dept;
                $per_class_top_schools[$group_key][$school_dept_key] = ($per_class_top_schools[$group_key][$school_dept_key] ?? 0) + 1;
            }
        }

        foreach ($per_class_stats as $group_key => $info) {
            $total = $info['total'] ?? 0;
            $national = $info['national'] ?? 0;
            $percent = $total > 0 ? round(($national / $total) * 100, 1) : 0.0;
            $schools = $per_class_top_schools[$group_key] ?? [];
            arsort($schools);
            $top5_pairs = array_slice($schools, 0, 5, true);
            $top5 = [];
            foreach ($top5_pairs as $sd_key => $cnt) {
                $parts = explode('||', (string)$sd_key, 2);
                $top5[] = [
                    'school' => (string)($parts[0] ?? '未填寫'),
                    'department' => (string)($parts[1] ?? '未填寫'),
                    'count' => (int)$cnt
                ];
            }
            $cls = $info['class_name'] ?? '';
            $cls_display = str_replace(['資一孝班', '資一孝', '資一忠班', '資一忠'], ['孝班', '孝班', '忠班', '忠班'], $cls);
            $dept_part = ($info['dept_display'] ?? '') ?: trim(explode('|', $group_key, 2)[0] ?? '');
            $display_label = $dept_part ? ($dept_part . $cls_display) : $cls_display;
            $per_class_stats_list[] = [
                'class_name' => $display_label,
                'class_name_raw' => $cls,
                'dept_display' => $info['dept_display'] ?? '',
                'total' => $total,
                'national' => $national,
                'percent' => $percent,
                'top5' => $top5
            ];
        }
    }

    echo json_encode([
        'graduate_university_stats' => $graduate_university_stats,
        'graduate_university_stats_by_class' => $graduate_university_stats_by_class,
        'per_class_stats_list' => $per_class_stats_list
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('graduate_stats_api: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => '取得畢業生統計失敗', 'detail' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
