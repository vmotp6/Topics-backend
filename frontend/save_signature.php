<?php
require_once __DIR__ . '/session_config.php';

// 檢查登入狀態和角色權限
checkBackendLogin();

header('Content-Type: application/json; charset=utf-8');

try {
    // 獲取 POST 資料
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['signature']) || empty($input['signature'])) {
        throw new Exception('簽名資料為空');
    }

    $signatureData = $input['signature'];
    $user_id = $input['user_id'] ?? $_SESSION['user_id'] ?? 0;
    $document_id = isset($input['document_id']) && $input['document_id'] !== null ? (int)$input['document_id'] : null;
    $document_type = $input['document_type'] ?? 'general';
    $timestamp = $input['timestamp'] ?? date('Y-m-d H:i:s');

    if ($user_id <= 0) {
        throw new Exception('用戶ID無效');
    }

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
    $relative_path = 'uploads/signatures/' . $filename;
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    $stmt = $conn->prepare("
        INSERT INTO signatures (user_id, document_id, document_type, signature_path, signature_filename, created_at, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->bind_param("iissssss", 
        $user_id, 
        $document_id, 
        $document_type,
        $relative_path, 
        $filename,
        $timestamp, 
        $ip_address,
        $user_agent
    );
    
    $stmt->execute();
    $signature_id = $conn->insert_id;
    $stmt->close();
    $conn->close();

    // 構建返回資料
    $response = [
        'success' => true,
        'message' => '簽名儲存成功',
        'signature_id' => $signature_id,
        'signature_url' => $relative_path,
        'signature_filename' => $filename
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

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    
    error_log('簽名儲存錯誤: ' . $e->getMessage());
}
?>



