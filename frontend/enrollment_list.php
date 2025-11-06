<?php
session_start();

// 檢查是否已登入
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: login.php");
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
        die('錯誤：找不到資料庫設定檔案 (config.php)');
    }
}

require_once $config_path;

if (!function_exists('getDatabaseConnection')) {
    die('錯誤：資料庫連接函數未定義');
}

// 初始化變數
$enrollments = [];
$teachers = [];
$error_message = '';
$username = isset($_SESSION['username']) ? $_SESSION['username'] : '';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$is_imd_user = ($username === 'IMD');
$is_fld_user = ($username === 'FLD');
$is_department_user = ($is_imd_user || $is_fld_user);
// 判斷是否為招生中心（admin1 或管理員角色，但排除部門用戶）
// 確保部門用戶（IMD/FLD）優先，即使他們有管理員角色，也只顯示教師分配功能
$is_admission_center = !$is_department_user && ($username === 'admin1' || in_array($user_role, ['admin', '管理員', '學校行政人員']));

// 設置頁面標題
if ($is_imd_user) {
    $page_title = '資管科就讀意願名單';
} elseif ($is_fld_user) {
    $page_title = '應用外語科就讀意願名單';
} else {
    $page_title = '就讀意願名單';
}

try {
    // 建立資料庫連接
    $conn = getDatabaseConnection();

    // 獲取報名資料（根據用戶權限過濾）
    if ($is_imd_user) {
        // IMD用戶可以看到：1) 資管科相關的就讀意願 或 2) 分配給IMD的學生，並獲取分配的老師資訊
        $stmt = $conn->prepare("SELECT ei.*, 
                               t.name as teacher_name, 
                               u.username as teacher_username
                               FROM enrollment_intention ei
                               LEFT JOIN user u ON ei.assigned_teacher_id = u.id
                               LEFT JOIN teacher t ON u.id = t.user_id
                               WHERE (intention1 LIKE '%資管%' OR intention1 LIKE '%資訊管理%' 
                               OR intention2 LIKE '%資管%' OR intention2 LIKE '%資訊管理%' 
                               OR intention3 LIKE '%資管%' OR intention3 LIKE '%資訊管理%')
                               OR assigned_department = 'IMD'
                               ORDER BY created_at DESC");
    } elseif ($is_fld_user) {
        // FLD用戶可以看到分配給FLD的學生，並獲取分配的老師資訊
        $stmt = $conn->prepare("SELECT ei.*, 
                               t.name as teacher_name, 
                               u.username as teacher_username
                               FROM enrollment_intention ei
                               LEFT JOIN user u ON ei.assigned_teacher_id = u.id
                               LEFT JOIN teacher t ON u.id = t.user_id
                               WHERE assigned_department = 'FLD'
                               ORDER BY created_at DESC");
    } else {
        // 一般管理員可以看到所有就讀意願，並獲取分配的老師資訊
        $stmt = $conn->prepare("SELECT ei.*, 
                               t.name as teacher_name, 
                               u.username as teacher_username
                               FROM enrollment_intention ei
                               LEFT JOIN user u ON ei.assigned_teacher_id = u.id
                               LEFT JOIN teacher t ON u.id = t.user_id
                               ORDER BY created_at DESC");
    }
    
    if (!$stmt) {
        throw new Exception('準備查詢語句失敗: ' . $conn->error);
    }
    
    if (!$stmt->execute()) {
        throw new Exception('執行查詢失敗: ' . $stmt->error);
    }
    
    $result = $stmt->get_result();
    if ($result) {
        $enrollments = $result->fetch_all(MYSQLI_ASSOC);
    }

    // 檢查是否有新的就讀意願（最近1小時內）
    $new_enrollments_count = 0;
    $new_enrollments = [];
    $one_hour_ago_timestamp = strtotime('-1 hour');
    
    if (!empty($enrollments)) {
        foreach ($enrollments as $enrollment) {
            if (isset($enrollment['created_at']) && !empty($enrollment['created_at'])) {
                $created_timestamp = strtotime($enrollment['created_at']);
                if ($created_timestamp !== false && $created_timestamp >= $one_hour_ago_timestamp) {
                    $new_enrollments_count++;
                    $new_enrollments[] = $enrollment;
                }
            }
        }
    }

    // 如果是部門用戶（IMD或FLD），獲取老師列表
    if ($is_department_user) {
        // 先檢查必要的表格是否存在
        $table_check = $conn->query("SHOW TABLES LIKE 'user'");
        if ($table_check && $table_check->num_rows > 0) {
            $teacher_stmt = $conn->prepare("
                SELECT u.id, u.username, t.name, t.department 
                FROM user u 
                LEFT JOIN teacher t ON u.id = t.user_id 
                WHERE u.role = '老師' 
                ORDER BY t.name ASC
            ");
            
            if ($teacher_stmt && $teacher_stmt->execute()) {
                $teacher_result = $teacher_stmt->get_result();
                if ($teacher_result) {
                    $teachers = $teacher_result->fetch_all(MYSQLI_ASSOC);
                }
            }
        } else {
            error_log('enrollment_list.php: user 表格不存在，無法載入老師列表');
            if (empty($error_message)) {
                $error_message = '警告：無法載入老師列表，因為 user 表格不存在。請聯絡系統管理員。';
            }
        }
    }

    $conn->close();
    
} catch (Exception $e) {
    $error_message = '資料庫操作失敗，請稍後再試: ' . $e->getMessage();
    error_log('enrollment_list.php 錯誤: ' . $e->getMessage());
    if (isset($conn)) {
        $conn->close();
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Topics 後台管理系統</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1890ff;
            --text-color: #262626;
            --text-secondary-color: #8c8c8c;
            --border-color: #f0f0f0;
            --background-color: #f0f2f5;
            --card-background-color: #fff;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background: var(--background-color); color: var(--text-color); overflow-x: hidden; }
        .dashboard { display: flex; min-height: 100vh; }
        .main-content {
            /* 防止內部過寬的元素撐開主內容區，影響 header */
            overflow-x: hidden;
        }
        .content { padding: 24px; width: 100%; }

        .page-controls { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; gap: 16px; }
        .breadcrumb { margin-bottom: 0; font-size: 16px; color: var(--text-secondary-color); }
        .breadcrumb a { color: var(--primary-color); text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }
        
        .table-wrapper {
            background: var(--card-background-color);
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
            /* overflow: hidden; */ /* 移除此行以允許內部容器的捲軸顯示 */
        }

        .table-container {
            overflow-x: auto;
        }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 16px 24px; text-align: left; border-bottom: 1px solid var(--border-color); font-size: 16px; white-space: nowrap; }
        .table th { background: #fafafa; font-weight: 600; color: #262626; cursor: pointer; user-select: none; position: relative; }
        .table td {
            color: #595959; /* 與 users.php 統一表格內文顏色 */
        }
        .table th:hover { background: #f0f0f0; }
        .sort-icon { margin-left: 8px; font-size: 12px; color: #8c8c8c; }
        .sort-icon.active { color: #1890ff; }
        .sort-icon.asc::after { content: "↑"; }
        .sort-icon.desc::after { content: "↓"; }
        .table tr:hover { background: #fafafa; }

        .search-input {
            padding: 8px 12px;
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            font-size: 14px;
            width: 250px;
            transition: all 0.3s;
        }
        .search-input:focus {
            outline: none;
            border-color: #1890ff;
            box-shadow: 0 0 0 2px rgba(24,144,255,0.2);
        }

        .empty-state { text-align: center; padding: 40px; color: var(--text-secondary-color); }

        .assign-btn {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.3s;
        }
        .assign-btn:hover {
            background: #40a9ff;
            transform: translateY(-1px);
        }
        .assign-btn i {
            margin-right: 4px;
        }

        /* 彈出視窗樣式 */
        .modal {
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .modal-content {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            width: 90%;
            max-width: 500px;
            max-height: 80vh;
            overflow-y: auto;
        }
        .modal-header {
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h3 {
            margin: 0;
            color: var(--text-color);
        }
        .close {
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            color: var(--text-secondary-color);
        }
        .close:hover {
            color: var(--text-color);
        }
        .modal-body {
            padding: 20px;
        }
        .modal-body p {
            margin-bottom: 16px;
            font-size: 16px;
        }
        .teacher-list h4 {
            margin-bottom: 12px;
            color: var(--text-color);
        }
        .teacher-options {
            max-height: 300px;
            overflow-y: auto;
        }
        .teacher-option {
            display: block;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            margin-bottom: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .teacher-option:hover {
            background-color: #f5f5f5;
            border-color: var(--primary-color);
        }
        .teacher-option input[type="radio"] {
            margin-right: 12px;
        }
        .teacher-info {
            display: inline-block;
            vertical-align: top;
        }
        .teacher-info strong {
            display: block;
            color: var(--text-color);
            margin-bottom: 4px;
        }
        .teacher-dept {
            color: var(--text-secondary-color);
            font-size: 14px;
        }
        .modal-footer {
            padding: 20px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }
        .btn-cancel, .btn-confirm {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
        }
        .btn-cancel {
            background-color: #f5f5f5;
            color: var(--text-color);
        }
        .btn-cancel:hover {
            background-color: #e8e8e8;
        }
        .btn-confirm {
            background-color: var(--primary-color);
            color: white;
        }
        .btn-confirm:hover {
            background-color: #40a9ff;
        }
        
        /* 聯絡紀錄模態視窗樣式 */
        .contact-log-modal {
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .contact-log-modal .modal-content {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            width: 90%;
            max-width: 800px;
            max-height: 80vh;
            overflow-y: auto;
        }
        .contact-log-item {
            background: #f8f9fa;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 16px;
            margin-bottom: 12px;
        }
        .contact-log-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border-color);
        }
        .contact-log-date {
            font-weight: 600;
            color: var(--text-color);
            font-size: 16px;
        }
        .contact-log-method {
            background: var(--primary-color);
            color: white;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        .contact-log-teacher {
            color: var(--text-secondary-color);
            font-size: 14px;
            margin-top: 8px;
        }
        .contact-log-result {
            color: var(--text-color);
            line-height: 1.6;
            margin-top: 12px;
            white-space: pre-wrap;
        }
        .contact-log-notes {
            background: #fff3cd;
            border-left: 3px solid #ffc107;
            padding: 12px;
            margin-top: 12px;
            border-radius: 4px;
            color: #856404;
            font-size: 14px;
            white-space: pre-wrap;
        }
        .contact-log-empty {
            text-align: center;
            padding: 40px;
            color: var(--text-secondary-color);
        }
        .contact-log-loading {
            text-align: center;
            padding: 40px;
            color: var(--text-secondary-color);
        }
        
        /* 新就讀意願提醒樣式 */
        .new-enrollment-alert {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 16px 20px;
            border-radius: 8px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
            animation: slideDown 0.3s ease-out;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .new-enrollment-alert-content {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
        }
        
        .new-enrollment-alert-icon {
            font-size: 24px;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.1);
            }
        }
        
        .new-enrollment-alert-text {
            font-size: 16px;
            font-weight: 500;
        }
        
        .new-enrollment-alert-count {
            background: rgba(255, 255, 255, 0.3);
            padding: 4px 12px;
            border-radius: 12px;
            font-weight: 600;
            margin-left: 8px;
        }
        
        .new-enrollment-alert-close {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            transition: all 0.3s;
        }
        
        .new-enrollment-alert-close:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: rotate(90deg);
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include 'sidebar.php'; ?>
        <div class="main-content" id="mainContent">
            <?php include 'header.php'; ?>
            <div class="content">
                <div class="page-controls">
                    <div class="breadcrumb">
                        <a href="index.php">首頁</a> / <?php echo $page_title; ?>
                    </div>
                    <div class="table-search">
                        <input type="text" id="searchInput" class="search-input" placeholder="搜尋姓名或電話...">
                    </div>
                </div>

                <?php if (!empty($error_message)): ?>
                <div style="background: #fff2f0; border: 1px solid #ffccc7; border-radius: 8px; padding: 16px; margin-bottom: 24px; color: #cf1322;">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
                </div>
                <?php endif; ?>

                <?php if ($new_enrollments_count > 0): ?>
                <div class="new-enrollment-alert" id="newEnrollmentAlert">
                    <div class="new-enrollment-alert-content">
                        <i class="fas fa-bell new-enrollment-alert-icon"></i>
                        <span class="new-enrollment-alert-text">
                            有 <span class="new-enrollment-alert-count"><?php echo $new_enrollments_count; ?></span> 筆新的就讀意願表單已提交！
                        </span>
                    </div>
                    <button class="new-enrollment-alert-close" onclick="closeNewEnrollmentAlert()" title="關閉提醒">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <?php endif; ?>

                <div class="table-wrapper">
                    <div class="table-container">
                        <?php if (empty($enrollments)): ?>
                            <div class="empty-state">
                                <i class="fas fa-inbox fa-3x" style="margin-bottom: 16px;"></i>
                                <p>目前尚無任何就讀意願登錄資料。</p>
                            </div>
                        <?php else: ?>
                            <table class="table" id="enrollmentTable">
                                <thead>
                                    <tr>
                                        <th onclick="sortTable(0)">姓名</th>
                                        <th onclick="sortTable(1)">身分別</th>
                                        <th onclick="sortTable(2)">性別</th>
                                        <th onclick="sortTable(3)">聯絡電話一</th>
                                        <th onclick="sortTable(4)">聯絡電話二</th>
                                        <th onclick="sortTable(5)">Email</th>
                                        <th onclick="sortTable(6)">就讀學校</th>
                                        <th onclick="sortTable(7)">年級</th>
                                        <?php if ($is_imd_user): ?>
                                        <th onclick="sortTable(8)">意願(學制)</th>
                                        <?php else: ?>
                                        <th onclick="sortTable(8)">意願一 (學制)</th>
                                        <th onclick="sortTable(9)">意願二 (學制)</th>
                                        <th onclick="sortTable(10)">意願三 (學制)</th>
                                        <?php endif; ?>
                                        <th onclick="sortTable(11)">Line ID</th>
                                        <th onclick="sortTable(12)">Facebook</th>
                                        <th onclick="sortTable(13)">備註</th>
                                        <th onclick="sortTable(14, 'date')">填寫日期</th>
                                        <?php if ($is_admission_center): ?>
                                        <th onclick="sortTable(15)">分配部門</th>
                                        <th>操作</th>
                                        <?php elseif ($is_department_user): ?>
                                        <th onclick="sortTable(15)">分配狀態</th>
                                        <th>操作</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($enrollments as $item): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['name']); ?></td>
                                        <td><?php echo htmlspecialchars($item['identity']); ?></td>
                                        <td><?php echo htmlspecialchars($item['gender'] ?? '未提供'); ?></td>
                                        <td><?php echo htmlspecialchars($item['phone1']); ?></td>
                                        <td><?php echo htmlspecialchars($item['phone2'] ?? '無'); ?></td>
                                        <td><?php echo htmlspecialchars($item['email']); ?></td>
                                        <td><?php echo htmlspecialchars($item['junior_high']); ?></td>
                                        <td><?php echo htmlspecialchars($item['current_grade']); ?></td>
                                        <?php if ($is_imd_user): ?>
                                        <?php
                                        // 對於IMD用戶，找出資訊管理科是第幾志願
                                        $imd_intention = null;
                                        $imd_priority = null;
                                        
                                        if (!empty($item['intention1']) && (stripos($item['intention1'], '資管') !== false || stripos($item['intention1'], '資訊管理') !== false)) {
                                            $imd_intention = $item['intention1'];
                                            $imd_priority = 1;
                                        } elseif (!empty($item['intention2']) && (stripos($item['intention2'], '資管') !== false || stripos($item['intention2'], '資訊管理') !== false)) {
                                            $imd_intention = $item['intention2'];
                                            $imd_priority = 2;
                                        } elseif (!empty($item['intention3']) && (stripos($item['intention3'], '資管') !== false || stripos($item['intention3'], '資訊管理') !== false)) {
                                            $imd_intention = $item['intention3'];
                                            $imd_priority = 3;
                                        }
                                        
                                        if ($imd_intention) {
                                            $imd_system = '';
                                            if ($imd_priority == 1) {
                                                $imd_system = $item['system1'] ?? '';
                                            } elseif ($imd_priority == 2) {
                                                $imd_system = $item['system2'] ?? '';
                                            } elseif ($imd_priority == 3) {
                                                $imd_system = $item['system3'] ?? '';
                                            }
                                            $display_text = '資訊管理科';
                                            if ($imd_system) {
                                                $display_text .= '(' . htmlspecialchars($imd_system) . ')';
                                            }
                                            // 添加志願順序
                                            $priority_text = '';
                                            if ($imd_priority == 1) {
                                                $priority_text = '第一志願';
                                            } elseif ($imd_priority == 2) {
                                                $priority_text = '第二志願';
                                            } elseif ($imd_priority == 3) {
                                                $priority_text = '第三志願';
                                            }
                                            $display_text .= ' - ' . $priority_text;
                                        } else {
                                            $display_text = '無志願';
                                        }
                                        ?>
                                        <td><?php echo $display_text; ?></td>
                                        <?php else: ?>
                                        <td><?php echo htmlspecialchars($item['intention1'] . ' (' . ($item['system1'] ?? 'N/A') . ')'); ?></td>
                                        <td><?php echo htmlspecialchars($item['intention2'] . ' (' . ($item['system2'] ?? 'N/A') . ')'); ?></td>
                                        <td><?php echo htmlspecialchars($item['intention3'] . ' (' . ($item['system3'] ?? 'N/A') . ')'); ?></td>
                                        <?php endif; ?>
                                        <td><?php echo htmlspecialchars($item['line_id'] ?? '無'); ?></td>
                                        <td><?php echo htmlspecialchars($item['facebook'] ?? '無'); ?></td>
                                        <td><?php echo htmlspecialchars($item['remarks'] ?? '無'); ?></td>
                                        <td><?php echo date('Y/m/d H:i', strtotime($item['created_at'])); ?></td>
                                        <?php if ($is_admission_center): ?>
                                        <td>
                                            <?php if (!empty($item['assigned_department'])): ?>
                                                <span style="color: #52c41a;">
                                                    <i class="fas fa-check-circle"></i> 已分配 - 
                                                    <?php echo htmlspecialchars($item['assigned_department']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="color: #8c8c8c;">
                                                    <i class="fas fa-clock"></i> 未分配
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button class="assign-btn" onclick="openAssignDepartmentModal(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['name']); ?>', '<?php echo htmlspecialchars($item['assigned_department'] ?? ''); ?>')">
                                                <i class="fas fa-building"></i> <?php echo !empty($item['assigned_department']) ? '重新分配' : '分配'; ?>
                                            </button>
                                        </td>
                                        <?php elseif ($is_department_user): ?>
                                        <td>
                                            <?php if (!empty($item['assigned_teacher_id'])): ?>
                                                <span style="color: #52c41a;">
                                                    <i class="fas fa-check-circle"></i> 已分配 - 
                                                    <?php echo htmlspecialchars($item['teacher_name'] ?? $item['teacher_username'] ?? '未知老師'); ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="color: #8c8c8c;">
                                                    <i class="fas fa-clock"></i> 未分配
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                                <button class="assign-btn" onclick="openAssignModal(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['name']); ?>', <?php echo !empty($item['assigned_teacher_id']) ? $item['assigned_teacher_id'] : 'null'; ?>)">
                                                    <i class="fas fa-user-plus"></i> <?php echo !empty($item['assigned_teacher_id']) ? '重新分配' : '分配'; ?>
                                                </button>
                                                <?php if (!empty($item['assigned_teacher_id'])): ?>
                                                <button class="assign-btn" style="background: #17a2b8;" onclick="openContactLogsModal(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['name']); ?>')">
                                                    <i class="fas fa-address-book"></i> 查看聯絡紀錄
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 分配部門彈出視窗（招生中心/行政人員） -->
    <?php if ($is_admission_center): ?>
    <div id="assignDepartmentModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>分配學生至部門</h3>
                <span class="close" onclick="closeAssignDepartmentModal()">&times;</span>
            </div>
            <div class="modal-body">
                <p>學生：<span id="departmentStudentName"></span></p>
                <div class="teacher-list">
                    <h4>選擇部門：</h4>
                    <div class="teacher-options">
                        <label class="teacher-option">
                            <input type="radio" name="department" value="IMD">
                            <div class="teacher-info">
                                <strong>資管科 (IMD)</strong>
                                <span class="teacher-dept">資訊管理科</span>
                            </div>
                        </label>
                        <label class="teacher-option">
                            <input type="radio" name="department" value="FLD">
                            <div class="teacher-info">
                                <strong>應用外語科 (FLD)</strong>
                                <span class="teacher-dept">應用外語科</span>
                            </div>
                        </label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-cancel" onclick="closeAssignDepartmentModal()">取消</button>
                <button class="btn-confirm" onclick="assignDepartment()">確認分配</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 分配學生彈出視窗（部門用戶：IMD或FLD） -->
    <?php if ($is_department_user): ?>
    <div id="assignModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>分配學生</h3>
                <span class="close" onclick="closeAssignModal()">&times;</span>
            </div>
            <div class="modal-body">
                <p>學生：<span id="studentName"></span></p>
                <div class="teacher-list">
                    <h4>選擇老師：</h4>
                    <div class="teacher-options">
                        <?php foreach ($teachers as $teacher): ?>
                        <label class="teacher-option">
                            <input type="radio" name="teacher" value="<?php echo $teacher['id']; ?>">
                            <div class="teacher-info">
                                <strong><?php echo htmlspecialchars($teacher['name'] ?? $teacher['username']); ?></strong>
                                <span class="teacher-dept"><?php echo htmlspecialchars($teacher['department'] ?? '未設定科系'); ?></span>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-cancel" onclick="closeAssignModal()">取消</button>
                <button class="btn-confirm" onclick="assignStudent()">確認分配</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 查看聯絡紀錄模態視窗（部門用戶：IMD或FLD） -->
    <?php if ($is_department_user): ?>
    <div id="contactLogsModal" class="contact-log-modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>聯絡紀錄 - <span id="contactLogStudentName"></span></h3>
                <span class="close" onclick="closeContactLogsModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div id="contactLogsList" class="contact-log-loading">
                    <i class="fas fa-spinner fa-spin"></i> 載入中...
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-cancel" onclick="closeContactLogsModal()">關閉</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        let sortStates = {}; // { colIndex: 'asc' | 'desc' }

        const searchInput = document.getElementById('searchInput');
        const table = document.getElementById('enrollmentTable');
        const rows = table ? table.getElementsByTagName('tbody')[0].getElementsByTagName('tr') : [];

        if (searchInput) {
            searchInput.addEventListener('keyup', function() {
                const filter = searchInput.value.toLowerCase();
                
                for (let i = 0; i < rows.length; i++) {
                    const nameCell = rows[i].getElementsByTagName('td')[0];
                    const phoneCell = rows[i].getElementsByTagName('td')[2];
                    
                    if (nameCell || phoneCell) {
                        const nameText = nameCell.textContent || nameCell.innerText;
                        const phoneText = phoneCell.textContent || phoneCell.innerText;
                        
                        if (nameText.toLowerCase().indexOf(filter) > -1 || phoneText.toLowerCase().indexOf(filter) > -1) {
                            rows[i].style.display = "";
                        } else {
                            rows[i].style.display = "none";
                        }
                    }
                }
            });
        }

        window.sortTable = function(colIndex, type = 'string') {
            const table = document.getElementById('enrollmentTable');
            const tbody = table.getElementsByTagName('tbody')[0];
            const rows = Array.from(tbody.getElementsByTagName('tr'));
            
            const currentOrder = sortStates[colIndex] === 'asc' ? 'desc' : 'asc';
            sortStates = { [colIndex]: currentOrder }; // Reset other column states

            rows.sort((a, b) => {
                const valA = a.getElementsByTagName('td')[colIndex].textContent.trim();
                const valB = b.getElementsByTagName('td')[colIndex].textContent.trim();

                let comparison = 0;
                if (type === 'date') {
                    comparison = new Date(valA) - new Date(valB);
                } else if (!isNaN(valA) && !isNaN(valB)) {
                    comparison = parseFloat(valA) - parseFloat(valB);
                } else {
                    comparison = valA.localeCompare(valB, 'zh-Hant');
                }

                return currentOrder === 'asc' ? comparison : -comparison;
            });

            // Re-append sorted rows
            rows.forEach(row => tbody.appendChild(row));

            // Update sort icons
            updateSortIcons(colIndex, currentOrder);
        };

        function updateSortIcons(activeIndex, order) {
            const headers = document.querySelectorAll('#enrollmentTable th');
            headers.forEach((th, index) => {
                const icon = th.querySelector('.sort-icon');
                if (icon) {
                    if (index === activeIndex) {
                        icon.className = `sort-icon active ${order}`;
                    } else {
                        icon.className = 'sort-icon';
                    }
                }
            });
        }

        // Initial sort by date desc
        function initialSort() {
            const dateColumnIndex = 14;
            sortStates = { [dateColumnIndex]: 'desc' };
            sortTable(dateColumnIndex, 'date'); // Sort once to set desc
            sortTable(dateColumnIndex, 'date'); // Sort again to trigger desc
        }

        if (rows.length > 0) {
            initialSort();
        }
    });

    // 分配學生相關變數
    let currentStudentId = null;

    // 開啟分配學生彈出視窗
    function openAssignModal(studentId, studentName, currentTeacherId) {
        currentStudentId = studentId;
        document.getElementById('studentName').textContent = studentName;
        document.getElementById('assignModal').style.display = 'flex';
        
        // 清除之前的選擇，如果有已分配的老師則預選
        const radioButtons = document.querySelectorAll('input[name="teacher"]');
        radioButtons.forEach(radio => {
            if (currentTeacherId && radio.value == currentTeacherId) {
                radio.checked = true;
            } else {
                radio.checked = false;
            }
        });
    }

    // 關閉分配學生彈出視窗
    function closeAssignModal() {
        document.getElementById('assignModal').style.display = 'none';
        currentStudentId = null;
    }

    // 分配學生
    function assignStudent() {
        const selectedTeacher = document.querySelector('input[name="teacher"]:checked');
        
        if (!selectedTeacher) {
            alert('請選擇一位老師');
            return;
        }

        const teacherId = selectedTeacher.value;
        
        // 發送AJAX請求
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'assign_student.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4) {
                if (xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            alert('學生分配成功！');
                            closeAssignModal();
                            // 可以選擇重新載入頁面或更新UI
                            location.reload();
                        } else {
                            alert('分配失敗：' + (response.message || '未知錯誤'));
                        }
                    } catch (e) {
                        alert('回應格式錯誤：' + xhr.responseText);
                    }
                } else {
                    alert('請求失敗，狀態碼：' + xhr.status);
                }
            }
        };
        
        xhr.send('student_id=' + encodeURIComponent(currentStudentId) + 
                 '&teacher_id=' + encodeURIComponent(teacherId));
    }

    // 點擊彈出視窗外部關閉
    <?php if ($is_department_user): ?>
    document.getElementById('assignModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeAssignModal();
        }
    });
    <?php endif; ?>

    // 分配部門相關變數
    let currentDepartmentStudentId = null;

    // 開啟分配部門彈出視窗
    function openAssignDepartmentModal(studentId, studentName, currentDepartment) {
        currentDepartmentStudentId = studentId;
        document.getElementById('departmentStudentName').textContent = studentName;
        document.getElementById('assignDepartmentModal').style.display = 'flex';
        
        // 清除之前的選擇，如果有已分配的部門則預選
        const radioButtons = document.querySelectorAll('input[name="department"]');
        radioButtons.forEach(radio => {
            if (currentDepartment && radio.value === currentDepartment) {
                radio.checked = true;
            } else {
                radio.checked = false;
            }
        });
    }

    // 關閉分配部門彈出視窗
    function closeAssignDepartmentModal() {
        document.getElementById('assignDepartmentModal').style.display = 'none';
        currentDepartmentStudentId = null;
    }

    // 分配部門
    function assignDepartment() {
        const selectedDepartment = document.querySelector('input[name="department"]:checked');
        
        if (!selectedDepartment) {
            alert('請選擇一個部門');
            return;
        }

        const department = selectedDepartment.value;
        
        // 發送AJAX請求
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'assign_department.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4) {
                if (xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            alert('學生分配成功！');
                            closeAssignDepartmentModal();
                            location.reload();
                        } else {
                            alert('分配失敗：' + (response.message || '未知錯誤'));
                        }
                    } catch (e) {
                        alert('回應格式錯誤：' + xhr.responseText);
                    }
                } else {
                    alert('請求失敗，狀態碼：' + xhr.status);
                }
            }
        };
        
        xhr.send('student_id=' + encodeURIComponent(currentDepartmentStudentId) + 
                 '&department=' + encodeURIComponent(department));
    }

    // 點擊分配部門彈出視窗外部關閉
    <?php if ($is_admission_center): ?>
    document.getElementById('assignDepartmentModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeAssignDepartmentModal();
        }
    });
    <?php endif; ?>

    // 聯絡紀錄相關變數
    let currentContactLogStudentId = null;

    // 開啟聯絡紀錄模態視窗
    function openContactLogsModal(studentId, studentName) {
        currentContactLogStudentId = studentId;
        document.getElementById('contactLogStudentName').textContent = studentName;
        document.getElementById('contactLogsModal').style.display = 'flex';
        loadContactLogs(studentId);
    }

    // 關閉聯絡紀錄模態視窗
    function closeContactLogsModal() {
        document.getElementById('contactLogsModal').style.display = 'none';
        currentContactLogStudentId = null;
    }

    // 載入聯絡紀錄
    async function loadContactLogs(studentId) {
        const logsList = document.getElementById('contactLogsList');
        logsList.innerHTML = '<div class="contact-log-loading"><i class="fas fa-spinner fa-spin"></i> 載入中...</div>';

        try {
            const response = await fetch(`get_contact_logs.php?student_id=${studentId}`);
            const data = await response.json();

            if (data.success && data.logs) {
                if (data.logs.length === 0) {
                    logsList.innerHTML = `
                        <div class="contact-log-empty">
                            <i class="fas fa-inbox fa-3x" style="margin-bottom: 16px; color: #ccc;"></i>
                            <p>目前尚無聯絡紀錄</p>
                        </div>
                    `;
                } else {
                    logsList.innerHTML = data.logs.map(log => `
                        <div class="contact-log-item">
                            <div class="contact-log-header">
                                <div>
                                    <div class="contact-log-date">${formatDate(log.contact_date)}</div>
                                    <div class="contact-log-teacher">聯絡老師：${log.teacher_name || '未知'}</div>
                                </div>
                                <span class="contact-log-method">${log.method}</span>
                            </div>
                            <div class="contact-log-result">
                                <strong>聯絡結果：</strong><br>
                                ${escapeHtml(log.result)}
                            </div>
                            ${log.follow_up_notes ? `
                                <div class="contact-log-notes">
                                    <strong>後續追蹤備註：</strong><br>
                                    ${escapeHtml(log.follow_up_notes)}
                                </div>
                            ` : ''}
                        </div>
                    `).join('');
                }
            } else {
                logsList.innerHTML = `
                    <div class="contact-log-empty">
                        <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px; color: #ff9800;"></i>
                        <p>載入失敗：${data.message || '未知錯誤'}</p>
                    </div>
                `;
            }
        } catch (error) {
            console.error('載入聯絡紀錄錯誤:', error);
            logsList.innerHTML = `
                <div class="contact-log-empty">
                    <i class="fas fa-exclamation-triangle fa-3x" style="margin-bottom: 16px; color: #ff9800;"></i>
                    <p>載入失敗，請稍後再試</p>
                    <p style="font-size: 12px; color: #999; margin-top: 8px;">${error.message}</p>
                </div>
            `;
        }
    }

    // 格式化日期
    function formatDate(dateString) {
        if (!dateString) return '';
        const date = new Date(dateString);
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}年${month}月${day}日`;
    }

    // HTML 轉義函數
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // 點擊聯絡紀錄模態視窗外部關閉
    <?php if ($is_department_user): ?>
    document.getElementById('contactLogsModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeContactLogsModal();
        }
    });
    <?php endif; ?>

    // 關閉新就讀意願提醒
    function closeNewEnrollmentAlert() {
        const alert = document.getElementById('newEnrollmentAlert');
        if (alert) {
            alert.style.animation = 'slideDown 0.3s ease-out reverse';
            setTimeout(() => {
                alert.style.display = 'none';
            }, 300);
        }
    }
    </script>
</body>
</html>