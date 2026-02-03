<?php
// 確保輸出緩衝區是乾淨的，避免在 JSON 之前有輸出
if (ob_get_level() > 0) {
    ob_clean();
}

require_once __DIR__ . '/session_config.php';

// 檢查登入狀態和角色權限
checkBackendLogin();

header('Content-Type: application/json; charset=utf-8');

try {
    // 獲取 POST 資料
    $input = json_decode(file_get_contents('php://input'), true);
    
    $user_id = $input['user_id'] ?? $_SESSION['user_id'] ?? 0;
    $document_id = isset($input['document_id']) && $input['document_id'] !== null ? (int)$input['document_id'] : null;
    $document_type = $input['document_type'] ?? 'general';
    $timestamp = $input['timestamp'] ?? date('Y-m-d H:i:s');
    $authentication_method = $input['authentication_method'] ?? 'canvas';

    if ($user_id <= 0) {
        throw new Exception('用戶ID無效');
    }

    // 判斷是 WebAuthn 還是 Canvas 簽名
    if ($authentication_method === 'webauthn' && isset($input['webauthn_auth'])) {
        // WebAuthn 簽名處理
        $webauthn_data = $input['webauthn_auth'];
        $credential_id = $webauthn_data['credential_id'] ?? '';
        $authenticator_data = $webauthn_data['authenticator_data'] ?? '';
        $client_data_json = $webauthn_data['client_data_json'] ?? '';
        $signature = $webauthn_data['signature'] ?? '';
        $device_name = $webauthn_data['device_name'] ?? '未知設備';
        
        if (empty($credential_id) || empty($authenticator_data) || empty($client_data_json) || empty($signature)) {
            throw new Exception('WebAuthn 簽名資料不完整');
        }
        
        // WebAuthn 簽名不需要生成圖片，因為它是數位簽名
        // 但為了滿足資料庫 NOT NULL 約束，我們提供預設值
        // 可選：生成一個標記圖片用於顯示（需要 PHP GD 擴展）
        $signature_path = 'webauthn'; // 預設值，表示這是 WebAuthn 簽名
        $signature_filename = 'webauthn_' . $user_id . '_' . time() . '.webauthn';
        
        // 可選：如果 GD 擴展可用，生成一個標記圖片
        if (function_exists('imagecreatetruecolor')) {
            try {
                $upload_dir = __DIR__ . '/uploads/signatures/';
                if (!is_dir($upload_dir)) {
                    if (!mkdir($upload_dir, 0755, true)) {
                        throw new Exception('無法建立上傳目錄');
                    }
                }
                
                // 生成一個簡單的標記圖片
                $img = imagecreatetruecolor(400, 200);
                $bg_color = imagecolorallocate($img, 255, 255, 255);
                $text_color = imagecolorallocate($img, 0, 0, 0);
                imagefilledrectangle($img, 0, 0, 400, 200, $bg_color);
                imagestring($img, 5, 50, 80, 'WebAuthn Signature', $text_color);
                imagestring($img, 3, 50, 120, date('Y-m-d H:i:s'), $text_color);
                
                $filename = 'webauthn_' . $user_id . '_' . time() . '_' . uniqid() . '.png';
                $filepath = $upload_dir . $filename;
                imagepng($img, $filepath);
                imagedestroy($img);
                
                $signature_path = 'uploads/signatures/' . $filename;
                $signature_filename = $filename;
            } catch (Exception $e) {
                // GD 擴展不可用或生成圖片失敗，使用預設值
                // WebAuthn 簽名不需要圖片，這是可選的
                error_log("WebAuthn 簽名圖片生成失敗（可選）: " . $e->getMessage());
                // 保持預設值
            }
        }
        
    } else {
        // Canvas 簽名處理（原有邏輯）
        if (!isset($input['signature']) || empty($input['signature'])) {
            throw new Exception('簽名資料為空');
        }

        $signatureData = $input['signature'];

        // 移除 data:image/png;base64, 前綴
        if (strpos($signatureData, 'data:image') === 0) {
            $signatureData = preg_replace('/^data:image\/\w+;base64,/', '', $signatureData);
        }

        // 解碼 Base64
        $imageData = base64_decode($signatureData);
        
        if ($imageData === false) {
            throw new Exception('簽名資料解碼失敗');
        }

        // 驗證圖片資料（簡單檢查）
        if (strlen($imageData) < 100) {
            throw new Exception('簽名圖片資料過小，可能無效');
        }

        // 建立儲存目錄
        $upload_dir = __DIR__ . '/uploads/signatures/';
        if (!is_dir($upload_dir)) {
            if (!mkdir($upload_dir, 0755, true)) {
                throw new Exception('無法建立上傳目錄');
            }
        }

        // 生成唯一檔名
        $filename = 'signature_' . $user_id . '_' . time() . '_' . uniqid() . '.png';
        $filepath = $upload_dir . $filename;

        // 儲存圖片
        if (file_put_contents($filepath, $imageData) === false) {
            throw new Exception('檔案儲存失敗');
        }

        // 驗證圖片是否有效（嘗試讀取）
        $imageInfo = @getimagesize($filepath);
        if ($imageInfo === false) {
            @unlink($filepath); // 刪除無效檔案
            throw new Exception('儲存的圖片無效');
        }
        
        $signature_path = 'uploads/signatures/' . $filename;
        $signature_filename = $filename;
    }

    // 儲存到資料庫
    require_once '../../Topics-frontend/frontend/config.php';
    $conn = getDatabaseConnection();

    // 建立簽名記錄表（如果不存在）
    $create_table_sql = "
        CREATE TABLE IF NOT EXISTS signatures (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            document_id INT NULL,
            document_type VARCHAR(50) DEFAULT 'general',
            signature_path VARCHAR(255) NOT NULL,
            signature_filename VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL,
            ip_address VARCHAR(45),
            user_agent TEXT,
            INDEX idx_user_id (user_id),
            INDEX idx_document_id (document_id),
            INDEX idx_document_type (document_type),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    try {
        $conn->query($create_table_sql);
    } catch (Exception $e) {
        error_log("建立簽名表失敗（可能已存在）: " . $e->getMessage());
    }

    // 插入記錄
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    // 準備 WebAuthn 相關資料
    $webauthn_credential_id = null;
    $webauthn_authenticator_data = null;
    $webauthn_signature = null;
    $webauthn_client_data = null;
    
    if ($authentication_method === 'webauthn' && isset($input['webauthn_auth'])) {
        $webauthn_data = $input['webauthn_auth'];
        $webauthn_credential_id = $webauthn_data['credential_id'] ?? null;
        $webauthn_authenticator_data = $webauthn_data['authenticator_data'] ?? null;
        $webauthn_signature = $webauthn_data['signature'] ?? null;
        $webauthn_client_data = $webauthn_data['client_data_json'] ?? null;
    }
    
    // 檢查表結構是否包含新欄位
    $table_check = $conn->query("SHOW COLUMNS FROM signatures LIKE 'authentication_method'");
    $has_new_columns = $table_check && $table_check->num_rows > 0;
    
    if ($has_new_columns) {
        // 使用新結構（包含 WebAuthn 欄位）
        $stmt = $conn->prepare("
            INSERT INTO signatures (
                user_id, document_id, document_type, signature_path, signature_filename, 
                created_at, ip_address, user_agent, authentication_method,
                webauthn_credential_id, webauthn_authenticator_data, webauthn_signature, webauthn_client_data
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->bind_param("iisssssssssss", 
            $user_id, 
            $document_id, 
            $document_type,
            $signature_path, 
            $signature_filename,
            $timestamp, 
            $ip_address,
            $user_agent,
            $authentication_method,
            $webauthn_credential_id,
            $webauthn_authenticator_data,
            $webauthn_signature,
            $webauthn_client_data
        );
    } else {
        // 使用舊結構（向後兼容）
        $stmt = $conn->prepare("
            INSERT INTO signatures (user_id, document_id, document_type, signature_path, signature_filename, created_at, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->bind_param("iissssss", 
            $user_id, 
            $document_id, 
            $document_type,
            $signature_path, 
            $signature_filename,
            $timestamp, 
            $ip_address,
            $user_agent
        );
    }
    
    // 確保 signature_path 和 signature_filename 不為 null（滿足資料庫 NOT NULL 約束）
    if (empty($signature_path)) {
        $signature_path = 'webauthn';
    }
    if (empty($signature_filename)) {
        $signature_filename = 'webauthn_' . $user_id . '_' . time() . '.webauthn';
    }
    
    $stmt->execute();
    
    if ($stmt->error) {
        throw new Exception('資料庫錯誤: ' . $stmt->error);
    }
    
    $signature_id = $conn->insert_id;
    $stmt->close();
    $conn->close();

    // 構建返回資料
    $response = [
        'success' => true,
        'message' => '簽名儲存成功',
        'signature_id' => $signature_id,
        'signature_url' => $signature_path ?? null,
        'signature_filename' => $signature_filename ?? null
    ];

    // 如果有文件ID，可以添加重定向URL
    if ($document_id) {
        // 根據文件類型決定重定向URL
        switch ($document_type) {
            case 'admission':
                $response['redirect_url'] = 'continued_admission_detail.php?id=' . $document_id;
                break;
            default:
                // 預設不重定向
                break;
        }
    }

    // 確保輸出緩衝區是乾淨的
    if (ob_get_level() > 0) {
        ob_clean();
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit; // 確保在輸出 JSON 後立即退出

} catch (Exception $e) {
    // 確保輸出緩衝區是乾淨的
    if (ob_get_level() > 0) {
        ob_clean();
    }
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    
    error_log('簽名儲存錯誤: ' . $e->getMessage());
    exit; // 確保在輸出 JSON 後立即退出
}
?>











