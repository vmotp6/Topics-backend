<?php
// 檔案：Topics-backend/frontend/submit_contact_log.php
require_once __DIR__ . '/session_config.php';

header('Content-Type: application/json; charset=utf-8');

checkBackendLogin();

// 引入資料庫設定
$config_path = '../../Topics-frontend/frontend/config.php';
if (!file_exists($config_path)) {
    $alt_paths = [
        '../../../Topics-frontend/frontend/config.php',
        __DIR__ . '/../../Topics-frontend/frontend/config.php',
        dirname(__DIR__) . '/../Topics-frontend/frontend/config.php'
    ];
    
    foreach ($alt_paths as $alt_path) {
        if (file_exists($alt_path)) {
            $config_path = $alt_path;
            break;
        }
    }
}

if (!file_exists($config_path)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '系統錯誤：找不到設定檔']);
    exit;
}

require_once $config_path;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

try {
    // 獲取並驗證輸入
    $enrollment_id = isset($_POST['enrollment_id']) ? (int)$_POST['enrollment_id'] : 0;
    $contact_date = $_POST['contact_date'] ?? date('Y-m-d');
    $method = $_POST['method'] ?? '其他';
    $notes = trim($_POST['notes'] ?? '');
    $teacher_id = $_SESSION['user_id']; // 紀錄是誰寫的

    if ($enrollment_id <= 0) {
        echo json_encode(['success' => false, 'message' => '無效的學生 ID']);
        exit;
    }

    if (empty($notes)) {
        echo json_encode(['success' => false, 'message' => '請輸入聯絡內容']);
        exit;
    }

    $conn = getDatabaseConnection();
    
    // 寫入資料庫
    // 使用 enrollment_contact_logs 表 (欄位：enrollment_id, teacher_id, contact_date, method, notes)
    $stmt = $conn->prepare("INSERT INTO enrollment_contact_logs (enrollment_id, teacher_id, contact_date, method, notes, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    
    if (!$stmt) {
        throw new Exception("Database prepare failed: " . $conn->error);
    }

    $stmt->bind_param("iisss", $enrollment_id, $teacher_id, $contact_date, $method, $notes);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => '紀錄新增成功']);
    } else {
        throw new Exception("Execute failed: " . $stmt->error);
    }

    $stmt->close();
    $conn->close();

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '系統錯誤: ' . $e->getMessage()]);
}
?>