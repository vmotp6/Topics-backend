<?php
require_once __DIR__ . '/session_config.php';

// 檢查登入狀態和角色權限
checkBackendLogin();

// 處理登出
if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_destroy();
    header("Location: login.php");
    exit;
}

// 設置頁面標題
$page_title = '使用者管理';

// 引入資料庫設定
require_once '../../Topics-frontend/frontend/config.php';

$users = [];
$by_department = []; // 以科系為單位： [ ['code'=>,'name'=>,'directors'=>[], 'teachers'=>[] ], ... ]
$student_users = []; // 五專學生（role=STU）
$other_users = [];   // 非科系成員：行政、管理員、招生中心組員、未歸科的老師/主任等（不含學生）
$error_message = '';

try {
    $conn = getDatabaseConnection();

    // 1. 取得所有科系（departments 表可能不存在則用 teacher/director 的 department 去重）
    $departments = [];
    $dept_table = $conn->query("SHOW TABLES LIKE 'departments'");
    if ($dept_table && $dept_table->num_rows > 0) {
        $dept_result = $conn->query("SELECT code, name FROM departments ORDER BY code");
        if ($dept_result) {
            while ($row = $dept_result->fetch_assoc()) {
                $departments[] = ['code' => $row['code'], 'name' => $row['name'] ?: $row['code']];
            }
        }
    }
    // 若沒有 departments 表，從 teacher / director 的 department 欄位收集不重複的科系
    if (empty($departments)) {
        $codes = [];
        foreach (['teacher', 'director'] as $tbl) {
            $tbl_check = $conn->query("SHOW TABLES LIKE '$tbl'");
            if ($tbl_check && $tbl_check->num_rows > 0) {
                $col_check = $conn->query("SHOW COLUMNS FROM $tbl LIKE 'department'");
                if ($col_check && $col_check->num_rows > 0) {
                    $r = $conn->query("SELECT DISTINCT department FROM $tbl WHERE department IS NOT NULL AND department != ''");
                    if ($r) {
                        while ($row = $r->fetch_assoc()) {
                            $codes[$row['department']] = $row['department'];
                        }
                    }
                }
            }
        }
        foreach ($codes as $code => $name) {
            $departments[] = ['code' => $code, 'name' => $name];
        }
        usort($departments, function ($a, $b) { return strcmp($a['name'], $b['name']); });
    }

    // 2. 每個科系底下的主任與老師
    $dir_tbl = $conn->query("SHOW TABLES LIKE 'director'");
    $director_table_exists = $dir_tbl && $dir_tbl->num_rows > 0;
    $tea_tbl = $conn->query("SHOW TABLES LIKE 'teacher'");
    $teacher_table_exists = $tea_tbl && $tea_tbl->num_rows > 0;

    foreach ($departments as $dept) {
        $code = $dept['code'];
        $name = $dept['name'];
        $directors = [];
        $teachers = [];

        // 主任：director 表 department = code 或 name（相容代碼/名稱）
        if ($director_table_exists) {
            $dir_sql = "SELECT u.id, u.username, u.name, u.email, u.role, u.status 
                        FROM user u INNER JOIN director d ON u.id = d.user_id 
                        WHERE d.department = ? ORDER BY u.name";
            $dir_stmt = $conn->prepare($dir_sql);
            if ($dir_stmt) {
                $dir_stmt->bind_param("s", $code);
                $dir_stmt->execute();
                $res = $dir_stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $row['role_name'] = getRoleName($row['role']);
                    $directors[] = $row;
                }
                $dir_stmt->close();
            }
            // 若用 code 沒找到，嘗試用 name 再查一次（避免有的存名稱）
            if (empty($directors) && $name !== $code) {
                $dir_stmt = $conn->prepare($dir_sql);
                if ($dir_stmt) {
                    $dir_stmt->bind_param("s", $name);
                    $dir_stmt->execute();
                    $res = $dir_stmt->get_result();
                    while ($row = $res->fetch_assoc()) {
                        $row['role_name'] = getRoleName($row['role']);
                        $directors[] = $row;
                    }
                    $dir_stmt->close();
                }
            }
        }

        // 老師：teacher 表 department = code 或 name，且同一 user 不在該科主任名單內（同一人不會同時當主任又當老師）
        if ($teacher_table_exists) {
            $tea_sql = "SELECT u.id, u.username, u.name, u.email, u.role, u.status 
                        FROM user u INNER JOIN teacher t ON u.id = t.user_id 
                        WHERE (t.department = ? OR t.department = ?) ORDER BY u.name";
            $tea_stmt = $conn->prepare($tea_sql);
            if ($tea_stmt) {
                $tea_stmt->bind_param("ss", $code, $name);
                $tea_stmt->execute();
                $res = $tea_stmt->get_result();
                $dir_ids = array_column($directors, 'id');
                while ($row = $res->fetch_assoc()) {
                    if (!in_array((int)$row['id'], $dir_ids)) {
                        $row['role_name'] = getRoleName($row['role']);
                        $teachers[] = $row;
                    }
                }
                $tea_stmt->close();
            }
        }

        $by_department[] = [
            'code' => $code,
            'name' => $name,
            'directors' => $directors,
            'teachers' => $teachers
        ];
    }

    // 3. 其他使用者：非科系成員（ADM, STA, STAM, AS, IM 等）以及 role 為 TEA/DI 但未出現在任何科系的 teacher/director 中
    $in_dept_user_ids = [];
    foreach ($by_department as $d) {
        foreach (array_merge($d['directors'], $d['teachers']) as $u) {
            $in_dept_user_ids[(int)$u['id']] = true;
        }
    }

    $all_sql = "SELECT u.id, u.username, u.name, u.email, u.role, u.status, rt.name as role_name 
                FROM user u 
                LEFT JOIN role_types rt ON u.role = rt.code 
                ORDER BY u.role, u.name";
    $all_result = $conn->query($all_sql);
    if ($all_result) {
        while ($row = $all_result->fetch_assoc()) {
            if (empty($row['role_name'])) {
                $row['role_name'] = getRoleName($row['role']);
            }
            $role = $row['role'];
            $id = (int)$row['id'];
            // 學生單獨一區「五專學生」
            if (in_array($role, ['STU', 'STUDENT', '學生', 'student'])) {
                $student_users[] = $row;
                continue;
            }
            // 老師、主任若已在某科系底下則不重複放到「其他」
            if (in_array($role, ['TEA', 'DI', 'TE', '老師', '主任'])) {
                if (!empty($in_dept_user_ids[$id])) {
                    continue;
                }
            }
            $other_users[] = $row;
        }
    }

    // 保留 $users 為全部使用者（供搜尋/匯出等若需要）
    $users = [];
    foreach ($by_department as $d) {
        foreach (array_merge($d['directors'], $d['teachers']) as $u) {
            $users[] = $u;
        }
    }
    foreach ($student_users as $u) {
        $users[] = $u;
    }
    foreach ($other_users as $u) {
        $users[] = $u;
    }
} catch (Exception $e) {
    $error_message = "讀取使用者資料失敗：" . $e->getMessage();
}

