<?php
require_once __DIR__ . '/session_config.php';
checkBackendLogin();

require_once '../../Topics-frontend/frontend/config.php';

header('Content-Type: application/json; charset=utf-8');

$department_code = $_GET['department'] ?? '';

if (empty($department_code)) {
    echo json_encode(['success' => false, 'message' => '缺少科系代碼'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $conn = getDatabaseConnection();
    
    // 驗證科系代碼是否存在
    $dept_name = '';
    $dept_check = $conn->prepare("SELECT name FROM departments WHERE code = ? LIMIT 1");
    if ($dept_check) {
        $dept_check->bind_param("s", $department_code);
        $dept_check->execute();
        $dept_result = $dept_check->get_result();
        if ($dept_row = $dept_result->fetch_assoc()) {
            $dept_name = $dept_row['name'];
        }
        $dept_check->close();
    }
    
    // 查詢該科系的所有老師（排除主任）
    // 說明：
    //  - 有些舊資料可能在 teacher.department 存的是「科系名稱」，而不是「科系代碼」
    //  - 為了相容舊資料，這裡同時透過 departments 表來比對，無論存名稱或代碼都能抓到
    //  - 排除主任（主任在 director 表裡，不應該被分配）
    $teachers = [];
    
    // 主要查詢：透過 departments 表，比對科系代碼
    // 1. 如果 teacher.department 存的是代碼 → 直接用 t.department = ?
    // 2. 如果 teacher.department 存的是名稱 → 透過 d.name = t.department 並用 d.code = ? 過濾
    // 3. 排除主任（LEFT JOIN director 並過濾掉有 director 記錄的用戶）
    $sql = "SELECT DISTINCT u.id as user_id, u.name, u.username, u.email, t.department, u.role
            FROM user u
            INNER JOIN teacher t ON u.id = t.user_id
            LEFT JOIN departments d 
                ON (t.department = d.code OR t.department = d.name)
            LEFT JOIN director dir ON u.id = dir.user_id
            WHERE (
                t.department = ?
                OR d.code = ?
            )
            AND u.role IN ('TE', 'TEA', '老師')
            AND dir.user_id IS NULL  -- 排除主任
            ORDER BY u.name ASC";
    
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        // 同一個科系代碼帶兩次（對應 t.department = ? 以及 d.code = ?）
        $stmt->bind_param("ss", $department_code, $department_code);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $teachers[] = [
                'user_id' => $row['user_id'],
                'name' => $row['name'],
                'username' => $row['username'],
                'email' => $row['email']
            ];
        }
        $stmt->close();
    }
    
    // 如果沒找到老師，提供調試資訊
    $debug_info = [
        'department_code' => $department_code,
        'department_name' => $dept_name,
        'found_count' => count($teachers),
        'query_explanation' => '查詢條件：teacher.department = 科系代碼 OR departments.code = 科系代碼，且 role = TE 或 老師，且不是主任'
    ];
    
    if (empty($teachers)) {
        error_log("get_department_teachers.php: 找不到科系代碼 '$department_code' (名稱: '$dept_name') 的老師");
        
        // 查詢該科系的所有老師（不排除主任，用於調試）
        $debug_sql = "SELECT DISTINCT u.id as user_id, u.name, u.username, u.email, u.role, t.department,
                CASE WHEN dir.user_id IS NOT NULL THEN '是' ELSE '否' END as is_director
                FROM user u
                INNER JOIN teacher t ON u.id = t.user_id
                LEFT JOIN departments d 
                    ON (t.department = d.code OR t.department = d.name)
                LEFT JOIN director dir ON u.id = dir.user_id
                WHERE (
                    t.department = ?
                    OR d.code = ?
                )
                AND u.role IN ('TE', 'TEA', '老師')
                ORDER BY t.department, u.name ASC";
        $debug_stmt = $conn->prepare($debug_sql);
        if ($debug_stmt) {
            $debug_stmt->bind_param("ss", $department_code, $department_code);
            $debug_stmt->execute();
            $debug_result = $debug_stmt->get_result();
            $debug_teachers = [];
            while ($debug_row = $debug_result->fetch_assoc()) {
                $debug_teachers[] = [
                    'user_id' => $debug_row['user_id'],
                    'name' => $debug_row['name'],
                    'username' => $debug_row['username'],
                    'role' => $debug_row['role'],
                    'department' => $debug_row['department'] ?? 'NULL',
                    'is_director' => $debug_row['is_director']
                ];
            }
            $debug_info['teachers_in_department'] = $debug_teachers;
            $debug_info['teachers_in_department_count'] = count($debug_teachers);
            $debug_stmt->close();
        }
        
        // 查詢所有老師以調試（查看他們的科系設定）- 不限制 role
        $all_teachers_sql = "SELECT DISTINCT u.id as user_id, u.name, u.username, u.email, u.role, t.department
                FROM user u
                LEFT JOIN teacher t ON u.id = t.user_id
                WHERE u.role IN ('TE', 'TEA', '老師')
                ORDER BY t.department, u.name ASC
                LIMIT 50";
        $all_teachers_result = $conn->query($all_teachers_sql);
        if ($all_teachers_result) {
            $all_teachers = [];
            while ($all_row = $all_teachers_result->fetch_assoc()) {
                $all_teachers[] = [
                    'user_id' => $all_row['user_id'],
                    'name' => $all_row['name'],
                    'username' => $all_row['username'],
                    'role' => $all_row['role'],
                    'department' => $all_row['department'] ?? 'NULL'
                ];
            }
            $debug_info['all_teachers_sample'] = $all_teachers;
            $debug_info['all_teachers_count'] = count($all_teachers);
            error_log("get_department_teachers.php: 系統中所有老師的科系設定: " . json_encode($all_teachers, JSON_UNESCAPED_UNICODE));
        }
        
        // 查詢該科系的所有用戶（包括主任，用於對比）
        $all_users_sql = "SELECT DISTINCT u.id, u.name, u.username, u.role, 
                t.department as teacher_dept, 
                dir.department as director_dept
                FROM user u
                LEFT JOIN teacher t ON u.id = t.user_id
                LEFT JOIN director dir ON u.id = dir.user_id
                WHERE (t.department = ? OR dir.department = ?)
                ORDER BY u.role, u.name ASC";
        $all_users_stmt = $conn->prepare($all_users_sql);
        if ($all_users_stmt) {
            $all_users_stmt->bind_param("ss", $department_code, $department_code);
            $all_users_stmt->execute();
            $all_users_result = $all_users_stmt->get_result();
            $all_users = [];
            while ($row = $all_users_result->fetch_assoc()) {
                $all_users[] = $row;
            }
            $debug_info['all_users_in_department'] = $all_users;
            $debug_info['all_users_in_department_count'] = count($all_users);
            $all_users_stmt->close();
        }
        
        // 額外查詢：嘗試用科系名稱匹配（更寬鬆的查詢）
        if (!empty($dept_name)) {
            $loose_sql = "SELECT DISTINCT u.id as user_id, u.name, u.username, u.email, u.role, t.department
                    FROM user u
                    INNER JOIN teacher t ON u.id = t.user_id
                    LEFT JOIN director dir ON u.id = dir.user_id
                    WHERE (
                        t.department LIKE ?
                        OR t.department LIKE ?
                    )
                    AND dir.user_id IS NULL
                    ORDER BY u.name ASC
                    LIMIT 20";
            $loose_stmt = $conn->prepare($loose_sql);
            if ($loose_stmt) {
                $dept_name_pattern = '%' . $dept_name . '%';
                $dept_code_pattern = '%' . $department_code . '%';
                $loose_stmt->bind_param("ss", $dept_name_pattern, $dept_code_pattern);
                $loose_stmt->execute();
                $loose_result = $loose_stmt->get_result();
                $loose_teachers = [];
                while ($loose_row = $loose_result->fetch_assoc()) {
                    $loose_teachers[] = [
                        'user_id' => $loose_row['user_id'],
                        'name' => $loose_row['name'],
                        'username' => $loose_row['username'],
                        'role' => $loose_row['role'],
                        'department' => $loose_row['department']
                    ];
                }
                $debug_info['loose_match_teachers'] = $loose_teachers;
                $debug_info['loose_match_count'] = count($loose_teachers);
                $loose_stmt->close();
            }
        }
    }
    
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'teachers' => $teachers,
        'debug' => $debug_info
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log('get_department_teachers.php error: ' . $e->getMessage());
    error_log('get_department_teachers.php trace: ' . $e->getTraceAsString());
    echo json_encode([
        'success' => false,
        'message' => '查詢失敗：' . $e->getMessage(),
        'debug' => [
            'error' => $e->getMessage(),
            'department_code' => $department_code,
            'trace' => $e->getTraceAsString()
        ]
    ], JSON_UNESCAPED_UNICODE);
}
?>

