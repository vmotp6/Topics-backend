<?php
// 檔案：Topics-backend/frontend/submit_contact_log.php
require_once __DIR__ . '/session_config.php';

header('Content-Type: application/json; charset=utf-8');

checkBackendLogin();

// 引入資料庫設定
$config_path = '../../Topics-frontend/frontend/config.php';
if (!file_exists($config_path)) {
    $alt_paths = [
        '../../../Topics-frontend/frontend/config.php',
        __DIR__ . '/../../Topics-frontend/frontend/config.php',
        dirname(__DIR__) . '/../Topics-frontend/frontend/config.php'
    ];
    
    foreach ($alt_paths as $alt_path) {
        if (file_exists($alt_path)) {
            $config_path = $alt_path;
            break;
        }
    }
}

if (!file_exists($config_path)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '系統錯誤：找不到設定檔']);
    exit;
}

require_once $config_path;
require_once __DIR__ . '/enrollment_intention_eval.php';
require_once __DIR__ . '/includes/intention_change_log.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

try {
    // 獲取並驗證輸入
    $enrollment_id = isset($_POST['enrollment_id']) ? (int)$_POST['enrollment_id'] : 0;
    $contact_date = $_POST['contact_date'] ?? date('Y-m-d');
    $method = $_POST['method'] ?? '其他';
    $notes = trim($_POST['notes'] ?? '');
    $teacher_id = $_SESSION['user_id']; // 紀錄是誰寫的

    if ($enrollment_id <= 0) {
        echo json_encode(['success' => false, 'message' => '無效的學生 ID']);
        exit;
    }

    if (empty($notes)) {
        echo json_encode(['success' => false, 'message' => '請輸入聯絡內容']);
        exit;
    }

    $conn = getDatabaseConnection();

    // 依聯絡紀錄自動評估意願度，並在 notes 中註明計算結果（列表意願欄與聯絡紀錄都會顯示）
    $intention_level = evaluateIntentionLevelFromNotes($notes);
    if ($intention_level !== null) {
        $level_label = ($intention_level === 'high') ? '高意願' : (($intention_level === 'low') ? '低意願' : '中意願');
        $notes .= "\n系統自動評估意願度：" . $level_label;
    }

    // 寫入聯絡紀錄（notes 已含「系統自動評估意願度」說明）
    $stmt = $conn->prepare("INSERT INTO enrollment_contact_logs (enrollment_id, teacher_id, contact_date, method, notes, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    if (!$stmt) {
        throw new Exception("Database prepare failed: " . $conn->error);
    }
    $stmt->bind_param("iisss", $enrollment_id, $teacher_id, $contact_date, $method, $notes);
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }
    $contact_log_id = (int)$conn->insert_id;
    $stmt->close();

    // 更新 enrollment_intention.intention_level（新資料蓋過舊，列表意願欄會顯示）
    $old_level = null;
    if ($intention_level !== null) {
        $get = $conn->prepare("SELECT intention_level FROM enrollment_intention WHERE id = ? LIMIT 1");
        if ($get) {
            $get->bind_param("i", $enrollment_id);
            $get->execute();
            $res = $get->get_result();
            $row = $res->fetch_assoc();
            if ($row && isset($row['intention_level']) && (string)$row['intention_level'] !== '') {
                $old_level = trim((string)$row['intention_level']);
            }
            $get->close();
        }
        $upd = $conn->prepare("UPDATE enrollment_intention SET intention_level = ? WHERE id = ?");
        if ($upd) {
            $upd->bind_param("si", $intention_level, $enrollment_id);
            $upd->execute();
            $upd->close();
        }
        logIntentionChange($conn, $enrollment_id, $old_level, $intention_level, $contact_log_id, $teacher_id);
    }

    echo json_encode(['success' => true, 'message' => '紀錄新增成功']);
    $conn->close();

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '系統錯誤: ' . $e->getMessage()]);
}
?>