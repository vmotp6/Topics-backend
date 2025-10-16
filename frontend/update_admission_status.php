<?php
// 開啟錯誤顯示以便調試
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
header('Content-Type: application/json');

// 檢查管理員是否已登入
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '未授權，請先登入']);
    exit;
}

// 資料庫連接設定（與招生中心保持一致）
$host = '100.79.58.120';
$dbname = 'topics_good';
$db_username = 'root';
$db_password = '';

// 獲取從前端發送的 JSON 資料
$data = json_decode(file_get_contents('php://input'), true);
$application_id = $data['id'] ?? null;
$new_status = $data['status'] ?? null;
$review_notes = $data['review_notes'] ?? '';

// 驗證輸入資料
$allowed_statuses = ['pending', 'approved', 'rejected', 'waitlist'];
if (!$application_id || !in_array($new_status, $allowed_statuses)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '無效的請求參數']);
    exit;
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $db_username, $db_password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 檢查是否有 review_notes 欄位
    $stmt = $pdo->prepare("SHOW COLUMNS FROM continued_admission LIKE 'review_notes'");
    $stmt->execute();
    $has_review_notes = $stmt->rowCount() > 0;
    
    if ($has_review_notes) {
        // 如果有 review_notes 欄位，則更新包含備註
        $stmt = $pdo->prepare("UPDATE continued_admission SET status = ?, reviewed_at = NOW(), review_notes = ? WHERE id = ?");
        $stmt->execute([$new_status, $review_notes, $application_id]);
    } else {
        // 如果沒有 review_notes 欄位，則只更新狀態和時間
        $stmt = $pdo->prepare("UPDATE continued_admission SET status = ?, reviewed_at = NOW() WHERE id = ?");
        $stmt->execute([$new_status, $application_id]);
    }
    
    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => '狀態更新成功']);
    } else {
        echo json_encode(['success' => false, 'message' => '沒有找到要更新的記錄']);
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    error_log("續招狀態更新失敗: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '伺服器錯誤：' . $e->getMessage()]);
}
?>