<?php
// 引入統一的 session 設定檔
require_once __DIR__ . '/../../Topics-frontend/frontend/session_config.php';

// 檢查是否已登入
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '未授權訪問']);
    exit;
}

// 設定回應標頭
header('Content-Type: application/json');

// 允許的檔案路徑（安全檢查）
$allowed_paths = [
    '../../Topics-frontend/frontend/admission.php',
    '../../Topics-frontend/frontend/AI.php',
    '../../Topics-frontend/frontend/teacher_profile.php',
    '../../Topics-frontend/frontend/cooperation_upload.php',
    '../../Topics-frontend/frontend/index.php',
    '../../Topics-frontend/frontend/QA.php',
    '../../Topics-frontend/frontend/chat.php',
    '../../Topics-frontend/frontend/chat_simple.php',
    '../../Topics-frontend/frontend/teacher.php',
    '../../Topics-frontend/frontend/records.php',
    '../../Topics-frontend/frontend/my_records.php',
    '../../Topics-frontend/frontend/activity_records_management.php',
    '../../Topics-frontend/frontend/admin_admission.php',
    '../../Topics-frontend/frontend/cooperation_upload.php',
    '../../Topics-frontend/frontend/qa.php'
];

$action = $_POST['action'] ?? '';
$file_path = $_POST['file_path'] ?? '';

// 驗證檔案路徑
if (!in_array($file_path, $allowed_paths)) {
    echo json_encode(['success' => false, 'message' => '不允許訪問此檔案']);
    exit;
}

// 確保檔案路徑存在
if (!file_exists($file_path)) {
    echo json_encode(['success' => false, 'message' => '檔案不存在']);
    exit;
}

try {
    switch ($action) {
        case 'read':
            // 讀取檔案內容
            $content = file_get_contents($file_path);
            if ($content === false) {
                throw new Exception('無法讀取檔案');
            }
            
            echo json_encode([
                'success' => true,
                'content' => $content,
                'file_size' => filesize($file_path),
                'last_modified' => date('Y-m-d H:i:s', filemtime($file_path))
            ]);
            break;
            
        case 'write':
            // 寫入檔案內容
            $content = $_POST['content'] ?? '';
            
            // 備份原檔案
            $backup_path = $file_path . '.backup.' . date('Y-m-d_H-i-s');
            if (!copy($file_path, $backup_path)) {
                throw new Exception('無法創建備份檔案');
            }
            
            // 寫入新內容
            if (file_put_contents($file_path, $content) === false) {
                throw new Exception('無法寫入檔案');
            }
            
            echo json_encode([
                'success' => true,
                'message' => '檔案儲存成功',
                'backup_path' => $backup_path,
                'file_size' => strlen($content),
                'saved_at' => date('Y-m-d H:i:s')
            ]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => '無效的操作']);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
