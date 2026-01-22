<?php
require_once __DIR__ . '/session_config.php';
checkBackendLogin();

// 僅允許 招生中心（STA）與管理員（ADM）
$user_role = $_SESSION['role'] ?? '';
$allowed = ['STA', '行政人員', '學校行政人員', 'ADM', '管理員'];
if (!in_array($user_role, $allowed, true)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => '權限不足']);
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
    foreach ($alt_paths as $p) {
        if (file_exists($p)) { $config_path = $p; break; }
    }
}
if (!file_exists($config_path)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => '找不到資料庫設定檔案']);
    exit;
}
require_once $config_path;

// 計算當前學年度的開始日期
function getCurrentAcademicYearStart() {
    $current_month = (int)date('m');
    $current_year = (int)date('Y');
    
    // 如果當前月份 >= 6月，學年度從今年6月開始
    // 如果當前月份 < 6月，學年度從去年6月開始
    if ($current_month >= 6) {
        $start_year = $current_year;
    } else {
        $start_year = $current_year - 1;
    }
    
    return sprintf('%04d-06-01 00:00:00', $start_year);
}

header('Content-Type: application/json');

$student_id = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;

if ($student_id <= 0) {
    echo json_encode(['success' => false, 'message' => '無效的學生ID']);
    exit;
}

try {
    $conn = getDatabaseConnection();
    if (!$conn) {
        throw new Exception('資料庫連接失敗');
    }
    
    // 取得當前學年度開始日期
    $academic_year_start = getCurrentAcademicYearStart();
    
    // 更新學生的 created_at 到當前學年度開始日期
    $stmt = $conn->prepare("UPDATE new_student_basic_info SET created_at = ? WHERE id = ?");
    if (!$stmt) {
        throw new Exception('查詢準備失敗');
    }
    
    $stmt->bind_param('si', $academic_year_start, $student_id);
    $stmt->execute();
    
    if ($stmt->affected_rows > 0) {
        echo json_encode(['success' => true, 'message' => '已成功更新']);
    } else {
        echo json_encode(['success' => false, 'message' => '未找到該學生或無需更新']);
    }
    
    $stmt->close();
    $conn->close();
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>

