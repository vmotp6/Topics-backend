<?php
session_start();

// 檢查是否已登入
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '未登入']);
    exit;
}

// 檢查權限：admin1 或行政人員可以分配學生至主任
$username = isset($_SESSION['username']) ? $_SESSION['username'] : '';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$is_admission_center = ($username === 'admin1' || in_array($user_role, ['admin', '管理員', '學校行政人員']));

if (!$is_admission_center) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '權限不足，只有招生中心或行政人員可以進行此操作']);
    exit;
}

// 僅允許 POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '不支援的請求方法']);
    exit;
}

$student_id = isset($_POST['student_id']) ? intval($_POST['student_id']) : 0;
$department_account = isset($_POST['department']) ? trim($_POST['department']) : '';

// 僅允許指定的部門帳號
$allowed_departments = ['IMD', 'FLD'];
if ($student_id <= 0 || !in_array($department_account, $allowed_departments, true)) {
    echo json_encode(['success' => false, 'message' => '無效的參數']);
    exit;
}

require_once '../../Topics-frontend/frontend/config.php';

try {
    $conn = getDatabaseConnection();

    // 檢查 enrollment_intention 是否存在欄位 assigned_department
    $check_column = $conn->query("SHOW COLUMNS FROM enrollment_intention LIKE 'assigned_department'");
    if ($check_column->num_rows == 0) {
        // 先檢查是否有 status 欄位，如果沒有則在 remarks 之後添加
        $status_check = $conn->query("SHOW COLUMNS FROM enrollment_intention LIKE 'status'");
        if ($status_check && $status_check->num_rows > 0) {
            $alter_sql = "ALTER TABLE enrollment_intention ADD COLUMN assigned_department VARCHAR(50) NULL AFTER status";
        } else {
            // 檢查是否有 remarks 欄位，如果沒有則直接添加
            $remarks_check = $conn->query("SHOW COLUMNS FROM enrollment_intention LIKE 'remarks'");
            if ($remarks_check && $remarks_check->num_rows > 0) {
                $alter_sql = "ALTER TABLE enrollment_intention ADD COLUMN assigned_department VARCHAR(50) NULL AFTER remarks";
            } else {
                $alter_sql = "ALTER TABLE enrollment_intention ADD COLUMN assigned_department VARCHAR(50) NULL";
            }
        }
        $conn->query($alter_sql);
    }

    // 檢查學生是否存在
    $stmt = $conn->prepare("SELECT id, name FROM enrollment_intention WHERE id = ?");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $student = $result->fetch_assoc();
    if (!$student) {
        echo json_encode(['success' => false, 'message' => '找不到指定的學生']);
        exit;
    }

    // 更新學生的部門分配
    $update = $conn->prepare("UPDATE enrollment_intention SET assigned_department = ? WHERE id = ?");
    $update->bind_param("si", $department_account, $student_id);

    if ($update->execute()) {
        // 記錄分配日誌
        // 先檢查表是否存在，如果不存在則創建
        $table_check = $conn->query("SHOW TABLES LIKE 'assignment_logs'");
        if ($table_check->num_rows == 0) {
            $conn->query("CREATE TABLE assignment_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                teacher_id INT NULL,
                assigned_by VARCHAR(100) NOT NULL,
                assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            // 如果表已存在，檢查 teacher_id 欄位是否允許 NULL
            $column_check = $conn->query("SHOW COLUMNS FROM assignment_logs LIKE 'teacher_id'");
            if ($column_check->num_rows > 0) {
                $column_info = $column_check->fetch_assoc();
                if ($column_info['Null'] === 'NO') {
                    // 如果 teacher_id 不允許 NULL，則修改欄位
                    $conn->query("ALTER TABLE assignment_logs MODIFY COLUMN teacher_id INT NULL");
                }
            }
        }

        // 插入日誌記錄
        $log_stmt = $conn->prepare("INSERT INTO assignment_logs (student_id, teacher_id, assigned_by, assigned_at) VALUES (?, NULL, ?, NOW())");
        $assigned_by = $_SESSION['username'];
        $log_stmt->bind_param("is", $student_id, $assigned_by);
        $log_stmt->execute();

        echo json_encode([
            'success' => true,
            'message' => '已分配學生至主任帳號',
            'student_name' => $student['name'],
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


