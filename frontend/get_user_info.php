<?php
/**
 * 獲取當前登入用戶的基本資訊
 */

// 清理輸出緩衝區，確保只輸出 JSON
if (ob_get_level()) {
    ob_end_clean();
}
ob_start();

require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/../../Topics-frontend/frontend/config.php';

// 清理可能的輸出
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

// 檢查登入狀態
checkBackendLogin();

$user_id = $_SESSION['user_id'] ?? 0;
if ($user_id <= 0) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => '未登入'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $conn = getDatabaseConnection();
    
    $stmt = $conn->prepare("SELECT id, username, name, email FROM user WHERE id = ? LIMIT 1");
    if (!$stmt) {
        throw new Exception('SQL 準備失敗: ' . $conn->error);
    }
    
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    
    if (!$user) {
        throw new Exception('找不到用戶資料');
    }
    
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'user' => [
            'id' => (int)$user['id'],
            'username' => $user['username'],
            'name' => $user['name'],
            'email' => $user['email']
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
