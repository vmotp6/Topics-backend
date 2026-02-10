<?php
/**
 * 2FA 流程診斷測試
 */

// 清理輸出緩衝區
if (ob_get_level()) {
    ob_end_clean();
}
ob_start();

require_once __DIR__ . '/../session_config.php';
require_once __DIR__ . '/../../../Topics-frontend/frontend/config.php';

// 清理可能的輸出
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

$response = [
    'status' => 'OK',
    'tests' => [],
    'errors' => []
];

// 測試 1: 檢查登入狀態
$test1 = [
    'name' => '登入狀態檢查',
    'passed' => false,
    'message' => ''
];

$isLoggedIn = (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) ||
              (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true);

if ($isLoggedIn && isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0) {
    $test1['passed'] = true;
    $test1['message'] = '已登入，用戶 ID: ' . $_SESSION['user_id'];
} else {
    $test1['passed'] = false;
    $test1['message'] = '未登入或登入已過期';
    $response['status'] = 'ERROR';
}

$response['tests'][] = $test1;

// 如果已登入，進行後續測試
if ($test1['passed']) {
    $user_id = $_SESSION['user_id'];
    
    // 測試 2: 檢查資料庫連接
    $test2 = [
        'name' => '資料庫連接測試',
        'passed' => false,
        'message' => ''
    ];
    
    try {
        $conn = getDatabaseConnection();
        if ($conn) {
            $test2['passed'] = true;
            $test2['message'] = '資料庫連接成功';
            
            // 測試 3: 檢查用戶資料
            $test3 = [
                'name' => '用戶資料檢查',
                'passed' => false,
                'message' => ''
            ];
            
            $stmt = $conn->prepare("SELECT username, name, email FROM user WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();
            
            if ($user) {
                $test3['passed'] = true;
                $test3['message'] = '用戶: ' . $user['name'] . ' (' . $user['username'] . '), Email: ' . $user['email'];
            } else {
                $test3['passed'] = false;
                $test3['message'] = '找不到用戶資料';
                $response['status'] = 'ERROR';
            }
            $response['tests'][] = $test3;
            
            // 測試 4: 檢查 webauthn_2fa_codes 表
            $test4 = [
                'name' => '2FA 驗證碼表檢查',
                'passed' => false,
                'message' => ''
            ];
            
            $result = $conn->query("SHOW TABLES LIKE 'webauthn_2fa_codes'");
            if ($result && $result->num_rows > 0) {
                $test4['passed'] = true;
                
                // 檢查最近的驗證碼記錄
                $stmt = $conn->prepare("SELECT COUNT(*) as total, MAX(created_at) as latest FROM webauthn_2fa_codes WHERE user_id = ?");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result->fetch_assoc();
                $stmt->close();
                
                $test4['message'] = '表存在，該用戶有 ' . $row['total'] . ' 條記錄，最新: ' . ($row['latest'] ?? 'N/A');
            } else {
                $test4['passed'] = false;
                $test4['message'] = '表不存在';
                $response['status'] = 'ERROR';
            }
            $response['tests'][] = $test4;
            
            // 測試 5: 檢查 Email 發送功能
            $test5 = [
                'name' => 'Email 函數可用性檢查',
                'passed' => false,
                'message' => ''
            ];
            
            if (function_exists('sendEmail')) {
                $test5['passed'] = true;
                $test5['message'] = 'sendEmail 函數可用';
            } else {
                $test5['passed'] = false;
                $test5['message'] = 'sendEmail 函數不可用';
                $response['status'] = 'ERROR';
            }
            $response['tests'][] = $test5;
            
            $conn->close();
        } else {
            $test2['passed'] = false;
            $test2['message'] = '資料庫連接失敗';
            $response['status'] = 'ERROR';
        }
    } catch (Exception $e) {
        $test2['passed'] = false;
        $test2['message'] = $e->getMessage();
        $response['status'] = 'ERROR';
        $response['errors'][] = $e->getMessage();
    }
    
    $response['tests'][] = $test2;
}

// 測試 6: 檢查必要的常數
$test6 = [
    'name' => 'SMTP 設定檢查',
    'passed' => false,
    'message' => ''
];

if (defined('SMTP_HOST') && defined('SMTP_USERNAME') && defined('SMTP_PASSWORD')) {
    $test6['passed'] = true;
    $test6['message'] = 'SMTP 設定已定義: ' . SMTP_HOST;
} else {
    $test6['passed'] = false;
    $test6['message'] = '部分 SMTP 設定缺失';
    $response['status'] = 'ERROR';
}
$response['tests'][] = $test6;

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
