<?php
session_start();

// 檢查是否已登入
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '未授權存取']);
    exit;
}

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
                COALESCE(dq.total_quota, 0) as total_quota
            FROM departments d
            LEFT JOIN department_quotas dq ON d.code = dq.department_code AND dq.is_active = 1
            ORDER BY d.code
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $departments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // 先一次性獲取所有已錄取的學生志願（按科系代碼統計）
        $stmt_approved = $conn->prepare("
            SELECT ca.id, cac.choice_order, cac.department_code
            FROM continued_admission ca
            INNER JOIN continued_admission_choices cac ON ca.id = cac.application_id
            WHERE ca.status = 'approved'
            ORDER BY ca.id, cac.choice_order
        ");
        $stmt_approved->execute();
        $approved_result = $stmt_approved->get_result();
        
        // 組織已錄取學生的志願數據（按科系代碼統計）
        $approved_by_department = [];
        while ($row = $approved_result->fetch_assoc()) {
            $dept_code = $row['department_code'];
            // 只統計第一志願
            if ($row['choice_order'] == 1) {
                if (!isset($approved_by_department[$dept_code])) {
                    $approved_by_department[$dept_code] = 0;
                }
                $approved_by_department[$dept_code]++;
            }
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
                'remaining' => max(0, (int)$dept['total_quota'] - $enrolled_count)
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
        
        // 檢查記錄是否存在（根據 department_code）
        $stmt = $conn->prepare("SELECT id FROM department_quotas WHERE department_code = ?");
        $stmt->bind_param("s", $department_code);
        $stmt->execute();
        $existing_quota = $stmt->get_result()->fetch_assoc();
        
        if ($existing_quota) {
            // 更新現有記錄
            $stmt = $conn->prepare("UPDATE department_quotas SET total_quota = ?, is_active = 1 WHERE id = ?");
            $stmt->bind_param("ii", $total_quota, $existing_quota['id']);
            $stmt->execute();
            echo json_encode(['success' => true, 'message' => '名額更新成功']);
        } else {
            // 插入新記錄
            $stmt = $conn->prepare("INSERT INTO department_quotas (department_code, total_quota, is_active) VALUES (?, ?, 1)");
            $stmt->bind_param("si", $department_code, $total_quota);
            $stmt->execute();
            echo json_encode(['success' => true, 'message' => '名額設定成功']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '更新失敗: ' . $e->getMessage()]);
    }
}
?>
