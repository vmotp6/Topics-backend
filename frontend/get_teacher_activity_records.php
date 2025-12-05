<?php
session_start();

// 檢查登入狀態
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => '未登入']);
    exit;
}

// 引入資料庫設定
require_once '../../Topics-frontend/frontend/config.php';

// 獲取使用者角色和資訊
$user_id = $_SESSION['user_id'] ?? 0;
$user_role = $_SESSION['role'] ?? '';

// 權限判斷
$is_admin = ($user_role === 'ADM');
$is_staff = ($user_role === 'STA');
$is_director = ($user_role === 'DI');

// 檢查權限：只有學校行政、管理員和主任可以訪問
if (!($is_admin || $is_staff || $is_director)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => '無權限']);
    exit;
}

// 獲取教師ID參數
$teacher_id = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;

if ($teacher_id <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => '無效的教師ID']);
    exit;
}

// 建立資料庫連接
$conn = getDatabaseConnection();
if (!$conn) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => '資料庫連接失敗']);
    exit;
}

// 如果是主任，驗證該教師是否屬於自己的科系
if ($is_director) {
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
        $user_department_code = null;
        if ($row = $result_dept->fetch_assoc()) {
            $user_department_code = $row['department'];
        }
        $stmt_dept->close();
        
        // 檢查該教師是否屬於主任的科系
        if ($user_department_code) {
            $teacher_dept_stmt = $conn->prepare("SELECT department FROM teacher WHERE user_id = ?");
            $teacher_dept_stmt->bind_param("i", $teacher_id);
            $teacher_dept_stmt->execute();
            $teacher_dept_result = $teacher_dept_stmt->get_result();
            if ($teacher_dept_row = $teacher_dept_result->fetch_assoc()) {
                if ($teacher_dept_row['department'] !== $user_department_code) {
                    $teacher_dept_stmt->close();
                    $conn->close();
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => '無權限查看該教師的紀錄']);
                    exit;
                }
            }
            $teacher_dept_stmt->close();
        }
        $conn_temp->close();
    } catch (Exception $e) {
        error_log('Error checking director department: ' . $e->getMessage());
    }
}

// 查詢教師的活動紀錄
$records_sql = "SELECT ar.*, at.name AS activity_type_name, sd.name AS school_name, u.name AS teacher_name
               FROM activity_records ar
               LEFT JOIN activity_types at ON ar.activity_type = at.ID
               LEFT JOIN school_data sd ON ar.school = sd.school_code
               LEFT JOIN teacher t ON ar.teacher_id = t.user_id
               LEFT JOIN user u ON t.user_id = u.id
               WHERE ar.teacher_id = ?
               ORDER BY ar.activity_date DESC, ar.id DESC";
$stmt = $conn->prepare($records_sql);
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$result = $stmt->get_result();
$records = [];
$teacher_name = '';

if ($result) {
    $records = $result->fetch_all(MYSQLI_ASSOC);
    if (!empty($records)) {
        $teacher_name = $records[0]['teacher_name'] ?? '';
    }
}
$stmt->close();
$conn->close();

// 返回 JSON 回應
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'records' => $records,
    'teacher_name' => $teacher_name
]);

