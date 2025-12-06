<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

// 檢查是否已登入
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '未授權']);
    exit;
}

// 權限檢查：只允許 ADM 和 STA 角色訪問
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$allowed_roles = ['ADM', 'STA'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '權限不足']);
    exit;
}

// 引入資料庫設定
$config_path = '../../Topics-frontend/frontend/config.php';
if (!file_exists($config_path)) {
    $alt_paths = [
        '../../../Topics-frontend/frontend/config.php',
        __DIR__ . '/../../Topics-frontend/frontend/config.php',
        dirname(__DIR__) . '/../Topics-frontend/frontend/config.php'
    ];
    
    $found = false;
    foreach ($alt_paths as $alt_path) {
        if (file_exists($alt_path)) {
            $config_path = $alt_path;
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '找不到資料庫設定檔案']);
        exit;
    }
}

require_once $config_path;

if (!function_exists('getDatabaseConnection')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '資料庫連接函數未定義']);
    exit;
}

try {
    // 獲取 JSON 數據
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    if (!$data) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '無效的請求數據']);
        exit;
    }
    
    $type = $data['type'] ?? '';
    $id = intval($data['id'] ?? 0);
    $action = $data['action'] ?? 'update';
    
    if (empty($type) || $id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '缺少必要參數']);
        exit;
    }
    
    $conn = getDatabaseConnection();
    
    // 處理刪除操作
    if ($action === 'delete') {
        if ($type === 'contact') {
            $table_name = 'user_contacts';
        } else if ($type === 'group') {
            $table_name = 'group_chat_members';
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '無效的類型']);
            exit;
        }
        
        // 檢查表是否存在
        $table_check = $conn->query("SHOW TABLES LIKE '$table_name'");
        if (!$table_check || $table_check->num_rows == 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => "找不到 $table_name 表"]);
            exit;
        }
        
        $sql = "DELETE FROM $table_name WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => '刪除成功']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => '刪除失敗: ' . $stmt->error]);
        }
        
        $stmt->close();
        $conn->close();
        exit;
    }
    
    // 獲取表結構
    function getTableColumns($conn, $table_name) {
        $columns = [];
        $result = $conn->query("DESCRIBE $table_name");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $columns[] = $row['Field'];
            }
        }
        return $columns;
    }
    
    if ($type === 'contact') {
        $table_name = 'user_contacts';
        $table_check = $conn->query("SHOW TABLES LIKE '$table_name'");
        if (!$table_check || $table_check->num_rows == 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => '找不到 user_contacts 表']);
            exit;
        }
        
        $columns = getTableColumns($conn, $table_name);
        $update_fields = [];
        $update_values = [];
        $param_types = '';
        
        // 檢查並添加可更新的欄位
        $allowed_fields = ['user_id', 'contact_user_id', 'email', 'phone', 'contact_name'];
        foreach ($allowed_fields as $field) {
            if (in_array($field, $columns) && isset($data[$field])) {
                $update_fields[] = "$field = ?";
                $update_values[] = $data[$field];
                $param_types .= 's';
            }
        }
        
        if (empty($update_fields)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '沒有可更新的欄位']);
            exit;
        }
        
        $update_values[] = $id;
        $param_types .= 'i';
        
        $sql = "UPDATE $table_name SET " . implode(', ', $update_fields) . " WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($param_types, ...$update_values);
        
    } else if ($type === 'group') {
        $table_name = 'group_chat_members';
        $table_check = $conn->query("SHOW TABLES LIKE '$table_name'");
        if (!$table_check || $table_check->num_rows == 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => '找不到 group_chat_members 表']);
            exit;
        }
        
        $columns = getTableColumns($conn, $table_name);
        $update_fields = [];
        $update_values = [];
        $param_types = '';
        
        // 檢查並添加可更新的欄位
        $allowed_fields = ['group_id', 'user_id', 'role', 'joined_at'];
        foreach ($allowed_fields as $field) {
            if (in_array($field, $columns) && isset($data[$field])) {
                $update_fields[] = "$field = ?";
                $update_values[] = $data[$field];
                $param_types .= 's';
            }
        }
        
        if (empty($update_fields)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '沒有可更新的欄位']);
            exit;
        }
        
        $update_values[] = $id;
        $param_types .= 'i';
        
        $sql = "UPDATE $table_name SET " . implode(', ', $update_fields) . " WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($param_types, ...$update_values);
        
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '無效的類型']);
        exit;
    }
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => '更新成功']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '更新失敗: ' . $stmt->error]);
    }
    
    $stmt->close();
    $conn->close();
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '資料庫操作失敗: ' . $e->getMessage()]);
    error_log('update_chat_data.php 錯誤: ' . $e->getMessage());
}
?>

