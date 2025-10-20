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
    case 'update_quota':
        updateQuota($pdo);
        break;
    case 'add_department':
        addDepartment($pdo);
        break;
    case 'delete_department':
        deleteDepartment($pdo);
        break;
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '無效的操作']);
}

function getQuotas($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM department_quotas WHERE is_active = 1 ORDER BY department_name");
        $stmt->execute();
        $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 計算各科系已錄取人數
        $department_stats = [];
        foreach ($departments as $dept) {
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM continued_admission WHERE status = 'approved' AND JSON_CONTAINS(choices, JSON_QUOTE(?))");
            $stmt->execute([$dept['department_name']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $department_stats[] = [
                'id' => $dept['id'],
                'name' => $dept['department_name'],
                'code' => $dept['department_code'],
                'total_quota' => $dept['total_quota'],
                'current_enrolled' => $result['count'],
                'remaining' => $dept['total_quota'] - $result['count'],
                'description' => $dept['description']
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
    
    if (!isset($input['id']) || !isset($input['total_quota'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少必要參數']);
        return;
    }
    
    $id = (int)$input['id'];
    $total_quota = (int)$input['total_quota'];
    
    if ($total_quota < 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '名額不能為負數']);
        return;
    }
    
    try {
        $stmt = $pdo->prepare("UPDATE department_quotas SET total_quota = ? WHERE id = ?");
        $stmt->execute([$total_quota, $id]);
        
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => '名額更新成功']);
        } else {
            echo json_encode(['success' => false, 'message' => '未找到指定的科系']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '更新失敗: ' . $e->getMessage()]);
    }
}

function addDepartment($pdo) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => '方法不允許']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['name']) || !isset($input['code']) || !isset($input['quota'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少必要參數']);
        return;
    }
    
    $name = trim($input['name']);
    $code = trim($input['code']);
    $quota = (int)$input['quota'];
    $description = trim($input['description'] ?? '');
    
    if (empty($name) || empty($code) || $quota < 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '參數格式不正確']);
        return;
    }
    
    try {
        // 檢查科系代碼是否已存在
        $stmt = $pdo->prepare("SELECT id FROM department_quotas WHERE department_code = ?");
        $stmt->execute([$code]);
        if ($stmt->fetch()) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '科系代碼已存在']);
            return;
        }
        
        $stmt = $pdo->prepare("INSERT INTO department_quotas (department_name, department_code, total_quota, description) VALUES (?, ?, ?, ?)");
        $stmt->execute([$name, $code, $quota, $description]);
        
        echo json_encode(['success' => true, 'message' => '科系新增成功', 'id' => $pdo->lastInsertId()]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '新增失敗: ' . $e->getMessage()]);
    }
}

function deleteDepartment($pdo) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => '方法不允許']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少必要參數']);
        return;
    }
    
    $id = (int)$input['id'];
    
    try {
        // 檢查是否有學生已錄取此科系
        $stmt = $pdo->prepare("SELECT d.department_name FROM department_quotas d WHERE d.id = ?");
        $stmt->execute([$id]);
        $dept = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$dept) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => '未找到指定的科系']);
            return;
        }
        
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM continued_admission WHERE status = 'approved' AND JSON_CONTAINS(choices, JSON_QUOTE(?))");
        $stmt->execute([$dept['department_name']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['count'] > 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '此科系已有錄取學生，無法刪除']);
            return;
        }
        
        $stmt = $pdo->prepare("UPDATE department_quotas SET is_active = 0 WHERE id = ?");
        $stmt->execute([$id]);
        
        echo json_encode(['success' => true, 'message' => '科系已停用']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '刪除失敗: ' . $e->getMessage()]);
    }
}
?>
