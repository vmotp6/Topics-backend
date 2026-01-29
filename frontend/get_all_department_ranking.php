<?php
require_once __DIR__ . '/session_config.php';
checkBackendLogin();

require_once '../../Topics-frontend/frontend/config.php';
require_once __DIR__ . '/includes/continued_admission_ranking.php';

header('Content-Type: application/json; charset=utf-8');

$user_role = $_SESSION['role'] ?? '';
$is_admin_or_staff = in_array($user_role, ['ADM', 'STA']);

// 只有招生中心可以獲取所有科系的排名數據
if (!$is_admin_or_staff) {
    echo json_encode([
        'success' => false,
        'message' => '權限不足：只有招生中心可以查看所有科系的排名數據'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $conn = getDatabaseConnection();
    
    // 獲取所有科系
    $dept_result = $conn->query("SELECT code, name FROM departments WHERE code != 'AA' ORDER BY code");
    $departments = [];
    
    if ($dept_result) {
        while ($row = $dept_result->fetch_assoc()) {
            $dept_ranking = getDepartmentRanking($conn, $row['code']);
            if (!empty($dept_ranking) && !empty($dept_ranking['applications'])) {
                $departments[$row['code']] = [
                    'department_code' => $row['code'],
                    'department_name' => $row['name'],
                    'total_quota' => $dept_ranking['total_quota'] ?? 0,
                    'cutoff_score' => $dept_ranking['cutoff_score'] ?? 60,
                    'applications' => $dept_ranking['applications']
                ];
            }
        }
    }
    
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'departments' => $departments
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log('get_all_department_ranking.php error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => '系統錯誤：' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>

