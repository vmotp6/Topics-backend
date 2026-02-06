<?php
require_once __DIR__ . '/session_config.php';
checkBackendLogin();

header('Content-Type: application/json; charset=utf-8');

$user_id = (int)($_SESSION['user_id'] ?? 0);
$data = json_decode(file_get_contents('php://input'), true);
$signature_id = isset($data['signature_id']) ? (int)$data['signature_id'] : 0;

if ($signature_id <= 0) {
    echo json_encode([
        'success' => false,
        'message' => '無效的簽章 ID'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 驗證簽章是否屬於當前用戶（允許 continued_admission_score 或 teacher_score_session）
require_once '../../Topics-frontend/frontend/config.php';
$conn = getDatabaseConnection();

try {
    $stmt = $conn->prepare("
        SELECT id 
        FROM signatures 
        WHERE id = ? AND user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $signature_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $valid = $result && $result->fetch_assoc();
    $stmt->close();
    $conn->close();

    if (!$valid) {
        echo json_encode([
            'success' => false,
            'message' => '簽章驗證失敗（非本人簽章）'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 本輪簽名有效期限：24 小時
    $_SESSION['teacher_score_signature_id'] = $signature_id;
    $_SESSION['teacher_score_signature_at'] = time();

    echo json_encode([
        'success' => true,
        'message' => '本輪簽名已保存，評分時不必重複認證'
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
