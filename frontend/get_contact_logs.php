<?php
require_once __DIR__ . '/session_config.php';

header('Content-Type: application/json; charset=utf-8');

checkBackendLogin();

require_once '../../Topics-frontend/frontend/config.php';

try {
    $enrollment_id = isset($_GET['enrollment_id']) ? (int)$_GET['enrollment_id'] : 
                    (isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0);
    if ($enrollment_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少或不合法的 enrollment_id']);
        exit;
    }

    $conn = getDatabaseConnection();

    // 查詢聯絡紀錄（使用實際的欄位名稱：enrollment_id, notes）
    $q = $conn->prepare("SELECT id, enrollment_id, teacher_id, contact_date, method, notes, created_at FROM enrollment_contact_logs WHERE enrollment_id = ? ORDER BY contact_date DESC, id DESC");
    $q->bind_param('i', $enrollment_id);
    $q->execute();
    $res = $q->get_result();
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    
    // 為了向後兼容，添加 student_id 欄位
    foreach ($rows as &$row) {
        $row['student_id'] = $row['enrollment_id']; // 向後兼容
        // 為了向後兼容，將 notes 拆分為 result 和 follow_up_notes（如果包含分隔符）
        $notes = $row['notes'] ?? '';
        if (strpos($notes, '後續追蹤備註：') !== false) {
            $parts = explode('後續追蹤備註：', $notes, 2);
            $row['result'] = trim($parts[0]);
            $row['follow_up_notes'] = isset($parts[1]) ? trim($parts[1]) : '';
        } else {
            $row['result'] = $notes;
            $row['follow_up_notes'] = '';
        }
    }
    unset($row);
    
    echo json_encode(['success' => true, 'logs' => $rows]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '系統錯誤', 'error' => $e->getMessage()]);
}

?>
