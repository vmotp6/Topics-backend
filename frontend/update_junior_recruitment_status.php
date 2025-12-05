<?php
// 使用輸出緩衝區，確保不會有意外輸出
ob_start();

session_start();

// 清除任何可能的輸出
ob_clean();

header('Content-Type: application/json');

// 檢查管理員是否已登入
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    http_response_code(401);
    ob_clean();
    echo json_encode(['success' => false, 'message' => '未授權，請先登入']);
    exit;
}

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
        http_response_code(500);
        ob_clean();
        echo json_encode(['success' => false, 'message' => '找不到資料庫設定檔案']);
        exit;
    }
}

require_once $config_path;

if (!function_exists('getDatabaseConnection')) {
    http_response_code(500);
    ob_clean();
    echo json_encode(['success' => false, 'message' => '資料庫連接函數未定義']);
    exit;
}

// 獲取從前端發送的 JSON 資料
$data = json_decode(file_get_contents('php://input'), true);
$application_id = $data['id'] ?? null;
$new_status = $data['status'] ?? null;
$admin_comment = $data['admin_comment'] ?? '';
$preferred_date = $data['preferred_date'] ?? null;
$preferred_time = $data['preferred_time'] ?? null;
$expected_students = $data['expected_students'] ?? null;
$venue_type = $data['venue_type'] ?? null;
$special_requirements = $data['special_requirements'] ?? null;
$remarks = $data['remarks'] ?? null;

// 驗證輸入資料
$allowed_statuses = ['pending', 'approved', 'rejected', 'waitlist'];
if (!$application_id) {
    http_response_code(400);
    ob_clean();
    echo json_encode(['success' => false, 'message' => '無效的請求參數：缺少申請ID']);
    exit;
}

// 如果提供了狀態，驗證狀態是否有效
if ($new_status !== null && !in_array($new_status, $allowed_statuses)) {
    http_response_code(400);
    ob_clean();
    echo json_encode(['success' => false, 'message' => '無效的狀態值']);
    exit;
}

// 將前端狀態值轉換為資料庫狀態代碼
// 資料庫狀態：'PE'=待審核, 'AP'=已錄取, 'RE'=未錄取, 'AD'=備取
$status_map = [
    'pending' => 'PE',
    'approved' => 'AP',
    'rejected' => 'RE',
    'waitlist' => 'AD'
];
$db_status = $status_map[$new_status] ?? $new_status;

