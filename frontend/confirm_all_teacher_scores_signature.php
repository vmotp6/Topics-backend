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

require_once '../../Topics-frontend/frontend/config.php';
$conn = getDatabaseConnection();

try {
    // 驗證簽章屬於當前用戶
    $stmt = $conn->prepare("SELECT id FROM signatures WHERE id = ? AND user_id = ? LIMIT 1");
    $stmt->bind_param("ii", $signature_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!$result || !$result->fetch_assoc()) {
        $stmt->close();
        $conn->close();
        echo json_encode([
            'success' => false,
            'message' => '簽章驗證失敗（非本人簽章）'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $stmt->close();

    $current_year = (int)date('Y');

    // 將當前用戶「今年」所有尚未簽名的評分，全部寫入此簽章 ID
    $update_stmt = $conn->prepare("
        UPDATE continued_admission_scores cas
        INNER JOIN continued_admission ca ON ca.id = cas.application_id
        SET cas.signature_id = ?
        WHERE cas.reviewer_user_id = ? AND cas.signature_id IS NULL
          AND LEFT(ca.apply_no, 4) = ?
    ");
    $year_str = (string)$current_year;
    $update_stmt->bind_param("iis", $signature_id, $user_id, $year_str);
    $update_stmt->execute();
    $affected = $conn->affected_rows;
    $update_stmt->close();
    $conn->close();

    echo json_encode([
        'success' => true,
        'message' => '已簽名確認全部評分',
        'affected' => $affected
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if (isset($conn) && $conn) {
        $conn->close();
    }
    echo json_encode([
        'success' => false,
        'message' => '寫入失敗：' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
