<?php
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
        die(json_encode(['success' => false, 'message' => '找不到資料庫設定檔案']));
    }
}

require_once $config_path;
require_once __DIR__ . '/includes/enrollment_assignment_log.php';

if (!function_exists('getDatabaseConnection')) {
    die(json_encode(['success' => false, 'message' => '資料庫連接函數未定義']));
}

header('Content-Type: application/json; charset=utf-8');

if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => '缺少學生ID']);
    exit;
}

$enrollment_id = intval($_GET['id']);

try {
    $conn = getDatabaseConnection();
    
    // 獲取學生基本信息
    $sql = "
        SELECT 
            ei.*,
            u.name AS teacher_name,
            u.username AS teacher_username,
            d.name AS assigned_department_name,
            sd.name AS junior_high_name,
            io.name AS current_grade_name,
            rt.name AS recommended_teacher_name,
            CASE 
                WHEN ei.identity = 1 THEN '學生'
                WHEN ei.identity = 2 THEN '家長'
                ELSE '未知'
            END AS identity_text,
            CASE 
                WHEN ei.gender = 1 THEN '男'
                WHEN ei.gender = 2 THEN '女'
                ELSE '未填寫'
            END AS gender_text
        FROM enrollment_intention ei
        LEFT JOIN user u ON ei.assigned_teacher_id = u.id
        LEFT JOIN departments d ON ei.assigned_department = d.code
        LEFT JOIN school_data sd ON ei.junior_high = sd.school_code
        LEFT JOIN identity_options io ON ei.current_grade = io.code
        LEFT JOIN user rt ON ei.recommended_teacher = rt.id
        WHERE ei.id = ?
    ";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('準備查詢失敗: ' . $conn->error);
    }
    
    $stmt->bind_param("i", $enrollment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => '找不到該學生資料']);
        $stmt->close();
        $conn->close();
        exit;
    }
    
    $student = $result->fetch_assoc();
    $stmt->close();
    
    // 獲取意願選項
    $choices_sql = "
        SELECT 
            ec.choice_order,
            ec.department_code,
            d.name AS department_name,
            ec.system_code,
            es.name AS system_name
        FROM enrollment_choices ec
        LEFT JOIN departments d ON ec.department_code = d.code
        LEFT JOIN education_systems es ON ec.system_code = es.code
        WHERE ec.enrollment_id = ?
        ORDER BY ec.choice_order ASC
    ";
    
    $choices_stmt = $conn->prepare($choices_sql);
    $choices_stmt->bind_param("i", $enrollment_id);
    $choices_stmt->execute();
    $choices_result = $choices_stmt->get_result();
    
    $choices = [];
    while ($row = $choices_result->fetch_assoc()) {
        $choices[] = $row;
    }
    $choices_stmt->close();
    
    // 僅招生中心：取得每意願的分配時間（enrollment_department_assignment_log）
    $is_admission_center = false;
    if (!empty($_SESSION['role']) && in_array($_SESSION['role'], ['ADM', 'STA'])) {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $has_dept = false;
        $t = @$conn->query("SHOW TABLES LIKE 'director'");
        if ($t && $t->num_rows > 0) {
            $st = $conn->prepare("SELECT 1 FROM director WHERE user_id = ? LIMIT 1");
            if ($st) { $st->bind_param("i", $uid); $st->execute(); if ($st->get_result()->num_rows > 0) $has_dept = true; $st->close(); }
        }
        if (!$has_dept) {
            $t2 = @$conn->query("SHOW TABLES LIKE 'teacher'");
            if ($t2 && $t2->num_rows > 0) {
                $st2 = $conn->prepare("SELECT 1 FROM teacher WHERE user_id = ? LIMIT 1");
                if ($st2) { $st2->bind_param("i", $uid); $st2->execute(); if ($st2->get_result()->num_rows > 0) $has_dept = true; $st2->close(); }
            }
        }
        $is_admission_center = !$has_dept;
    }
    if ($is_admission_center) {
        ensure_enrollment_assignment_log_table($conn);
        $log_sql = "SELECT choice_order, assigned_at, source FROM enrollment_department_assignment_log WHERE enrollment_id = ? ORDER BY choice_order ASC";
        $log_stmt = $conn->prepare($log_sql);
        if ($log_stmt) {
            $log_stmt->bind_param("i", $enrollment_id);
            $log_stmt->execute();
            $log_res = $log_stmt->get_result();
            $log_by_order = [];
            while ($lr = $log_res->fetch_assoc()) {
                $order = (int)$lr['choice_order'];
                $src = $lr['source'] ?? 'initial';
                $label = '';
                if ($src === 'initial') {
                    $label = ($order === 1) ? '系統自動分配給第一意願' : '系統分配';
                } elseif ($src === 'reassign') {
                    $label = '逾3日未聯絡自動轉入此意願';
                } else {
                    $label = '手動改派';
                }
                $log_by_order[$order] = [
                    'assigned_at' => $lr['assigned_at'],
                    'assignment_label' => $label
                ];
            }
            $log_stmt->close();
            foreach ($choices as &$c) {
                $order = (int)($c['choice_order'] ?? 0);
                $entry = $log_by_order[$order] ?? null;
                $c['assigned_at'] = $entry['assigned_at'] ?? null;
                $c['assignment_label'] = $entry['assignment_label'] ?? null;
            }
            unset($c);
            // 舊資料補登：若第一意願沒有歷程但已有分配科系，用建立時間顯示
            $assigned_dept = trim((string)($student['assigned_department'] ?? ''));
            $created_at = $student['created_at'] ?? null;
            if ($created_at && $assigned_dept !== '') {
                $first = null;
                foreach ($choices as $idx => $ch) {
                    if ((int)($ch['choice_order'] ?? 0) === 1) {
                        $first = $idx;
                        break;
                    }
                }
                if ($first !== null && (empty($choices[$first]['assigned_at']) || empty($choices[$first]['assignment_label']))) {
                    $match = (strtoupper(trim((string)($choices[$first]['department_code'] ?? ''))) === strtoupper($assigned_dept));
                    if ($match) {
                        $choices[$first]['assigned_at'] = $created_at;
                        $choices[$first]['assignment_label'] = '系統自動分配給第一意願（依建立時間）';
                    }
                }
            }
        }
    }
    
    // 獲取聯絡記錄數量（不獲取詳細內容，因為可以通過 contact_logs_api.php 獲取）
    $logs_count_sql = "SELECT COUNT(*) as count FROM enrollment_contact_logs WHERE enrollment_id = ?";
    $logs_count_stmt = $conn->prepare($logs_count_sql);
    $logs_count_stmt->bind_param("i", $enrollment_id);
    $logs_count_stmt->execute();
    $logs_count_result = $logs_count_stmt->get_result();
    $logs_count_row = $logs_count_result->fetch_assoc();
    $contact_logs_count = $logs_count_row['count'] ?? 0;
    $logs_count_stmt->close();
    
    $conn->close();
    
    // 組合返回數據
    $response = [
        'success' => true,
        'student' => $student,
        'choices' => $choices,
        'contact_logs_count' => $contact_logs_count
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log('get_student_details.php 錯誤: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '資料庫操作失敗: ' . $e->getMessage()]);
    if (isset($conn)) {
        $conn->close();
    }
}
?>

