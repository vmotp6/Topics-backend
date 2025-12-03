<?php
// 臨時啟用錯誤顯示以便調試
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// 使用輸出緩衝區，確保不會有意外輸出
ob_start();

session_start();

// 清除任何可能的輸出
ob_clean();

header('Content-Type: application/json');

// 檢查管理員是否已登入
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    http_response_code(401);
    ob_clean();
    echo json_encode(['success' => false, 'message' => '未授權，請先登入']);
    exit;
}

// 引入資料庫設定
require_once '../../Topics-frontend/frontend/config.php';

// 獲取從前端發送的 JSON 資料
$data = json_decode(file_get_contents('php://input'), true);
$application_id = $data['id'] ?? null;
$new_status = $data['status'] ?? null;
$review_notes = $data['review_notes'] ?? '';

// 驗證輸入資料
$allowed_statuses = ['pending', 'approved', 'rejected', 'waitlist'];
if (!$application_id || !in_array($new_status, $allowed_statuses)) {
    http_response_code(400);
    ob_clean();
    echo json_encode(['success' => false, 'message' => '無效的請求參數']);
    exit;
}

try {
    $conn = getDatabaseConnection();
    
    // 獲取申請資料和分配部門（注意：資料表使用 ID 而非 id）
    $stmt_app = $conn->prepare("SELECT assigned_department, status FROM continued_admission WHERE ID = ?");
    $stmt_app->bind_param("i", $application_id);
    $stmt_app->execute();
    $app_result = $stmt_app->get_result();
    $app_data = $app_result->fetch_assoc();
    if (!$app_data) {
        http_response_code(404);
        ob_clean();
        echo json_encode(['success' => false, 'message' => '找不到指定的報名記錄']);
        exit;
    }
    $assigned_department = $app_data['assigned_department'] ?? null;
    $stmt_app->close();
    
    // 如果是更新為 "錄取"，則在更新前檢查名額
    if ($new_status === 'approved') {
        // 1. 獲取學生的第一志願（從 continued_admission_choices 表）
        $stmt_choices = $conn->prepare("
            SELECT cac.department_code, d.name as department_name
            FROM continued_admission_choices cac
            LEFT JOIN departments d ON cac.department_code = d.code
            WHERE cac.application_id = ? AND cac.choice_order = 1
            LIMIT 1
        ");
        $stmt_choices->bind_param("i", $application_id);
        $stmt_choices->execute();
        $choice_result = $stmt_choices->get_result();
        $first_choice = $choice_result->fetch_assoc();
        $stmt_choices->close();

        if (!$first_choice || empty($first_choice['department_code'])) {
            http_response_code(400);
            ob_clean();
            echo json_encode(['success' => false, 'message' => '此報名無志願科系，無法進行錄取操作']);
            exit;
        }

        // 使用分配部門或第一志願的科系代碼
        $department_code = $assigned_department ?? $first_choice['department_code'];
        $department_name = $first_choice['department_name'] ?? $department_code;

        // 2. 查詢該科系的名額和已錄取人數（從 department_quotas 表）
        $sql_quota = "SELECT dq.department_code, d.name as department_name, COALESCE(dq.total_quota, 0) as total_quota, 
                      (SELECT COUNT(*) 
                       FROM continued_admission ca
                       WHERE (ca.status = 'approved' OR ca.status = 'PE') AND ca.assigned_department = dq.department_code
                      ) as current_enrolled 
                      FROM department_quotas dq 
                      LEFT JOIN departments d ON dq.department_code = d.code
                      WHERE dq.department_code = ? AND dq.is_active = 1
                      LIMIT 1";
        $stmt_quota = $conn->prepare($sql_quota);
        $stmt_quota->bind_param("s", $department_code);
        $stmt_quota->execute();
        $quota_info = $stmt_quota->get_result()->fetch_assoc();
        $stmt_quota->close();

        if (!$quota_info) {
            http_response_code(400);
            ob_clean();
            echo json_encode(['success' => false, 'message' => "找不到科系 '{$department_name}' 的名額設定"]);
            exit;
        }
        
        // 核心檢查：如果已錄取人數大於或等於總名額
        if ($quota_info['current_enrolled'] >= $quota_info['total_quota']) {
            // 回傳 409 Conflict 狀態碼，表示請求與伺服器當前狀態衝突（名額已滿）
            http_response_code(409); // 409 Conflict
            // 提供明確的錯誤訊息給前端
            $dept_name = $quota_info['department_name'] ?? $department_code;
            ob_clean();
            echo json_encode(['success' => false, 'message' => "科系 '{$dept_name}' 名額已滿，無法錄取"]);
            exit;
        }
    }
    
    // 如果是錄取，確保 assigned_department 有值（使用分配部門或第一志願）
    $final_department_code = $assigned_department ?? null;
    if ($new_status === 'approved' && empty($final_department_code)) {
        // 如果沒有分配部門，使用第一志願的科系代碼
        $stmt_choice = $conn->prepare("
            SELECT department_code
            FROM continued_admission_choices
            WHERE application_id = ? AND choice_order = 1
            LIMIT 1
        ");
        $stmt_choice->bind_param("i", $application_id);
        $stmt_choice->execute();
        $choice_result = $stmt_choice->get_result();
        if ($choice_row = $choice_result->fetch_assoc()) {
            $final_department_code = $choice_row['department_code'];
        }
        $stmt_choice->close();
    }
    
    // 初始化變數
    $affected_rows = 0;
    
    // 檢查是否有 review_notes 欄位
    $stmt_check = $conn->prepare("SHOW COLUMNS FROM continued_admission LIKE 'review_notes'");
    $stmt_check->execute();
    $has_review_notes = $stmt_check->get_result()->num_rows > 0;
    $stmt_check->close();

    // 準備更新語句
    if ($has_review_notes && !empty($final_department_code)) {
        // 如果有 review_notes 欄位且有部門代碼，則更新包含備註和部門代碼（注意：資料表使用 ID 而非 id）
        $stmt = $conn->prepare("UPDATE continued_admission SET status = ?, reviewed_at = NOW(), review_notes = ?, assigned_department = ? WHERE ID = ?");
        if ($stmt) {
            $stmt->bind_param("ssssi", $new_status, $review_notes, $final_department_code, $application_id);
            $stmt->execute();
            $affected_rows = $stmt->affected_rows;
            if ($stmt->error) {
                throw new Exception("更新失敗: " . $stmt->error);
            }
            $stmt->close();
        } else {
            throw new Exception("準備 SQL 語句失敗: " . $conn->error);
        }
    } elseif ($has_review_notes) {
        // 如果有 review_notes 欄位但沒有部門代碼，則只更新狀態、時間和備註
        $stmt = $conn->prepare("UPDATE continued_admission SET status = ?, reviewed_at = NOW(), review_notes = ? WHERE ID = ?");
        if ($stmt) {
            $stmt->bind_param("ssi", $new_status, $review_notes, $application_id);
            $stmt->execute();
            $affected_rows = $stmt->affected_rows;
            if ($stmt->error) {
                throw new Exception("更新失敗: " . $stmt->error);
            }
            $stmt->close();
        } else {
            throw new Exception("準備 SQL 語句失敗: " . $conn->error);
        }
    } elseif (!empty($final_department_code)) {
        // 如果沒有 review_notes 欄位但有部門代碼，則更新狀態、時間和部門代碼
        $stmt = $conn->prepare("UPDATE continued_admission SET status = ?, reviewed_at = NOW(), assigned_department = ? WHERE ID = ?");
        if ($stmt) {
            $stmt->bind_param("ssi", $new_status, $final_department_code, $application_id);
            $stmt->execute();
            $affected_rows = $stmt->affected_rows;
            if ($stmt->error) {
                throw new Exception("更新失敗: " . $stmt->error);
            }
            $stmt->close();
        } else {
            throw new Exception("準備 SQL 語句失敗: " . $conn->error);
        }
    } else {
        // 如果沒有 review_notes 欄位也沒有部門代碼，則只更新狀態和時間
        $stmt = $conn->prepare("UPDATE continued_admission SET status = ?, reviewed_at = NOW() WHERE ID = ?");
        if ($stmt) {
            $stmt->bind_param("si", $new_status, $application_id);
            $stmt->execute();
            $affected_rows = $stmt->affected_rows;
            if ($stmt->error) {
                throw new Exception("更新失敗: " . $stmt->error);
            }
            $stmt->close();
        } else {
            throw new Exception("準備 SQL 語句失敗: " . $conn->error);
        }
    }
    
    // 關閉資料庫連接
    $conn->close();
    
    if ($affected_rows > 0) {
        ob_clean();
        echo json_encode(['success' => true, 'message' => '狀態更新成功']);
    } else {
        ob_clean();
        echo json_encode(['success' => false, 'message' => '沒有找到要更新的記錄']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    error_log("續招狀態更新失敗: " . $e->getMessage());
    // 確保清除任何輸出緩衝
    ob_clean();
    echo json_encode(['success' => false, 'message' => '伺服器錯誤：' . $e->getMessage()]);
    exit;
}

// 結束輸出緩衝
ob_end_flush();
?>