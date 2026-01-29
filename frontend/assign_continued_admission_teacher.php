<?php
require_once __DIR__ . '/session_config.php';
checkBackendLogin();

require_once '../../Topics-frontend/frontend/config.php';

header('Content-Type: application/json; charset=utf-8');

$user_role = $_SESSION['role'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;

// 只有主任可以分配
if ($user_role !== 'DI') {
    echo json_encode(['success' => false, 'message' => '權限不足'], JSON_UNESCAPED_UNICODE);
    exit;
}

$application_id = isset($_POST['application_id']) ? (int)$_POST['application_id'] : 0;
$teacher_1_id = isset($_POST['teacher_1_id']) && !empty($_POST['teacher_1_id']) ? (int)$_POST['teacher_1_id'] : null;
$teacher_2_id = isset($_POST['teacher_2_id']) && !empty($_POST['teacher_2_id']) ? (int)$_POST['teacher_2_id'] : null;

if ($application_id === 0) {
    echo json_encode(['success' => false, 'message' => '缺少報名ID'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($teacher_1_id === null && $teacher_2_id === null) {
    echo json_encode(['success' => false, 'message' => '請至少選擇一位老師'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 獲取主任的 user_id（主任自己也要被分配，作為第3位評審者）
$director_user_id = $user_id; // 主任自己

try {
    $conn = getDatabaseConnection();
    
    // 檢查報名記錄是否存在且已分配給該主任的科系
    $check_stmt = $conn->prepare("SELECT assigned_department FROM continued_admission WHERE id = ?");
    $check_stmt->bind_param("i", $application_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $application = $check_result->fetch_assoc();
    $check_stmt->close();
    
    if (!$application) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => '找不到報名記錄'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 獲取主任的科系代碼
    $director_dept_stmt = $conn->prepare("SELECT department FROM director WHERE user_id = ? LIMIT 1");
    if (!$director_dept_stmt) {
        $director_dept_stmt = $conn->prepare("SELECT department FROM teacher WHERE user_id = ? LIMIT 1");
    }
    $director_dept_stmt->bind_param("i", $user_id);
    $director_dept_stmt->execute();
    $director_dept_result = $director_dept_stmt->get_result();
    $director_dept = $director_dept_result->fetch_assoc();
    $director_dept_stmt->close();
    
    if (!$director_dept || $application['assigned_department'] !== $director_dept['department']) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => '權限不足：只能分配自己科系的學生'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 檢查正規化分配表是否存在
    $table_check = $conn->query("SHOW TABLES LIKE 'continued_admission_assignments'");
    $has_normalized_table = ($table_check && $table_check->num_rows > 0);
    
    if ($has_normalized_table) {
        // 使用正規化表
        // 開始事務
        $conn->begin_transaction();
        
        try {
            // 先刪除該報名的所有舊分配記錄
            $delete_stmt = $conn->prepare("DELETE FROM continued_admission_assignments WHERE application_id = ?");
            $delete_stmt->bind_param("i", $application_id);
            $delete_stmt->execute();
            $delete_stmt->close();
            
            // 插入新的分配記錄
            // 第一位老師（如果有的話）
            if ($teacher_1_id !== null) {
                $insert_stmt = $conn->prepare("INSERT INTO continued_admission_assignments 
                    (application_id, reviewer_user_id, reviewer_type, assignment_order, assigned_at, assigned_by_user_id) 
                    VALUES (?, ?, 'teacher', 1, NOW(), ?)");
                $insert_stmt->bind_param("iii", $application_id, $teacher_1_id, $user_id);
                $insert_stmt->execute();
                $insert_stmt->close();
            }
            
            // 第二位老師（如果有的話）
            if ($teacher_2_id !== null) {
                $insert_stmt = $conn->prepare("INSERT INTO continued_admission_assignments 
                    (application_id, reviewer_user_id, reviewer_type, assignment_order, assigned_at, assigned_by_user_id) 
                    VALUES (?, ?, 'teacher', 2, NOW(), ?)");
                $insert_stmt->bind_param("iii", $application_id, $teacher_2_id, $user_id);
                $insert_stmt->execute();
                $insert_stmt->close();
            }
            
            // 主任自己（第3位評審者）
            $insert_stmt = $conn->prepare("INSERT INTO continued_admission_assignments 
                (application_id, reviewer_user_id, reviewer_type, assignment_order, assigned_at, assigned_by_user_id) 
                VALUES (?, ?, 'director', 3, NOW(), ?)");
            $insert_stmt->bind_param("iii", $application_id, $director_user_id, $user_id);
            $insert_stmt->execute();
            $insert_stmt->close();
            
            // 提交事務
            $conn->commit();
            
            // 獲取報名資料用於發送郵件通知
            $app_stmt = $conn->prepare("SELECT id, apply_no, name FROM continued_admission WHERE id = ?");
            $app_stmt->bind_param("i", $application_id);
            $app_stmt->execute();
            $app_result = $app_stmt->get_result();
            $application_data = $app_result->fetch_assoc();
            $app_stmt->close();
            
            // 發送郵件通知給被分配的老師
            if ($application_data) {
                $student_data = [
                    'name' => $application_data['name'] ?? '學生',
                    'apply_no' => $application_data['apply_no'] ?? ''
                ];
                
                // 發送郵件給第一位老師
                if ($teacher_1_id !== null) {
                    try {
                        $notification_path = __DIR__ . '/../../Topics-frontend/frontend/includes/continued_admission_notification_functions.php';
                        if (file_exists($notification_path)) {
                            require_once $notification_path;
                            sendContinuedAdmissionTeacherNotification($conn, $teacher_1_id, $student_data, $application_id);
                        } else {
                            error_log("找不到續招報名郵件通知函數文件: $notification_path");
                        }
                    } catch (Exception $e) {
                        error_log("發送第一位老師通知郵件時發生錯誤: " . $e->getMessage());
                        // 不影響主流程，繼續執行
                    }
                }
                
                // 發送郵件給第二位老師
                if ($teacher_2_id !== null) {
                    try {
                        $notification_path = __DIR__ . '/../../Topics-frontend/frontend/includes/continued_admission_notification_functions.php';
                        if (file_exists($notification_path)) {
                            require_once $notification_path;
                            sendContinuedAdmissionTeacherNotification($conn, $teacher_2_id, $student_data, $application_id);
                        } else {
                            error_log("找不到續招報名郵件通知函數文件: $notification_path");
                        }
                    } catch (Exception $e) {
                        error_log("發送第二位老師通知郵件時發生錯誤: " . $e->getMessage());
                        // 不影響主流程，繼續執行
                    }
                }
            }
            
            echo json_encode([
                'success' => true,
                'message' => '分配成功（已分配給' . 
                    ($teacher_1_id ? '老師1' : '') . 
                    ($teacher_1_id && $teacher_2_id ? '、' : '') . 
                    ($teacher_2_id ? '老師2' : '') . 
                    '和主任）'
            ], JSON_UNESCAPED_UNICODE);
            
        } catch (Exception $e) {
            // 回滾事務
            $conn->rollback();
            throw $e;
        }
    } else {
        // 向後兼容：使用舊欄位
        // 檢查是否有舊欄位
        $has_old_fields = false;
        $column_check = $conn->query("SHOW COLUMNS FROM continued_admission LIKE 'assigned_teacher_1_id'");
        if ($column_check && $column_check->num_rows > 0) {
            $has_old_fields = true;
        } else {
            // 如果欄位不存在，嘗試添加
            try {
                $conn->query("ALTER TABLE continued_admission 
                    ADD COLUMN assigned_teacher_1_id INT NULL,
                    ADD COLUMN assigned_teacher_2_id INT NULL,
                    ADD COLUMN assigned_at DATETIME NULL");
                $has_old_fields = true;
            } catch (Exception $e) {
                error_log("添加分配欄位失敗: " . $e->getMessage());
            }
        }
        
        if (!$has_old_fields) {
            $conn->close();
            echo json_encode(['success' => false, 'message' => '資料庫欄位不存在，請先執行 SQL 腳本'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // 更新舊欄位
        $update_stmt = $conn->prepare("UPDATE continued_admission 
            SET assigned_teacher_1_id = ?, assigned_teacher_2_id = ?, assigned_at = NOW() 
            WHERE id = ?");
        $update_stmt->bind_param("iii", $teacher_1_id, $teacher_2_id, $application_id);
        
        if ($update_stmt->execute()) {
            // 獲取報名資料用於發送郵件通知
            $app_stmt = $conn->prepare("SELECT id, apply_no, name FROM continued_admission WHERE id = ?");
            $app_stmt->bind_param("i", $application_id);
            $app_stmt->execute();
            $app_result = $app_stmt->get_result();
            $application_data = $app_result->fetch_assoc();
            $app_stmt->close();
            
            // 發送郵件通知給被分配的老師
            if ($application_data) {
                $student_data = [
                    'name' => $application_data['name'] ?? '學生',
                    'apply_no' => $application_data['apply_no'] ?? ''
                ];
                
                // 發送郵件給第一位老師
                if ($teacher_1_id !== null) {
                    try {
                        $notification_path = __DIR__ . '/../../Topics-frontend/frontend/includes/continued_admission_notification_functions.php';
                        if (file_exists($notification_path)) {
                            require_once $notification_path;
                            sendContinuedAdmissionTeacherNotification($conn, $teacher_1_id, $student_data, $application_id);
                        } else {
                            error_log("找不到續招報名郵件通知函數文件: $notification_path");
                        }
                    } catch (Exception $e) {
                        error_log("發送第一位老師通知郵件時發生錯誤: " . $e->getMessage());
                        // 不影響主流程，繼續執行
                    }
                }
                
                // 發送郵件給第二位老師
                if ($teacher_2_id !== null) {
                    try {
                        $notification_path = __DIR__ . '/../../Topics-frontend/frontend/includes/continued_admission_notification_functions.php';
                        if (file_exists($notification_path)) {
                            require_once $notification_path;
                            sendContinuedAdmissionTeacherNotification($conn, $teacher_2_id, $student_data, $application_id);
                        } else {
                            error_log("找不到續招報名郵件通知函數文件: $notification_path");
                        }
                    } catch (Exception $e) {
                        error_log("發送第二位老師通知郵件時發生錯誤: " . $e->getMessage());
                        // 不影響主流程，繼續執行
                    }
                }
            }
            
            echo json_encode([
                'success' => true,
                'message' => '分配成功（已分配給' . 
                    ($teacher_1_id ? '老師1' : '') . 
                    ($teacher_1_id && $teacher_2_id ? '、' : '') . 
                    ($teacher_2_id ? '老師2' : '') . 
                    '）<br><small>注意：請執行正規化 SQL 腳本以支持主任評分</small>'
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode([
                'success' => false,
                'message' => '分配失敗：' . $conn->error
            ], JSON_UNESCAPED_UNICODE);
        }
        
        $update_stmt->close();
    }
    
    $conn->close();
    
} catch (Exception $e) {
    error_log('assign_continued_admission_teacher.php error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => '系統錯誤：' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>

