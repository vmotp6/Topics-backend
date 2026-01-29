<?php
require_once __DIR__ . '/session_config.php';

checkBackendLogin();

// 檢查權限：僅指定帳號（username=12）且角色為招生中心（STA）可修改審核結果
$username = isset($_SESSION['username']) ? $_SESSION['username'] : '';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$can_view_review_result = ($username === '12' && $user_role === 'STA');

if (!$can_view_review_result) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '權限不足，只有指定帳號可以進行此操作']);
    exit;
}

// 僅允許 POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '不支援的請求方法']);
    exit;
}

$recommendation_id = isset($_POST['recommendation_id']) ? intval($_POST['recommendation_id']) : 0;
$review_result = isset($_POST['review_result']) ? trim($_POST['review_result']) : '';

// 驗證參數
$allowed_results = ['通過', '不通過', '需人工審查'];
if ($recommendation_id <= 0 || !in_array($review_result, $allowed_results, true)) {
    echo json_encode(['success' => false, 'message' => '無效的參數']);
    exit;
}

require_once '../../Topics-frontend/frontend/config.php';
require_once __DIR__ . '/recommendation_review_email.php';

// 確保 application_statuses 中存在指定的狀態 code（避免 status 外鍵寫入失敗）
// $needed 格式：['通過' => ['code'=>'AP','name'=>'通過','order'=>90], ...]
function ensure_application_status_codes($conn, $needed) {
    if (!$conn || empty($needed) || !is_array($needed)) return;

    // 檢查表是否存在
    $t = $conn->query("SHOW TABLES LIKE 'application_statuses'");
    if (!$t || $t->num_rows <= 0) return;

    // 取得欄位資訊（不同資料庫版本可能欄位略有差異）
    $cols = [];
    $cr = $conn->query("SHOW COLUMNS FROM application_statuses");
    if ($cr) {
        while ($row = $cr->fetch_assoc()) {
            $cols[] = $row['Field'];
        }
    }
    $has_code = in_array('code', $cols, true);
    if (!$has_code) return;
    $has_name = in_array('name', $cols, true);
    $has_order = in_array('display_order', $cols, true);

    $stmt_check = $conn->prepare("SELECT code FROM application_statuses WHERE code = ? LIMIT 1");
    if (!$stmt_check) return;

    $stmt_ins = null;
    if ($has_name && $has_order) {
        $stmt_ins = $conn->prepare("INSERT INTO application_statuses (code, name, display_order) VALUES (?, ?, ?)");
    } elseif ($has_name) {
        $stmt_ins = $conn->prepare("INSERT INTO application_statuses (code, name) VALUES (?, ?)");
    } else {
        $stmt_ins = $conn->prepare("INSERT INTO application_statuses (code) VALUES (?)");
    }
    if (!$stmt_ins) {
        $stmt_check->close();
        return;
    }

    foreach ($needed as $meta) {
        $code = trim((string)($meta['code'] ?? ''));
        if ($code === '') continue;

        // 已存在就跳過
        $stmt_check->bind_param('s', $code);
        if ($stmt_check->execute()) {
            $res = $stmt_check->get_result();
            if ($res && $res->num_rows > 0) {
                continue;
            }
        }

        // 不存在就新增（忽略重複鍵等錯誤）
        try {
            if ($has_name && $has_order) {
                $name = (string)($meta['name'] ?? $code);
                $order = (int)($meta['order'] ?? 0);
                $stmt_ins->bind_param('ssi', $code, $name, $order);
                @$stmt_ins->execute();
            } elseif ($has_name) {
                $name = (string)($meta['name'] ?? $code);
                $stmt_ins->bind_param('ss', $code, $name);
                @$stmt_ins->execute();
            } else {
                $stmt_ins->bind_param('s', $code);
                @$stmt_ins->execute();
            }
        } catch (Exception $e) {
            // ignore
        }
    }

    $stmt_check->close();
    $stmt_ins->close();
}

// 審核結果對應的狀態代碼（與主文件一致）
$review_status_map = [
    '通過' => ['code' => 'AP', 'name' => '通過', 'order' => 90],
    '不通過' => ['code' => 'RE', 'name' => '不通過', 'order' => 91],
    '需人工審查' => ['code' => 'MC', 'name' => '需人工審查', 'order' => 92],
];

$status_code = $review_status_map[$review_result]['code'];

try {
    $conn = getDatabaseConnection();

    // 確保 application_statuses 中存在指定的狀態 code（避免 status 外鍵寫入失敗）
    ensure_application_status_codes($conn, $review_status_map);

    // 檢查推薦記錄是否存在
    $stmt = $conn->prepare("SELECT id FROM admission_recommendations WHERE id = ?");
    $stmt->bind_param("i", $recommendation_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $recommendation = $result->fetch_assoc();
    if (!$recommendation) {
        echo json_encode(['success' => false, 'message' => '找不到指定的推薦記錄']);
        exit;
    }
    $stmt->close();

    // 檢查 status 欄位是否存在
    $check_column = $conn->query("SHOW COLUMNS FROM admission_recommendations LIKE 'status'");
    if ($check_column->num_rows == 0) {
        // 如果不存在，則添加
        $alter_sql = "ALTER TABLE admission_recommendations ADD COLUMN status VARCHAR(20) DEFAULT 'pending'";
        $conn->query($alter_sql);
    }

    // 更新推薦記錄的審核結果
    // admission_recommendations.status 欄位有外鍵約束到 application_statuses.code
    // 必須確保 application_statuses 表中存在對應的 code（已在上面處理）
    // 通過 => 'AP', 不通過 => 'RE'
    $update = $conn->prepare("UPDATE admission_recommendations SET status = ? WHERE id = ?");
    $update->bind_param("si", $status_code, $recommendation_id);

    if ($update->execute()) {
        // 檢查是否有受影響的行
        if ($update->affected_rows > 0) {
            // 審核結果 email 通知：通過(AP)/不通過(RE) 都寄信（每筆每狀態只寄一次）
            if (function_exists('send_review_result_email_once')) {
                @send_review_result_email_once($conn, $recommendation_id, $status_code, $username);
            }
            echo json_encode([
                'success' => true,
                'message' => '審核結果更新成功',
                'review_result' => $review_result,
                'status_code' => $status_code
            ]);
        } else {
            // 可能是記錄不存在或狀態沒有變化
            echo json_encode([
                'success' => false,
                'message' => '更新失敗：沒有記錄被更新（可能記錄不存在或狀態未改變）'
            ]);
        }
    } else {
        // 檢查是否為外鍵約束錯誤
        $error_msg = $conn->error;
        if (strpos($error_msg, 'foreign key') !== false || strpos($error_msg, '1452') !== false) {
            echo json_encode([
                'success' => false,
                'message' => '更新失敗：外鍵約束錯誤，請確保 application_statuses 表中存在 code = ' . $status_code
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => '更新失敗：' . $error_msg]);
        }
    }

    $update->close();
    $conn->close();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '系統錯誤：' . $e->getMessage()]);
}
?>

