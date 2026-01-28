<?php
// 儲存線上簽到表單配置
// 僅招生中心身份可以儲存配置

// 關閉錯誤顯示
ini_set('display_errors', 0);
error_reporting(E_ALL);

// 開啟輸出緩衝
ob_start();

// 設置 JSON 響應頭
header('Content-Type: application/json; charset=utf-8');

// 引入資料庫設定和 session 配置
require_once '../../Topics-frontend/frontend/config.php';
require_once 'session_config.php';

// 清除輸出緩衝
ob_clean();

// 檢查是否為 POST 請求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '僅支援 POST 請求']);
    exit;
}

// 檢查是否為招生中心身份
$user_role = $_SESSION['role'] ?? '';
$is_admission_center = in_array($user_role, ['STA', 'STAM', '行政人員', '招生中心組員', 'ADM', '管理員']);

if (!$is_admission_center) {
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '權限不足：僅招生中心可以使用此功能']);
    exit;
}

// 獲取表單資料
$session_id = isset($_POST['session_id']) ? intval($_POST['session_id']) : 0;
$field_config_json = isset($_POST['field_config']) ? $_POST['field_config'] : '';

// 驗證必填欄位
if ($session_id === 0) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => '缺少場次ID']);
    exit;
}

if (empty($field_config_json)) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => '缺少欄位配置']);
    exit;
}

// 驗證 JSON 格式
$field_config = json_decode($field_config_json, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => '欄位配置格式錯誤：' . json_last_error_msg()]);
    exit;
}

if (!is_array($field_config) || empty($field_config)) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => '欄位配置不能為空']);
    exit;
}

try {
    // 建立資料庫連接
    if (!function_exists('getDatabaseConnection')) {
        throw new Exception('資料庫連接函數未定義');
    }
    
    $conn = getDatabaseConnection();
    
    if (!$conn) {
        throw new Exception('無法建立資料庫連接');
    }
    
    // 檢查場次是否存在
    $stmt = $conn->prepare("SELECT id FROM admission_sessions WHERE id = ?");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $session_result = $stmt->get_result();
    $session = $session_result->fetch_assoc();
    $stmt->close();
    
    if (!$session) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => '找不到指定的場次']);
        $conn->close();
        exit;
    }
    
    // 檢查表單配置表是否存在，如果不存在則創建
    $table_check = $conn->query("SHOW TABLES LIKE 'online_check_in_form_config'");
    if (!$table_check || $table_check->num_rows == 0) {
        $create_table_sql = "CREATE TABLE IF NOT EXISTS `online_check_in_form_config` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `session_id` int(11) NOT NULL COMMENT '場次ID',
          `field_config` text NOT NULL COMMENT '欄位配置 JSON',
          `check_in_url` varchar(500) DEFAULT NULL COMMENT '簽到連結',
          `created_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT '建立時間',
          `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新時間',
          PRIMARY KEY (`id`),
          UNIQUE KEY `idx_session_id` (`session_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='線上簽到表單配置表'";
        
        if (!$conn->query($create_table_sql)) {
            throw new Exception("創建資料表失敗: " . $conn->error);
        }
    }
    
    // 生成簽到連結
    // 連結必須包含 fill_mode=1 參數，確保一般用戶看到的是填寫表單，而不是編輯表單
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $script_path = dirname($_SERVER['SCRIPT_NAME']);
    $check_in_url = $protocol . '://' . $host . $script_path . '/online_check_in.php?session_id=' . $session_id . '&fill_mode=1';
    
    // 檢查是否已存在配置
    $check_stmt = $conn->prepare("SELECT id FROM online_check_in_form_config WHERE session_id = ?");
    $check_stmt->bind_param("i", $session_id);
    $check_stmt->execute();
    $exists = $check_stmt->get_result()->fetch_assoc();
    $check_stmt->close();
    
    if ($exists) {
        // 更新現有配置
        $update_stmt = $conn->prepare("
            UPDATE online_check_in_form_config 
            SET field_config = ?, 
                check_in_url = ?,
                updated_at = NOW()
            WHERE session_id = ?
        ");
        $update_stmt->bind_param("ssi", $field_config_json, $check_in_url, $session_id);
        
        if (!$update_stmt->execute()) {
            throw new Exception("更新配置失敗: " . $update_stmt->error);
        }
        
        $update_stmt->close();
    } else {
        // 插入新配置
        $insert_stmt = $conn->prepare("
            INSERT INTO online_check_in_form_config 
            (session_id, field_config, check_in_url, created_at, updated_at) 
            VALUES (?, ?, ?, NOW(), NOW())
        ");
        $insert_stmt->bind_param("iss", $session_id, $field_config_json, $check_in_url);
        
        if (!$insert_stmt->execute()) {
            throw new Exception("插入配置失敗: " . $insert_stmt->error);
        }
        
        $insert_stmt->close();
    }
    
    $conn->close();
    
    // 清除輸出緩衝並輸出 JSON
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    echo json_encode([
        'success' => true, 
        'message' => '配置已儲存成功',
        'check_in_url' => $check_in_url
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // 清除輸出緩衝
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // 確保連接已關閉
    if (isset($conn) && $conn) {
        @$conn->close();
    }
    
    http_response_code(500);
    
    $error_message = '處理失敗：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    echo json_encode([
        'success' => false, 
        'message' => $error_message
    ], JSON_UNESCAPED_UNICODE);
} catch (Error $e) {
    // 捕獲 PHP 7+ 的 Error 類型
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    if (isset($conn) && $conn) {
        @$conn->close();
    }
    
    http_response_code(500);
    $error_message = '系統錯誤：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    echo json_encode([
        'success' => false, 
        'message' => $error_message
    ], JSON_UNESCAPED_UNICODE);
}
