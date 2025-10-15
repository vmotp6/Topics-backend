<?php
session_start();
header('Content-Type: application/json');

// 檢查管理員是否已登入
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '未授權，請先登入']);
    exit;
}

// 引入資料庫設定
require_once '../../Topics-frontend/frontend/config.php';

// 獲取從前端發送的 JSON 資料
$data = json_decode(file_get_contents('php://input'), true);
$application_id = $data['id'] ?? null;
$new_status = $data['status'] ?? null;

// 驗證輸入資料
$allowed_statuses = ['pending', 'approved', 'rejected', 'waitlist'];
if (!$application_id || !in_array($new_status, $allowed_statuses)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '無效的請求參數']);
    exit;
}

try {
    $conn = getDatabaseConnection();
    // 同時更新 status, reviewed_at, reviewed_by
    $stmt = $conn->prepare("UPDATE continued_admission SET status = ?, reviewed_at = NOW(), reviewed_by = ? WHERE id = ?");
    $admin_username = $_SESSION['admin_username'] ?? 'admin'; // 從 session 獲取管理員名稱

    $stmt->bind_param("ssi", $new_status, $admin_username, $application_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => '狀態更新成功']);
    } else {
        throw new Exception('資料庫更新失敗: ' . $stmt->error);
    }
    
    $stmt->close();
    $conn->close();
} catch (Exception $e) {
    http_response_code(500);
    error_log("續招狀態更新失敗: " . $e->getMessage()); // 新增錯誤日誌
    echo json_encode(['success' => false, 'message' => '伺服器錯誤：' . $e->getMessage()]);
}
?>