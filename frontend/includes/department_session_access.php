<?php
/**
 * 科系主任場次存取工具
 * - 統一角色代碼映射（向後相容中文/舊代碼）
 * - 取得目前登入者 user_id 與科系代碼
 * - 判斷科主任是否可「看到」指定場次（自己建立 or 該科系有體驗課程資料）
 */
 
function normalizeBackendRole($role) {
    $role = trim($role);
    $role_map = [
        '管理員' => 'ADM',
        'admin' => 'ADM',
        'Admin' => 'ADM',
        'ADM' => 'ADM',
        '行政人員' => 'STA',
        '學校行政人員' => 'STA',
        'staff' => 'STA',
        'STA' => 'STA',
        '主任' => 'DI',
        'director' => 'DI',
        'DI' => 'DI',
        '老師' => 'TEA',
        'teacher' => 'TEA',
        'TEA' => 'TEA',
        '招生中心組員' => 'STAM',
        'STAM' => 'STAM',
        '資管科主任' => 'IM',
        '資管主任' => 'IM',
        'IM主任' => 'IM',
        'IM' => 'IM',
        '科助' => 'AS',
        'assistant' => 'AS',
        'AS' => 'AS',
    ];
    if (isset($role_map[$role])) return $role_map[$role];
    if (stripos($role, 'IM') !== false || stripos($role, '資管') !== false || stripos($role, '資訊管理') !== false) return 'IM';
    if (stripos($role, 'AS') !== false || stripos($role, '科助') !== false) return 'AS';
    return $role;
}

function isSuperUserRole($normalized_role) {
    return in_array($normalized_role, ['ADM', 'STA'], true);
}

function isDepartmentDirectorRole($normalized_role) {
    return in_array($normalized_role, ['DI', 'IM'], true);
}

function getOrFetchCurrentUserId($conn) {
    if (!empty($_SESSION['user_id'])) return (int)$_SESSION['user_id'];
    $username = $_SESSION['username'] ?? '';
    if ($username === '') return null;
    $stmt = $conn->prepare("SELECT id FROM user WHERE username = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;
    $_SESSION['user_id'] = (int)$row['id'];
    return (int)$row['id'];
}

function directorTableExists($conn) {
    static $cached = null;
    if ($cached !== null) return (bool)$cached;
    $r = $conn->query("SHOW TABLES LIKE 'director'");
    $cached = ($r && $r->num_rows > 0);
    return (bool)$cached;
}

function resolveDepartmentCode($conn, $raw_department) {
    if ($raw_department === null) return null;
    $raw_department = trim($raw_department);
    if ($raw_department === '') return null;

    $stmt = $conn->prepare("SELECT code FROM departments WHERE code = ? OR name = ? LIMIT 1");
    if (!$stmt) return $raw_department;
    $stmt->bind_param("ss", $raw_department, $raw_department);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row && !empty($row['code'])) return (string)$row['code'];
    return $raw_department;
}

function getCurrentUserDepartmentCode($conn, $user_id) {
    $dept = null;
    if (directorTableExists($conn)) {
        $stmt = $conn->prepare("SELECT department FROM director WHERE user_id = ? LIMIT 1");
    } else {
        $stmt = $conn->prepare("SELECT department FROM teacher WHERE user_id = ? LIMIT 1");
    }
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row && isset($row['department'])) {
            $dept = $row['department'];
        }
    }
    return resolveDepartmentCode($conn, $dept);
}

/**
 * 科主任是否可看到該場次：
 * - 場次 created_by 是自己，或
 * - 於該場次年份，有報名者體驗課程志願包含該科系（course_priority_1/2）
 */
function canDepartmentDirectorViewSession($conn, $session_id, $user_id, $department_code) {
    // 自己建立的場次一定可看
    $stmt = $conn->prepare("SELECT created_by, session_date FROM admission_sessions WHERE id = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$session) return false;

    $created_by = isset($session['created_by']) ? (int)$session['created_by'] : 0;
    if ($created_by === $user_id) return true;

    $exists_stmt = $conn->prepare("
        SELECT 1
        FROM admission_applications aa
        WHERE aa.session_id = ?
          AND YEAR(aa.created_at) = YEAR(?)
          AND (aa.course_priority_1 = ? OR aa.course_priority_2 = ?)
        LIMIT 1
    ");
    if (!$exists_stmt) return false;
    $session_date = $session['session_date'] ?? null;
    if ($session_date === null) return false;
    $exists_stmt->bind_param("isss", $session_id, $session_date, $department_code, $department_code);
    $exists_stmt->execute();
    $ok = (bool)$exists_stmt->get_result()->fetch_row();
    $exists_stmt->close();
    return $ok;
}

