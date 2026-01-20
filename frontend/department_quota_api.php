<?php
require_once __DIR__ . '/session_config.php';

checkBackendLogin();

// 引入資料庫設定
require_once '../../Topics-frontend/frontend/config.php';

try {
    // 建立資料庫連接
    $conn = getDatabaseConnection();
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '資料庫連接失敗']);
    exit;
}

header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'get_quotas':
        getQuotas($conn);
        break;
    case 'update_or_add_quota': // 將 update_quota 改為更通用的名稱
        updateQuota($conn);
        break;
    case 'get_global_register_time':
        getGlobalRegisterTime($conn);
        break;
    case 'update_global_register_time':
        updateGlobalRegisterTime($conn);
        break;
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '無效的操作']);
}

function getQuotas($conn) {
    try {
        // 直接從 department_quotas 和 departments 表讀取續招名額資料
        $sql = "
            SELECT 
                d.code as department_code,
                d.name as department_name,
                dq.id as quota_id,
                COALESCE(dq.total_quota, 0) as total_quota,
                dq.cutoff_score,
                dq.register_start,
                dq.register_end,
                dq.review_start,
                dq.review_end,
                dq.announce_time
            FROM departments d
            LEFT JOIN department_quotas dq ON d.code = dq.department_code AND dq.is_active = 1
            ORDER BY d.code
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $departments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // 統計已錄取的學生（根據 assigned_department，狀態為 'approved' 或 'AP'）
        $stmt_approved = $conn->prepare("
            SELECT assigned_department, COUNT(*) as enrolled_count
            FROM continued_admission
            WHERE (status = 'approved' OR status = 'AP')
            AND assigned_department IS NOT NULL
            AND assigned_department != ''
            GROUP BY assigned_department
        ");
        $stmt_approved->execute();
        $approved_result = $stmt_approved->get_result();
        
        // 組織已錄取學生的數據（按科系代碼統計）
        $approved_by_department = [];
        while ($row = $approved_result->fetch_assoc()) {
            $dept_code = $row['assigned_department'];
            $approved_by_department[$dept_code] = (int)$row['enrolled_count'];
        }

        // 計算各科系已錄取人數
        $department_stats = [];
        foreach ($departments as $dept) {
            $dept_code = $dept['department_code'];
            $enrolled_count = isset($approved_by_department[$dept_code]) ? $approved_by_department[$dept_code] : 0;
            
            $department_stats[] = [
                'id' => $dept['quota_id'] ?: $dept_code, // 使用 quota_id 或 department_code 作為 ID
                'name' => $dept['department_name'],
                'code' => $dept_code,
                'total_quota' => (int)$dept['total_quota'],
                'current_enrolled' => $enrolled_count,
                'remaining' => max(0, (int)$dept['total_quota'] - $enrolled_count),
                'cutoff_score' => isset($dept['cutoff_score']) ? (int)$dept['cutoff_score'] : null,
                'register_start' => $dept['register_start'] ?? null,
                'register_end' => $dept['register_end'] ?? null,
                'review_start' => $dept['review_start'] ?? null,
                'review_end' => $dept['review_end'] ?? null,
                'announce_time' => $dept['announce_time'] ?? null,
            ];
        }
        
        echo json_encode(['success' => true, 'data' => $department_stats]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '獲取資料失敗: ' . $e->getMessage()]);
    }
}

