<?php
require_once __DIR__ . '/session_config.php';

checkBackendLogin();

// 檢查權限：admin1 可以分配推薦學生至主任
$username = isset($_SESSION['username']) ? $_SESSION['username'] : '';
if ($username !== 'admin1') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '權限不足，只有admin1可以進行此操作']);
    exit;
}

// 僅允許 POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '不支援的請求方法']);
    exit;
}

$recommendation_id = isset($_POST['recommendation_id']) ? intval($_POST['recommendation_id']) : 0;
$department_account = isset($_POST['department']) ? trim($_POST['department']) : '';

// 僅允許指定的部門帳號
$allowed_departments = ['IMD', 'FLD'];
if ($recommendation_id <= 0 || !in_array($department_account, $allowed_departments, true)) {
    echo json_encode(['success' => false, 'message' => '無效的參數']);
    exit;
}

require_once '../../Topics-frontend/frontend/config.php';

try {
    $conn = getDatabaseConnection();

    // 檢查 admission_recommendations 是否存在欄位 assigned_department
    $check_column = $conn->query("SHOW COLUMNS FROM admission_recommendations LIKE 'assigned_department'");
    if ($check_column->num_rows == 0) {
        // 在 enrollment_status 之後添加
        $alter_sql = "ALTER TABLE admission_recommendations ADD COLUMN assigned_department VARCHAR(50) NULL AFTER enrollment_status";
        $conn->query($alter_sql);
    }

    // 檢查推薦記錄是否存在
    $stmt = $conn->prepare("SELECT id, student_name FROM admission_recommendations WHERE id = ?");
    $stmt->bind_param("i", $recommendation_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $recommendation = $result->fetch_assoc();
    if (!$recommendation) {
        echo json_encode(['success' => false, 'message' => '找不到指定的推薦記錄']);
        exit;
    }

    // 更新推薦記錄的部門分配
    $update = $conn->prepare("UPDATE admission_recommendations SET assigned_department = ? WHERE id = ?");
    $update->bind_param("si", $department_account, $recommendation_id);

    if ($update->execute()) {
        // 記錄分配日誌
        // 先檢查表是否存在，如果不存在則創建
        $table_check = $conn->query("SHOW TABLES LIKE 'recommendation_assignment_logs'");
        if ($table_check->num_rows == 0) {
            $conn->query("CREATE TABLE recommendation_assignment_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                recommendation_id INT NOT NULL,
                teacher_id INT NULL,
                assigned_by VARCHAR(100) NOT NULL,
                assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_recommendation_id (recommendation_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        // 插入日誌記錄
        $log_stmt = $conn->prepare("INSERT INTO recommendation_assignment_logs (recommendation_id, teacher_id, assigned_by, assigned_at) VALUES (?, NULL, ?, NOW())");
        $assigned_by = $_SESSION['username'];
        $log_stmt->bind_param("is", $recommendation_id, $assigned_by);
        $log_stmt->execute();

        echo json_encode([
            'success' => true,
            'message' => '已分配推薦學生至主任帳號',
            'student_name' => $recommendation['student_name'],
            'department' => $department_account
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => '分配失敗：' . $conn->error]);
    }

    $conn->close();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '系統錯誤：' . $e->getMessage()]);
}
?>

