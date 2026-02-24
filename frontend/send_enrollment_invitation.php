<?php
/**
 * 自動寫入就讀意願名單：與年級無關，只要是「高意願」就直接寫入 enrollment_intention，不發送 email。
 */
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/includes/enrollment_assignment_log.php';

header('Content-Type: application/json; charset=utf-8');
@ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

function json_response(array $payload): void {
    $extra = trim((string)ob_get_clean());
    if ($extra !== '') {
        $payload['_raw_output'] = $extra;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function ensure_enrollment_intention_columns(mysqli $conn): void {
    $cols = [
        'assigned_department' => "VARCHAR(50) NULL",
        'assigned_teacher_id' => "INT NULL",
        'graduation_year' => "INT NULL",
        'intention_level' => "VARCHAR(20) DEFAULT NULL",
        'follow_up_status' => "VARCHAR(30) DEFAULT 'tracking'",
    ];
    foreach ($cols as $col => $def) {
        $r = @$conn->query("SHOW COLUMNS FROM enrollment_intention LIKE '{$conn->real_escape_string($col)}'");
        if (!$r || $r->num_rows === 0) {
            @$conn->query("ALTER TABLE enrollment_intention ADD COLUMN {$col} {$def}");
        }
    }
}

function normalize_department_code(mysqli $conn, string $value): string {
    $v = trim($value);
    if ($v === '') return '';
    $stmt = $conn->prepare("SELECT code FROM departments WHERE code = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $v);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $row = $res->fetch_assoc()) {
            $stmt->close();
            return $row['code'];
        }
        $stmt->close();
    }
    $stmt = $conn->prepare("SELECT code FROM departments WHERE name = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $v);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $row = $res->fetch_assoc()) {
            $stmt->close();
            return $row['code'];
        }
        $stmt->close();
    }
    return $v;
}

$session_id = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;
if ($session_id === 0) json_response(['success' => false, 'message' => '無效的場次ID']);

