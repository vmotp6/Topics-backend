<?php
session_start();

// 檢查是否已登入
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '未登入']);
    exit;
}

// 檢查權限：招生中心/行政人員可以分配學生至主任
$username = isset($_SESSION['username']) ? $_SESSION['username'] : '';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$is_admission_center = ($username === 'admin1' || in_array($user_role, ['admin', '管理員', '學校行政人員', 'ADM', 'STA']));

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
// 前端會傳入科系代碼（例如 IM, AF），驗證該科系是否存在
$department_code = isset($_POST['department']) ? trim($_POST['department']) : '';
if ($student_id <= 0 || empty($department_code)) {
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

    // 驗證科系代碼是否存在於 departments 表
    $dep_check = $conn->prepare("SELECT code, name FROM departments WHERE code = ? LIMIT 1");
    $dep_check->bind_param("s", $department_code);
    $dep_check->execute();
    $dep_res = $dep_check->get_result();
    $department_row = $dep_res ? $dep_res->fetch_assoc() : null;
    if (!$department_row) {
        echo json_encode(['success' => false, 'message' => '指定的科系代碼不存在']);
        exit;
    }

    // 驗證：只能分配學生志願裡有的科系
    $choices_stmt = $conn->prepare("SELECT department_code FROM enrollment_choices WHERE enrollment_id = ? AND department_code IS NOT NULL AND department_code != ''");
    $choices_stmt->bind_param("i", $student_id);
    $choices_stmt->execute();
    $choices_result = $choices_stmt->get_result();
    $chosen_codes = [];
    if ($choices_result) {
        while ($r = $choices_result->fetch_assoc()) {
            if (!empty($r['department_code'])) {
                $chosen_codes[] = $r['department_code'];
            }
        }
    }
    $chosen_codes = array_values(array_unique($chosen_codes)); // 去重

    // 驗證：只能分配學生志願裡有的科系
    // 如果學生有填志願（至少一個非空志願），則檢查被指派的科系是否為該志願科系
    if (!empty($chosen_codes)) {
        if (!in_array($department_code, $chosen_codes)) {
            echo json_encode(['success' => false, 'message' => '只能分配學生志願裡有的科系，無法分配至 ' . $department_row['name']]);
            exit;
        }
    } else {
        // 如果學生沒有填寫任何志願，不允許分配
        echo json_encode(['success' => false, 'message' => '此學生尚未填寫志願，無法進行分配。請先確認學生是否已填寫志願。']);
        exit;
    }

    // 更新學生的部門分配
    // 更新 assigned_department 為科系代碼
    $update = $conn->prepare("UPDATE enrollment_intention SET assigned_department = ?, assigned_teacher_id = NULL WHERE id = ?");
    $update->bind_param("si", $department_code, $student_id);

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
            'message' => '已分配學生至科系',
            'student_name' => $student['name'],
            'department' => $department_row['code'],
            'department_name' => $department_row['name'] ?? ''
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => '分配失敗：' . $conn->error]);
    }

    $conn->close();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '系統錯誤：' . $e->getMessage()]);
}
?>


