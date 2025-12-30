<?php
require_once __DIR__ . '/session_config.php';

checkBackendLogin();

// 檢查權限：招生中心/行政人員可以分配續招名單至主任
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

$application_id = isset($_POST['application_id']) ? intval($_POST['application_id']) : 0;
// 前端會傳入科系代碼（例如 IM, AF），驗證該科系是否存在
$department_code = isset($_POST['department']) ? trim($_POST['department']) : '';
if ($application_id <= 0 || empty($department_code)) {
    echo json_encode(['success' => false, 'message' => '無效的參數']);
    exit;
}

require_once '../../Topics-frontend/frontend/config.php';

try {
    $conn = getDatabaseConnection();

    // 檢查續招報名記錄是否存在
    $stmt = $conn->prepare("SELECT id, name FROM continued_admission WHERE id = ?");
    $stmt->bind_param("i", $application_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $application = $result->fetch_assoc();
    if (!$application) {
        echo json_encode(['success' => false, 'message' => '找不到指定的續招報名記錄']);
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
    $choices_stmt = $conn->prepare("SELECT department_code FROM continued_admission_choices WHERE application_id = ? AND department_code = ?");
    $choices_stmt->bind_param("is", $application_id, $department_code);
    $choices_stmt->execute();
    $choices_result = $choices_stmt->get_result();
    if (!$choices_result || $choices_result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => '只能分配學生志願裡有的科系，無法分配至 ' . $department_row['name']]);
        exit;
    }

    // 更新續招報名的部門分配
    $update = $conn->prepare("UPDATE continued_admission SET assigned_department = ? WHERE id = ?");
    $update->bind_param("si", $department_code, $application_id);

    if ($update->execute()) {
        echo json_encode([
            'success' => true,
            'message' => '已分配續招報名至科系',
            'student_name' => $application['name'],
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

