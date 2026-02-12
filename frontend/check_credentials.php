<?php
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/includes/webauthn_helpers.php';

header('Content-Type: application/json; charset=utf-8');

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
    $credentials = getUserWebAuthnCredentials((int)$user_id);
    echo json_encode([
        'success' => true,
        'count' => count($credentials),
        'credentials' => $credentials
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '查詢失敗',
        'detail' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
