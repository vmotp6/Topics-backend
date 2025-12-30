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
$applications = [];
$error_message = '';
$user_id = $_SESSION['user_id'] ?? 0;
$username = isset($_SESSION['username']) ? $_SESSION['username'] : '';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';

// 權限檢查：允許 ADM、STA 或有權限的 STAM 訪問
$is_admin = ($user_role === 'ADM');
$is_staff = ($user_role === 'STA');
$is_stam = ($user_role === 'STAM');

// 檢查 STAM 是否有國中招生申請名單權限
$stam_has_permission = false;
if ($is_stam && $user_id) {
    try {
        $conn_temp = getDatabaseConnection();
        $perm_stmt = $conn_temp->prepare("SELECT permission_code FROM staff_member_permissions WHERE user_id = ? AND permission_code = 'mobile_junior_B'");
        $perm_stmt->bind_param("i", $user_id);
        $perm_stmt->execute();
        $perm_result = $perm_stmt->get_result();
        if ($perm_result->num_rows > 0) {
            $stam_has_permission = true;
        }
        $perm_stmt->close();
        $conn_temp->close();
    } catch (Exception $e) {
        error_log('Error checking STAM permission: ' . $e->getMessage());
    }
}

// 檢查權限：只有學校行政、管理員或有權限的STAM可以訪問
if (!($is_admin || $is_staff || ($is_stam && $stam_has_permission))) {
    header("Location: index.php");
    exit;
}

// 設置頁面標題
$page_title = '國中招生申請名單';

// 排序參數
$sortBy = $_GET['sort_by'] ?? 'created_at';
$sortOrder = $_GET['sort_order'] ?? 'desc';

// 驗證排序參數，防止 SQL 注入
$allowed_columns = ['id', 'school_code', 'preferred_date', 'expected_students', 'status', 'created_at'];
if (!in_array($sortBy, $allowed_columns)) {
    $sortBy = 'created_at';
}
if (!in_array(strtolower($sortOrder), ['asc', 'desc'])) {
    $sortOrder = 'desc';
}

// 載入學校對應表
$school_data = [];
$identity_options = [];

