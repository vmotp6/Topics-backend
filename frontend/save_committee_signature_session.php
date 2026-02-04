<?php
require_once __DIR__ . '/session_config.php';
checkBackendLogin();

header('Content-Type: application/json; charset=utf-8');

$user_id = (int)($_SESSION['user_id'] ?? 0);
$data = json_decode(file_get_contents('php://input'), true);
$signature_id = isset($data['signature_id']) ? (int)$data['signature_id'] : 0;
$year = isset($data['year']) ? (int)$data['year'] : (int)date('Y');

if ($signature_id <= 0) {
    echo json_encode([
        'success' => false,
        'message' => '無效的簽章 ID'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 驗證簽章是否屬於當前用戶
require_once '../../Topics-frontend/frontend/config.php';
$conn = getDatabaseConnection();

try {
    $stmt = $conn->prepare("
        SELECT id 
        FROM signatures 
        WHERE id = ? AND user_id = ?
          AND document_type = 'continued_admission_committee_confirm'
          AND document_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("iii", $signature_id, $user_id, $year);
    $stmt->execute();
    $result = $stmt->get_result();
    $valid = $result && $result->fetch_assoc();
    $stmt->close();
    $conn->close();
    
    if (!$valid) {
        echo json_encode([
            'success' => false,
            'message' => '簽章驗證失敗'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 將簽章 ID 保存到 session
    $session_signature_key = "committee_signature_{$year}_{$user_id}";
    $_SESSION[$session_signature_key] = $signature_id;
    
    echo json_encode([
        'success' => true,
        'message' => '簽章狀態已保存'
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    if (isset($conn) && $conn) {
        $conn->close();
    }
    echo json_encode([
        'success' => false,
        'message' => '保存失敗：' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>


