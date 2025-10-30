<?php
session_start();

header('Content-Type: application/json; charset=utf-8');

// 僅允許後台已登入的管理端查看
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '未授權']);
    exit;
}

require_once '../../Topics-frontend/frontend/config.php';

function ensureContactLogsTable($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS enrollment_contact_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        teacher_id INT NOT NULL,
        contact_date DATE NOT NULL,
        method VARCHAR(20) NOT NULL,
        result TEXT NOT NULL,
        follow_up_notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_student_id (student_id),
        INDEX idx_teacher_id (teacher_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $conn->query($sql);
}

try {
    $student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
    if ($student_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少或不合法的 student_id']);
        exit;
    }

    $conn = getDatabaseConnection();
    ensureContactLogsTable($conn);

    // 取出紀錄並帶出老師顯示名稱
    $sql = "
        SELECT 
            l.id,
            l.student_id,
            l.teacher_id,
            DATE_FORMAT(l.contact_date, '%Y-%m-%d') AS contact_date,
            l.method,
            l.result,
            l.follow_up_notes,
            l.created_at,
            COALESCE(t.name, u.username) AS teacher_name
        FROM enrollment_contact_logs l
        LEFT JOIN user u ON l.teacher_id = u.id
        LEFT JOIN teacher t ON u.id = t.user_id
        WHERE l.student_id = ?
        ORDER BY l.contact_date DESC, l.id DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $logs = $result->fetch_all(MYSQLI_ASSOC);

    echo json_encode(['success' => true, 'logs' => $logs]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '系統錯誤', 'error' => $e->getMessage()]);
}

?>