try {
    $conn = getDatabaseConnection();
    
    // 檢查表是否存在
    $table_check = $conn->query("SHOW TABLES LIKE 'junior_school_recruitment_applications'");
    if (!$table_check || $table_check->num_rows === 0) {
        http_response_code(404);
        ob_clean();
        echo json_encode(['success' => false, 'message' => '找不到指定的資料表']);
        exit;
    }
    
    // 檢查記錄是否存在並獲取當前狀態
    $stmt_check = $conn->prepare("SELECT id, status FROM junior_school_recruitment_applications WHERE id = ?");
    $stmt_check->bind_param("i", $application_id);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    if ($result_check->num_rows === 0) {
        http_response_code(404);
        ob_clean();
        echo json_encode(['success' => false, 'message' => '找不到指定的申請記錄']);
        exit;
    }
    $current_record = $result_check->fetch_assoc();
    $current_status = $current_record['status'];
    $stmt_check->close();
    
    // 只有待審核(pending/PE)和待處理(waitlist/AD)可以修改狀態
    // 已批准(approved/AP)和已拒絕(rejected/RE)不能再修改狀態，但可以更新其他資料
    $can_modify_status = in_array($current_status, ['PE', 'pending', 'AD', 'waitlist']);
    $is_approved_or_rejected = in_array($current_status, ['AP', 'approved', 'RE', 'rejected']);
    
    // 檢查表是否有 status 欄位
    $column_check = $conn->query("SHOW COLUMNS FROM junior_school_recruitment_applications LIKE 'status'");
    if (!$column_check || $column_check->num_rows === 0) {
        // 如果沒有 status 欄位，嘗試添加
        $conn->query("ALTER TABLE junior_school_recruitment_applications ADD COLUMN status VARCHAR(10) DEFAULT 'PE'");
    }
    
    // 如果已批准或已拒絕，且嘗試修改狀態，則保持原狀態
    if ($is_approved_or_rejected && $new_status !== null) {
        // 已批准或已拒絕的申請，狀態保持不變
        if (in_array($current_status, ['AP', 'approved'])) {
            $new_status = 'approved';
            $db_status = 'AP';
        } elseif (in_array($current_status, ['RE', 'rejected'])) {
            $new_status = 'rejected';
            $db_status = 'RE';
        }
    }
    
    // 檢查是否有 updated_at 欄位
    $updated_at_check = $conn->query("SHOW COLUMNS FROM junior_school_recruitment_applications LIKE 'updated_at'");
    $has_updated_at = ($updated_at_check && $updated_at_check->num_rows > 0);
    
    // 檢查欄位是否存在
    $column_checks = [
        'admin_comment' => false,
        'preferred_date' => false,
        'preferred_time' => false,
        'expected_students' => false,
        'venue_type' => false,
        'special_requirements' => false,
        'remarks' => false
    ];
    
    foreach ($column_checks as $column => $exists) {
        $check = $conn->query("SHOW COLUMNS FROM junior_school_recruitment_applications LIKE '$column'");
        $column_checks[$column] = ($check && $check->num_rows > 0);
    }
    
    // 構建更新語句
    $update_fields = [];
    $update_values = [];
    $param_types = '';
    
    // 更新狀態（只有在提供了狀態且可以修改時才更新）
    if ($new_status !== null && ($can_modify_status || $is_approved_or_rejected)) {
        $update_fields[] = "status = ?";
        $update_values[] = $db_status;
        $param_types .= 's';
    }
    
    // 更新其他欄位
    if ($column_checks['preferred_date'] && $preferred_date !== null) {
        $update_fields[] = "preferred_date = ?";
        $update_values[] = $preferred_date;
        $param_types .= 's';
    }
    
    if ($column_checks['preferred_time'] && $preferred_time !== null) {
        $update_fields[] = "preferred_time = ?";
        $update_values[] = $preferred_time;
        $param_types .= 's';
    }
    
    if ($column_checks['expected_students'] && $expected_students !== null && $expected_students !== '') {
        $update_fields[] = "expected_students = ?";
        $update_values[] = intval($expected_students);
        $param_types .= 'i';
    }
    
    if ($column_checks['venue_type'] && $venue_type !== null) {
        $update_fields[] = "venue_type = ?";
        $update_values[] = $venue_type;
        $param_types .= 's';
    }
    
    if ($column_checks['special_requirements'] && $special_requirements !== null) {
        $update_fields[] = "special_requirements = ?";
        $update_values[] = $special_requirements;
        $param_types .= 's';
    }
    
    if ($column_checks['remarks'] && $remarks !== null) {
        $update_fields[] = "remarks = ?";
        $update_values[] = $remarks;
        $param_types .= 's';
    }
    
    if ($column_checks['admin_comment'] && $admin_comment !== null) {
        $update_fields[] = "admin_comment = ?";
        $update_values[] = $admin_comment;
        $param_types .= 's';
    }
    
    if ($has_updated_at) {
        $update_fields[] = "updated_at = NOW()";
    }
    
    if (empty($update_fields)) {
        http_response_code(400);
        ob_clean();
        echo json_encode(['success' => false, 'message' => '沒有要更新的欄位']);
        exit;
    }
    
    $update_values[] = $application_id;
    $param_types .= 'i';
    
    $sql = "UPDATE junior_school_recruitment_applications SET " . implode(", ", $update_fields) . " WHERE id = ?";
    $stmt = $conn->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param($param_types, ...$update_values);
        $stmt->execute();
        $affected_rows = $stmt->affected_rows;
        
        if ($stmt->error) {
            throw new Exception("更新失敗: " . $stmt->error);
        }
        $stmt->close();
    } else {
        throw new Exception("準備 SQL 語句失敗: " . $conn->error);
    }
    
    // 關閉資料庫連接
    $conn->close();
    
    if ($affected_rows > 0) {
        ob_clean();
        echo json_encode(['success' => true, 'message' => '資料更新成功']);
    } else {
        ob_clean();
        echo json_encode(['success' => false, 'message' => '沒有找到要更新的記錄']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    error_log("國中招生狀態更新失敗: " . $e->getMessage());
    // 確保清除任何輸出緩衝
    ob_clean();
    echo json_encode(['success' => false, 'message' => '伺服器錯誤：' . $e->getMessage()]);
    exit;
}

// 結束輸出緩衝
ob_end_flush();
?>