// 角色代碼到中文名稱的映射函數
function getRoleName($roleCode) {
    $roleMap = [
        'STU' => '學生', 'TEA' => '老師', 'ADM' => '管理員', 'STA' => '行政人員',
        'DI' => '主任', 'STAM' => '招生中心組員', 'AS' => '科助', 'IM' => '資管科主任',
        'student' => '學生', 'teacher' => '老師', 'admin' => '管理員', 'staff' => '行政人員',
        'director' => '主任'
    ];
    return $roleMap[$roleCode] ?? $roleCode;
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>使用者管理 - Topics 後台管理系統</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f0f2f5;
            color: #262626;
            overflow-x: hidden;
        }
        
        .page-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            gap: 16px;
        }
        
        /* 主介面樣式 */
        .dashboard {
            display: flex;
            min-height: 100vh;
        }
        

        
        /* 內容區域 */
        .content {
            padding: 24px;
        }
        
        /* 麵包屑 */
        .breadcrumb {
            margin-bottom: 0; /* 從 page-controls 控制 */
            font-size: 16px;
            color: #8c8c8c;
        }
        
        .breadcrumb a {
            color: #1890ff;
            text-decoration: none;
        }
        
        .breadcrumb a:hover {
            text-decoration: underline;
        }
        
        /* 表格區域 */
        .table-container {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            overflow: hidden;
            border: 1px solid #f0f0f0;
        }

        /* 以科系為單位顯示 */
        .dept-section {
            margin-bottom: 28px;
            border: 1px solid #f0f0f0;
            border-radius: 8px;
            overflow: hidden;
        }
        .dept-section:last-of-type { margin-bottom: 0; }
        .dept-title {
            background: #fafafa;
            padding: 14px 24px;
            font-size: 18px;
            font-weight: 600;
            color: #262626;
            border-bottom: 1px solid #f0f0f0;
            cursor: pointer;
            user-select: none;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .dept-title:hover { background: #f0f0f0; }
        .dept-title .dept-toggle {
            font-size: 14px;
            color: #8c8c8c;
            transition: transform 0.2s;
        }
        .dept-section.collapsed .dept-title .dept-toggle { transform: rotate(-90deg); }
        .dept-section .dept-body { display: block; }
        .dept-section.collapsed .dept-body { display: none; }
        .role-subsection {
            padding: 0 24px 16px;
        }
        .role-subsection-title {
            font-size: 14px;
            font-weight: 600;
            color: #8c8c8c;
            margin: 12px 0 8px;
            padding-bottom: 4px;
        }
        .role-subsection:first-child .role-subsection-title { margin-top: 16px; }
        .dept-section .user-table { margin-bottom: 0; table-layout: fixed; width: 100%; }
        .dept-section .user-table th, .dept-section .user-table td {
            padding: 10px 12px;
            font-size: 14px;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .dept-section .user-table th:nth-child(1), .dept-section .user-table td:nth-child(1) { width: 56px; min-width: 56px; }
        .dept-section .user-table th:nth-child(2), .dept-section .user-table td:nth-child(2) { width: 90px; min-width: 90px; }
        .dept-section .user-table th:nth-child(3), .dept-section .user-table td:nth-child(3) { width: 90px; min-width: 90px; }
        .dept-section .user-table th:nth-child(4), .dept-section .user-table td:nth-child(4) { width: 180px; min-width: 180px; }
        .dept-section .user-table th:nth-child(5), .dept-section .user-table td:nth-child(5) { width: 100px; min-width: 100px; }
        .dept-section .user-table th:nth-child(6), .dept-section .user-table td:nth-child(6) { width: 72px; min-width: 72px; }
        .dept-section .user-table th:nth-child(7), .dept-section .user-table td:nth-child(7) { width: 130px; min-width: 130px; }
        .dept-section .user-table th:first-child, .dept-section .user-table td:first-child { padding-left: 12px; }
        .other-section .dept-title { background: #f6f8fa; }
        .student-section .dept-title { background: #f0f5ff; }
        .student-section .pagination { margin-top: 12px; margin-bottom: 8px; }
        
        
        .table-search {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }
        .table-search input {
            padding: 8px 12px;
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            width: 240px;
            background: #fff;
            font-size: 16px;
            transition: all 0.3s;
        }
        .filter-select {
            padding: 8px 12px;
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            font-size: 14px;
            background: #fff;
            min-width: 120px;
            cursor: pointer;
        }
        .filter-select:focus {
            outline: none;
            border-color: #1890ff;
        }
        
        .table-search input:focus {
            outline: none;
            border-color: #1890ff;
            box-shadow: 0 0 0 2px rgba(24,144,255,0.2);
        }
        
        .user-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        
        .user-table th {
            background: #fafafa;
            padding: 16px 24px;
            text-align: left;
            font-weight: 600;
            color: #262626;
            border-bottom: 1px solid #f0f0f0;
            font-size: 16px;
            cursor: pointer;
            user-select: none;
            position: relative;
        }
        
        .user-table th:first-child, .user-table td:first-child {
            padding-left: 60px;
        }
        
        .user-table th:hover {
            background: #f0f0f0;
        }
        
        .sort-icon {
            margin-left: 8px;
            font-size: 12px;
            color: #8c8c8c;
        }
        
        .sort-icon.active {
            color: #1890ff;
        }
        
        .sort-icon.asc::after {
            content: "↑";
        }
        
        .sort-icon.desc::after {
            content: "↓";
        }
        
        .user-table td {
            padding: 16px 24px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 16px;
            color: #595959;
        }
        
        .user-table tr:hover {
            background: #fafafa;
        }
        
        .role-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
        }
        
        .role-student {
            background: #f6ffed;
            color: #52c41a;
            border: 1px solid #b7eb8f;
        }
        
        .role-teacher {
            background: #e6f7ff;
            color: #1890ff;
            border: 1px solid #91d5ff;
        }
        
        .role-admin {
            background: #fff2e8;
            color: #fa8c16;
            border: 1px solid #ffd591;
        }
        
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
        }
        
        .status-0 {
            background: #fff2f0;
            color: #ff4d4f;
            border: 1px solid #ffccc7;
        }
        
        .status-1 {
            background: #f6ffed;
            color: #52c41a;
            border: 1px solid #b7eb8f;
        }
        
        .status-2 {
            background: #e6f7ff;
            color: #1890ff;
            border: 1px solid #91d5ff;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .btn-view, .btn-edit {
            padding: 4px 12px;
            border: 1px solid #d9d9d9;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
            background: #fff;
        }
        
        .btn-view {
            color: #1890ff;
            border-color: #1890ff;
        }
        
        .btn-view:hover {
            background: #1890ff;
            color: white;
        }
        
        .btn-edit {
            color: #52c41a;
            border-color: #52c41a;
        }
        
        .btn-edit:hover {
            background: #52c41a;
            color: white;
        }
        
        .message {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 16px;
            font-weight: 500;
            font-size: 14px;
        }
        
        .message.success {
            background: #f6ffed;
            color: #52c41a;
            border: 1px solid #b7eb8f;
        }
        
        .message.error {
            background: #fff2f0;
            color: #ff4d4f;
            border: 1px solid #ffccc7;
        }
        
        .loading {
            text-align: center;
            padding: 40px;
            color: #8c8c8c;
            font-size: 14px;
        }
        
        /* 模態對話框 */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.45);
        }
        
        .modal-content {
            background-color: #fff;
            margin: 5% auto;
            padding: 0;
            border-radius: 8px;
            width: 90%;
            max-width: 520px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .modal-header {
            padding: 16px 24px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #fff;
            border-radius: 8px 8px 0 0;
        }
        
        .modal-title {
            font-size: 16px;
            font-weight: 600;
            color: #262626;
        }
        
        .close {
            color: #8c8c8c;
            font-size: 20px;
            font-weight: bold;
            cursor: pointer;
            padding: 4px;
            border-radius: 4px;
            transition: all 0.3s;
        }
        
        .close:hover {
            color: #262626;
            background: #f5f5f5;
        }
        
        .modal-body {
            padding: 24px;
        }
        
        .form-group {
            margin-bottom: 16px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #262626;
            font-size: 14px;
        }
        
        .form-group input, .form-group select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: #1890ff;
            box-shadow: 0 0 0 2px rgba(24,144,255,0.2);
        }
        
        .form-group input[readonly] {
            background: #f5f5f5;
            color: #8c8c8c;
        }
        
        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid #f0f0f0;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            background: #fafafa;
            border-radius: 0 0 8px 8px;
        }
        
        .btn {
            padding: 8px 16px;
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
            background: #fff;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        
        .btn-primary {
            background: #1890ff;
            color: white;
            border-color: #1890ff;
        }
        
        .btn-primary:hover {
            background: #40a9ff;
            border-color: #40a9ff;
        }
        
        .btn-secondary {
            background: #fff;
            color: #595959;
            border-color: #d9d9d9;
        }
        
        .btn-secondary:hover {
            background: #f5f5f5;
            border-color: #40a9ff;
            color: #40a9ff;
        }
        
        /* 分頁樣式 */
        .pagination {
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 1px solid #f0f0f0;
            background: #fafafa;
        }

        .pagination-info {
            display: flex;
            align-items: center;
            gap: 16px;
            color: #8c8c8c;
            font-size: 14px;
        }

        .pagination-controls {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .pagination select {
            padding: 6px 12px;
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            font-size: 14px;
            background: #fff;
            cursor: pointer;
        }

        .pagination select:focus {
            outline: none;
            border-color: #1890ff;
            box-shadow: 0 0 0 2px rgba(24,144,255,0.2);
        }

        .pagination button {
            padding: 6px 12px;
            border: 1px solid #d9d9d9;
            background: #fff;
            color: #595959;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
        }

        .pagination button:hover:not(:disabled) {
            border-color: #1890ff;
            color: #1890ff;
        }

        .pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .pagination button.active {
            background: #1890ff;
            color: white;
            border-color: #1890ff;
        }

        /* 響應式設計 */
        @media (max-width: 768px) {
            .table-search input {
                width: 200px;
            }
            
            .nav-search input {
                width: 200px;
            }
        }
    </style>
</head>
<body>
    <!-- 主介面 -->
    <div class="dashboard">
        <!-- 引入側邊欄 -->
        <?php include 'sidebar.php'; ?>
        
        <!-- 主內容區 -->
        <div class="main-content" id="mainContent">
            <!-- 引入標題欄 -->
            <?php include 'header.php'; ?>
            
            <!-- 內容區域 -->
            <div class="content">
                <!-- 麵包屑 -->
                <div class="page-controls">
                    <div class="breadcrumb">
                        <a href="index.php">首頁</a> / 使用者管理
                    </div>
                    <div class="table-search">
                        <input type="text" id="tableSearchInput" placeholder="搜尋使用者..." onkeyup="filterTable()">
                        <select id="filterDept" class="filter-select" onchange="filterTable()" title="科系篩選">
                            <option value="">全部科系</option>
                            <?php foreach ($by_department as $d): ?>
                            <option value="<?php echo htmlspecialchars($d['name'] ?? $d['code'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($d['name'] ?? $d['code']); ?></option>
                            <?php endforeach; ?>
                            <option value="五專學生">五專學生</option>
                            <option value="其他">其他</option>
                        </select>
                        <select id="filterRole" class="filter-select" onchange="filterTable()" title="角色篩選">
                            <option value="">全部角色</option>
                            <option value="STU">學生</option>
                            <option value="TEA">老師</option>
                            <option value="DI">主任</option>
                            <option value="STA">行政人員</option>
                            <option value="ADM">管理員</option>
                            <option value="STAM">招生中心組員</option>
                            <option value="AS">科助</option>
                            <option value="IM">資管科主任</option>
                        </select>
                        <a href="add_user.php" class="btn btn-primary" style="padding: 8px 12px; font-size: 14px;">
                            <i class="fas fa-plus" style="margin-right: 6px;"></i>
                            新增使用者
                        </a>
                    </div>
                </div>
                
                <div id="messageContainer"></div>
                
                <!-- 使用者表格（以科系為單位顯示） -->
                <div class="table-container">
                    <div id="tableContainer"
                         data-by-department='<?php echo htmlspecialchars(json_encode($by_department), ENT_QUOTES, 'UTF-8'); ?>'
                         data-student-users='<?php echo htmlspecialchars(json_encode($student_users), ENT_QUOTES, 'UTF-8'); ?>'
                         data-other-users='<?php echo htmlspecialchars(json_encode($other_users), ENT_QUOTES, 'UTF-8'); ?>'>
                        <div class="loading">載入中...</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 查看用戶模態對話框 -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <span class="modal-title">查看用戶資料</span>
                <span class="close" onclick="closeModal('viewModal')">&times;</span>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>ID:</label>
                    <input type="text" id="viewUserId" readonly>
                </div>
                <div class="form-group">
                    <label>帳號:</label>
                    <input type="text" id="viewUsername" readonly>
                </div>
                <div class="form-group">
                    <label>姓名:</label>
                    <input type="text" id="viewName" readonly>
                </div>
                <div class="form-group">
                    <label>電子郵件:</label>
                    <input type="email" id="viewEmail" readonly>
                </div>
                <div class="form-group">
                    <label>角色:</label>
                    <input type="text" id="viewRole" readonly>
                </div>
                <div class="form-group">
                    <label>狀態:</label>
                    <input type="text" id="viewStatus" readonly>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('viewModal')">關閉</button>
            </div>
        </div>
    </div>
    
    <script>
    // 排序表格
    function sortTable(field) {
        let newSortOrder = 'asc';
        
        // 如果點擊的是當前排序欄位，則切換排序方向
        if (currentSortBy === field) {
            newSortOrder = currentSortOrder === 'asc' ? 'desc' : 'asc';
        }
        
        window.location.href = `users.php?sort_by=${field}&sort_order=${newSortOrder}`;
    }
    
    // 更新排序圖標
    function updateSortIcons() {
        // 清除所有圖標
        const icons = document.querySelectorAll('.sort-icon');
        icons.forEach(icon => {
            icon.className = 'sort-icon';
        });
        
        // 設置當前排序欄位的圖標
        const currentIcon = document.getElementById(`sort-${currentSortBy}`);
        if (currentIcon) {
            currentIcon.className = `sort-icon active ${currentSortOrder}`;
        }
    }
    
    // 渲染單一使用者列
    function renderUserRow(user) {
        const roleClass = getRoleClass(user.role);
        const userStatus = parseInt(user.status);
        const statusClass = getStatusClass(userStatus);
        const roleName = user.role_name || getRoleName(user.role);
        const roleCode = (user.role || '').toUpperCase();
        return `
            <tr data-user-id="${user.id}" data-role="${roleCode}" data-search="${(user.username + ' ' + (user.name || '') + ' ' + (user.email || '') + ' ' + roleName).toLowerCase()}">
                <td>${user.id}</td>
                <td>${user.username}</td>
                <td>${user.name}</td>
                <td>${user.email}</td>
                <td><span class="role-badge ${roleClass}">${roleName}</span></td>
                <td><span class="status-badge ${statusClass}">${userStatus === 0 ? '停用' : '啟用'}</span></td>
                <td>
                    <div class="action-buttons">
                        <button onclick="viewUser(${user.id})" class="btn-view">查看</button>
                        <button onclick="editUser(${user.id})" class="btn-edit">編輯</button>
                    </div>
                </td>
            </tr>
        `;
    }

    // 以科系為單位渲染使用者
    function renderUserTableByDepartment(byDepartment, studentUsers, otherUsers) {
        const tableContainer = document.getElementById('tableContainer');
        const hasDept = byDepartment && byDepartment.length > 0;
        const hasStudent = studentUsers && studentUsers.length > 0;
        const hasOther = otherUsers && otherUsers.length > 0;
        if (!hasDept && !hasStudent && !hasOther) {
            tableContainer.innerHTML = '<div class="loading">沒有找到使用者資料</div>';
            return;
        }

        let html = '';
        const allUsers = [];

        // 各科系
        (byDepartment || []).forEach(function(dept) {
            const directors = dept.directors || [];
            const teachers = dept.teachers || [];
            if (directors.length === 0 && teachers.length === 0) return;

            const deptName = (dept.name || dept.code || '科系');
            const deptNameEsc = (dept.name || '').replace(/"/g, '&quot;');
            html += '<div class="dept-section" data-dept-name="' + deptNameEsc + '">';
            html += '<div class="dept-title" role="button" tabindex="0" aria-expanded="true"><i class="fas fa-chevron-down dept-toggle"></i><span>' + deptName + '</span></div>';
            html += '<div class="dept-body">';

            if (directors.length > 0) {
                html += '<div class="role-subsection">';
                html += '<div class="role-subsection-title">主任</div>';
                html += '<table class="user-table"><thead><tr><th>ID</th><th>帳號</th><th>姓名</th><th>電子郵件</th><th>角色</th><th>狀態</th><th>操作</th></tr></thead><tbody>';
                directors.forEach(function(u) { allUsers.push(u); html += renderUserRow(u); });
                html += '</tbody></table></div>';
            }
            if (teachers.length > 0) {
                html += '<div class="role-subsection">';
                html += '<div class="role-subsection-title">老師</div>';
                html += '<table class="user-table"><thead><tr><th>ID</th><th>帳號</th><th>姓名</th><th>電子郵件</th><th>角色</th><th>狀態</th><th>操作</th></tr></thead><tbody>';
                teachers.forEach(function(u) { allUsers.push(u); html += renderUserRow(u); });
                html += '</tbody></table></div>';
            }
            html += '</div></div>';
        });

        // 五專學生（獨立一區，含分頁）
        if (studentUsers && studentUsers.length > 0) {
            html += '<div class="dept-section student-section" id="studentSection" data-dept-name="五專學生">';
            html += '<div class="dept-title" role="button" tabindex="0" aria-expanded="true"><i class="fas fa-chevron-down dept-toggle"></i><span>五專學生</span></div>';
            html += '<div class="dept-body">';
            html += '<div class="role-subsection">';
            html += '<table class="user-table" id="studentTable"><thead><tr><th>ID</th><th>帳號</th><th>姓名</th><th>電子郵件</th><th>角色</th><th>狀態</th><th>操作</th></tr></thead><tbody>';
            studentUsers.forEach(function(u) { allUsers.push(u); html += renderUserRow(u); });
            html += '</tbody></table>';
            html += '<div class="pagination" id="studentPagination">';
            html += '<div class="pagination-info"><span>每頁顯示：</span>';
            html += '<select id="studentItemsPerPage" onchange="changeStudentItemsPerPage()"><option value="10" selected>10</option><option value="20">20</option><option value="50">50</option><option value="100">100</option><option value="all">全部</option></select>';
            html += '<span id="studentPageInfo">顯示第 <span id="studentCurrentRange">1-10</span> 筆，共 ' + studentUsers.length + ' 筆</span></div>';
            html += '<div class="pagination-controls"><button id="studentPrevPage" onclick="changeStudentPage(-1)" disabled>上一頁</button><span id="studentPageNumbers"></span><button id="studentNextPage" onclick="changeStudentPage(1)">下一頁</button></div>';
            html += '</div></div></div></div>';
        }

        // 其他（行政、管理員、招生中心組員、未歸科老師/主任等）
        if (otherUsers && otherUsers.length > 0) {
            html += '<div class="dept-section other-section" data-dept-name="其他">';
            html += '<div class="dept-title" role="button" tabindex="0" aria-expanded="true"><i class="fas fa-chevron-down dept-toggle"></i><span>其他（行政、管理員、招生中心組員、未歸科等）</span></div>';
            html += '<div class="dept-body">';
            html += '<div class="role-subsection">';
            html += '<table class="user-table" id="userTable"><thead><tr><th>ID</th><th>帳號</th><th>姓名</th><th>電子郵件</th><th>角色</th><th>狀態</th><th>操作</th></tr></thead><tbody>';
            otherUsers.forEach(function(u) { allUsers.push(u); html += renderUserRow(u); });
            html += '</tbody></table></div></div></div>';
        }

        tableContainer.innerHTML = html || '<div class="loading">沒有找到使用者資料</div>';
        tableContainer.dataset.users = JSON.stringify(allUsers);

        // 科系標題點擊收合/展開
        tableContainer.querySelectorAll('.dept-title').forEach(function(el) {
            el.addEventListener('click', function() {
                const section = this.closest('.dept-section');
                if (section) {
                    section.classList.toggle('collapsed');
                    this.setAttribute('aria-expanded', section.classList.contains('collapsed') ? 'false' : 'true');
                }
            });
        });

        if (hasStudent) initStudentPagination();
    }

    // 五專學生分頁（參考 enrollment_list 邏輯）
    let studentCurrentPage = 1;
    let studentItemsPerPage = 10;
    let studentAllRows = [];

    function initStudentPagination() {
        const section = document.getElementById('studentSection');
        if (!section) return;
        const tbody = section.querySelector('#studentTable tbody');
        if (!tbody) return;
        studentAllRows = Array.from(tbody.querySelectorAll('tr[data-user-id]'));
        studentCurrentPage = 1;
        const sel = document.getElementById('studentItemsPerPage');
        studentItemsPerPage = sel ? (sel.value === 'all' ? studentAllRows.length : parseInt(sel.value) || 10) : 10;
        updateStudentPagination();
    }

    function changeStudentItemsPerPage() {
        const select = document.getElementById('studentItemsPerPage');
        studentItemsPerPage = select && select.value === 'all' ? studentAllRows.length : parseInt(select ? select.value : 10) || 10;
        studentCurrentPage = 1;
        updateStudentPagination();
    }

    function changeStudentPage(direction) {
        const filtered = getStudentFilteredRows();
        const totalPages = studentItemsPerPage === 'all' || studentItemsPerPage >= filtered.length ? 1 : Math.ceil(filtered.length / studentItemsPerPage);
        studentCurrentPage += direction;
        if (studentCurrentPage < 1) studentCurrentPage = 1;
        if (studentCurrentPage > totalPages) studentCurrentPage = totalPages;
        updateStudentPagination();
    }

    function goToStudentPage(page) {
        studentCurrentPage = page;
        updateStudentPagination();
    }

    function getStudentFilteredRows() {
        const filter = (document.getElementById('tableSearchInput') || {}).value.toLowerCase().trim();
        const filterRole = (document.getElementById('filterRole') || {}).value.toUpperCase();
        return studentAllRows.filter(function(row) {
            const searchAttr = (row.getAttribute('data-search') || '').toLowerCase();
            const rowRole = (row.getAttribute('data-role') || '').toUpperCase();
            const textMatch = !filter || searchAttr.indexOf(filter) > -1;
            const roleMatch = !filterRole || rowRole === filterRole;
            return textMatch && roleMatch;
        });
    }

    function updateStudentPagination() {
        const section = document.getElementById('studentSection');
        if (!section) return;
        const filteredRows = getStudentFilteredRows();
        const totalItems = filteredRows.length;
        const totalPages = studentItemsPerPage === 'all' || studentItemsPerPage >= totalItems ? 1 : Math.ceil(totalItems / studentItemsPerPage);

        studentAllRows.forEach(function(row) { row.style.display = 'none'; });
        if (totalItems === 0) {
            if (document.getElementById('studentCurrentRange')) document.getElementById('studentCurrentRange').textContent = '0-0';
            if (document.getElementById('studentPageInfo')) document.getElementById('studentPageInfo').innerHTML = '顯示第 <span id="studentCurrentRange">0-0</span> 筆，共 0 筆';
        } else if (studentItemsPerPage === 'all' || studentItemsPerPage >= totalItems) {
            filteredRows.forEach(function(row) { row.style.display = ''; });
            if (document.getElementById('studentCurrentRange')) document.getElementById('studentCurrentRange').textContent = '1-' + totalItems;
            if (document.getElementById('studentPageInfo')) document.getElementById('studentPageInfo').innerHTML = '顯示第 <span id="studentCurrentRange">1-' + totalItems + '</span> 筆，共 ' + totalItems + ' 筆';
        } else {
            const start = (studentCurrentPage - 1) * studentItemsPerPage;
            const end = Math.min(start + studentItemsPerPage, totalItems);
            for (let i = start; i < end; i++) if (filteredRows[i]) filteredRows[i].style.display = '';
            if (document.getElementById('studentCurrentRange')) document.getElementById('studentCurrentRange').textContent = (start + 1) + '-' + end;
            if (document.getElementById('studentPageInfo')) document.getElementById('studentPageInfo').innerHTML = '顯示第 <span id="studentCurrentRange">' + (start + 1) + '-' + end + '</span> 筆，共 ' + totalItems + ' 筆';
        }

        const prevBtn = document.getElementById('studentPrevPage');
        const nextBtn = document.getElementById('studentNextPage');
        if (prevBtn) prevBtn.disabled = studentCurrentPage === 1;
        if (nextBtn) nextBtn.disabled = studentCurrentPage >= totalPages || totalPages <= 1;
        updateStudentPageNumbers(totalPages);
    }

    function updateStudentPageNumbers(totalPages) {
        const container = document.getElementById('studentPageNumbers');
        if (!container) return;
        container.innerHTML = '';
        if (totalPages >= 1) {
            const pagesToShow = totalPages === 1 ? [1] : Array.from({ length: totalPages }, function(_, i) { return i + 1; });
            pagesToShow.forEach(function(i) {
                const btn = document.createElement('button');
                btn.textContent = i;
                btn.onclick = function() { goToStudentPage(i); };
                if (i === studentCurrentPage) btn.classList.add('active');
                container.appendChild(btn);
            });
        }
    }
    
    // 獲取角色樣式類別
    function getRoleClass(role) {
        // 支援新舊代碼格式
        const roleUpper = role.toUpperCase();
        switch (roleUpper) {
            case 'STU':
            case 'STUDENT': return 'role-student';
            case 'TEA':
            case 'TEACHER': return 'role-teacher';
            case 'ADM':
            case 'ADMIN': return 'role-admin';
            case 'STA':
            case 'STAFF': return 'role-teacher'; // 行政人員使用老師樣式
            case 'DI':
            case 'DIRECTOR': return 'role-admin'; // 主任使用管理員樣式
            default: return 'role-student';
        }
    }
    
    // 獲取角色中文名稱
    function getRoleName(roleCode) {
        const roleMap = {
            'STU': '學生', 'TEA': '老師', 'ADM': '管理員', 'STA': '行政人員',
            'DI': '主任', 'STAM': '招生中心組員', 'AS': '科助', 'IM': '資管科主任',
            'student': '學生', 'teacher': '老師', 'admin': '管理員', 'staff': '行政人員',
            'director': '主任'
        };
        return roleMap[roleCode] || roleMap[(roleCode || '').toUpperCase()] || roleCode;
    }

    // 獲取狀態樣式類別
    function getStatusClass(status) {
        // 確保 status 是數字類型，處理字符串 "0", "1" 或數字 0, 1
        const statusNum = parseInt(status, 10);
        if (isNaN(statusNum)) {
            return 'status-0'; // 如果無法解析，預設為停用
        }
        if (statusNum === 1) {
            return 'status-1'; // 啟用 - 綠色
        } else {
            return 'status-0'; // 停用 - 紅色
        }
    }
    
    // 查看使用者
    async function viewUser(userId) {
        const tableContainer = document.getElementById('tableContainer');
        const usersJson = tableContainer.dataset.users;
        if (!usersJson) return;
        const users = JSON.parse(usersJson);
        const user = users.find(u => u.id == userId);

        if (user) {
            document.getElementById('viewUserId').value = user.id;
            document.getElementById('viewUsername').value = user.username;
            document.getElementById('viewName').value = user.name;
            document.getElementById('viewEmail').value = user.email;
            const roleName = user.role_name || getRoleName(user.role);
            document.getElementById('viewRole').value = roleName;
            // 確保 status 是數字類型
            const userStatus = parseInt(user.status);
            document.getElementById('viewStatus').value = userStatus === 0 ? '停用' : '啟用';
            
            document.getElementById('viewModal').style.display = 'block';
        } else {
            showMessage('載入使用者資料失敗', 'error');
        }
    }

    // 搜尋使用者 (本地端)
    function searchUsers() {
        const query = document.getElementById('searchInput').value.toLowerCase();
        const rows = document.querySelectorAll('#userTable tbody tr');
        // ... (此處省略本地搜尋邏輯，因為 filterTable 已實現)
        filterTable();
    }
    
    // 編輯使用者 - 跳轉到編輯頁面
    function editUser(userId) {
        window.location.href = `edit_user.php?id=${userId}`;
    }
    
    // 關閉模態對話框
    function closeModal(modalId) {
        document.getElementById(modalId).style.display = 'none';
    }
    
    // 點擊模態對話框外部關閉
    window.onclick = function(event) {
        const viewModal = document.getElementById('viewModal');
        
        if (event.target === viewModal) {
            viewModal.style.display = 'none';
        }
    }
    
    // 顯示訊息
    function showMessage(message, type) {
        const messageContainer = document.getElementById('messageContainer');
        messageContainer.innerHTML = `<div class="message ${type}">${message}</div>`;
        
        setTimeout(() => {
            messageContainer.innerHTML = '';
        }, 3000);
    }
    
    // 分頁相關變數
    let currentPage = 1;
    let itemsPerPage = 10;
    let allRows = [];
    let filteredRows = [];
    
    // 初始化分頁
    function initPagination() {
        const table = document.getElementById('userTable');
        if (!table) return;
        
        const tbody = table.getElementsByTagName('tbody')[0];
        if (!tbody) return;
        
        allRows = Array.from(tbody.getElementsByTagName('tr'));
        filteredRows = allRows;
        
        // 顯示分頁控制
        const paginationContainer = document.getElementById('paginationContainer');
        if (paginationContainer && allRows.length > 0) {
            paginationContainer.style.display = 'flex';
        }
        
        // 初始化分頁
        updatePagination();
    }
    
    function changeItemsPerPage() {
        const select = document.getElementById('itemsPerPage');
        itemsPerPage = select.value === 'all' ? 
                      filteredRows.length : 
                      parseInt(select.value);
        currentPage = 1;
        updatePagination();
    }

    function changePage(direction) {
        const totalPages = Math.ceil(filteredRows.length / itemsPerPage);
        currentPage += direction;
        
        if (currentPage < 1) currentPage = 1;
        if (currentPage > totalPages) currentPage = totalPages;
        
        updatePagination();
    }

    function goToPage(page) {
        currentPage = page;
        updatePagination();
    }

    function updatePagination() {
        const totalItems = filteredRows.length;
        const totalPages = itemsPerPage === 'all' ? 1 : Math.ceil(totalItems / itemsPerPage);
        
        // 隱藏所有行
        allRows.forEach(row => row.style.display = 'none');
        
        if (itemsPerPage === 'all' || itemsPerPage >= totalItems) {
            // 顯示所有過濾後的行
            filteredRows.forEach(row => row.style.display = '');
            
            // 更新分頁資訊
            document.getElementById('currentRange').textContent = 
                totalItems > 0 ? `1-${totalItems}` : '0-0';
        } else {
            // 計算當前頁的範圍
            const start = (currentPage - 1) * itemsPerPage;
            const end = Math.min(start + itemsPerPage, totalItems);
            
            // 顯示當前頁的行
            for (let i = start; i < end; i++) {
                if (filteredRows[i]) {
                    filteredRows[i].style.display = '';
                }
            }
            
            // 更新分頁資訊
            document.getElementById('currentRange').textContent = 
                totalItems > 0 ? `${start + 1}-${end}` : '0-0';
        }
        
        // 更新總數
        document.getElementById('pageInfo').innerHTML = 
            `顯示第 <span id="currentRange">${document.getElementById('currentRange').textContent}</span> 筆，共 ${totalItems} 筆`;
        
        // 更新上一頁/下一頁按鈕
        document.getElementById('prevPage').disabled = currentPage === 1;
        document.getElementById('nextPage').disabled = currentPage >= totalPages || totalPages <= 1;
        
        // 更新頁碼按鈕
        updatePageNumbers(totalPages);
    }

    function updatePageNumbers(totalPages) {
        const pageNumbers = document.getElementById('pageNumbers');
        pageNumbers.innerHTML = '';
        
        // 總是顯示頁碼按鈕（即使只有1頁）
        if (totalPages >= 1) {
            // 如果只有1頁，只顯示"1"
            // 如果有多頁，顯示所有頁碼
            const pagesToShow = totalPages === 1 ? [1] : Array.from({length: totalPages}, (_, i) => i + 1);
            
            for (let i of pagesToShow) {
                const btn = document.createElement('button');
                btn.textContent = i;
                btn.onclick = () => goToPage(i);
                if (i === currentPage) btn.classList.add('active');
                pageNumbers.appendChild(btn);
            }
        }
    }
    
    // 表格搜尋與篩選（關鍵字、科系、角色）
    function filterTable() {
        const input = document.getElementById('tableSearchInput');
        const filterDeptEl = document.getElementById('filterDept');
        const filterRoleEl = document.getElementById('filterRole');
        const filter = (input ? input.value : '').toLowerCase().trim();
        const filterDept = filterDeptEl ? filterDeptEl.value : '';
        const filterRole = filterRoleEl ? filterRoleEl.value : '';
        const container = document.getElementById('tableContainer');
        if (!container) return;

        const sections = container.querySelectorAll('.dept-section');
        sections.forEach(function(section) {
            const deptName = (section.getAttribute('data-dept-name') || '').trim();
            // 科系篩選：若選了科系，只顯示該科系或「其他」
            if (filterDept && deptName !== filterDept) {
                section.style.display = 'none';
                return;
            }
            const rows = section.querySelectorAll('tr[data-user-id]');
            let visibleCount = 0;
            rows.forEach(function(row) {
                const searchAttr = row.getAttribute('data-search') || '';
                const rowRole = (row.getAttribute('data-role') || '').toUpperCase();
                const textMatch = !filter || searchAttr.indexOf(filter) > -1 || (row.textContent || '').toLowerCase().indexOf(filter) > -1;
                const roleMatch = !filterRole || rowRole === filterRole.toUpperCase();
                const match = textMatch && roleMatch;
                row.style.display = match ? '' : 'none';
                if (match) visibleCount++;
            });
            const subs = section.querySelectorAll('.role-subsection');
            subs.forEach(function(sub) {
                const subRows = sub.querySelectorAll('tr[data-user-id]');
                const subVisible = Array.from(subRows).some(function(r) { return r.style.display !== 'none'; });
                sub.style.display = subVisible ? '' : 'none';
            });
            section.style.display = visibleCount > 0 ? '' : 'none';
        });
        var studentSection = document.getElementById('studentSection');
        if (studentSection) {
            studentAllRows = Array.from(studentSection.querySelectorAll('tr[data-user-id]'));
            updateStudentPagination();
        }
    }

    // 頁面載入時執行
    document.addEventListener('DOMContentLoaded', function() {
        const tableContainer = document.getElementById('tableContainer');
        const byDepartmentData = tableContainer.dataset.byDepartment;
        const studentUsersData = tableContainer.dataset.studentUsers;
        const otherUsersData = tableContainer.dataset.otherUsers;

        if (byDepartmentData !== undefined || studentUsersData !== undefined || otherUsersData !== undefined) {
            const byDepartment = byDepartmentData ? JSON.parse(byDepartmentData) : [];
            const studentUsers = studentUsersData ? JSON.parse(studentUsersData) : [];
            const otherUsers = otherUsersData ? JSON.parse(otherUsersData) : [];
            renderUserTableByDepartment(byDepartment, studentUsers, otherUsers);
        } else {
            tableContainer.innerHTML = '<div class="loading">無法載入使用者資料</div>';
        }
    });
    </script>
</body>
</html>
