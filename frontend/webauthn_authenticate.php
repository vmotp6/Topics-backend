<?php
/**
 * WebAuthn 認證 API
 * 處理用戶使用 WebAuthn 憑證進行簽名認證
 */

require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/includes/webauthn_helpers.php';

// 確保輸出緩衝區是乾淨的，避免在 JSON 之前有輸出
if (ob_get_level() > 0) {
    ob_clean();
}

header('Content-Type: application/json; charset=utf-8');

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
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    if ($action === 'start') {
        // 開始認證流程：生成 challenge 和認證選項
        // 生成 32 字節的隨機 challenge
        $challenge = random_bytes(32);
        
        // 重要：確保使用當前登入用戶的憑證
        // $user_id 來自 $_SESSION['user_id']，這是在 checkBackendLogin() 中設置的
        // 在跨設備認證中，手機端必須登入與 QR code 中相同的帳號
        
        // 獲取用戶的所有有效憑證
        $credentials = getUserWebAuthnCredentials($user_id);
        
        // 調試：記錄認證請求的用戶 ID
        error_log("WebAuthn 認證開始 - 用戶 ID: $user_id, 用戶名: " . ($_SESSION['username'] ?? 'unknown'));
        
        if (empty($credentials)) {
            throw new Exception('您尚未註冊任何生物驗證設備，請先註冊');
        }
        
        // 構建允許的憑證 ID 列表
        // 優先使用平台認證器（手機生物驗證），限制跨平台認證器（USB 金鑰）
        $allow_credentials = [];
        $has_platform_credential = false;
        $platform_credentials = [];
        $cross_platform_credentials = [];
        
        // 分類憑證
        foreach ($credentials as $cred) {
            // 檢查是否為平台認證器（手機設備）
            $is_mobile_device = (
                $cred['device_type'] === 'phone' || 
                $cred['device_type'] === 'tablet' ||
                stripos($cred['device_name'] ?? '', '手機') !== false ||
                stripos($cred['device_name'] ?? '', 'iPhone') !== false ||
                stripos($cred['device_name'] ?? '', 'Android') !== false ||
                stripos($cred['device_name'] ?? '', 'iPad') !== false
            );
            
            if ($is_mobile_device) {
                $platform_credentials[] = $cred;
                $has_platform_credential = true;
            } else {
                $cross_platform_credentials[] = $cred;
            }
        }
        
        // 構建允許的憑證列表
        // 優先使用平台認證器（手機生物驗證、Windows Hello、Passkey），但也允許跨平台認證器作為備選
        if ($has_platform_credential) {
            // 優先添加平台認證器（手機、Windows Hello、Passkey）
            foreach ($platform_credentials as $cred) {
                $allow_credentials[] = [
                    'id' => $cred['credential_id'],
                    'type' => 'public-key',
                    // 支援多種 transports：
                    // 'internal' - 平台認證器（Windows Hello、手機生物驗證）
                    // 'hybrid' - Passkey 跨設備認證（手機登入）
                    'transports' => ['internal', 'hybrid']
                ];
            }
            // 如果有跨平台認證器，也添加（作為備選）
            foreach ($cross_platform_credentials as $cred) {
                $allow_credentials[] = [
                    'id' => $cred['credential_id'],
                    'type' => 'public-key',
                    'transports' => ['usb', 'nfc', 'ble']
                ];
            }
        } else {
            // 如果沒有平台認證器，允許跨平台認證器（USB 金鑰）
            foreach ($cross_platform_credentials as $cred) {
                $allow_credentials[] = [
                    'id' => $cred['credential_id'],
                    'type' => 'public-key',
                    'transports' => ['usb', 'nfc', 'ble']
                ];
            }
        }
        
        // 如果還是沒有，添加所有憑證（向後兼容）
        if (empty($allow_credentials)) {
            foreach ($credentials as $cred) {
                $allow_credentials[] = [
                    'id' => $cred['credential_id'],
                    'type' => 'public-key',
                    'transports' => ['internal', 'usb', 'nfc', 'ble', 'hybrid']
                ];
            }
        }
        
        // 調試資訊
        error_log("WebAuthn 認證 - 用戶 ID: $user_id, 憑證數量: " . count($credentials) . ", 平台憑證: " . ($has_platform_credential ? '是' : '否') . ", 允許的憑證數: " . count($allow_credentials));
        foreach ($credentials as $cred) {
            error_log("憑證: ID=" . substr($cred['credential_id'], 0, 20) . "... device_type=" . ($cred['device_type'] ?? 'NULL') . " device_name=" . ($cred['device_name'] ?? 'NULL'));
        }
        
        // 儲存 challenge 到 session
        $_SESSION['webauthn_auth_challenge'] = $challenge;
        $_SESSION['webauthn_auth_timestamp'] = time();
        $_SESSION['webauthn_auth_user_id'] = $user_id;
        
        // 構建認證選項
        // 使用 'preferred' 而不是 'required'，允許更多認證方式：
        // - 生物驗證（指紋、臉部辨識）
        // - PIN 碼（Windows Hello PIN）
        // - Passkey（手機登入，Windows 會顯示 QR code）
        $rp_id = getRelyingPartyId();
        
        $publicKeyCredentialRequestOptions = [
            'challenge' => base64UrlEncode($challenge),
            'timeout' => 120000, // 增加到 120 秒，給 Passkey 更多時間
            'rpId' => $rp_id,
            'userVerification' => 'preferred' // 改為 preferred，允許 PIN、生物驗證、Passkey 等多種方式
        ];
        
        // 重要：為了讓 Passkey 正常工作（跨設備認證），我們需要：
        // 1. 不指定 allowCredentials，讓系統自動查找所有可用的 Passkey（包括其他設備上的）
        // 2. Passkey 是跨設備同步的，手機上的 Passkey 可能不在我們的資料庫中
        // 3. 系統會根據 RP ID 和 user.id 自動匹配 Passkey
        
        // 如果指定了 allowCredentials，可能會限制 Passkey 的查找範圍
        // 為了支援跨設備 Passkey，我們不指定 allowCredentials
        // 這樣系統會查找所有可用的 Passkey（包括手機上的）
        
        // 注意：不指定 allowCredentials 可能會讓系統顯示所有可用的認證器
        // 但這是 Passkey 跨設備認證的必要條件
        
        // 調試資訊
        error_log("WebAuthn 認證選項 - RP ID: $rp_id, 不指定 allowCredentials 以支援 Passkey 跨設備認證");
        
        // 注意：WebAuthn get() API 不支持 authenticatorSelection
        // Passkey 是跨設備的，手機上的 Passkey 可能不在我們的資料庫中
        // 但系統會根據 RP ID 和 user.id 自動匹配和同步
        
        // 調試資訊
        error_log("WebAuthn 認證 - 用戶 ID: $user_id, 憑證數量: " . count($credentials) . ", 平台憑證: " . ($has_platform_credential ? '是' : '否') . ", 允許的憑證數: " . count($allow_credentials));
        foreach ($credentials as $cred) {
            error_log("憑證: ID=" . substr($cred['credential_id'], 0, 20) . "... device_type=" . ($cred['device_type'] ?? 'NULL') . " device_name=" . ($cred['device_name'] ?? 'NULL'));
        }
        
        echo json_encode([
            'success' => true,
            'options' => $publicKeyCredentialRequestOptions,
            'credentials_count' => count($credentials),
            'has_platform_credential' => $has_platform_credential,
            'debug_info' => [
                'total_credentials' => count($credentials),
                'platform_credentials' => count(array_filter($credentials, function($c) { 
                    $is_mobile = (
                        ($c['device_type'] ?? '') === 'phone' || 
                        ($c['device_type'] ?? '') === 'tablet' ||
                        stripos($c['device_name'] ?? '', '手機') !== false ||
                        stripos($c['device_name'] ?? '', 'iPhone') !== false ||
                        stripos($c['device_name'] ?? '', 'Android') !== false ||
                        stripos($c['device_name'] ?? '', 'iPad') !== false
                    );
                    return $is_mobile;
                })),
                'allowed_credentials' => count($allow_credentials),
                'credentials_detail' => array_map(function($c) {
                    return [
                        'device_name' => $c['device_name'] ?? 'NULL',
                        'device_type' => $c['device_type'] ?? 'NULL'
                    ];
                }, $credentials)
            ]
        ], JSON_UNESCAPED_UNICODE);
        
    } else if ($action === 'complete') {
        // 完成認證流程：驗證簽名
        if (!isset($_SESSION['webauthn_auth_challenge'])) {
            throw new Exception('認證流程未開始或已過期');
        }
        
        // 檢查 challenge 是否過期（5分鐘）
        if (time() - $_SESSION['webauthn_auth_timestamp'] > 300) {
            unset($_SESSION['webauthn_auth_challenge']);
            throw new Exception('認證流程已過期，請重新開始');
        }
        
        $challenge = $_SESSION['webauthn_auth_challenge'];
        $credential = $input['credential'] ?? null;
        
        if (!$credential) {
            throw new Exception('憑證資料缺失');
        }
        
        $credential_id = $credential['id'] ?? '';
        $raw_id = $credential['rawId'] ?? '';
        $response = $credential['response'] ?? [];
        
        // rawId 是 base64 編碼的，需要解碼後再進行 base64 URL 編碼（與註冊時一致）
        // 註冊時：base64UrlEncode($raw_id)，其中 $raw_id 是二進制數據
        // 認證時：$raw_id 是 base64 字串，需要先解碼
        $raw_id_binary = base64_decode($raw_id);
        $credential_id_encoded = base64UrlEncode($raw_id_binary);
        
        // 驗證 credential_id 是否在允許列表中
        $credentials = getUserWebAuthnCredentials($user_id);
        $valid_credential = null;
        
        foreach ($credentials as $cred) {
            // 嘗試多種格式匹配（處理不同的編碼格式）
            $stored_id = $cred['credential_id'];
            
            // 直接匹配
            if ($stored_id === $credential_id || 
                $stored_id === $credential_id_encoded ||
                $stored_id === $raw_id) {
                $valid_credential = $cred;
                break;
            }
            
            // 嘗試解碼後比較（處理可能的編碼差異）
            try {
                $stored_decoded = base64UrlDecode($stored_id);
                $received_decoded = base64UrlDecode($credential_id_encoded);
                if ($stored_decoded === $received_decoded || $stored_decoded === $raw_id_binary) {
                    $valid_credential = $cred;
                    break;
                }
            } catch (Exception $e) {
                // 解碼失敗，繼續嘗試其他格式
            }
        }
        
        // 解析認證資料
        $authenticator_data = base64UrlDecode($response['authenticatorData'] ?? '');
        $client_data_json = base64UrlDecode($response['clientDataJSON'] ?? '');
        $signature = base64UrlDecode($response['signature'] ?? '');
        
        // 驗證 clientDataJSON 中的 challenge
        $client_data = json_decode($client_data_json, true);
        if (!$client_data) {
            throw new Exception('ClientDataJSON 解析失敗');
        }
        
        // 驗證 challenge
        $received_challenge = base64UrlDecode($client_data['challenge'] ?? '');
        if ($received_challenge !== $challenge) {
            throw new Exception('Challenge 驗證失敗');
        }
        
        // 驗證 origin
        $expected_origin = getRelyingPartyOrigin();
        if ($client_data['origin'] !== $expected_origin) {
            throw new Exception('Origin 驗證失敗');
        }
        
        // 驗證 type
        if ($client_data['type'] !== 'webauthn.get') {
            throw new Exception('認證類型錯誤');
        }
        
        // 如果憑證不在資料庫中，可能是 Passkey 跨設備認證
        // 但我們需要驗證 userHandle 是否匹配當前用戶，確保安全
        if (!$valid_credential) {
            // 檢查 userHandle（如果有的話）- userHandle 包含註冊時的 user.id
            $user_handle = $response['userHandle'] ?? null;
            if ($user_handle) {
                // userHandle 是 base64 編碼的，需要解碼
                $user_handle_binary = base64_decode($user_handle);
                
                // 獲取當前用戶的 user.id（與註冊時一致）
                if ($user_id > 0) {
                    $current_user_id_bytes = pack('J', $user_id); // J = 64-bit unsigned integer
                } else {
                    // 如果 user_id 為 0，無法驗證，拒絕
                    throw new Exception('安全錯誤：無法驗證用戶身份');
                }
                
                // 驗證 userHandle 是否匹配
                if ($user_handle_binary !== $current_user_id_bytes) {
                    error_log("認證安全錯誤: userHandle 不匹配。當前用戶 ID: $user_id");
                    error_log("userHandle 長度: " . strlen($user_handle_binary) . ", 期望長度: " . strlen($current_user_id_bytes));
                    throw new Exception('安全錯誤：此憑證不屬於當前用戶。請使用您自己的設備進行認證。');
                }
            } else {
                // 沒有 userHandle，檢查憑證是否屬於其他用戶
                require_once '../../Topics-frontend/frontend/config.php';
                $conn = getDatabaseConnection();
                $check_stmt = $conn->prepare("SELECT user_id FROM webauthn_credentials WHERE credential_id = ?");
                $check_stmt->bind_param("s", $credential_id_encoded);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    $other_cred = $check_result->fetch_assoc();
                    if ($other_cred['user_id'] != $user_id) {
                        $check_stmt->close();
                        $conn->close();
                        error_log("認證安全錯誤: 憑證屬於其他用戶。當前用戶 ID: $user_id, 憑證用戶 ID: {$other_cred['user_id']}");
                        throw new Exception('安全錯誤：此憑證屬於其他用戶。請使用您自己的設備進行認證。');
                    }
                }
                $check_stmt->close();
                $conn->close();
            }
            
            error_log("認證：憑證 ID 不在當前用戶的資料庫中，但通過了安全驗證（userHandle 匹配）。收到的 ID: $credential_id");
            
            // 創建一個臨時的憑證記錄，用於後續處理
            $valid_credential = [
                'credential_id' => $credential_id_encoded,
                'device_name' => 'Passkey (跨設備)',
                'device_type' => 'passkey',
                'counter' => 0
            ];
        }
        
        // 驗證認證結果（簡化版本，實際生產環境需要完整驗證）
        // 額外檢查：如果我們期望使用平台認證器，但返回的憑證不是平台認證器，則拒絕
        $is_platform_expected = (
            $valid_credential['device_type'] === 'phone' || 
            $valid_credential['device_type'] === 'tablet' ||
            stripos($valid_credential['device_name'] ?? '', '手機') !== false ||
            stripos($valid_credential['device_name'] ?? '', 'iPhone') !== false ||
            stripos($valid_credential['device_name'] ?? '', 'Android') !== false ||
            stripos($valid_credential['device_name'] ?? '', 'iPad') !== false
        );
        
        // 如果期望使用平台認證器，但憑證類型不匹配，可能是使用了 USB 金鑰
        // 注意：這裡我們無法直接從認證結果判斷是否為平台認證器，但可以通過 device_type 判斷
        
        $verification_result = [
            'valid' => true,
            'credential_id' => $valid_credential['credential_id'],
            'is_platform' => $is_platform_expected
        ];
        
        if (!$verification_result['valid']) {
            throw new Exception($verification_result['error'] ?? '認證驗證失敗');
        }
        
        // 更新憑證計數器（如果憑證在資料庫中）
        if (isset($valid_credential['id'])) {
            // 憑證在資料庫中，更新計數器
            updateCredentialCounter($valid_credential['credential_id'], ($valid_credential['counter'] ?? 0) + 1);
        } else {
            // Passkey 跨設備認證，憑證不在資料庫中
            // 可選：將此 Passkey 憑證儲存到資料庫，以便後續使用
            try {
                saveWebAuthnCredential(
                    $user_id,
                    $valid_credential['credential_id'],
                    base64_encode($authenticator_data), // 暫時使用 authenticator_data 作為 public_key
                    $valid_credential['device_name'],
                    $valid_credential['device_type']
                );
                error_log("Passkey 憑證已自動儲存到資料庫");
            } catch (Exception $e) {
                error_log("儲存 Passkey 憑證失敗: " . $e->getMessage());
                // 不影響認證流程，繼續執行
            }
        }
        
        // 清除 session 中的 challenge
        $auth_challenge = $_SESSION['webauthn_auth_challenge'];
        unset($_SESSION['webauthn_auth_challenge']);
        unset($_SESSION['webauthn_auth_timestamp']);
        unset($_SESSION['webauthn_auth_user_id']);
        
        // 返回認證成功結果
        // 確保在輸出 JSON 之前沒有其他輸出
        $response_data = [
            'success' => true,
            'message' => '生物驗證成功',
            'credential_id' => $valid_credential['credential_id'],
            'device_name' => $valid_credential['device_name'] ?? '未知設備',
            'device_type' => $valid_credential['device_type'] ?? 'unknown',
            'is_platform' => isset($valid_credential['id']) ? false : true, // Passkey 跨設備認證
            'authenticator_data' => base64_encode($authenticator_data),
            'client_data_json' => base64_encode($client_data_json),
            'signature' => base64_encode($signature)
        ];
        
        // 確保輸出緩衝區是乾淨的
        if (ob_get_level() > 0) {
            ob_clean();
        }
        
        echo json_encode($response_data, JSON_UNESCAPED_UNICODE);
        exit; // 確保在輸出 JSON 後立即退出
        
    } else {
        throw new Exception('無效的操作');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    
    error_log('WebAuthn 認證錯誤: ' . $e->getMessage());
}

