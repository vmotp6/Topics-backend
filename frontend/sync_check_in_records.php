<?php
require_once __DIR__ . '/session_config.php';

// 檢查是否已登入
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: login.php");
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// 引入資料庫設定
require_once '../../Topics-frontend/frontend/config.php';

// 獲取場次ID
$session_id = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;
if ($session_id === 0) {
    echo json_encode(['success' => false, 'message' => '缺少場次ID']);
    exit;
}

try {
    // 建立資料庫連接
    $conn = getDatabaseConnection();
    
    // 檢查場次是否存在
    $stmt = $conn->prepare("SELECT id, session_name FROM admission_sessions WHERE id = ?");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $session_result = $stmt->get_result();
    $session = $session_result->fetch_assoc();
    $stmt->close();
    
    if (!$session) {
        echo json_encode(['success' => false, 'message' => '找不到指定的場次']);
        $conn->close();
        exit;
    }
    
    // 檢查 online_check_in_records 表是否存在
    $table_check = $conn->query("SHOW TABLES LIKE 'online_check_in_records'");
    if (!$table_check || $table_check->num_rows == 0) {
        echo json_encode(['success' => false, 'message' => '找不到線上簽到記錄表']);
        $conn->close();
        exit;
    }
    
    // 獲取該場次的所有線上簽到記錄
    $check_in_stmt = $conn->prepare("
        SELECT id, name, phone, email, created_at, application_id
        FROM online_check_in_records
        WHERE session_id = ?
        ORDER BY created_at ASC
    ");
    $check_in_stmt->bind_param("i", $session_id);
    $check_in_stmt->execute();
    $check_in_result = $check_in_stmt->get_result();
    $check_in_records = $check_in_result->fetch_all(MYSQLI_ASSOC);
    $check_in_stmt->close();
    
    if (empty($check_in_records)) {
        echo json_encode([
            'success' => true, 
            'message' => '沒有需要比對的簽到記錄',
            'matched_count' => 0,
            'updated_count' => 0
        ]);
        $conn->close();
        exit;
    }
    
    $matched_count = 0;
    $updated_count = 0;
    $matched_details = [];
    $conn->begin_transaction();
    
    foreach ($check_in_records as $check_in) {
        $check_in_name = trim($check_in['name']);
        $check_in_phone = trim($check_in['phone']);
        $check_in_created_at = $check_in['created_at'];
        
        // 如果已經有關聯的 application_id，跳過
        if (!empty($check_in['application_id'])) {
            continue;
        }
        
        // 正規化電話號碼（只取數字）
        $normalized_phone = preg_replace('/\D+/', '', $check_in_phone);
        
        // 根據姓名和電話在 admission_applications 中查找匹配的記錄
        // 重要：必須姓名和電話都完全符合，且只看今年度的報名資料
        $current_year = (int)date('Y');
        
        // 必須同時比對姓名和電話（嚴格匹配）
        // 只看今年度的報名資料（YEAR(created_at) = 當前年份）
        $find_stmt = $conn->prepare("
            SELECT id, student_name, contact_phone, email, notes, YEAR(created_at) as created_year
            FROM admission_applications
            WHERE session_id = ?
            AND student_name = ?
            AND REPLACE(REPLACE(REPLACE(REPLACE(contact_phone, '-', ''), ' ', ''), '(', ''), ')', '') = ?
            AND YEAR(created_at) = ?
            LIMIT 1
        ");
        
        $find_stmt->bind_param("issi", $session_id, $check_in_name, $normalized_phone, $current_year);
        $find_stmt->execute();
        $result = $find_stmt->get_result();
        $matched_application = $result->fetch_assoc();
        $find_stmt->close();
        
        // 如果找到匹配的記錄，檢查是否為自動創建的（notes 包含「未報名但有來」）
        if ($matched_application) {
            $app_notes = $matched_application['notes'] ?? '';
            // 如果 notes 包含「未報名但有來」，則視為未報名（自動創建的）
            if (strpos($app_notes, '未報名但有來') !== false) {
                $matched_application = null; // 視為未找到匹配
            }
        }
        
        if ($matched_application) {
            $application_id = $matched_application['id'];
            $matched_count++;
            
            // 檢查這個報名記錄是否為今年度且是真正報名的（不是自動創建的）
            // 檢查 notes 欄位是否包含「未報名但有來」，如果包含則表示是自動創建的
            $check_notes_stmt = $conn->prepare("
                SELECT notes, YEAR(created_at) as created_year
                FROM admission_applications
                WHERE id = ?
            ");
            $check_notes_stmt->bind_param("i", $application_id);
            $check_notes_stmt->execute();
            $app_info = $check_notes_stmt->get_result()->fetch_assoc();
            $check_notes_stmt->close();
            
            // 判斷是否為已報名：必須是今年度的記錄，且 notes 不包含「未報名但有來」
            $current_year = (int)date('Y');
            $is_truly_registered = false;
            if ($app_info) {
                $app_year = (int)$app_info['created_year'];
                $app_notes = $app_info['notes'] ?? '';
                // 只有今年度的記錄，且 notes 不包含「未報名但有來」，才算是真正報名
                $is_truly_registered = ($app_year === $current_year && 
                                       strpos($app_notes, '未報名但有來') === false);
            }
            
            // 更新 online_check_in_records 的 application_id 和 is_registered
            $update_check_in_stmt = $conn->prepare("
                UPDATE online_check_in_records
                SET application_id = ?, is_registered = ?
                WHERE id = ?
            ");
            $is_registered_value = $is_truly_registered ? 1 : 0;
            $update_check_in_stmt->bind_param("iii", $application_id, $is_registered_value, $check_in['id']);
            $update_check_in_stmt->execute();
            $update_check_in_stmt->close();
            
            // 檢查 attendance_records 表是否存在
            $attendance_table_check = $conn->query("SHOW TABLES LIKE 'attendance_records'");
            if ($attendance_table_check && $attendance_table_check->num_rows > 0) {
                // 檢查是否已存在記錄
                $check_attendance_stmt = $conn->prepare("
                    SELECT id, check_in_time 
                    FROM attendance_records 
                    WHERE session_id = ? AND application_id = ?
                ");
                $check_attendance_stmt->bind_param("ii", $session_id, $application_id);
                $check_attendance_stmt->execute();
                $attendance_exists = $check_attendance_stmt->get_result()->fetch_assoc();
                $check_attendance_stmt->close();
                
                // 使用 online_check_in_records 的 created_at 作為簽到時間
                $check_in_time = $check_in_created_at;
                
                if ($attendance_exists) {
                    // 更新現有記錄（確保狀態為已到，並使用線上簽到的時間）
                    $current_status = isset($attendance_exists['attendance_status']) ? $attendance_exists['attendance_status'] : 0;
                    $current_check_in_time = $attendance_exists['check_in_time'] ?? null;
                    
                    // 如果狀態不是已到，或者簽到時間不同，則更新
                    if ($current_status != 1 || $current_check_in_time != $check_in_time) {
                        $update_attendance_stmt = $conn->prepare("
                            UPDATE attendance_records 
                            SET attendance_status = 1, 
                                check_in_time = ?,
                                absent_time = NULL
                            WHERE session_id = ? AND application_id = ?
                        ");
                        $update_attendance_stmt->bind_param("sii", $check_in_time, $session_id, $application_id);
                        $update_attendance_stmt->execute();
                        $update_attendance_stmt->close();
                        $updated_count++;
                    }
                } else {
                    // 新增記錄
                    $insert_attendance_stmt = $conn->prepare("
                        INSERT INTO attendance_records 
                        (session_id, application_id, attendance_status, check_in_time, absent_time) 
                        VALUES (?, ?, 1, ?, NULL)
                    ");
                    $insert_attendance_stmt->bind_param("iis", $session_id, $application_id, $check_in_time);
                    $insert_attendance_stmt->execute();
                    $insert_attendance_stmt->close();
                    $updated_count++;
                }
                
                $matched_details[] = [
                    'name' => $check_in_name,
                    'phone' => $check_in_phone,
                    'application_id' => $application_id,
                    'check_in_time' => $check_in_time
                ];
            }
        } else {
            // 如果沒有找到匹配的報名記錄，自動創建 admission_applications 記錄
            // 檢查 admission_applications 表是否有 notes 欄位，如果沒有則添加
            $column_check = $conn->query("SHOW COLUMNS FROM admission_applications LIKE 'notes'");
            if (!$column_check || $column_check->num_rows == 0) {
                $conn->query("ALTER TABLE `admission_applications` ADD COLUMN `notes` text DEFAULT NULL COMMENT '備註（主要用於記錄沒有報名但有來聽演講的人員）'");
            }
            
            // 在 admission_applications 中創建新記錄
            $application_notes = '未報名但有來';
            
            // 確保所有參數都是變數（bind_param 需要變數引用）
            $email_value = !empty($check_in['email']) ? $check_in['email'] : '';
            
            $insert_application_stmt = $conn->prepare("
                INSERT INTO admission_applications 
                (session_id, student_name, email, contact_phone, notes, created_at) 
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            
            $insert_application_stmt->bind_param("issss", 
                $session_id, 
                $check_in_name, 
                $email_value, 
                $check_in_phone, 
                $application_notes
            );
            
            if ($insert_application_stmt->execute()) {
                $new_application_id = $conn->insert_id;
                
                // 更新 online_check_in_records 的 application_id
                // 重要：自動創建的記錄不應該標記為已報名（is_registered = 0）
                $update_check_in_stmt = $conn->prepare("
                    UPDATE online_check_in_records
                    SET application_id = ?, is_registered = 0
                    WHERE id = ?
                ");
                $update_check_in_stmt->bind_param("ii", $new_application_id, $check_in['id']);
                $update_check_in_stmt->execute();
                $update_check_in_stmt->close();
                
                // 檢查 attendance_records 表是否存在
                $attendance_table_check = $conn->query("SHOW TABLES LIKE 'attendance_records'");
                if ($attendance_table_check && $attendance_table_check->num_rows > 0) {
                    // 使用 online_check_in_records 的 created_at 作為簽到時間
                    $check_in_time = $check_in_created_at;
                    
                    // 新增出席記錄
                    $insert_attendance_stmt = $conn->prepare("
                        INSERT INTO attendance_records 
                        (session_id, application_id, attendance_status, check_in_time, absent_time) 
                        VALUES (?, ?, 1, ?, NULL)
                    ");
                    $insert_attendance_stmt->bind_param("iis", $session_id, $new_application_id, $check_in_time);
                    $insert_attendance_stmt->execute();
                    $insert_attendance_stmt->close();
                    $updated_count++;
                }
                
                $matched_count++;
                $matched_details[] = [
                    'name' => $check_in_name,
                    'phone' => $check_in_phone,
                    'application_id' => $new_application_id,
                    'check_in_time' => $check_in_time,
                    'auto_created' => true
                ];
            }
            $insert_application_stmt->close();
        }
    }
    
    $conn->commit();
    $conn->close();
    
    $auto_created_count = 0;
    foreach ($matched_details as $detail) {
        if (isset($detail['auto_created']) && $detail['auto_created']) {
            $auto_created_count++;
        }
    }
    
    $message = "比對完成！";
    if ($matched_count > 0) {
        $message .= "找到 {$matched_count} 筆匹配記錄";
        if ($auto_created_count > 0) {
            $message .= "（其中 {$auto_created_count} 筆為自動創建）";
        }
        $message .= "，更新了 {$updated_count} 筆出席記錄。";
    } else {
        $message .= "沒有找到需要比對的記錄。";
    }
    
    echo json_encode([
        'success' => true,
        'message' => $message,
        'matched_count' => $matched_count,
        'updated_count' => $updated_count,
        'auto_created_count' => $auto_created_count,
        'matched_details' => $matched_details
    ]);
    
} catch (Exception $e) {
    if (method_exists($conn, 'in_transaction') && $conn->in_transaction()) {
        $conn->rollback();
    } else {
        @$conn->rollback();
    }
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '比對失敗：' . $e->getMessage()
    ]);
    
    if (isset($conn)) {
        $conn->close();
    }
}

