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

