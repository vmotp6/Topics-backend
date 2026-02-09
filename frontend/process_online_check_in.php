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

// 清除輸出緩衝（確保沒有意外輸出）
ob_clean();

// 驗證必填欄位
if ($session_id === 0) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => '缺少場次ID']);
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
    $stmt = $conn->prepare("SELECT id, session_name, session_date, session_end_date, session_link FROM admission_sessions WHERE id = ?");
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
    
    // 獲取表單配置
    $form_config = null;
    $config_stmt = $conn->prepare("SELECT field_config FROM online_check_in_form_config WHERE session_id = ?");
    $config_stmt->bind_param("i", $session_id);
    $config_stmt->execute();
    $config_result = $config_stmt->get_result();
    if ($config_result->num_rows > 0) {
        $config_data = $config_result->fetch_assoc();
        $form_config = json_decode($config_data['field_config'], true);
    }
    $config_stmt->close();
    
    // 如果沒有配置，使用預設配置（向後兼容）
    if (!$form_config) {
        $form_config = [
            ['name' => 'name', 'label' => '姓名', 'type' => 'text', 'required' => true],
            ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => false],
            ['name' => 'phone', 'label' => '電話', 'type' => 'tel', 'required' => true],
            ['name' => 'school', 'label' => '就讀學校', 'type' => 'select', 'required' => false, 'options' => []],
            ['name' => 'grade', 'label' => '年級', 'type' => 'select', 'required' => false, 'options' => [['value' => '國一', 'label' => '國一'], ['value' => '國二', 'label' => '國二'], ['value' => '國三', 'label' => '國三']]],
            ['name' => 'notes', 'label' => '備註', 'type' => 'textarea', 'required' => false]
        ];
    }
    
    // 收集表單資料
    $form_data = [];
    $name = '';
    $email = '';
    $phone = '';
    $school = '';
    $grade = '';
    $notes = '';
    $custom_fields = [];
    
    foreach ($form_config as $field) {
        $field_name = $field['name'];
        $field_value = isset($_POST[$field_name]) ? trim($_POST[$field_name]) : '';
        $form_data[$field_name] = $field_value;
        
        // 保留標準欄位名稱以向後兼容
        if ($field_name === 'name') {
            $name = $field_value;
        } elseif ($field_name === 'email') {
            $email = $field_value;
        } elseif ($field_name === 'phone') {
            $phone = $field_value;
        } elseif ($field_name === 'school') {
            $school = $field_value;
        } elseif ($field_name === 'grade') {
            $grade = $field_value;
        } elseif ($field_name === 'notes') {
            $notes = $field_value;
        } else {
            // 自定義欄位
            $custom_fields[$field_name] = [
                'label' => $field['label'],
                'value' => $field_value
            ];
        }
        
        // 驗證必填欄位
        if (!empty($field['required']) && empty($field_value)) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => '請輸入' . $field['label']]);
            $conn->close();
            exit;
        }
        
        // 驗證 Email 格式
        if ($field['type'] === 'email' && !empty($field_value) && !filter_var($field_value, FILTER_VALIDATE_EMAIL)) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => $field['label'] . ' 格式不正確']);
            $conn->close();
            exit;
        }
    }
    
    // 如果使用預設配置，確保 name 和 phone 有值（向後兼容）
    if (empty($name) && empty($form_data['name'])) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => '請輸入姓名']);
        $conn->close();
        exit;
    }
    
    if (empty($phone) && empty($form_data['phone'])) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => '請輸入電話號碼']);
        $conn->close();
        exit;
    }
    
    // 使用表單資料中的值；若欄位空白且找到報名記錄，則以報名資料的學校、年級帶入
    $name = $form_data['name'] ?? $name;
    $phone = $form_data['phone'] ?? $phone;
    $email = $form_data['email'] ?? $email;
    $notes = $form_data['notes'] ?? $notes;
    if ($school === '' && $matched_school !== '') $school = $matched_school;
    if ($grade === '' && $matched_grade !== '') $grade = $matched_grade;
    
    // grade 轉成 identity_options 的 code（供 admission_applications 與 online_check_in_records 寫入，滿足 FK）
    if ($grade !== '') {
        $io_stmt = $conn->prepare("SELECT code FROM identity_options WHERE code = ? OR name = ? LIMIT 1");
        if ($io_stmt) {
            $io_stmt->bind_param("ss", $grade, $grade);
            $io_stmt->execute();
            $io_res = $io_stmt->get_result();
            if ($io_res && $io_res->num_rows > 0) {
                $row = $io_res->fetch_assoc();
                $grade = $row['code'];
            } else {
                $grade = null;
            }
            $io_stmt->close();
        } else {
            $grade = null;
        }
    } else {
        $grade = null;
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
          `school` varchar(255) DEFAULT NULL COMMENT '就讀學校（學校代碼或名稱）',
          `grade` varchar(20) DEFAULT NULL COMMENT '年級：國一/國二/國三',
          `notes` text DEFAULT NULL COMMENT '備註（用於標記沒有報名但有來的人）',
          `custom_fields` text DEFAULT NULL COMMENT '自定義欄位 JSON',
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
    } else {
        // 檢查是否有 custom_fields 欄位，如果沒有則添加
        $column_check = $conn->query("SHOW COLUMNS FROM online_check_in_records LIKE 'custom_fields'");
        if (!$column_check || $column_check->num_rows == 0) {
            $conn->query("ALTER TABLE `online_check_in_records` ADD COLUMN `custom_fields` text DEFAULT NULL COMMENT '自定義欄位 JSON' AFTER `notes`");
        }
        // 檢查是否有 school、grade 欄位，如果沒有則添加（放在 phone 後面）
        foreach (['school' => "varchar(255) DEFAULT NULL COMMENT '就讀學校（學校代碼或名稱）'", 'grade' => "varchar(20) DEFAULT NULL COMMENT '年級：國一/國二/國三'"] as $col => $def) {
            $col_check = $conn->query("SHOW COLUMNS FROM online_check_in_records LIKE '$col'");
            if (!$col_check || $col_check->num_rows == 0) {
                $after = $col === 'school' ? 'phone' : 'school';
                $conn->query("ALTER TABLE `online_check_in_records` ADD COLUMN `$col` $def AFTER `$after`");
            }
        }
    }
    
    // 將自定義欄位轉換為 JSON
    $custom_fields_json = !empty($custom_fields) ? json_encode($custom_fields, JSON_UNESCAPED_UNICODE) : null;
    
    // 嘗試根據姓名和電話找到對應的報名記錄
    // 重要：必須姓名和電話都完全符合，且只看今年度的報名資料
    $application_id = null;
    $is_registered = 0;
    
    // 正規化電話號碼（只取數字）
    $normalized_phone = preg_replace('/\D+/', '', $phone);
    
    // 獲取當前年份，只查詢今年的報名資料
    $current_year = (int)date('Y');
    
    // 必須同時比對姓名和電話（嚴格匹配），並帶出報名資料的學校、年級供自動帶入
    $matched_school = '';
    $matched_grade = '';
    if (!empty($name) && !empty($normalized_phone)) {
        $find_stmt = $conn->prepare("
            SELECT id, school, grade 
            FROM admission_applications 
            WHERE session_id = ? 
            AND student_name = ?
            AND REPLACE(REPLACE(REPLACE(REPLACE(contact_phone, '-', ''), ' ', ''), '(', ''), ')', '') = ?
            AND YEAR(created_at) = ?
            LIMIT 1
        ");
        $find_stmt->bind_param("issi", $session_id, $name, $normalized_phone, $current_year);
        $find_stmt->execute();
        $result = $find_stmt->get_result();
        if ($result->num_rows > 0) {
            $application = $result->fetch_assoc();
            $application_id = $application['id'];
            $is_registered = 1;
            $matched_school = trim((string)($application['school'] ?? ''));
            $matched_grade = trim((string)($application['grade'] ?? ''));
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
        
        // 插入新的報名記錄（沒有報名但有參加）：一併寫入學校、年級
        $email_value = !empty($email) ? $email : '';
        $aa_has_school = false;
        $aa_has_grade = false;
        $aa_cols = $conn->query("SHOW COLUMNS FROM admission_applications");
        if ($aa_cols) {
            while ($ac = $aa_cols->fetch_assoc()) {
                if (($ac['Field'] ?? '') === 'school') $aa_has_school = true;
                if (($ac['Field'] ?? '') === 'grade') $aa_has_grade = true;
            }
            $aa_cols->free();
        }
        if ($aa_has_school && $aa_has_grade) {
            $insert_application_stmt = $conn->prepare("
                INSERT INTO admission_applications 
                (session_id, student_name, email, contact_phone, notes, school, grade, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $insert_application_stmt->bind_param("issssss",
                $session_id,
                $name,
                $email_value,
                $phone,
                $application_notes,
                $school,
                $grade
            );
        } else {
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
        }
        if (!$insert_application_stmt->execute()) {
            throw new Exception("創建報名記錄失敗: " . $insert_application_stmt->error);
        }
        
        $application_id = $conn->insert_id;
        // 重要：自動創建的記錄不應該標記為已報名（is_registered = 0）
        // 因為這是未報名但有到場的情況
        $is_registered = 0; // 保持為未報名狀態
        $insert_application_stmt->close();
        
        // 更新 online_check_in_records 的備註
        if (empty($notes)) {
            $notes = '未報名但有到場（已自動創建報名記錄）';
        } else {
            $notes = '未報名但有到場（已自動創建報名記錄） - ' . $notes;
        }
    } else {
        // 如果有找到報名記錄（姓名和電話都符合），在備註中標記
        if (empty($notes)) {
            $notes = '已報名且有到場';
        }
    }
    
    // 獲取當前時間作為簽到時間（用於 online_check_in_records 的 created_at）
    $check_in_time = date('Y-m-d H:i:s');
    
    // 插入簽到記錄
    $insert_stmt = $conn->prepare("
        INSERT INTO online_check_in_records 
        (session_id, application_id, name, email, phone, school, grade, notes, custom_fields, is_registered, check_in_time, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $insert_stmt->bind_param("iisssssssiss", 
        $session_id, 
        $application_id, 
        $name, 
        $email, 
        $phone, 
        $school,
        $grade,
        $notes,
        $custom_fields_json,
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
    
    // 簽到成功後，發送感謝簡訊或 Email
    $notification_sent = false;
    try {
        // 引入 Email 發送功能
        $email_functions_path = __DIR__ . '/../../Topics-frontend/frontend/includes/email_functions.php';
        if (file_exists($email_functions_path)) {
            require_once $email_functions_path;
            
            // 獲取場次資訊（用於生成簡報下載連結）
            $session_name = htmlspecialchars($session['session_name']);
            $session_date = !empty($session['session_date']) ? date('Y年m月d日', strtotime($session['session_date'])) : '';
            
            // 生成簡報下載連結（如果有提供 session_link，則使用；否則生成預設連結）
            $briefing_link = !empty($session['session_link']) 
                ? $session['session_link'] 
                : 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/attendance_management.php?session_id=' . $session_id;
            
            // 如果有 Email，發送感謝 Email
            if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $subject = "【感謝參與】{$session_name} - 活動簡報下載";
                
                $body = "
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset='UTF-8'>
                    <style>
                        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .header { background: #1890ff; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                        .content { background: #f9f9f9; padding: 20px; border: 1px solid #e0e0e0; }
                        .info-box { background: white; padding: 15px; margin: 15px 0; border-radius: 6px; border-left: 4px solid #1890ff; }
                        .button { display: inline-block; padding: 12px 24px; background: #1890ff; color: white; text-decoration: none; border-radius: 6px; margin: 20px 0; }
                        .footer { text-align: center; color: #999; font-size: 12px; margin-top: 20px; }
                    </style>
                </head>
                <body>
                    <div class='container'>
                        <div class='header'>
                            <h2>感謝您的參與</h2>
                        </div>
                        <div class='content'>
                            <p>親愛的 {$name}：</p>
                            
                            <p>感謝您參與「<strong>{$session_name}</strong>」活動！</p>
                            
                            <div class='info-box'>
                                <h3 style='margin-top: 0; color: #1890ff;'>📋 活動資訊</h3>
                                <p><strong>活動名稱：</strong>{$session_name}</p>
                                " . (!empty($session_date) ? "<p><strong>活動日期：</strong>{$session_date}</p>" : "") . "
                            </div>
                            
                            <p>我們已為您準備了當天的活動簡報，歡迎下載參考：</p>
                            
                            <div style='text-align: center;'>
                                <a href='{$briefing_link}' class='button'>下載活動簡報</a>
                            </div>
                            
                            <p style='color: #666; font-size: 14px;'>
                                如有任何問題，歡迎隨時與我們聯繫。期待下次再相見！
                            </p>
                        </div>
                        <div class='footer'>
                            <p>此為系統自動發送的郵件，請勿直接回覆。</p>
                        </div>
                    </div>
                </body>
                </html>
                ";
                
                $altBody = "親愛的 {$name}：\n\n感謝您參與「{$session_name}」活動！\n\n活動簡報下載連結：{$briefing_link}\n\n如有任何問題，歡迎隨時與我們聯繫。期待下次再相見！";
                
                if (function_exists('sendEmail')) {
                    $notification_sent = sendEmail($email, $subject, $body, $altBody);
                }
            }
            
            // 如果有電話，可以發送簡訊（需要簡訊 API）
            // 這裡先記錄，實際簡訊發送需要整合簡訊 API
            if (!empty($phone)) {
                // 檢查是否有簡訊發送功能
                $sms_functions_path = __DIR__ . '/../../Topics-frontend/frontend/includes/sms_functions.php';
                if (file_exists($sms_functions_path)) {
                    require_once $sms_functions_path;
                    if (function_exists('sendSMS')) {
                        $sms_message = "感謝您參與「{$session_name}」活動！活動簡報下載連結：{$briefing_link}";
                        try {
                            sendSMS($phone, $sms_message);
                        } catch (Exception $e) {
                            error_log("發送簡訊失敗: " . $e->getMessage());
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        // 發送通知失敗不影響簽到流程，只記錄錯誤
        error_log("發送簽到感謝通知失敗: " . $e->getMessage());
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
    
    // 如果已發送通知，在訊息中提示
    if ($notification_sent && !empty($email)) {
        $message .= "我們已將活動簡報下載連結發送至您的 Email。";
    }
    
    $response = json_encode([
        'success' => true, 
        'message' => $message,
        'is_registered' => $is_registered,
        'was_auto_created' => $was_auto_created,
        'notification_sent' => $notification_sent
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