try {
    // 建立資料庫連接
    $conn = getDatabaseConnection();

    // 載入學校對應表
    $school_result = $conn->query("SELECT school_code, name FROM school_data");
    if ($school_result) {
        while ($row = $school_result->fetch_assoc()) {
            $school_data[$row['school_code']] = $row['name'];
        }
    }

    // 輔助函數：格式化日期
    function formatDate($date) {
        if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
            return '未提供';
        }
        return date('Y/m/d', strtotime($date));
    }

    function formatDateTime($datetime) {
        if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
            return '未提供';
        }
        return date('Y/m/d H:i', strtotime($datetime));
    }

    function getSchoolName($code, $schools) {
        if (isset($schools[$code]) && $schools[$code] !== '') {
            return htmlspecialchars($schools[$code]);
        }
        return $code ? htmlspecialchars($code) : '未提供';
    }

    // 狀態轉換函數
    function getStatusText($status) {
        $status_map = [
            'pending' => '待審核',
            'PE' => '待審核',
            'approved' => '已批准',
            'AP' => '已批准',
            'rejected' => '已拒絕',
            'RE' => '已拒絕',
            'waitlist' => '待處理',
            'AD' => '待處理'
        ];
        return $status_map[$status] ?? $status ?? '待審核';
    }

    function getStatusClass($status) {
        $status = $status ?? 'pending';
        if (in_array($status, ['approved', 'AP'])) {
            return 'status-approved';
        } elseif (in_array($status, ['rejected', 'RE'])) {
            return 'status-rejected';
        } elseif (in_array($status, ['waitlist', 'AD'])) {
            return 'status-waitlist';
        } else {
            return 'status-pending';
        }
    }

    // 查詢 junior_school_recruitment_applications 表
    // 先檢查表是否存在
    $table_check = $conn->query("SHOW TABLES LIKE 'junior_school_recruitment_applications'");
    if ($table_check && $table_check->num_rows > 0) {
        // 查詢所有申請資料，JOIN school_data 獲取學校名稱，JOIN schools_contacts 獲取聯絡人資訊
        $sql = "SELECT 
                    jsra.id,
                    jsra.school_code,
                    jsra.school_address,
                    jsra.contacts_id,
                    jsra.preferred_date,
                    jsra.preferred_time,
                    jsra.expected_students,
                    jsra.venue_type,
                    jsra.special_requirements,
                    jsra.remarks,
                    jsra.status,
                    jsra.admin_comment,
                    jsra.created_at,
                    sd.name as school_name,
                    sc.contact_name,
                    sc.email as contact_email,
                    sc.phone as contact_phone,
                    sc.title as contact_title,
                    v.name as venue_name
                FROM junior_school_recruitment_applications jsra
                LEFT JOIN school_data sd ON jsra.school_code = sd.school_code
                LEFT JOIN schools_contacts sc ON jsra.contacts_id = sc.id
                LEFT JOIN venue v ON jsra.venue_type = v.code
                ORDER BY jsra.$sortBy $sortOrder";

        $result = $conn->query($sql);
        if ($result) {
            $applications = $result->fetch_all(MYSQLI_ASSOC);
        } else {
            $error_message = '查詢失敗: ' . $conn->error;
        }
    } else {
        $error_message = '找不到 junior_school_recruitment_applications 表';
    }

    $conn->close();
    
} catch (Exception $e) {
    $error_message = '資料庫操作失敗，請稍後再試: ' . $e->getMessage();
    error_log('mobile_junior_B.php 錯誤: ' . $e->getMessage());
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
        }

        .table-container {
            overflow-x: auto;
        }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 16px 24px; text-align: left; border-bottom: 1px solid var(--border-color); font-size: 16px; white-space: nowrap; }
        .table th:first-child, .table td:first-child {
            padding-left: 60px;
        }
        .table th { background: #fafafa; font-weight: 600; color: #262626; cursor: pointer; user-select: none; position: relative; }
        .table td {
            color: #595959;
        }
        .table th:hover { background: #f0f0f0; }
        .sort-icon { margin-left: 8px; font-size: 12px; color: #8c8c8c; }
        .sort-icon.active { color: #1890ff; }
        .sort-icon.asc::after { content: "↑"; }
        .sort-icon.desc::after { content: "↓"; }
        .table tr:hover { background: #fafafa; }
        
        /* 分頁樣式 */
        .pagination {
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 1px solid var(--border-color);
            background: #fafafa;
        }

        .pagination-info {
            display: flex;
            align-items: center;
            gap: 16px;
            color: var(--text-secondary-color);
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
        
        .detail-row {
            background: #f9f9f9;
        }
        
        .info-row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 4px;
        }
        .info-label {
            font-weight: 600;
            color: var(--text-secondary-color);
            min-width: 80px;
        }
        .info-value {
            color: var(--text-color);
        }
        
        .table-row-clickable {
            cursor: pointer;
        }

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

        .status-badge { 
            padding: 4px 8px; 
            border-radius: 4px; 
            font-size: 12px; 
            font-weight: 500; 
            border: 1px solid; 
            display: inline-block;
        }
        .status-approved { 
            background: #f6ffed; 
            color: #52c41a; 
            border-color: #b7eb8f; 
        }
        .status-rejected { 
            background: #fff2f0; 
            color: #ff4d4f; 
            border-color: #ffccc7; 
        }
        .status-waitlist { 
            background: #fff7e6; 
            color: #fa8c16; 
            border-color: #ffd591; 
        }
        .status-pending { 
            background: #e6f7ff; 
            color: #1890ff; 
            border-color: #91d5ff; 
        }

        .status-select {
            padding: 4px 8px;
            border: 1px solid #d9d9d9;
            border-radius: 4px;
            font-size: 12px;
            background-color: #fff;
            cursor: pointer;
            margin-right: 8px;
        }
        .status-select:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        .btn-update {
            padding: 4px 12px;
            border: 1px solid #1890ff;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            background: #1890ff;
            color: white;
            transition: all 0.3s;
        }
        .btn-update:hover {
            background: #40a9ff;
            border-color: #40a9ff;
        }
        .btn-update:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .btn-edit {
            padding: 6px 12px;
            border: 1px solid #1890ff;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            background: #1890ff;
            color: white;
            transition: all 0.3s;
        }
        .btn-edit:hover:not(:disabled) {
            background: #40a9ff;
            border-color: #40a9ff;
        }
        .btn-edit:disabled,
        .btn-edit.disabled {
            background: #d9d9d9;
            border-color: #d9d9d9;
            color: #8c8c8c;
            cursor: not-allowed;
            opacity: 0.6;
        }
        .btn-edit i {
            margin-right: 4px;
        }

        /* 按鈕樣式（參考 settings.php） */
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
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        .btn-primary:hover {
            background: #40a9ff;
            border-color: #40a9ff;
        }

        /* 編輯模態視窗樣式 */
        .edit-modal {
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            display: none;
            align-items: center;
            justify-content: center;
        }
        .edit-modal-content {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
        }
        .edit-modal-header {
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: white;
            z-index: 10;
        }
        .edit-modal-header h3 {
            margin: 0;
            color: var(--text-color);
        }
        .edit-modal-body {
            padding: 20px;
        }
        .edit-form-row {
            display: flex;
            gap: 16px;
            margin-bottom: 16px;
        }
        .edit-form-group {
            flex: 1;
        }
        .edit-form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: var(--text-color);
            font-size: 14px;
        }
        .edit-form-group input,
        .edit-form-group select,
        .edit-form-group textarea {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
        }
        .edit-form-group textarea {
            min-height: 80px;
            resize: vertical;
        }
        .edit-form-group input:disabled,
        .edit-form-group select:disabled,
        .edit-form-group textarea:disabled {
            background-color: #f5f5f5;
            cursor: not-allowed;
        }
        .edit-form-group.full-width {
            flex: 1 1 100%;
        }
        .edit-modal-footer {
            padding: 20px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            position: sticky;
            bottom: 0;
            background: white;
            z-index: 10;
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
                        <input type="text" id="searchInput" class="search-input" placeholder="搜尋申請編號或學校名稱...">
                    </div>
                </div>

                <?php if (!empty($error_message)): ?>
                <div style="background: #fff2f0; border: 1px solid #ffccc7; border-radius: 8px; padding: 16px; margin-bottom: 24px; color: #cf1322;">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
                </div>
                <?php endif; ?>

                <div class="table-wrapper">
                    <div class="table-container">
                        <?php if (empty($applications)): ?>
                            <div class="empty-state">
                                <i class="fas fa-inbox fa-3x" style="margin-bottom: 16px;"></i>
                                <p>目前尚無任何報名資料。</p>
                            </div>
                        <?php else: ?>
                            <table class="table" id="applicationsTable">
                                <thead>
                                    <tr>
                                        <th onclick="sortTable('id')">申請編號 <span class="sort-icon" id="sort-id"></span></th>
                                        <th onclick="sortTable('school_code')">學校名稱 <span class="sort-icon" id="sort-school_code"></span></th>
                                        <th>學校地址</th>
                                        <th onclick="sortTable('preferred_date')">期望招生日期 <span class="sort-icon" id="sort-preferred_date"></span></th>
                                        <th>期望時間</th>
                                        <th onclick="sortTable('expected_students')">預期人數 <span class="sort-icon" id="sort-expected_students"></span></th>
                                        <th>場地類型</th>
                                        <th onclick="sortTable('status')">申請狀態 <span class="sort-icon" id="sort-status"></span></th>
                                        <th onclick="sortTable('created_at')">申請時間 <span class="sort-icon" id="sort-created_at"></span></th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($applications as $item): ?>
                                    <tr class="table-row-clickable" onclick="toggleDetail(<?php echo $item['id']; ?>)">
                                        <td><?php echo htmlspecialchars($item['id'] ?? '未提供'); ?></td>
                                        <td><?php echo htmlspecialchars($item['school_name'] ?? $item['school_code'] ?? '未提供'); ?></td>
                                        <td><?php echo htmlspecialchars($item['school_address'] ?? '未提供'); ?></td>
                                        <td><?php echo formatDate($item['preferred_date'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($item['preferred_time'] ?? '未提供'); ?></td>
                                        <td><?php echo htmlspecialchars($item['expected_students'] ?? '未提供'); ?></td>
                                        <td><?php echo htmlspecialchars($item['venue_name'] ?? $item['venue_type'] ?? '未提供'); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo getStatusClass($item['status'] ?? 'pending'); ?>">
                                                <?php echo getStatusText($item['status'] ?? 'pending'); ?>
                                            </span>
                                        </td>
                                        <td><?php echo formatDateTime($item['created_at'] ?? ''); ?></td>
                                        <td onclick="event.stopPropagation();">
                                            <?php 
                                            $current_status = $item['status'] ?? 'pending';
                                            $can_edit = in_array($current_status, ['pending', 'PE', 'waitlist', 'AD']);
                                            $is_disabled = !$can_edit;
                                            ?>
                                            <button class="btn-edit <?php echo $is_disabled ? 'disabled' : ''; ?>" 
                                                    <?php echo $is_disabled ? 'disabled' : ''; ?>
                                                    onclick="<?php echo $is_disabled ? 'return false;' : 'openEditModal(' . htmlspecialchars(json_encode($item), ENT_QUOTES, 'UTF-8') . ')'; ?>" 
                                                    title="<?php echo $is_disabled ? '已批准或已拒絕的申請無法編輯' : '編輯'; ?>">
                                                <i class="fas fa-edit"></i> 編輯
                                            </button>
                                        </td>
                                    </tr>
                                    <tr id="detail-<?php echo $item['id']; ?>" class="detail-row" style="display: none;">
                                        <td colspan="10" style="padding: 20px; background: #f9f9f9; border: 2px solid #b3d9ff; border-radius: 4px;">
                                            <table style="width: 100%; border-collapse: collapse;">
                                                <tr>
                                                    <td style="width: 50%; vertical-align: top; padding-right: 20px;">
                                                        <h4 style="margin: 0 0 10px 0; font-size: 16px;">基本資料</h4>
                                                        <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5; width: 140px;">申請編號</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd;"><?php echo htmlspecialchars($item['id'] ?? '未提供'); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">學校名稱</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd;"><?php echo htmlspecialchars($item['school_name'] ?? $item['school_code'] ?? '未提供'); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">學校地址</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd;"><?php echo htmlspecialchars($item['school_address'] ?? '未提供'); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">聯絡人ID</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd;"><?php echo htmlspecialchars($item['contacts_id'] ?? '未提供'); ?></td>
                                                            </tr>
                                                            <?php if (!empty($item['contact_name'])): ?>
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">聯絡人姓名</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd;"><?php echo htmlspecialchars($item['contact_name']); ?></td>
                                                            </tr>
                                                            <?php endif; ?>
                                                            <?php if (!empty($item['contact_title'])): ?>
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">職稱</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd;"><?php echo htmlspecialchars($item['contact_title']); ?></td>
                                                            </tr>
                                                            <?php endif; ?>
                                                            <?php if (!empty($item['contact_email'])): ?>
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">聯絡人Email</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd;"><?php echo htmlspecialchars($item['contact_email']); ?></td>
                                                            </tr>
                                                            <?php endif; ?>
                                                            <?php if (!empty($item['contact_phone'])): ?>
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">聯絡人電話</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd;"><?php echo htmlspecialchars($item['contact_phone']); ?></td>
                                                            </tr>
                                                            <?php endif; ?>
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">期望招生日期</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd;"><?php echo formatDate($item['preferred_date'] ?? ''); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">期望時間</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd;"><?php echo htmlspecialchars($item['preferred_time'] ?? '未提供'); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">預期參與學生人數</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd;"><?php echo htmlspecialchars($item['expected_students'] ?? '未提供'); ?></td>
                                                            </tr>
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">場地類型</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd;"><?php echo htmlspecialchars($item['venue_name'] ?? $item['venue_type'] ?? '未提供'); ?></td>
                                                            </tr>
                                                        </table>
                                                    </td>
                                                    <td style="width: 50%; vertical-align: top; padding-left: 20px;">
                                                        <h4 style="margin: 0 0 10px 0; font-size: 16px;">其他資訊</h4>
                                                        <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5; width: 140px;">申請狀態</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd;">
                                                                    <span class="status-badge <?php echo getStatusClass($item['status'] ?? 'pending'); ?>">
                                                                        <?php echo getStatusText($item['status'] ?? 'pending'); ?>
                                                                    </span>
                                                                </td>
                                                            </tr>
                                                            <?php if (!empty($item['special_requirements'])): ?>
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">特殊需求</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd; white-space: pre-wrap;"><?php echo htmlspecialchars($item['special_requirements']); ?></td>
                                                            </tr>
                                                            <?php endif; ?>
                                                            <?php if (!empty($item['remarks'])): ?>
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">備註</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd; white-space: pre-wrap;"><?php echo htmlspecialchars($item['remarks']); ?></td>
                                                            </tr>
                                                            <?php endif; ?>
                                                            <?php if (!empty($item['admin_comment'])): ?>
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">管理員備註</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd; white-space: pre-wrap; background: #fff3cd;"><?php echo htmlspecialchars($item['admin_comment']); ?></td>
                                                            </tr>
                                                            <?php endif; ?>
                                                            <tr>
                                                                <td style="padding: 5px; border: 1px solid #ddd; background: #f5f5f5;">申請時間</td>
                                                                <td style="padding: 5px; border: 1px solid #ddd;"><?php echo formatDateTime($item['created_at'] ?? ''); ?></td>
                                                            </tr>
                                                        </table>
                                                    </td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                    <!-- 分頁控制 -->
                    <?php if (!empty($applications)): ?>
                    <div class="pagination">
                        <div class="pagination-info">
                            <span>每頁顯示：</span>
                            <select id="itemsPerPage" onchange="changeItemsPerPage()">
                                <option value="10" selected>10</option>
                                <option value="20">20</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                                <option value="all">全部</option>
                            </select>
                            <span id="pageInfo">顯示第 <span id="currentRange">1-<?php echo min(10, count($applications)); ?></span> 筆，共 <?php echo count($applications); ?> 筆</span>
                        </div>
                        <div class="pagination-controls">
                            <button id="prevPage" onclick="changePage(-1)" disabled>上一頁</button>
                            <span id="pageNumbers"></span>
                            <button id="nextPage" onclick="changePage(1)">下一頁</button>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 訊息提示框 -->
    <div id="toast" style="position: fixed; top: 20px; right: 20px; background-color: #333; color: white; padding: 15px 20px; border-radius: 8px; z-index: 9999; display: none; opacity: 0; transition: opacity 0.5s;"></div>

    <!-- 編輯模態視窗 -->
    <div id="editModal" class="edit-modal">
        <div class="edit-modal-content">
            <div class="edit-modal-header">
                <h3>編輯申請資料</h3>
                <span class="close" onclick="closeEditModal()" style="font-size: 24px; font-weight: bold; cursor: pointer; color: var(--text-secondary-color);">&times;</span>
            </div>
            <div class="edit-modal-body">
                <form id="editForm">
                    <input type="hidden" id="edit_id" name="id">
                    
                    <div class="edit-form-row">
                        <div class="edit-form-group">
                            <label>申請編號</label>
                            <input type="text" id="edit_id_display" disabled>
                        </div>
                        <div class="edit-form-group">
                            <label>學校名稱</label>
                            <input type="text" id="edit_school_name" disabled>
                        </div>
                    </div>

                    <div class="edit-form-row">
                        <div class="edit-form-group full-width">
                            <label>學校地址</label>
                            <input type="text" id="edit_school_address" disabled>
                        </div>
                    </div>

                    <div class="edit-form-row">
                        <div class="edit-form-group">
                            <label>聯絡人ID</label>
                            <input type="text" id="edit_contacts_id" disabled>
                        </div>
                        <div class="edit-form-group">
                            <label>期望招生日期</label>
                            <input type="date" id="edit_preferred_date" disabled>
                        </div>
                    </div>

                    <!-- 聯絡人資訊 -->
                    <div class="edit-form-row">
                        <div class="edit-form-group">
                            <label>聯絡人姓名</label>
                            <input type="text" id="edit_contact_name" disabled>
                        </div>
                        <div class="edit-form-group">
                            <label>職稱</label>
                            <input type="text" id="edit_contact_title" disabled>
                        </div>
                    </div>

                    <div class="edit-form-row">
                        <div class="edit-form-group">
                            <label>聯絡人Email</label>
                            <input type="email" id="edit_contact_email" disabled>
                        </div>
                        <div class="edit-form-group">
                            <label>聯絡人電話</label>
                            <input type="text" id="edit_contact_phone" disabled>
                        </div>
                    </div>

                    <div class="edit-form-row">
                        <div class="edit-form-group">
                            <label>期望時間</label>
                            <input type="text" id="edit_preferred_time" name="preferred_time" placeholder="例如：上午、下午、全天">
                        </div>
                        <div class="edit-form-group">
                            <label>預期參與學生人數</label>
                            <input type="number" id="edit_expected_students" name="expected_students" min="0">
                        </div>
                    </div>

                    <div class="edit-form-row">
                        <div class="edit-form-group">
                            <label>場地類型</label>
                            <input type="text" id="edit_venue_type" name="venue_type">
                        </div>
                        <div class="edit-form-group">
                            <label>申請狀態 <span style="color: #ff4d4f;">*</span></label>
                            <select id="edit_status" name="status">
                                <option value="pending">待審核</option>
                                <option value="approved">已批准</option>
                                <option value="rejected">已拒絕</option>
                                <option value="waitlist">待處理</option>
                            </select>
                            <small id="status_hint" style="display: none; color: #ff4d4f; font-size: 12px; margin-top: 4px; display: block;">已批准的申請不能再修改狀態</small>
                        </div>
                    </div>

                    <div class="edit-form-row">
                        <div class="edit-form-group full-width">
                            <label>特殊需求</label>
                            <textarea id="edit_special_requirements" name="special_requirements"></textarea>
                        </div>
                    </div>

                    <div class="edit-form-row">
                        <div class="edit-form-group full-width">
                            <label>備註</label>
                            <textarea id="edit_remarks" name="remarks"></textarea>
                        </div>
                    </div>

                    <div class="edit-form-row">
                        <div class="edit-form-group full-width">
                            <label>管理員備註</label>
                            <textarea id="edit_admin_comment" name="admin_comment" placeholder="請輸入管理員備註..."></textarea>
                        </div>
                    </div>

                    <div class="edit-form-row">
                        <div class="edit-form-group">
                            <label>申請時間</label>
                            <input type="text" id="edit_created_at" disabled>
                        </div>
                    </div>
                </form>
            </div>
            <div class="edit-modal-footer">
                <button type="button" class="btn" onclick="closeEditModal()">取消</button>
                <button type="button" class="btn btn-primary" onclick="saveEdit()">儲存</button>
            </div>
        </div>
    </div>

    <script>
    // 分頁相關變數
    let currentPage = 1;
    let itemsPerPage = 10;
    let allRows = [];
    let filteredRows = [];

    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('searchInput');
        const table = document.getElementById('applicationsTable');
        
        if (table) {
            const tbody = table.getElementsByTagName('tbody')[0];
            if (tbody) {
                // 只獲取真正的數據行，排除 detail-row 和其他非數據行
                const allTableRows = Array.from(tbody.getElementsByTagName('tr'));
                allRows = allTableRows.filter(row => {
                    // 排除 detail-row
                    if (row.classList.contains('detail-row')) {
                        return false;
                    }
                    // 確保是數據行（有 td 元素，且不是空行）
                    const cells = row.getElementsByTagName('td');
                    if (cells.length === 0) {
                        return false;
                    }
                    // 確保是主數據行（必須有 table-row-clickable 類，這是主數據行的標記）
                    // 或者至少有10個td（對應表格的10個欄位）
                    const isDataRow = row.classList.contains('table-row-clickable') || cells.length >= 10;
                    return isDataRow;
                });
                // 確保 filteredRows 和 allRows 一致（allRows 已經過濾過了）
                filteredRows = [...allRows];
            }
        }

        if (searchInput) {
            searchInput.addEventListener('keyup', function() {
                const filter = searchInput.value.toLowerCase();
                
                if (!table) return;
                
                const tbody = table.getElementsByTagName('tbody')[0];
                if (!tbody) return;
                
                // 確保過濾時不包含 detail-row
                filteredRows = allRows.filter(row => {
                    // 排除 detail-row
                    if (row.classList.contains('detail-row')) {
                        return false;
                    }
                    const cells = row.getElementsByTagName('td');
                    for (let j = 0; j < cells.length; j++) {
                        const cellText = cells[j].textContent || cells[j].innerText;
                        if (cellText.toLowerCase().indexOf(filter) > -1) {
                            return true;
                        }
                    }
                    return false;
                });
                
                currentPage = 1;
                updatePagination();
            });
        }

        // 排序表格
        function sortTable(field) {
            let newSortOrder = 'asc';
            
            // 如果點擊的是當前排序欄位，則切換排序方向
            const urlParams = new URLSearchParams(window.location.search);
            const currentSortBy = urlParams.get('sort_by') || 'created_at';
            const currentSortOrder = urlParams.get('sort_order') || 'desc';
            
            if (currentSortBy === field) {
                newSortOrder = currentSortOrder === 'asc' ? 'desc' : 'asc';
            }
            
            window.location.href = `mobile_junior_B.php?sort_by=${field}&sort_order=${newSortOrder}`;
        }
        
        // 更新排序圖標
        function updateSortIcons() {
            // 清除所有圖標
            const icons = document.querySelectorAll('.sort-icon');
            icons.forEach(icon => {
                icon.className = 'sort-icon';
            });
            
            // 獲取當前 URL 的排序參數
            const urlParams = new URLSearchParams(window.location.search);
            const currentSortBy = urlParams.get('sort_by') || 'created_at';
            const currentSortOrder = urlParams.get('sort_order') || 'desc';
            
            // 設置當前排序欄位的圖標
            const currentIcon = document.getElementById(`sort-${currentSortBy}`);
            if (currentIcon) {
                currentIcon.className = `sort-icon active ${currentSortOrder}`;
            }
        }
        
        // 展開/收合詳細資訊
        function toggleDetail(id) {
            const detailRow = document.getElementById('detail-' + id);
            if (detailRow) {
                if (detailRow.style.display === 'none' || detailRow.style.display === '') {
                    detailRow.style.display = 'table-row';
                } else {
                    detailRow.style.display = 'none';
                }
            }
        }
        
        // 將函數暴露到全局作用域
        window.sortTable = sortTable;
        window.toggleDetail = toggleDetail;
        
        // 更新排序圖標
        updateSortIcons();
        
        // 初始化分頁
        initPagination();
    });

    // 開啟編輯模態視窗
    function openEditModal(item) {
        const modal = document.getElementById('editModal');
        const statusSelect = document.getElementById('edit_status');
        
        // 判斷當前狀態：只有待審核(pending/PE)和待處理(waitlist/AD)可以修改
        const currentStatus = item.status || 'pending';
        const canModifyStatus = currentStatus === 'pending' || currentStatus === 'PE' || 
                                currentStatus === 'waitlist' || currentStatus === 'AD';
        const isApproved = currentStatus === 'approved' || currentStatus === 'AP';
        const isRejected = currentStatus === 'rejected' || currentStatus === 'RE';
        
        // 填充表單資料
        document.getElementById('edit_id').value = item.id;
        document.getElementById('edit_id_display').value = item.id;
        document.getElementById('edit_school_name').value = item.school_name || item.school_code || '';
        document.getElementById('edit_school_address').value = item.school_address || '';
        document.getElementById('edit_contacts_id').value = item.contacts_id || '';
        document.getElementById('edit_preferred_date').value = item.preferred_date ? item.preferred_date.split(' ')[0] : '';
        document.getElementById('edit_preferred_time').value = item.preferred_time || '';
        document.getElementById('edit_expected_students').value = item.expected_students || '';
        document.getElementById('edit_venue_type').value = item.venue_type || '';
        document.getElementById('edit_special_requirements').value = item.special_requirements || '';
        document.getElementById('edit_remarks').value = item.remarks || '';
        document.getElementById('edit_admin_comment').value = item.admin_comment || '';
        document.getElementById('edit_created_at').value = item.created_at ? new Date(item.created_at).toLocaleString('zh-TW') : '';
        
        // 填充聯絡人資訊
        document.getElementById('edit_contact_name').value = item.contact_name || '';
        document.getElementById('edit_contact_title').value = item.contact_title || '';
        document.getElementById('edit_contact_email').value = item.contact_email || '';
        document.getElementById('edit_contact_phone').value = item.contact_phone || '';
        
        // 設置狀態
        let statusValue = 'pending';
        if (isApproved) {
            statusValue = 'approved';
        } else if (isRejected) {
            statusValue = 'rejected';
        } else if (currentStatus === 'waitlist' || currentStatus === 'AD') {
            statusValue = 'waitlist';
        }
        statusSelect.value = statusValue;
        // 保存原始狀態以便驗證
        statusSelect.setAttribute('data-original-status', currentStatus);
        
        // 如果已批准或已拒絕，禁用狀態選擇
        const statusHint = document.getElementById('status_hint');
        if (!canModifyStatus) {
            statusSelect.disabled = true;
            statusSelect.style.backgroundColor = '#f5f5f5';
            statusSelect.style.cursor = 'not-allowed';
            if (statusHint) {
                if (isApproved) {
                    statusHint.textContent = '已批准的申請不能再修改狀態';
                } else if (isRejected) {
                    statusHint.textContent = '已拒絕的申請不能再修改狀態';
                }
                statusHint.style.display = 'block';
            }
        } else {
            statusSelect.disabled = false;
            statusSelect.style.backgroundColor = '#fff';
            statusSelect.style.cursor = 'pointer';
            if (statusHint) {
                statusHint.style.display = 'none';
            }
        }
        
        modal.style.display = 'flex';
    }

    // 關閉編輯模態視窗
    function closeEditModal() {
        document.getElementById('editModal').style.display = 'none';
    }

    // 儲存編輯
    function saveEdit() {
        const statusSelect = document.getElementById('edit_status');
        const applicationId = document.getElementById('edit_id').value;
        const currentStatus = statusSelect.getAttribute('data-original-status');
        
        // 檢查是否可以修改狀態：只有待審核和待處理可以修改
        const canModifyStatus = currentStatus === 'pending' || currentStatus === 'PE' || 
                                currentStatus === 'waitlist' || currentStatus === 'AD';
        const isApproved = currentStatus === 'approved' || currentStatus === 'AP';
        const isRejected = currentStatus === 'rejected' || currentStatus === 'RE';
        
        // 如果已批准或已拒絕，保持原狀態不變
        let finalStatus = statusSelect.value;
        if (!canModifyStatus) {
            // 如果已批准或已拒絕，保持原狀態，不自動變為待處理
            if (isApproved) {
                finalStatus = 'approved';
            } else if (isRejected) {
                finalStatus = 'rejected';
            }
        }
        
        const formData = {
            id: applicationId,
            status: finalStatus,
            admin_comment: document.getElementById('edit_admin_comment').value,
            preferred_date: document.getElementById('edit_preferred_date').value,
            preferred_time: document.getElementById('edit_preferred_time').value,
            expected_students: document.getElementById('edit_expected_students').value,
            venue_type: document.getElementById('edit_venue_type').value,
            special_requirements: document.getElementById('edit_special_requirements').value,
            remarks: document.getElementById('edit_remarks').value
        };

        // 發送更新請求
        fetch('update_junior_recruitment_status.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(formData)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('更新成功', 'success');
                setTimeout(() => {
                    location.reload();
                }, 1000);
            } else {
                showToast('更新失敗：' + (data.message || '未知錯誤'), 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('更新失敗，請稍後再試', 'error');
        });
    }

    // 點擊模態視窗外部關閉
    document.getElementById('editModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeEditModal();
        }
    });

    // 顯示提示訊息
    function showToast(message, type) {
        const toast = document.getElementById('toast');
        if (!toast) {
            // 如果沒有 toast 元素，創建一個
            const toastDiv = document.createElement('div');
            toastDiv.id = 'toast';
            toastDiv.style.cssText = 'position: fixed; top: 20px; right: 20px; background-color: #333; color: white; padding: 15px 20px; border-radius: 8px; z-index: 9999; display: block; opacity: 0; transition: opacity 0.5s;';
            document.body.appendChild(toastDiv);
        }
        const toastElement = document.getElementById('toast');
        toastElement.textContent = message;
        if (type === 'success') {
            toastElement.style.backgroundColor = '#52c41a';
        } else if (type === 'info') {
            toastElement.style.backgroundColor = '#1890ff';
        } else {
            toastElement.style.backgroundColor = '#ff4d4f';
        }
        toastElement.style.opacity = '1';
        toastElement.style.display = 'block';
        
        setTimeout(() => {
            toastElement.style.opacity = '0';
            setTimeout(() => {
                toastElement.style.display = 'none';
            }, 500);
        }, 3000);
    }

    // 分頁功能
    function initPagination() {
        // 重新從 allRows 計算 filteredRows，確保不包含 detail-row 和空行
        filteredRows = allRows.filter(row => {
            // 排除 detail-row
            if (row.classList.contains('detail-row')) {
                return false;
            }
            // 確保是數據行（有 td 元素，且不是空行）
            const cells = row.getElementsByTagName('td');
            if (cells.length === 0) {
                return false;
            }
            // 確保是主數據行（有 table-row-clickable 類或至少有10個td）
            return row.classList.contains('table-row-clickable') || cells.length >= 10;
        });
        
        // 確保 itemsPerPage 從 select 元素獲取正確的值
        const select = document.getElementById('itemsPerPage');
        if (select) {
            if (select.value === 'all') {
                itemsPerPage = filteredRows.length;
            } else {
                itemsPerPage = parseInt(select.value) || 10;
            }
        }
        currentPage = 1;
        updatePagination();
    }

    function changeItemsPerPage() {
        const select = document.getElementById('itemsPerPage');
        // 先重新計算 filteredRows，確保數據正確
        filteredRows = allRows.filter(row => {
            if (row.classList.contains('detail-row')) {
                return false;
            }
            const cells = row.getElementsByTagName('td');
            if (cells.length === 0) {
                return false;
            }
            return row.classList.contains('table-row-clickable') || cells.length >= 10;
        });
        
        itemsPerPage = select.value === 'all' ? filteredRows.length : parseInt(select.value);
        currentPage = 1;
        updatePagination();
    }

    function changePage(direction) {
        const totalItems = filteredRows.length;
        // 確保 itemsPerPage 是數字
        const itemsPerPageNum = typeof itemsPerPage === 'number' ? itemsPerPage : parseInt(itemsPerPage) || 10;
        // 只有當資料筆數大於每頁顯示筆數時，才需要多頁
        // 例如：10筆/頁，11筆資料才會有第2頁
        const totalPages = totalItems > itemsPerPageNum ? Math.ceil(totalItems / itemsPerPageNum) : 1;
        
        currentPage += direction;
        
        if (currentPage < 1) currentPage = 1;
        if (currentPage > totalPages) currentPage = totalPages;
        
        updatePagination();
    }

    function updatePagination() {
        // 重新從 allRows 計算 filteredRows，確保不包含 detail-row 和空行
        filteredRows = allRows.filter(row => {
            // 排除 detail-row
            if (row.classList.contains('detail-row')) {
                return false;
            }
            // 確保是數據行（有 td 元素，且不是空行）
            const cells = row.getElementsByTagName('td');
            if (cells.length === 0) {
                return false;
            }
            // 確保是主數據行（有 table-row-clickable 類或至少有10個td）
            const isDataRow = row.classList.contains('table-row-clickable') || cells.length >= 10;
            return isDataRow;
        });
        
        const totalItems = filteredRows.length;
        
        // 確保 itemsPerPage 是數字，並從 select 元素獲取最新值
        const select = document.getElementById('itemsPerPage');
        let itemsPerPageNum = 10;
        if (select) {
            if (select.value === 'all') {
                itemsPerPageNum = totalItems;
            } else {
                itemsPerPageNum = parseInt(select.value) || 10;
            }
        } else {
            itemsPerPageNum = typeof itemsPerPage === 'number' ? itemsPerPage : parseInt(itemsPerPage) || 10;
        }
        
        // 只有當資料筆數大於每頁顯示筆數時，才需要多頁
        // 例如：10筆/頁，11筆資料才會有第2頁
        // 如果 totalItems <= itemsPerPageNum，則只有1頁
        const totalPages = totalItems > itemsPerPageNum ? Math.ceil(totalItems / itemsPerPageNum) : 1;
        
        // 確保當前頁不超過總頁數
        if (currentPage > totalPages && totalPages > 0) {
            currentPage = totalPages;
        }
        if (currentPage < 1) {
            currentPage = 1;
        }
        
        // 更新顯示範圍 - 修正計算邏輯
        let start, end;
        if (totalItems === 0) {
            start = 0;
            end = 0;
        } else {
            // 無論如何，end 都不能超過 totalItems
            if (totalPages <= 1 || itemsPerPageNum >= totalItems) {
                // 只有一頁或選擇全部，顯示所有數據
                start = 1;
                end = totalItems; // 確保 end 等於 totalItems，不會超過
            } else {
                // 多頁情況
                start = (currentPage - 1) * itemsPerPageNum + 1;
                end = Math.min(currentPage * itemsPerPageNum, totalItems);
            }
            // 最終確保 end 不會超過 totalItems
            end = Math.min(end, totalItems);
        }
        
        const currentRangeEl = document.getElementById('currentRange');
        if (currentRangeEl) {
            currentRangeEl.textContent = `${start}-${end}`;
        }
        
        // 更新按鈕狀態
        const prevBtn = document.getElementById('prevPage');
        const nextBtn = document.getElementById('nextPage');
        if (prevBtn) prevBtn.disabled = currentPage === 1;
        if (nextBtn) nextBtn.disabled = currentPage >= totalPages || totalPages <= 1;
        
        // 更新頁碼 - 先清空所有按鈕
        const pageNumbers = document.getElementById('pageNumbers');
        if (pageNumbers) {
            pageNumbers.innerHTML = '';
            
            // 總是顯示頁碼按鈕（即使只有1頁）
            if (totalPages >= 1) {
                // 如果只有1頁，只顯示"1"
                // 如果有多頁，顯示所有頁碼
                const pagesToShow = totalPages === 1 ? [1] : Array.from({length: totalPages}, (_, i) => i + 1);
                
                for (let i of pagesToShow) {
                    const button = document.createElement('button');
                    button.textContent = i;
                    button.className = i === currentPage ? 'active' : '';
                    button.onclick = () => {
                        currentPage = i;
                        updatePagination();
                    };
                    pageNumbers.appendChild(button);
                }
            }
        }
        
        // 顯示/隱藏行（無論是否有分頁都要執行）
        allRows.forEach((row, index) => {
            const detailRow = row.nextElementSibling;
            if (filteredRows.includes(row)) {
                const rowIndex = filteredRows.indexOf(row);
                // 如果只有一頁或選擇全部，顯示所有行
                const shouldShow = (totalPages <= 1 || itemsPerPageNum >= totalItems) 
                    ? true 
                    : (rowIndex >= (currentPage - 1) * itemsPerPageNum && rowIndex < currentPage * itemsPerPageNum);
                row.style.display = shouldShow ? '' : 'none';
                if (detailRow && detailRow.classList.contains('detail-row')) {
                    detailRow.style.display = 'none';
                }
            } else {
                row.style.display = 'none';
                if (detailRow && detailRow.classList.contains('detail-row')) {
                    detailRow.style.display = 'none';
                }
            }
        });
    }
    </script>
</body>
</html>