function updateQuota($conn) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => '方法不允許']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    // id 可能是 quota_id 或 department_code，name 是 department_name
    if (!isset($input['id']) || !isset($input['name']) || !isset($input['total_quota'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少必要參數']);
        return;
    }
    
    $department_name = $input['name'];
    $total_quota = (int)$input['total_quota'];

    // 允許錄取分數與各時間欄位為可選
    $cutoff_score = isset($input['cutoff_score']) && $input['cutoff_score'] !== '' ? (int)$input['cutoff_score'] : null;

    $register_start = isset($input['register_start']) && $input['register_start'] !== '' ? $input['register_start'] : null;
    $register_end = isset($input['register_end']) && $input['register_end'] !== '' ? $input['register_end'] : null;
    $review_start = isset($input['review_start']) && $input['review_start'] !== '' ? $input['review_start'] : null;
    $review_end = isset($input['review_end']) && $input['review_end'] !== '' ? $input['review_end'] : null;
    $announce_time = isset($input['announce_time']) && $input['announce_time'] !== '' ? $input['announce_time'] : null;
    
    if ($total_quota < 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '名額不能為負數']);
        return;
    }
    
    try {
        // 從 department_name 取得 department_code
        $stmt = $conn->prepare("SELECT code FROM departments WHERE name = ? LIMIT 1");
        $stmt->bind_param("s", $department_name);
        $stmt->execute();
        $dept_result = $stmt->get_result()->fetch_assoc();
        
        if (!$dept_result) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '找不到對應的科系']);
            return;
        }
        
        $department_code = $dept_result['code'];
        
        // 檢查該科系已錄取的人數（狀態為 'approved' 或 'AP'，且 assigned_department 為該科系）
        // 注意：已錄取人數應該根據 assigned_department 統計，而不是根據志願
        $stmt_count = $conn->prepare("
            SELECT COUNT(*) as enrolled_count
            FROM continued_admission
            WHERE (status = 'approved' OR status = 'AP')
            AND assigned_department = ?
        ");
        $stmt_count->bind_param("s", $department_code);
        $stmt_count->execute();
        $count_result = $stmt_count->get_result()->fetch_assoc();
        $enrolled_count = (int)($count_result['enrolled_count'] ?? 0);
        $stmt_count->close();
        
        // 檢查新名額是否低於已錄取人數
        if ($total_quota < $enrolled_count) {
            http_response_code(400);
            echo json_encode([
                'success' => false, 
                'message' => "無法將名額調整為 {$total_quota}，因為該科系目前已錄取 {$enrolled_count} 人，名額不能低於已錄取人數。"
            ]);
            return;
        }
        
        // 檢查記錄是否存在（根據 department_code）
        $stmt = $conn->prepare("SELECT id FROM department_quotas WHERE department_code = ?");
        $stmt->bind_param("s", $department_code);
        $stmt->execute();
        $existing_quota = $stmt->get_result()->fetch_assoc();
        
        if ($existing_quota) {
            // 更新現有記錄
            $stmt = $conn->prepare("UPDATE department_quotas 
                SET total_quota = ?, cutoff_score = ?, register_start = ?, register_end = ?, review_start = ?, review_end = ?, announce_time = ?, is_active = 1 
                WHERE id = ?");
            $stmt->bind_param(
                "iisssssi",
                $total_quota,
                $cutoff_score,
                $register_start,
                $register_end,
                $review_start,
                $review_end,
                $announce_time,
                $existing_quota['id']
            );
            $stmt->execute();
            echo json_encode(['success' => true, 'message' => '名額更新成功']);
        } else {
            // 插入新記錄
            $stmt = $conn->prepare("INSERT INTO department_quotas (department_code, total_quota, cutoff_score, register_start, register_end, review_start, review_end, announce_time, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)");
            $stmt->bind_param(
                "siisssss",
                $department_code,
                $total_quota,
                $cutoff_score,
                $register_start,
                $register_end,
                $review_start,
                $review_end,
                $announce_time
            );
            $stmt->execute();
            echo json_encode(['success' => true, 'message' => '名額設定成功']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '更新失敗: ' . $e->getMessage()]);
    }
}

/**
 * 取得所有啟用科系的統一時間（報名／審查書面／錄取公告）
 * 報名與審查時間使用「最早開始、最晚結束」，公告時間使用「最晚時間」
 */
function getGlobalRegisterTime($conn) {
    try {
        $sql = "
            SELECT 
                MIN(register_start) AS min_register_start,
                MAX(register_end) AS max_register_end,
                MIN(review_start) AS min_review_start,
                MAX(review_end) AS max_review_end,
                MAX(announce_time) AS max_announce_time
            FROM department_quotas
            WHERE is_active = 1
              AND register_start IS NOT NULL
              AND register_end IS NOT NULL
        ";
        $result = $conn->query($sql);
        $row = $result ? $result->fetch_assoc() : null;

        $min_register_start = $row && !empty($row['min_register_start']) ? $row['min_register_start'] : null;
        $max_register_end = $row && !empty($row['max_register_end']) ? $row['max_register_end'] : null;
        $min_review_start = $row && !empty($row['min_review_start']) ? $row['min_review_start'] : null;
        $max_review_end = $row && !empty($row['max_review_end']) ? $row['max_review_end'] : null;
        $max_announce_time = $row && !empty($row['max_announce_time']) ? $row['max_announce_time'] : null;

        echo json_encode([
            'success' => true,
            'data' => [
                'register_start' => $min_register_start,
                'register_end' => $max_register_end,
                'review_start' => $min_review_start,
                'review_end' => $max_review_end,
                'announce_time' => $max_announce_time,
            ]
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '獲取統一報名時間失敗: ' . $e->getMessage()]);
    }
}

/**
 * 將時間套用到所有科系（統一設定）
 */
function updateGlobalRegisterTime($conn) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => '方法不允許']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    $register_start = $input['register_start'] ?? null;
    $register_end = $input['register_end'] ?? null;
    $review_start = $input['review_start'] ?? null;
    $review_end = $input['review_end'] ?? null;
    $announce_time = $input['announce_time'] ?? null;

    if (empty($register_start) || empty($register_end)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '請填寫完整的報名起訖時間']);
        return;
    }

    try {
        $stmt = $conn->prepare("UPDATE department_quotas SET register_start = ?, register_end = ?, review_start = ?, review_end = ?, announce_time = ? WHERE is_active = 1");
        $stmt->bind_param("sssss", $register_start, $register_end, $review_start, $review_end, $announce_time);
        $stmt->execute();

        echo json_encode(['success' => true, 'message' => '已套用統一報名時間到所有科系']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '更新統一報名時間失敗: ' . $e->getMessage()]);
    }
}
?>
