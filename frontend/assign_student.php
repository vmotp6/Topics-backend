<?php
session_start();

// 檢查是否已登入且為IMD用戶
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in'] || $_SESSION['username'] !== 'IMD') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '權限不足']);
    exit;
}

// 檢查請求方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '不支援的請求方法']);
    exit;
}

// 獲取POST參數
$student_id = isset($_POST['student_id']) ? intval($_POST['student_id']) : 0;
$teacher_id = isset($_POST['teacher_id']) ? intval($_POST['teacher_id']) : 0;

// 驗證參數
if ($student_id <= 0 || $teacher_id <= 0) {
    echo json_encode(['success' => false, 'message' => '無效的參數']);
    exit;
}

// 引入資料庫設定
require_once '../../Topics-frontend/frontend/config.php';

try {
    // 建立資料庫連接
    $conn = getDatabaseConnection();
    
    // 檢查enrollment_intention表是否有assigned_teacher_id欄位，如果沒有則新增
    $check_column = $conn->query("SHOW COLUMNS FROM enrollment_intention LIKE 'assigned_teacher_id'");
    if ($check_column->num_rows == 0) {
        $alter_sql = "ALTER TABLE enrollment_intention ADD COLUMN assigned_teacher_id INT NULL AFTER remarks";
        $conn->query($alter_sql);
    }
    
    // 檢查學生是否存在
    $stmt = $conn->prepare("SELECT id, name FROM enrollment_intention WHERE id = ?");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $student = $result->fetch_assoc();
    
    if (!$student) {
        echo json_encode(['success' => false, 'message' => '找不到指定的學生']);
        exit;
    }
    
    // 檢查學生是否已經被分配
    $check_assigned_stmt = $conn->prepare("SELECT assigned_teacher_id FROM enrollment_intention WHERE id = ?");
    $check_assigned_stmt->bind_param("i", $student_id);
    $check_assigned_stmt->execute();
    $assigned_result = $check_assigned_stmt->get_result();
    $current_assignment = $assigned_result->fetch_assoc();
    
    if ($current_assignment && $current_assignment['assigned_teacher_id'] !== null) {
        // 獲取已分配的老師信息
        $assigned_teacher_stmt = $conn->prepare("
            SELECT u.username, t.name 
            FROM user u 
            LEFT JOIN teacher t ON u.id = t.user_id 
            WHERE u.id = ?
        ");
        $assigned_teacher_stmt->bind_param("i", $current_assignment['assigned_teacher_id']);
        $assigned_teacher_stmt->execute();
        $assigned_teacher_result = $assigned_teacher_stmt->get_result();
        $assigned_teacher = $assigned_teacher_result->fetch_assoc();
        
        $assigned_teacher_name = $assigned_teacher['name'] ?? $assigned_teacher['username'] ?? '未知老師';
        
        echo json_encode([
            'success' => false, 
            'message' => "該學生已被分配給「{$assigned_teacher_name}」，無法重複分配"
        ]);
        exit;
    }
    
    // 檢查老師是否存在
    $stmt = $conn->prepare("SELECT u.id, u.username, t.name FROM user u LEFT JOIN teacher t ON u.id = t.user_id WHERE u.id = ? AND u.role = '老師'");
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $teacher = $result->fetch_assoc();
    
    if (!$teacher) {
        echo json_encode(['success' => false, 'message' => '找不到指定的老師']);
        exit;
    }
    
    // 更新學生的分配老師
    $stmt = $conn->prepare("UPDATE enrollment_intention SET assigned_teacher_id = ? WHERE id = ?");
    $stmt->bind_param("ii", $teacher_id, $student_id);
    
    if ($stmt->execute()) {
        // 記錄分配日誌（可選）
        try {
            // 檢查assignment_logs表是否存在，如果不存在則創建
            $check_table = $conn->query("SHOW TABLES LIKE 'assignment_logs'");
            if ($check_table->num_rows == 0) {
                $create_table_sql = "
                CREATE TABLE assignment_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    student_id INT NOT NULL,
                    teacher_id INT NOT NULL,
                    assigned_by VARCHAR(255) NOT NULL,
                    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_student_id (student_id),
                    INDEX idx_teacher_id (teacher_id),
                    INDEX idx_assigned_at (assigned_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ";
                $conn->query($create_table_sql);
            }
            
            $log_stmt = $conn->prepare("INSERT INTO assignment_logs (student_id, teacher_id, assigned_by, assigned_at) VALUES (?, ?, ?, NOW())");
            $assigned_by = $_SESSION['username'];
            $log_stmt->bind_param("iis", $student_id, $teacher_id, $assigned_by);
            $log_stmt->execute();
        } catch (Exception $log_error) {
            // 如果日誌記錄失敗，不影響主要功能
            error_log("分配日誌記錄失敗: " . $log_error->getMessage());
        }
        
        echo json_encode([
            'success' => true, 
            'message' => '學生分配成功',
            'student_name' => $student['name'],
            'teacher_name' => $teacher['name'] ?? $teacher['username']
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => '分配失敗：' . $conn->error]);
    }
    
    $conn->close();
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '系統錯誤：' . $e->getMessage()]);
}
?>