try {
    if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
        json_response(['success' => false, 'message' => '未授權']);
    }

    $config_path = __DIR__ . '/../../Topics-frontend/frontend/config.php';
    if (!file_exists($config_path)) {
        $alt_paths = [
            __DIR__ . '/../Topics-frontend/frontend/config.php',
            dirname(__DIR__) . '/Topics-frontend/frontend/config.php',
        ];
        foreach ($alt_paths as $p) {
            if (file_exists($p)) { $config_path = $p; break; }
        }
    }
    if (!file_exists($config_path)) {
        json_response(['success' => false, 'message' => '找不到 config.php']);
    }
    require_once $config_path;
    if (!function_exists('getDatabaseConnection')) {
        json_response(['success' => false, 'message' => 'getDatabaseConnection 未定義']);
    }
    $conn = getDatabaseConnection();

    $stmt = $conn->prepare("SELECT * FROM admission_sessions WHERE id = ?");
    if (!$stmt) throw new Exception("準備 SQL 語句失敗：" . $conn->error);
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$session) {
        $conn->close();
        json_response(['success' => false, 'message' => '找不到場次資料']);
    }

    // 取得該場次所有報名者（與 attendance_management.php 同一查詢條件，確保同一批資料）
    $session_year = (int)date('Y', strtotime((string)$session['session_date']));
    $stmt = $conn->prepare("
        SELECT 
            aa.*, 
            aa.grade as grade_code,
            COALESCE(io.name, aa.grade) as grade,
            sd.name as school_name_display,
            ar.attendance_status,
            as_session.session_type
        FROM admission_applications aa
        LEFT JOIN school_data sd ON aa.school = sd.school_code
        LEFT JOIN identity_options io ON aa.grade = io.code
        LEFT JOIN admission_sessions as_session ON aa.session_id = as_session.id
        LEFT JOIN attendance_records ar ON aa.id = ar.application_id 
            AND ar.session_id = ? 
            AND (
                (ar.check_in_time IS NOT NULL AND YEAR(ar.check_in_time) = ?)
                OR (ar.check_in_time IS NULL AND ar.absent_time IS NOT NULL AND YEAR(ar.absent_time) = ?)
                OR (ar.check_in_time IS NULL AND ar.absent_time IS NULL)
            )
        WHERE aa.session_id = ? AND YEAR(aa.created_at) = ?
    ");
    if (!$stmt) throw new Exception("準備 SQL 語句失敗：" . $conn->error);
    $stmt->bind_param("iiiii", $session_id, $session_year, $session_year, $session_id, $session_year);
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // 意願度計分（與 attendance_management.php 一致）：有報名+1、有簽到+3、實體場+2、2場以上+2、社團活動+1、技藝班+1
    $qualified_students = [];
    foreach ($students as $student) {
        $score = 0;
        $score += 1;
        if (isset($student['attendance_status']) && (int)$student['attendance_status'] === 1) {
            $score += 3;
        }
        if (isset($student['session_type']) && (int)$student['session_type'] === 2) {
            $score += 2;
        }
        $email = $student['email'] ?? '';
        $phone = $student['contact_phone'] ?? '';
        if (!empty($email) || !empty($phone)) {
            $count_stmt = $conn->prepare("
                SELECT COUNT(DISTINCT session_id) as session_count
                FROM admission_applications
                WHERE YEAR(created_at) = ? AND (email = ? OR contact_phone = ?)
            ");
            if ($count_stmt) {
                $count_stmt->bind_param("iss", $session_year, $email, $phone);
                $count_stmt->execute();
                $row = $count_stmt->get_result()->fetch_assoc();
                $count_stmt->close();
                if ($row && (int)($row['session_count'] ?? 0) >= 2) {
                    $score += 2;
                }
            }
        }
        if (!empty($student['joined_club_activity'])) {
            $score += 1;
        }
        if (!empty($student['attended_skill_class'])) {
            $score += 1;
        }
        if ($score >= 6) {
            $student['willingness_score'] = $score;
            $qualified_students[] = $student;
        }
    }

    // 確認就讀意願表存在
    $tbl = $conn->query("SHOW TABLES LIKE 'enrollment_intention'");
    if (!$tbl || $tbl->num_rows === 0) {
        $conn->close();
        json_response(['success' => false, 'message' => '資料表 enrollment_intention 不存在']);
    }
    ensure_enrollment_intention_columns($conn);

    $written_count = 0;
    $failed_count = 0;
    $errors = [];
    $session_name = (string)($session['session_name'] ?? '');
    $department_id = $session['department_id'] ?? '';
    $assigned_department = !empty($department_id) ? normalize_department_code($conn, (string)$department_id) : '';
    $current_month = (int)date('m');
    $current_year = (int)date('Y');
    $graduation_year = ($current_month >= 8) ? $current_year + 1 : $current_year;

    foreach ($qualified_students as $student) {
        $name = trim((string)($student['student_name'] ?? ''));
        $email = trim((string)($student['email'] ?? ''));
        $phone = trim((string)($student['contact_phone'] ?? ''));

        if ($name === '') {
            $failed_count++;
            $errors[] = "姓名為空，略過";
            continue;
        }

        // 是否已在就讀意願名單（email 或 phone 匹配）
        $existing_id = null;
        if (!empty($email) || !empty($phone)) {
            $check = $conn->prepare("SELECT id FROM enrollment_intention WHERE (email = ? OR phone1 = ? OR phone2 = ?) LIMIT 1");
            if ($check) {
                $check->bind_param("sss", $email, $phone, $phone);
                $check->execute();
                $row = $check->get_result()->fetch_assoc();
                $check->close();
                if ($row) {
                    $existing_id = (int)$row['id'];
                }
            }
        }

        $priority1_raw = (string)($student['course_priority_1'] ?? '');
        $priority2_raw = (string)($student['course_priority_2'] ?? '');
        $priority1 = normalize_department_code($conn, $priority1_raw);
        $priority2 = normalize_department_code($conn, $priority2_raw);
        $dept = $assigned_department !== '' ? $assigned_department : $priority1;
        $school_code = trim((string)($student['school'] ?? ''));
        $grade = trim((string)($student['grade'] ?? ''));
        $remarks = "來自場次：{$session_name} (報名時間：" . date('Y-m-d H:i', strtotime($student['created_at'] ?? 'now')) . ")";

        try {
            if ($existing_id) {
                $intention_level = 'high';
                $follow_up_status = 'tracking';
                $upd = $conn->prepare("
                    UPDATE enrollment_intention
                    SET name = COALESCE(NULLIF(name,''), ?),
                        email = COALESCE(NULLIF(email,''), ?),
                        phone1 = COALESCE(NULLIF(phone1,''), ?),
                        junior_high = COALESCE(NULLIF(junior_high,''), ?),
                        current_grade = COALESCE(NULLIF(current_grade,''), ?),
                        assigned_department = COALESCE(NULLIF(assigned_department,''), ?),
                        intention_level = ?,
                        follow_up_status = COALESCE(NULLIF(follow_up_status,''), ?),
                        graduation_year = COALESCE(graduation_year, ?)
                    WHERE id = ?
                ");
                if (!$upd) throw new Exception('更新失敗');
                $upd->bind_param("ssssssssii", $name, $email, $phone, $school_code, $grade, $dept, $intention_level, $follow_up_status, $graduation_year, $existing_id);
                $upd->execute();
                $upd->close();
                $enrollment_id = $existing_id;
                if (!empty($dept) && count_enrollment_assignment_logs($conn, $enrollment_id) === 0) {
                    insert_enrollment_assignment_log($conn, $enrollment_id, $dept, 1, 'initial');
                }
            } else {
                $ins = $conn->prepare("
                    INSERT INTO enrollment_intention (
                        name, email, phone1, phone2, junior_high, current_grade,
                        identity, gender, line_id, facebook, remarks,
                        assigned_department, intention_level, follow_up_status, graduation_year,
                        created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, 1, 0, '', '', ?, ?, 'high', 'tracking', ?, NOW())
                ");
                if (!$ins) throw new Exception('寫入失敗');
                $phone2 = '';
                $ins->bind_param("sssssssssi", $name, $email, $phone, $phone2, $school_code, $grade, $remarks, $dept, $graduation_year);
                $ins->execute();
                $enrollment_id = (int)$conn->insert_id;
                $ins->close();
                if (!empty($dept) && $enrollment_id > 0) {
                    insert_enrollment_assignment_log($conn, $enrollment_id, $dept, 1, 'initial');
                }
            }

            if (!empty($priority1) && $enrollment_id > 0) {
                @$conn->query("ALTER TABLE enrollment_choices ADD UNIQUE KEY uniq_enrollment_choice (enrollment_id, choice_order)");
                $c1 = $conn->prepare("INSERT INTO enrollment_choices (enrollment_id, choice_order, department_code, system_code) VALUES (?, 1, ?, NULL) ON DUPLICATE KEY UPDATE department_code = VALUES(department_code)");
                if ($c1) {
                    $c1->bind_param("is", $enrollment_id, $priority1);
                    $c1->execute();
                    $c1->close();
                }
            }
            if (!empty($priority2) && $enrollment_id > 0) {
                $c2 = $conn->prepare("INSERT INTO enrollment_choices (enrollment_id, choice_order, department_code, system_code) VALUES (?, 2, ?, NULL) ON DUPLICATE KEY UPDATE department_code = VALUES(department_code)");
                if ($c2) {
                    $c2->bind_param("is", $enrollment_id, $priority2);
                    $c2->execute();
                    $c2->close();
                }
            }

            $written_count++;
        } catch (Throwable $e) {
            $failed_count++;
            $errors[] = "學生「{$name}」：" . $e->getMessage();
        }
    }

    $conn->close();

    json_response([
        'success' => true,
        'written_count' => $written_count,
        'failed_count' => $failed_count,
        'total_students' => count($students),
        'total_qualified' => count($qualified_students),
        'errors' => $errors
    ]);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => '寫入失敗：' . $e->getMessage()]);
}
