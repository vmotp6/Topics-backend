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
        $stmt = $pdo->prepare("SELECT * FROM department_quotas WHERE is_active = 1 ORDER BY department_name");
        // 改為從 admission_courses 讀取，並關聯 department_quotas
        $sql = "
            SELECT 
                ac.id as course_id, 
                ac.course_name as department_name,
                dq.id as quota_id,
                COALESCE(dq.total_quota, 0) as total_quota
            FROM admission_courses ac
            LEFT JOIN department_quotas dq ON ac.course_name = dq.department_name AND dq.is_active = 1
            WHERE ac.is_active = 1
            ORDER BY ac.sort_order, ac.course_name
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // 計算各科系已錄取人數
        $department_stats = [];

        // 先一次性獲取所有已錄取的學生志願，以提高效率
        $stmt_approved = $conn->prepare("SELECT choices FROM continued_admission WHERE status = 'approved'");
        $stmt_approved->execute();
        $approved_applications = $stmt_approved->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($courses as $course) {
            // 在 PHP 中進行模糊比對，與 continued_admission_list.php 邏輯保持一致
            $enrolled_count = 0;
            foreach ($approved_applications as $app) {
                $choices = json_decode($app['choices'], true);
                if (is_array($choices) && !empty($choices) && strpos($course['department_name'], $choices[0]) !== false) {
                    $enrolled_count++;
                }
            }
            
            $department_stats[] = [
                'id' => $course['course_id'], // 使用 admission_courses 的 ID
                'name' => $course['department_name'],
                'code' => 'N/A', // 移除 department_code 的讀取
                'total_quota' => (int)$course['total_quota'],
                'current_enrolled' => $enrolled_count,
                'remaining' => (int)$course['total_quota'] - $enrolled_count
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
    
    // id 現在是 course_id，name 是 course_name
    if (!isset($input['id']) || !isset($input['name']) || !isset($input['total_quota'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少必要參數']);
        return;
    }
    
    $course_name = $input['name'];
    $total_quota = (int)$input['total_quota'];
    
    if ($total_quota < 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '名額不能為負數']);
        return;
    }
    
    try {
        // 檢查記錄是否存在
        $stmt = $conn->prepare("SELECT id FROM department_quotas WHERE department_name = ?");
        $stmt->bind_param("s", $course_name);
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
            $stmt = $conn->prepare("INSERT INTO department_quotas (department_name, total_quota, is_active) VALUES (?, ?, 1)"); // 移除 department_code
            $stmt->bind_param("si", $course_name, $total_quota);
            $stmt->execute();
            echo json_encode(['success' => true, 'message' => '名額設定成功']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '更新失敗: ' . $e->getMessage()]);
    }
}
?>
