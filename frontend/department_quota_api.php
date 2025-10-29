<?php
session_start();

// 檢查是否已登入
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '未授權存取']);
    exit;
}

// 資料庫連接設定
$host = '100.79.58.120';
$dbname = 'topics_good';
$db_username = 'root';
$db_password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $db_username, $db_password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '資料庫連接失敗']);
    exit;
}

header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'get_quotas':
        getQuotas($pdo);
        break;
    case 'update_or_add_quota': // 將 update_quota 改為更通用的名稱
        updateQuota($pdo);
        break;
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '無效的操作']);
}

function getQuotas($pdo) {
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
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 計算各科系已錄取人數
        $department_stats = [];

        // 先一次性獲取所有已錄取的學生志願，以提高效率
        $stmt_approved = $pdo->prepare("SELECT choices FROM continued_admission WHERE status = 'approved'");
        $stmt_approved->execute();
        $approved_applications = $stmt_approved->fetchAll(PDO::FETCH_ASSOC);

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

function updateQuota($pdo) {
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
        $stmt = $pdo->prepare("SELECT id FROM department_quotas WHERE department_name = ?");
        $stmt->execute([$course_name]);
        $existing_quota = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing_quota) {
            // 更新現有記錄
            $stmt = $pdo->prepare("UPDATE department_quotas SET total_quota = ?, is_active = 1 WHERE id = ?");
            $stmt->execute([$total_quota, $existing_quota['id']]);
            echo json_encode(['success' => true, 'message' => '名額更新成功']);
        } else {
            // 插入新記錄
            $stmt = $pdo->prepare("INSERT INTO department_quotas (department_name, total_quota, is_active) VALUES (?, ?, 1)"); // 移除 department_code
            $stmt->execute([$course_name, $total_quota]);
            echo json_encode(['success' => true, 'message' => '名額設定成功']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '更新失敗: ' . $e->getMessage()]);
    }
}
?>
