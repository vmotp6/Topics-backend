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

// 引入資料庫設定
require_once '../../Topics-frontend/frontend/config.php';

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
    // 安全地建立資料庫連接，不使用 die() 函數
    $conn = new mysqli(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME);
    
    if ($conn->connect_error) {
        throw new Exception('資料庫連接失敗: ' . $conn->connect_error);
    }
    
    $conn->set_charset(DB_CHARSET);
    
    // 更新 status, reviewed_at, reviewer_id, review_notes
    $stmt = $conn->prepare("UPDATE continued_admission SET status = ?, reviewed_at = NOW(), reviewer_id = ?, review_notes = ? WHERE id = ?");
    if (!$stmt) {
        throw new Exception('SQL 準備失敗: ' . $conn->error);
    }
    
    $admin_username = $_SESSION['admin_username'] ?? 'admin'; // 從 session 獲取管理員名稱
    $stmt->bind_param("sssi", $new_status, $admin_username, $review_notes, $application_id);
    
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            echo json_encode(['success' => true, 'message' => '狀態更新成功']);
        } else {
            throw new Exception('沒有找到要更新的記錄，ID: ' . $application_id);
        }
    } else {
        throw new Exception('資料庫更新失敗: ' . $stmt->error);
    }
    
    $stmt->close();
    $conn->close();
} catch (Exception $e) {
    http_response_code(500);
    error_log("續招狀態更新失敗: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '伺服器錯誤：' . $e->getMessage()]);
}
?>