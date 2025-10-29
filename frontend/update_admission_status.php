<?php
// 開啟錯誤顯示以便調試
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
header('Content-Type: application/json');

// 檢查管理員是否已登入
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '未授權，請先登入']);
    exit;
}

// 資料庫連接設定（與招生中心保持一致）
$host = '100.79.58.120';
$dbname = 'topics_good';
$db_username = 'root';
$db_password = '';

// 獲取從前端發送的 JSON 資料
$data = json_decode(file_get_contents('php://input'), true);
$application_id = $data['id'] ?? null;
$new_status = $data['status'] ?? null;
$review_notes = $data['review_notes'] ?? '';

// 驗證輸入資料
$allowed_statuses = ['pending', 'approved', 'rejected', 'waitlist'];
if (!$application_id || !in_array($new_status, $allowed_statuses)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '無效的請求參數']);
    exit;
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $db_username, $db_password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 如果是更新為 "錄取"，則在更新前檢查名額
    if ($new_status === 'approved') {
        // 1. 獲取學生的第一志願
        $stmt_choices = $pdo->prepare("SELECT choices FROM continued_admission WHERE id = ?");
        $stmt_choices->execute([$application_id]);
        $application = $stmt_choices->fetch(PDO::FETCH_ASSOC);

        $choices = json_decode($application['choices'], true);
        $first_choice_department = $choices[0] ?? null;

        if (!$first_choice_department) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '此報名無志願科系，無法進行錄取操作']);
            exit;
        }

        // 2. 查詢該科系的名額和已錄取人數
        // 使用 LIKE 進行模糊比對，以處理簡稱和全名的問題 (例如 動畫科 vs 數位影視動畫科)
        // 這裡的 dq.department_name 是從 department_quotas 找到的完整科系名稱
        $sql_quota = "SELECT dq.department_name, COALESCE(dq.total_quota, 0) as total_quota, 
                      (SELECT COUNT(*) 
                       FROM continued_admission ca
                       WHERE ca.status = 'approved' AND dq.department_name LIKE CONCAT('%', JSON_UNQUOTE(JSON_EXTRACT(ca.choices, '$[0]')), '%')
                      ) as current_enrolled 
                      FROM department_quotas dq WHERE dq.department_name LIKE ? LIMIT 1";
        $stmt_quota = $pdo->prepare($sql_quota);
        $stmt_quota->execute(['%' . $first_choice_department . '%']); // $first_choice_department 是學生的志願 (例如 "動畫科")
        $quota_info = $stmt_quota->fetch(PDO::FETCH_ASSOC);

        if (!$quota_info) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "找不到科系 '{$first_choice_department}' 的名額設定"]);
            exit;
        }
        
        // 核心檢查：如果已錄取人數大於或等於總名額
        if ($quota_info['current_enrolled'] >= $quota_info['total_quota']) {
            // 回傳 409 Conflict 狀態碼，表示請求與伺服器當前狀態衝突（名額已滿）
            http_response_code(409); // 409 Conflict
            // 提供明確的錯誤訊息給前端
            echo json_encode(['success' => false, 'message' => "科系 '{$quota_info['department_name']}' 名額已滿，無法錄取"]);
            exit;
        }
    }
    
    // 檢查是否有 review_notes 欄位
    $stmt = $pdo->prepare("SHOW COLUMNS FROM continued_admission LIKE 'review_notes'");
    $stmt->execute();
    $has_review_notes = $stmt->rowCount() > 0;

    if ($has_review_notes) {
        // 如果有 review_notes 欄位，則更新包含備註
        $stmt = $pdo->prepare("UPDATE continued_admission SET status = ?, reviewed_at = NOW(), review_notes = ? WHERE id = ?");
        $stmt->execute([$new_status, $review_notes, $application_id]);
    } else {
        // 如果沒有 review_notes 欄位，則只更新狀態和時間
        $stmt = $pdo->prepare("UPDATE continued_admission SET status = ?, reviewed_at = NOW() WHERE id = ?");
        $stmt->execute([$new_status, $application_id]);
    }
    
    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => '狀態更新成功']);
    } else {
        echo json_encode(['success' => false, 'message' => '沒有找到要更新的記錄']);
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    error_log("續招狀態更新失敗: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '伺服器錯誤：' . $e->getMessage()]);
}
?>