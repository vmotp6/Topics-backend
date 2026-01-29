<?php
require_once __DIR__ . '/session_config.php';
checkBackendLogin();

require_once '../../Topics-frontend/frontend/config.php';
require_once __DIR__ . '/includes/continued_admission_ranking.php';

header('Content-Type: application/json; charset=utf-8');

$user_role = $_SESSION['role'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;
$is_director = ($user_role === 'DI');
$is_admin_or_staff = in_array($user_role, ['ADM', 'STA']);

$department_code = $_GET['department'] ?? '';

if (empty($department_code)) {
    echo json_encode([
        'success' => false,
        'message' => '請提供科系代碼'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 權限檢查：主任只能查看自己科系，招生中心可以查看所有科系
if ($is_director && !$is_admin_or_staff) {
    // 獲取主任的科系
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
        $user_dept = null;
        if ($row = $result_dept->fetch_assoc()) {
            $user_dept = $row['department'];
        }
        $stmt_dept->close();
        $conn_temp->close();
        
        if ($user_dept !== $department_code) {
            echo json_encode([
                'success' => false,
                'message' => '權限不足：只能查看自己科系的排名數據'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } catch (Exception $e) {
        error_log('Error fetching user department: ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => '系統錯誤：無法驗證權限'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

try {
    $conn = getDatabaseConnection();
    
    // 獲取科系名稱
    $dept_stmt = $conn->prepare("SELECT name FROM departments WHERE code = ?");
    $dept_stmt->bind_param("s", $department_code);
    $dept_stmt->execute();
    $dept_result = $dept_stmt->get_result();
    $dept_row = $dept_result->fetch_assoc();
    $dept_stmt->close();
    
    $department_name = $dept_row ? $dept_row['name'] : $department_code;
    
    // 獲取排名數據
    $ranking_data = getDepartmentRanking($conn, $department_code);
    
    if (empty($ranking_data)) {
        $ranking_data = [
            'department_code' => $department_code,
            'department_name' => $department_name,
            'total_quota' => 0,
            'cutoff_score' => 60,
            'applications' => []
        ];
    } else {
        $ranking_data['department_name'] = $department_name;
    }
    
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'department' => $ranking_data
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log('get_department_ranking.php error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => '系統錯誤：' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>



