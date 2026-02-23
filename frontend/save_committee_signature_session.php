<?php
require_once __DIR__ . '/session_config.php';

header('Content-Type: application/json; charset=utf-8');

checkBackendLogin();

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $signature_id = isset($input['signature_id']) ? (int)$input['signature_id'] : 0;
    $year = isset($input['year']) ? (int)$input['year'] : 0;

    if ($signature_id <= 0 || $year <= 0) {
        throw new Exception('參數錯誤');
    }

    $user_id = (int)($_SESSION['user_id'] ?? 0);
    if ($user_id <= 0) {
        throw new Exception('未登入');
    }

    $session_key = "committee_signature_{$year}_{$user_id}";
    $_SESSION[$session_key] = $signature_id;

    echo json_encode([
        'success' => true,
        'message' => '已保存簽章狀態'
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
