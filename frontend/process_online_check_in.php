<?php
// 關閉錯誤顯示，避免輸出到 JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);

// 開啟輸出緩衝，捕獲任何意外輸出
ob_start();

// 設置 JSON 響應頭
header('Content-Type: application/json; charset=utf-8');

// 引入資料庫設定
require_once '../../Topics-frontend/frontend/config.php';

// 清除輸出緩衝（確保沒有意外輸出）
ob_clean();

// 檢查是否為 POST 請求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '僅支援 POST 請求']);
    exit;
}

// 獲取表單資料
$session_id = isset($_POST['session_id']) ? intval($_POST['session_id']) : 0;
$name = isset($_POST['name']) ? trim($_POST['name']) : '';
$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
$notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';

// 清除輸出緩衝（確保沒有意外輸出）
ob_clean();

// 驗證必填欄位
if ($session_id === 0) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => '缺少場次ID']);
    exit;
}

if (empty($name)) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => '請輸入姓名']);
    exit;
}

if (empty($phone)) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => '請輸入電話號碼']);
    exit;
}

// 驗證 Email 格式（如果有填寫）
if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Email 格式不正確']);
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
    $stmt = $conn->prepare("SELECT id, session_name FROM admission_sessions WHERE id = ?");
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
    
    // 檢查 online_check_in_records 表是否存在，如果不存在則創建
    $table_check = $conn->query("SHOW TABLES LIKE 'online_check_in_records'");
    if (!$table_check || $table_check->num_rows == 0) {
        // 創建表（不包含外鍵約束，避免依賴問題）
        $create_table_sql = "CREATE TABLE IF NOT EXISTS `online_check_in_records` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `session_id` int(11) NOT NULL COMMENT '場次ID',
          `application_id` int(11) DEFAULT NULL COMMENT '報名ID (admission_applications.id)，如果沒有報名則為 NULL',
          `name` varchar(255) NOT NULL COMMENT '姓名',
          `email` varchar(255) DEFAULT NULL COMMENT 'Email',
          `phone` varchar(50) DEFAULT NULL COMMENT '電話',
          `notes` text DEFAULT NULL COMMENT '備註（用於標記沒有報名但有來的人）',
          `is_registered` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否有報名: 0=未報名, 1=已報名',
          `check_in_time` datetime DEFAULT CURRENT_TIMESTAMP COMMENT '簽到時間',
          `created_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT '建立時間',
          `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新時間',
          PRIMARY KEY (`id`),
          KEY `idx_session_id` (`session_id`),
          KEY `idx_application_id` (`application_id`),
          KEY `idx_check_in_time` (`check_in_time`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='線上簽到記錄表'";
        
        if (!$conn->query($create_table_sql)) {
            throw new Exception("創建資料表失敗: " . $conn->error);
        }
    }
    
    // 嘗試根據姓名和電話找到對應的報名記錄
    $application_id = null;
    $is_registered = 0;
    
    // 正規化電話號碼（只取數字）
    $normalized_phone = preg_replace('/\D+/', '', $phone);
    
    // 優先：同時比對姓名和電話（最嚴格）
    if (!empty($name) && !empty($normalized_phone)) {
        $find_stmt = $conn->prepare("
            SELECT id FROM admission_applications 
            WHERE session_id = ? 
            AND student_name = ?
            AND (
                REPLACE(REPLACE(REPLACE(REPLACE(contact_phone, '-', ''), ' ', ''), '(', ''), ')', '') = ?
                OR REPLACE(REPLACE(REPLACE(REPLACE(contact_phone, '-', ''), ' ', ''), '(', ''), ')', '') LIKE ?
            )
            LIMIT 1
        ");
        $phone_exact = $normalized_phone;
        $phone_pattern = '%' . $normalized_phone . '%';
        $find_stmt->bind_param("isss", $session_id, $name, $phone_exact, $phone_pattern);
        $find_stmt->execute();
        $result = $find_stmt->get_result();
        if ($result->num_rows > 0) {
            $application = $result->fetch_assoc();
            $application_id = $application['id'];
            $is_registered = 1;
        }
        $find_stmt->close();
    }
    
    // 如果還沒找到，嘗試只根據電話號碼匹配（可能姓名有差異）
    if (!$application_id && !empty($normalized_phone)) {
        $find_stmt = $conn->prepare("
            SELECT id FROM admission_applications 
            WHERE session_id = ? 
            AND (
                REPLACE(REPLACE(REPLACE(REPLACE(contact_phone, '-', ''), ' ', ''), '(', ''), ')', '') = ?
                OR REPLACE(REPLACE(REPLACE(REPLACE(contact_phone, '-', ''), ' ', ''), '(', ''), ')', '') LIKE ?
            )
            LIMIT 1
        ");
        $phone_exact = $normalized_phone;
        $phone_pattern = '%' . $normalized_phone . '%';
        $find_stmt->bind_param("iss", $session_id, $phone_exact, $phone_pattern);
        $find_stmt->execute();
        $result = $find_stmt->get_result();
        if ($result->num_rows > 0) {
            $application = $result->fetch_assoc();
            $application_id = $application['id'];
            $is_registered = 1;
        }
        $find_stmt->close();
    }
    
    // 如果還沒找到，嘗試根據姓名和 Email 匹配
    if (!$application_id && !empty($name) && !empty($email)) {
        $find_stmt = $conn->prepare("
            SELECT id FROM admission_applications 
            WHERE session_id = ? 
            AND (student_name = ? OR email = ?)
            LIMIT 1
        ");
        $find_stmt->bind_param("iss", $session_id, $name, $email);
        $find_stmt->execute();
        $result = $find_stmt->get_result();
        if ($result->num_rows > 0) {
            $application = $result->fetch_assoc();
            $application_id = $application['id'];
            $is_registered = 1;
        }
        $find_stmt->close();
    }
    
    // 初始化變數
    $application_notes = '';
    $was_auto_created = false;
    
    // 如果沒有找到報名記錄，自動在 admission_applications 創建記錄
    if (!$is_registered) {
        // 檢查 admission_applications 表是否有 notes 欄位，如果沒有則添加
        $column_check = $conn->query("SHOW COLUMNS FROM admission_applications LIKE 'notes'");
        if (!$column_check || $column_check->num_rows == 0) {
            $conn->query("ALTER TABLE `admission_applications` ADD COLUMN `notes` text DEFAULT NULL COMMENT '備註（主要用於記錄沒有報名但有來聽演講的人員）'");
        }
        
        // 在 admission_applications 中創建新記錄
        $application_notes = '未報名但有來';
        if (!empty($notes)) {
            $application_notes = '未報名但有來 - ' . $notes;
        }
        $was_auto_created = true;
        
        // 獲取場次資訊以獲取必要欄位
        $session_stmt = $conn->prepare("SELECT * FROM admission_sessions WHERE id = ?");
        $session_stmt->bind_param("i", $session_id);
        $session_stmt->execute();
        $session_result = $session_stmt->get_result();
        $session_data = $session_result->fetch_assoc();
        $session_stmt->close();
        
        // 插入新的報名記錄
        // 確保所有參數都是變數（bind_param 需要變數引用）
        $email_value = !empty($email) ? $email : '';
        
        $insert_application_stmt = $conn->prepare("
            INSERT INTO admission_applications 
            (session_id, student_name, email, contact_phone, notes, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        
        $insert_application_stmt->bind_param("issss", 
            $session_id, 
            $name, 
            $email_value, 
            $phone, 
            $application_notes
        );
        
        if (!$insert_application_stmt->execute()) {
            throw new Exception("創建報名記錄失敗: " . $insert_application_stmt->error);
        }
        
        $application_id = $conn->insert_id;
        $is_registered = 1; // 現在已經有報名記錄了
        $insert_application_stmt->close();
        
        // 更新 online_check_in_records 的備註
        if (empty($notes)) {
            $notes = '未報名但有到場（已自動創建報名記錄）';
        } else {
            $notes = '未報名但有到場（已自動創建報名記錄） - ' . $notes;
        }
    } else {
        // 如果有找到報名記錄，在備註中標記
        if (empty($notes)) {
            $notes = '已報名且有到場';
        }
    }
    
    // 獲取當前時間作為簽到時間（用於 online_check_in_records 的 created_at）
    $check_in_time = date('Y-m-d H:i:s');
    
    // 插入簽到記錄
    $insert_stmt = $conn->prepare("
        INSERT INTO online_check_in_records 
        (session_id, application_id, name, email, phone, notes, is_registered, check_in_time, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $insert_stmt->bind_param("iissssiss", 
        $session_id, 
        $application_id, 
        $name, 
        $email, 
        $phone, 
        $notes, 
        $is_registered,
        $check_in_time,
        $check_in_time
    );
    
    if (!$insert_stmt->execute()) {
        throw new Exception("插入簽到記錄失敗: " . $insert_stmt->error);
    }
    
    $check_in_record_id = $conn->insert_id;
    $insert_stmt->close();
    
    // 同步更新 attendance_records 表（無論是否有報名記錄，現在都應該有 application_id）
    if ($application_id) {
        // 檢查 attendance_records 表是否存在
        $attendance_table_check = $conn->query("SHOW TABLES LIKE 'attendance_records'");
        if ($attendance_table_check && $attendance_table_check->num_rows > 0) {
            // 檢查是否已存在記錄
            $check_stmt = $conn->prepare("SELECT id, check_in_time FROM attendance_records WHERE session_id = ? AND application_id = ?");
            $check_stmt->bind_param("ii", $session_id, $application_id);
            $check_stmt->execute();
            $exists = $check_stmt->get_result()->fetch_assoc();
            $check_stmt->close();
            
            // 使用 online_check_in_records 的建立時間作為簽到時間
            // 如果已經有簽到時間且比現在早，則保留原來的時間；否則使用新的簽到時間
            $final_check_in_time = $check_in_time;
            if ($exists && !empty($exists['check_in_time']) && strtotime($exists['check_in_time']) < strtotime($check_in_time)) {
                $final_check_in_time = $exists['check_in_time'];
            }
            
            if ($exists) {
                // 更新現有記錄（確保狀態為已到，並更新簽到時間）
                $update_stmt = $conn->prepare("
                    UPDATE attendance_records 
                    SET attendance_status = 1, 
                        check_in_time = ?,
                        absent_time = NULL
                    WHERE session_id = ? AND application_id = ?
                ");
                $update_stmt->bind_param("sii", $final_check_in_time, $session_id, $application_id);
                $update_stmt->execute();
                $update_stmt->close();
            } else {
                // 新增記錄
                $insert_attendance_stmt = $conn->prepare("
                    INSERT INTO attendance_records 
                    (session_id, application_id, attendance_status, check_in_time, absent_time) 
                    VALUES (?, ?, 1, ?, NULL)
                ");
                $insert_attendance_stmt->bind_param("iis", $session_id, $application_id, $final_check_in_time);
                $insert_attendance_stmt->execute();
                $insert_attendance_stmt->close();
            }
        }
    }
    
    $conn->close();
    
    // 清除輸出緩衝並輸出 JSON
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    $message = $was_auto_created
        ? "簽到成功！已自動為您創建報名記錄。"
        : ($is_registered 
            ? "簽到成功！已找到您的報名記錄。" 
            : "簽到成功！感謝您的參與。");
    
    $response = json_encode([
        'success' => true, 
        'message' => $message,
        'is_registered' => $is_registered,
        'was_auto_created' => $was_auto_created
    ], JSON_UNESCAPED_UNICODE);
    
    if ($response === false) {
        // 如果 JSON 編碼失敗，輸出簡單的 JSON
        echo '{"success":false,"message":"處理成功但無法編碼回應"}';
    } else {
        echo $response;
    }
    
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
    
    // 確保輸出有效的 JSON
    $error_message = '處理失敗：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    $response = json_encode([
        'success' => false, 
        'message' => $error_message
    ], JSON_UNESCAPED_UNICODE);
    
    if ($response === false) {
        // 如果 JSON 編碼失敗，輸出簡單的 JSON
        echo '{"success":false,"message":"處理失敗：發生未知錯誤"}';
    } else {
        echo $response;
    }
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

