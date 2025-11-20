<?php
session_start();

// 檢查是否已登入且為部門帳號（IMD/FLD）
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in'] || !in_array($_SESSION['username'], ['IMD','FLD'])) {
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
$recommendation_id = isset($_POST['recommendation_id']) ? intval($_POST['recommendation_id']) : 0;
$teacher_id = isset($_POST['teacher_id']) ? intval($_POST['teacher_id']) : 0;

// 驗證參數
if ($recommendation_id <= 0 || $teacher_id <= 0) {
    echo json_encode(['success' => false, 'message' => '無效的參數']);
    exit;
}

// 引入資料庫設定
require_once '../../Topics-frontend/frontend/config.php';

try {
    // 建立資料庫連接
    $conn = getDatabaseConnection();
    
    // 檢查admission_recommendations表是否有assigned_teacher_id欄位，如果沒有則新增
    $check_column = $conn->query("SHOW COLUMNS FROM admission_recommendations LIKE 'assigned_teacher_id'");
    if ($check_column->num_rows == 0) {
        $alter_sql = "ALTER TABLE admission_recommendations ADD COLUMN assigned_teacher_id INT NULL AFTER enrollment_status";
        $conn->query($alter_sql);
    }
    
    // 檢查推薦記錄是否存在
    $stmt = $conn->prepare("SELECT id, student_name FROM admission_recommendations WHERE id = ?");
    $stmt->bind_param("i", $recommendation_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $recommendation = $result->fetch_assoc();
    
    if (!$recommendation) {
        echo json_encode(['success' => false, 'message' => '找不到指定的推薦記錄']);
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
    
    // 更新推薦記錄的分配老師
    $stmt = $conn->prepare("UPDATE admission_recommendations SET assigned_teacher_id = ? WHERE id = ?");
    $stmt->bind_param("ii", $teacher_id, $recommendation_id);
    
    if ($stmt->execute()) {
        // 記錄分配日誌
        // 先檢查表是否存在，如果不存在則創建
        $table_check = $conn->query("SHOW TABLES LIKE 'recommendation_assignment_logs'");
        if ($table_check->num_rows == 0) {
            $conn->query("CREATE TABLE recommendation_assignment_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                recommendation_id INT NOT NULL,
                teacher_id INT NULL,
                assigned_by VARCHAR(100) NOT NULL,
                assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_recommendation_id (recommendation_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        
        $log_stmt = $conn->prepare("INSERT INTO recommendation_assignment_logs (recommendation_id, teacher_id, assigned_by, assigned_at) VALUES (?, ?, ?, NOW())");
        $assigned_by = $_SESSION['username'];
        $log_stmt->bind_param("iis", $recommendation_id, $teacher_id, $assigned_by);
        $log_stmt->execute();
        
        echo json_encode([
            'success' => true, 
            'message' => '推薦學生分配成功',
            'student_name' => $recommendation['student_name'],
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

