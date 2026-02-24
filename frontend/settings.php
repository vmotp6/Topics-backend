<?php
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/includes/department_session_access.php';

checkBackendLogin();

// 引入資料庫設定
require_once '../../Topics-frontend/frontend/config.php';

// 設置頁面標題
$page_title = '場次設定';

// 建立資料庫連接
$conn = getDatabaseConnection();

$normalized_role = normalizeBackendRole($_SESSION['role'] ?? '');
$is_super_user = isSuperUserRole($normalized_role);
$current_user_id = getOrFetchCurrentUserId($conn);
$user_department_code = null;
$is_department_director = false;

if (!$is_super_user && isDepartmentDirectorRole($normalized_role) && $current_user_id) {
    $user_department_code = getCurrentUserDepartmentCode($conn, $current_user_id);
    if (!empty($user_department_code)) {
        $is_department_director = true;
    }
}

$message = "";
$messageType = "";

// 處理表單提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            // 場次管理
            case 'add_session':
                if (!$current_user_id) {
                    throw new Exception("無法取得使用者資訊，請重新登入後再試。");
                }
                $sql = "INSERT INTO admission_sessions(session_name, description, session_date, session_end_date,session_type, session_link, session_location,
                        max_participants, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                // session_type: 1=線上, 2=實體
                $session_type = ($_POST['session_type'] === '線上' || $_POST['session_type'] === '1') ? 1 : 2;
                // 處理日期格式：從 datetime-local 轉換為 datetime
                $session_date = date('Y-m-d H:i:s', strtotime($_POST['session_date']));
                // 處理結束時間
                $session_end_date = !empty($_POST['session_end_date']) ? date('Y-m-d H:i:s', strtotime($_POST['session_end_date'])) : null;
                // 處理線上連結和實體地點
                $session_link = ($session_type == 1 && !empty($_POST['session_link'])) ? trim($_POST['session_link']) : null;
                $session_location = ($session_type == 2 && !empty($_POST['session_location'])) ? trim($_POST['session_location']) : null;
                // 處理 max_participants：如果為空則設為 null
                $max_participants = !empty($_POST['max_participants']) ? intval($_POST['max_participants']) : null;
                // 處理說明
                $description = !empty($_POST['description']) ? $_POST['description'] : null;
                // 記錄創建者
                $created_by = $current_user_id;
                // bind_param: session_name(s), session_date(s), session_end_date(s), session_type(i), session_link(s), session_location(s), max_participants(i), description(s), created_by(i)
                $session_name = $_POST['session_name'];
                $is_active = 1; // 新增時預設啟用

                $stmt->bind_param(
                    "sssisssiii", 
                    $session_name, 
                    $description,
                    $session_date, 
                    $session_end_date, 
                    $session_type, 
                    $session_link, 
                    $session_location, 
                    $max_participants, 
                    $is_active, 
                    $created_by
                );                
                if ($stmt->execute()) {
                    $message = "場次新增成功！"; $messageType = "success";
                } else {
                    $message = "場次新增失敗：" . $stmt->error; $messageType = "error";
                }
                $stmt->close();
                break;

            case 'update_session':
                if ($is_department_director) {
                    $session_id_check = intval($_POST['session_id'] ?? 0);
                    $owner_stmt = $conn->prepare("SELECT created_by FROM admission_sessions WHERE id = ? LIMIT 1");
                    if (!$owner_stmt) {
                        throw new Exception("權限檢查失敗，請稍後再試。");
                    }
                    $owner_stmt->bind_param("i", $session_id_check);
                    $owner_stmt->execute();
                    $owner_row = $owner_stmt->get_result()->fetch_assoc();
                    $owner_stmt->close();
                    $created_by_owner = isset($owner_row['created_by']) ? intval($owner_row['created_by']) : 0;
                    if ($created_by_owner !== intval($current_user_id)) {
                        throw new Exception("權限不足：只有您自己新增的場次可以編輯。");
                    }
                }
                $sql = "UPDATE admission_sessions SET session_name = ?, session_date = ?, session_end_date = ?, session_type = ?, session_link = ?, session_location = ?, max_participants = ?, description = ?, is_active = ? WHERE id = ?";
                $stmt = $conn->prepare($sql);
                // session_type: 1=線上, 2=實體
                $session_type = ($_POST['session_type'] === '線上' || $_POST['session_type'] === '1') ? 1 : 2;
                $is_active = intval($_POST['is_active']);
                $session_id = intval($_POST['session_id']);
                // 處理日期格式：從 datetime-local 轉換為 datetime
                $session_date = date('Y-m-d H:i:s', strtotime($_POST['session_date']));
                // 處理結束時間
                $session_end_date = !empty($_POST['session_end_date']) ? date('Y-m-d H:i:s', strtotime($_POST['session_end_date'])) : null;
                // 處理線上連結和實體地點
                $session_link = ($session_type == 1 && !empty($_POST['session_link'])) ? trim($_POST['session_link']) : null;
                $session_location = ($session_type == 2 && !empty($_POST['session_location'])) ? trim($_POST['session_location']) : null;
                // 處理 max_participants：如果為空則設為 null
                $max_participants = !empty($_POST['max_participants']) ? intval($_POST['max_participants']) : null;
                // 處理說明
                $description = !empty($_POST['description']) ? $_POST['description'] : null;
                // bind_param: 共10个参数
                // session_name(s), session_date(s), session_end_date(s), session_type(i), 
                // session_link(s), session_location(s), max_participants(i), description(s), is_active(i), session_id(i)
                $session_name = $_POST['session_name'];
                // 类型字符串：sssississii (10个字符对应10个参数)
                // 参数顺序：session_name(s), session_date(s), session_end_date(s), session_type(i), 
                //          session_link(s), session_location(s), max_participants(i), description(s), is_active(i), session_id(i)
                // 类型字符串应该是10个字符对应10个参数
                // s-s-s-i-s-s-i-s-s-i-i (11个字符，错误！)
                // 应该是：s-s-s-i-s-s-i-s-s-i (10个字符)
                $stmt->bind_param("sssississi", $session_name, $session_date, $session_end_date, $session_type, $session_link, $session_location, $max_participants, $description, $is_active, $session_id);
                if ($stmt->execute()) {
                    $message = "場次更新成功！"; $messageType = "success";
                } else {
                    $message = "場次更新失敗：" . $stmt->error; $messageType = "error";
                }
                $stmt->close();
                break;

            case 'delete_session':
                if ($is_department_director) {
                    $session_id_check = intval($_POST['session_id'] ?? 0);
                    $owner_stmt = $conn->prepare("SELECT created_by FROM admission_sessions WHERE id = ? LIMIT 1");
                    if (!$owner_stmt) {
                        throw new Exception("權限檢查失敗，請稍後再試。");
                    }
                    $owner_stmt->bind_param("i", $session_id_check);
                    $owner_stmt->execute();
                    $owner_row = $owner_stmt->get_result()->fetch_assoc();
                    $owner_stmt->close();
                    $created_by_owner = isset($owner_row['created_by']) ? intval($owner_row['created_by']) : 0;
                    if ($created_by_owner !== intval($current_user_id)) {
                        throw new Exception("權限不足：只有您自己新增的場次可以刪除。");
                    }
                }
                $sql = "DELETE FROM admission_sessions WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $_POST['session_id']);
                if ($stmt->execute()) {
                    $message = "場次刪除成功！"; $messageType = "success";
                } else {
                    $message = "場次刪除失敗：" . $stmt->error; $messageType = "error";
                }
                $stmt->close();
                break;

        }
    } catch (Exception $e) {
        $message = "操作失敗：" . $e->getMessage();
        $messageType = "error";
    }
}

// 排序參數
$sortBy = $_GET['sort_by'] ?? 'session_date';
$sortOrder = $_GET['sort_order'] ?? 'desc';

// 驗證排序參數，防止 SQL 注入
$allowed_columns = ['id', 'session_name', 'session_date', 'session_type', 'max_participants', 'is_active'];
if (!in_array($sortBy, $allowed_columns)) {
    $sortBy = 'session_date';
}
if (!in_array(strtolower($sortOrder), ['asc', 'desc'])) {
    $sortOrder = 'desc';
}

// 獲取所有資料（包含出席統計）
// 場次設定頁面顯示所有場次，不區分歷史紀錄
// 注意：報名人數和出席人數只計算與場次年份相同的記錄
if ($is_department_director && $current_user_id && $user_department_code) {
    $sessions_sql = "
        SELECT s.*, 
               COUNT(DISTINCT CASE WHEN YEAR(a.created_at) = YEAR(s.session_date) AND (a.course_priority_1 = ? OR a.course_priority_2 = ?) THEN a.id END) as registration_count,
               (s.max_participants - COUNT(DISTINCT CASE WHEN YEAR(a.created_at) = YEAR(s.session_date) AND (a.course_priority_1 = ? OR a.course_priority_2 = ?) THEN a.id END)) as remaining_slots,
               COUNT(DISTINCT CASE WHEN ar.attendance_status = 1 AND YEAR(ar.check_in_time) = YEAR(s.session_date) AND (a.course_priority_1 = ? OR a.course_priority_2 = ?) THEN ar.id END) as attendance_count
        FROM admission_sessions s
        LEFT JOIN admission_applications a ON s.id = a.session_id 
        LEFT JOIN attendance_records ar ON s.id = ar.session_id AND a.id = ar.application_id
        WHERE (
            s.created_by = ?
            OR EXISTS (
                SELECT 1
                FROM admission_applications aa2
                WHERE aa2.session_id = s.id
                  AND YEAR(aa2.created_at) = YEAR(s.session_date)
                  AND (aa2.course_priority_1 = ? OR aa2.course_priority_2 = ?)
                LIMIT 1
            )
        )
        GROUP BY s.id 
        ORDER BY s.$sortBy $sortOrder";
    $stmt_sessions = $conn->prepare($sessions_sql);
    if (!$stmt_sessions) {
        $sessions = [];
    } else {
        $dept = $user_department_code;
        $uid = (int)$current_user_id;
        $stmt_sessions->bind_param(
            "ssssssiss",
            $dept, $dept,
            $dept, $dept,
            $dept, $dept,
            $uid,
            $dept, $dept
        );
        $stmt_sessions->execute();
        $sessions = $stmt_sessions->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_sessions->close();
    }
} else {
    $sessions_sql = "
        SELECT s.*, 
               COUNT(DISTINCT CASE WHEN YEAR(a.created_at) = YEAR(s.session_date) THEN a.id END) as registration_count,
               (s.max_participants - COUNT(DISTINCT CASE WHEN YEAR(a.created_at) = YEAR(s.session_date) THEN a.id END)) as remaining_slots,
               COUNT(DISTINCT CASE WHEN ar.attendance_status = 1 AND YEAR(ar.check_in_time) = YEAR(s.session_date) THEN ar.id END) as attendance_count
        FROM admission_sessions s
        LEFT JOIN admission_applications a ON s.id = a.session_id 
        LEFT JOIN attendance_records ar ON s.id = ar.session_id AND a.id = ar.application_id
        GROUP BY s.id 
        ORDER BY s.$sortBy $sortOrder";
    $sessions = $conn->query($sessions_sql)->fetch_all(MYSQLI_ASSOC);
}

$conn->close();
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
            --success-color: #52c41a;
            --warning-color: #faad14;
            --danger-color: #ff4d4f;
            --text-color: #262626;
            --text-secondary-color: #8c8c8c;
            --border-color: #f0f0f0;
            --background-color: #f0f2f5;
            --card-background-color: #fff;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background: var(--background-color); color: var(--text-color); overflow-x: hidden; }
        .dashboard { display: flex; min-height: 100vh; }
        .content { padding: 24px; }
        
        .page-controls { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-bottom: 16px; 
            gap: 16px; 
        }
        .breadcrumb { 
            margin-bottom: 0; 
            font-size: 16px; 
            color: var(--text-secondary-color); 
        }
        .breadcrumb a { 
            color: var(--primary-color); 
            text-decoration: none; 
        }
        .breadcrumb a:hover { 
            text-decoration: underline; 
        }
        
        .table-wrapper {
            background: var(--card-background-color);
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
        }

        .table-container { overflow-x: auto; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 16px 24px; text-align: left; border-bottom: 1px solid var(--border-color); font-size: 16px; }
        .table th:first-child, .table td:first-child {
            padding-left: 60px;
        }
        .table th { 
            background: #fafafa; 
            font-weight: 600; 
            color: #262626; 
            cursor: pointer; 
            user-select: none; 
            position: relative; 
        }
        .table th:hover { 
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
        .table td { color: #595959; }
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
        
        .table-search {
            display: flex;
            gap: 8px;
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
        
        .table-search input:focus {
            outline: none;
            border-color: #1890ff;
            box-shadow: 0 0 0 2px rgba(24,144,255,0.2);
        }
        .table a.btn { text-decoration: none; }

        .btn { padding: 8px 16px; border: 1px solid #d9d9d9; border-radius: 6px; cursor: pointer; font-size: 14px; transition: all 0.3s; background: #fff; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 6px; }
        .btn-primary { background: var(--primary-color); color: white; border-color: var(--primary-color); }
        .btn-primary:hover { background: #40a9ff; border-color: #40a9ff; }

        /* 表格內操作按鈕樣式 (與 users.php 統一) */
        .action-buttons { display: flex; gap: 8px; }
        .btn-action {
            padding: 4px 12px; border-radius: 4px; font-size: 14px;
            text-decoration: none; display: inline-block; transition: all 0.3s;
            background: #fff;
        }
        .btn-edit { color: var(--success-color); border: 1px solid var(--success-color); }
        .btn-edit:hover { background: var(--success-color); color: white; }
        .btn-view-list { color: var(--primary-color); border: 1px solid var(--primary-color); }
        .btn-view-list:hover { background: var(--primary-color); color: white; }
        .btn-delete { color: var(--danger-color); border: 1px solid var(--danger-color); }
        .btn-delete:hover { background: var(--danger-color); color: white; }
        
        /* 移除 .btn-sm, .btn-danger, .btn-info 等舊樣式，改用新的 .btn-action 系列 */


        .form-group { margin-bottom: 20px; }
        .form-label { display: block; margin-bottom: 8px; font-weight: 500; }
        .form-control { width: 100%; padding: 10px 12px; border: 1px solid #d9d9d9; border-radius: 6px; font-size: 14px; transition: border-color 0.3s; }
        .form-control:focus { outline: none; border-color: var(--primary-color); box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.2); }
        .form-row { display: flex; gap: 16px; }
        .form-row .form-group { flex: 1; }

        .message { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; font-weight: 500; }
        .message.success { background: #f6ffed; border: 1px solid #b7eb8f; color: var(--success-color); }
        .message.error { background: #fff2f0; border: 1px solid #ffccc7; color: var(--danger-color); }

        .status-badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500; }
        .status-active { background: #f6ffed; color: var(--success-color); border: 1px solid #b7eb8f; }
        .status-inactive { background: #fff2f0; color: var(--danger-color); border: 1px solid #ffccc7; }

        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.45); }
        .modal-content { background-color: #fff; margin: 2% auto; padding: 0; border-radius: 8px; width: 90%; max-width: 600px; max-height: 90vh; box-shadow: 0 4px 12px rgba(0,0,0,0.15); display: flex; flex-direction: column; }
        .modal-header { padding: 16px 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }
        .modal-title { font-size: 16px; font-weight: 600; }
        .close { color: var(--text-secondary-color); font-size: 20px; font-weight: bold; cursor: pointer; }
        .close:hover { color: var(--text-color); }
        .modal-body { padding: 24px; overflow-y: auto; flex: 1; }
        .modal-footer { padding: 16px 24px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 8px; background: #fafafa; flex-shrink: 0; }
        .required-asterisk { color: var(--danger-color); margin-right: 4px; }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include 'sidebar.php'; ?>
        <div class="main-content" id="mainContent">
            <?php include 'header.php'; ?>
            <div class="content">
                <?php if ($message): ?>
                    <div class="message <?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
                <?php endif; ?>

                <div class="page-controls">
                    <div class="breadcrumb">
                        <a href="index.php">首頁</a> / <?php echo $page_title; ?>
                    </div>
                    <div class="table-search">
                        <input type="text" id="tableSearchInput" placeholder="搜尋場次..." onkeyup="filterTable()">
                        <a href="attendance_statistics.php" class="btn btn-secondary"><i class="fas fa-chart-bar"></i> 實到人數統計與預估</a>
                        <button class="btn btn-primary" onclick="showModal('addSessionModal')"><i class="fas fa-plus"></i> 新增場次</button>
                    </div>
                </div>

                <div class="table-wrapper">
                    <div class="table-container">
                        <table class="table" id="sessionTable">
                            <thead>
                                <tr>
                                    <th onclick="sortTable('session_name')">場次名稱 <span class="sort-icon" id="sort-session_name"></span></th>
                                    <th onclick="sortTable('session_date')">日期時間 <span class="sort-icon" id="sort-session_date"></span></th>
                                    <th onclick="sortTable('session_type')">類型 <span class="sort-icon" id="sort-session_type"></span></th>
                                    <th onclick="sortTable('max_participants')">報名/上限 <span class="sort-icon" id="sort-max_participants"></span></th>
                                    <th>剩餘名額</th>
                                    <th>出席人數</th>
                                    <th onclick="sortTable('is_active')">狀態 <span class="sort-icon" id="sort-is_active"></span></th>
                                    <th>操作</th>                                       
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sessions as $item): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($item['session_name']); ?></td>
                                    <td><?php echo date('Y/m/d H:i', strtotime($item['session_date'])); ?></td>
                                    <td>
                                        <?php 
                                        $session_type_text = ($item['session_type'] == 1) ? '線上' : '實體';
                                        echo $session_type_text;
                                        if ($item['session_type'] == 1 && !empty($item['session_link'])) {
                                            echo '<br><small style="color: var(--primary-color);"><a href="' . htmlspecialchars($item['session_link']) . '" target="_blank" style="color: var(--primary-color); text-decoration: none;">' . htmlspecialchars($item['session_link']) . '</a></small>';
                                        } else if ($item['session_type'] == 2 && !empty($item['session_location'])) {
                                            echo '<br><small style="color: var(--text-secondary-color);">' . htmlspecialchars($item['session_location']) . '</small>';
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo $item['registration_count']; ?> / <?php echo $item['max_participants'] ?: '無限'; ?></td>
                                    <td>
                                        <?php 
                                        if ($item['max_participants']) {
                                            if ($item['remaining_slots'] <= 0) {
                                                echo ' <span style="color: var(--danger-color);">(已額滿)</span>';
                                            } else {
                                                echo '剩餘 ' . $item['remaining_slots'] . ' 位';
                                            }
                                        } else {
                                            echo '無限';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $attendance_count = isset($item['attendance_count']) ? intval($item['attendance_count']) : 0;
                                        $registration_count = isset($item['registration_count']) ? intval($item['registration_count']) : 0;
                                        if ($registration_count > 0) {
                                            $attendance_rate = round(($attendance_count / $registration_count) * 100, 1);
                                            echo '<span style="color: var(--success-color); font-weight: 600;">' . $attendance_count . '</span> / ' . $registration_count;
                                          
                                        } else {
                                            echo '<span style="color: var(--text-secondary-color);">0 / 0</span>';
                                        }
                                        ?>
                                    </td>
                                    <td><span class="status-badge <?php echo $item['is_active'] ? 'status-active' : 'status-inactive'; ?>"><?php echo $item['is_active'] ? '啟用' : '停用'; ?></span></td>
                                    <td>
                                        <div class="action-buttons">
                                        <?php
                                        $can_manage = true;
                                        if ($is_department_director && $current_user_id) {
                                            $created_by = isset($item['created_by']) ? intval($item['created_by']) : 0;
                                            $can_manage = ($created_by === intval($current_user_id));
                                        }
                                        ?>
                                        <?php if (!$is_department_director || $can_manage): ?>
                                            <button class="btn-action btn-edit" onclick='editSession(<?php echo json_encode($item); ?>)'>編輯</button>
                                        <?php endif; ?>
                                        <a href="view_registrations.php?session_id=<?php echo $item['id']; ?>" class="btn-action btn-view-list">查看名單</a>
                                        <a href="attendance_management.php?session_id=<?php echo $item['id']; ?>" class="btn-action btn-view-list" style="color: var(--warning-color); border-color: var(--warning-color);">出席紀錄</a>
                                        <?php if (!$is_department_director || $can_manage): ?>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('確定要刪除此場次嗎？');">
                                                <input type="hidden" name="action" value="delete_session">
                                                <input type="hidden" name="session_id" value="<?php echo $item['id']; ?>">
                                                <button type="submit" class="btn-action btn-delete">刪除</button>
                                            </form>
                                        <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- 分頁控制 -->
                    <?php if (!empty($sessions)): ?>
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
                            <span id="pageInfo">顯示第 <span id="currentRange">1-<?php echo min(10, count($sessions)); ?></span> 筆，共 <?php echo count($sessions); ?> 筆</span>
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

    <!-- 新增場次 Modal -->
    <div id="addSessionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">新增場次</h3>
                <span class="close" onclick="closeModal('addSessionModal')">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_session">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label"><span class="required-asterisk">*</span>場次名稱</label>
                        <input type="text" name="session_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><span class="required-asterisk">*</span>場次日期</label>
                        <input type="datetime-local" name="session_date" id="add_session_date" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">場次結束時間 (選填，用於自動發送提醒郵件)</label>
                        <input type="datetime-local" name="session_end_date" id="add_session_end_date" class="form-control">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label"><span class="required-asterisk">*</span>場次類型</label>
                            <select name="session_type" id="add_session_type" class="form-control" required onchange="toggleSessionTypeFields('add')">
                                <option value="實體">實體</option>
                                <option value="線上">線上</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">人數上限 (選填)</label>
                            <input type="number" name="max_participants" class="form-control" min="1">
                        </div>
                    </div>
                    <div class="form-group" id="add_session_link_group" style="display: none;">
                        <label class="form-label"><span class="required-asterisk">*</span>線上場次連結</label>
                        <input type="url" name="session_link" id="add_session_link" class="form-control" placeholder="https://meet.google.com/xxx 或 https://zoom.us/j/xxx">
                        <small style="color: var(--text-secondary-color); margin-top: 4px; display: block;">請輸入線上會議的連結網址</small>
                    </div>
                    <div class="form-group" id="add_session_location_group">
                        <label class="form-label"><span class="required-asterisk">*</span>實體場次地點</label>
                        <input type="text" name="session_location" id="add_session_location" class="form-control" placeholder="例如：康寧大學A棟先雲廳">
                        <small style="color: var(--text-secondary-color); margin-top: 4px; display: block;">請輸入實體場次的舉辦地點</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">場次說明 (選填)</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="請輸入場次說明，此說明將顯示在出席紀錄管理頁面"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal('addSessionModal')">取消</button>
                    <button type="submit" class="btn btn-primary">新增</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 編輯場次 Modal -->
    <div id="editSessionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">編輯場次</h3>
                <span class="close" onclick="closeModal('editSessionModal')">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_session">
                <input type="hidden" name="session_id" id="edit_session_id">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label"><span class="required-asterisk">*</span> 場次名稱</label>
                        <input type="text" name="session_name" id="edit_session_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><span class="required-asterisk">*</span> 場次日期</label>
                        <input type="datetime-local" name="session_date" id="edit_session_date" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">場次結束時間 (選填，用於自動發送提醒郵件)</label>
                        <input type="datetime-local" name="session_end_date" id="edit_session_end_date" class="form-control">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label"><span class="required-asterisk">*</span> 場次類型</label>
                            <select name="session_type" id="edit_session_type" class="form-control" required onchange="toggleSessionTypeFields('edit')">
                                <option value="實體">實體</option>
                                <option value="線上">線上</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">人數上限 (選填)</label>
                            <input type="number" name="max_participants" id="edit_max_participants" class="form-control" min="1">
                        </div>
                    </div>
                    <div class="form-group" id="edit_session_link_group" style="display: none;">
                        <label class="form-label"><span class="required-asterisk">*</span> 線上場次連結</label>
                        <input type="url" name="session_link" id="edit_session_link" class="form-control" placeholder="https://meet.google.com/xxx 或 https://zoom.us/j/xxx">
                        <small style="color: var(--text-secondary-color); margin-top: 4px; display: block;">請輸入線上會議的連結網址</small>
                    </div>
                    <div class="form-group" id="edit_session_location_group">
                        <label class="form-label"><span class="required-asterisk">*</span> 實體場次地點</label>
                        <input type="text" name="session_location" id="edit_session_location" class="form-control" placeholder="例如：康寧大學A棟先雲廳">
                        <small style="color: var(--text-secondary-color); margin-top: 4px; display: block;">請輸入實體場次的舉辦地點</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">場次說明 (選填)</label>
                        <textarea name="description" id="edit_description" class="form-control" rows="3" placeholder="請輸入場次說明，此說明將顯示在出席紀錄管理頁面"></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><span class="required-asterisk">*</span> 狀態</label>
                        <select name="is_active" id="edit_session_is_active" class="form-control" required>
                            <option value="1">啟用</option>
                            <option value="0">停用</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal('editSessionModal')">取消</button>
                    <button type="submit" class="btn btn-primary">儲存</button>
                </div>
            </form>
        </div>
    </div>


    <script>
        // 排序表格
        function sortTable(field) {
            let newSortOrder = 'asc';
            
            // 如果點擊的是當前排序欄位，則切換排序方向
            const urlParams = new URLSearchParams(window.location.search);
            const currentSortBy = urlParams.get('sort_by') || 'session_date';
            const currentSortOrder = urlParams.get('sort_order') || 'desc';
            
            if (currentSortBy === field) {
                newSortOrder = currentSortOrder === 'asc' ? 'desc' : 'asc';
            }
            
            window.location.href = `settings.php?sort_by=${field}&sort_order=${newSortOrder}`;
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
            const currentSortBy = urlParams.get('sort_by') || 'session_date';
            const currentSortOrder = urlParams.get('sort_order') || 'desc';
            
            // 設置當前排序欄位的圖標
            const currentIcon = document.getElementById(`sort-${currentSortBy}`);
            if (currentIcon) {
                currentIcon.className = `sort-icon active ${currentSortOrder}`;
            }
        }
        
        // 分頁相關變數
        let currentPage = 1;
        let itemsPerPage = 10;
        let allRows = [];
        let filteredRows = [];
        
        // 初始化分頁
        function initPagination() {
            const table = document.getElementById('sessionTable');
            if (!table) return;
            
            const tbody = table.getElementsByTagName('tbody')[0];
            if (!tbody) return;
            
            allRows = Array.from(tbody.getElementsByTagName('tr'));
            filteredRows = allRows;
            
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
        
        // 表格搜尋功能
        function filterTable() {
            const input = document.getElementById('tableSearchInput');
            const filter = input.value.toLowerCase();
            const table = document.getElementById('sessionTable');
            
            if (!table) return;
            
            const tbody = table.getElementsByTagName('tbody')[0];
            if (!tbody) return;
            
            allRows = Array.from(tbody.getElementsByTagName('tr'));
            
            filteredRows = allRows.filter(row => {
                const cells = row.getElementsByTagName('td');
                for (let j = 0; j < cells.length; j++) {
                    const cell = cells[j];
                    if (cell) {
                        const txtValue = cell.textContent || cell.innerText;
                        if (txtValue.toLowerCase().indexOf(filter) > -1) {
                            return true;
                        }
                    }
                }
                return false;
            });
            
            currentPage = 1;
            updatePagination();
        }

        function showModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
            
            // 如果是新增場次模態框，設置日期最小值為今天
            if (modalId === 'addSessionModal') {
                const dateInput = document.getElementById('add_session_date');
                if (dateInput) {
                    // 獲取今天的日期時間，格式為 YYYY-MM-DDTHH:mm
                    const now = new Date();
                    const year = now.getFullYear();
                    const month = String(now.getMonth() + 1).padStart(2, '0');
                    const day = String(now.getDate()).padStart(2, '0');
                    const hours = String(now.getHours()).padStart(2, '0');
                    const minutes = String(now.getMinutes()).padStart(2, '0');
                    const minDateTime = `${year}-${month}-${day}T${hours}:${minutes}`;
                    dateInput.setAttribute('min', minDateTime);
                    dateInput.value = ''; // 清空之前的值
                }
                // 初始化場次類型欄位顯示
                toggleSessionTypeFields('add');
            }
        }
        
        // 根據場次類型顯示/隱藏相應的輸入框
        function toggleSessionTypeFields(mode) {
            const prefix = mode === 'add' ? 'add' : 'edit';
            const sessionType = document.getElementById(prefix + '_session_type').value;
            const linkGroup = document.getElementById(prefix + '_session_link_group');
            const locationGroup = document.getElementById(prefix + '_session_location_group');
            const linkInput = document.getElementById(prefix + '_session_link');
            const locationInput = document.getElementById(prefix + '_session_location');
            
            if (sessionType === '線上') {
                linkGroup.style.display = 'block';
                locationGroup.style.display = 'none';
                linkInput.required = true;
                locationInput.required = false;
                locationInput.value = ''; // 清空地點
            } else {
                linkGroup.style.display = 'none';
                locationGroup.style.display = 'block';
                linkInput.required = false;
                locationInput.required = true;
                linkInput.value = ''; // 清空連結
            }
        }
        
        // 根據場次類型顯示/隱藏相應的輸入框
        function toggleSessionTypeFields(mode) {
            const prefix = mode === 'add' ? 'add' : 'edit';
            const sessionType = document.getElementById(prefix + '_session_type').value;
            const linkGroup = document.getElementById(prefix + '_session_link_group');
            const locationGroup = document.getElementById(prefix + '_session_location_group');
            const linkInput = document.getElementById(prefix + '_session_link');
            const locationInput = document.getElementById(prefix + '_session_location');
            
            if (sessionType === '線上') {
                linkGroup.style.display = 'block';
                locationGroup.style.display = 'none';
                linkInput.required = true;
                locationInput.required = false;
                locationInput.value = ''; // 清空地點
            } else {
                linkGroup.style.display = 'none';
                locationGroup.style.display = 'block';
                linkInput.required = false;
                locationInput.required = true;
                linkInput.value = ''; // 清空連結
            }
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
        
        // 頁面載入時更新排序圖標和初始化分頁
        document.addEventListener('DOMContentLoaded', function() {
            updateSortIcons();
            initPagination();
        });

        function setInputValue(id, value) {
    const el = document.getElementById(id);
    if (el) el.value = value;
}

function editSession(item) {
    setInputValue('edit_session_id', item.id);
    setInputValue('edit_session_name', item.session_name || '');
    
    // 格式化日期
    if (item.session_date) {
        const date = new Date(item.session_date.replace(' ', 'T'));
        if (!isNaN(date.getTime())) {
            const formatted = date.toISOString().slice(0,16);
            setInputValue('edit_session_date', formatted);
        } else {
            setInputValue('edit_session_date', '');
        }
    } else {
        setInputValue('edit_session_date', '');
    }

    if (item.session_end_date) {
        const endDate = new Date(item.session_end_date.replace(' ', 'T'));
        if (!isNaN(endDate.getTime())) {
            const formattedEnd = endDate.toISOString().slice(0,16);
            setInputValue('edit_session_end_date', formattedEnd);
        } else {
            setInputValue('edit_session_end_date', '');
        }
    } else {
        setInputValue('edit_session_end_date', '');
    }

    const sessionTypeMap = {1: '線上', 2: '實體'};
    setInputValue('edit_session_type', sessionTypeMap[item.session_type] || item.session_type);
    setInputValue('edit_max_participants', item.max_participants || '');
    setInputValue('edit_description', item.description || '');
    setInputValue('edit_session_is_active', item.is_active);

    setInputValue('edit_session_link', item.session_link || '');
    setInputValue('edit_session_location', item.session_location || '');

    toggleSessionTypeFields('edit');
    showModal('editSessionModal');
}


    </script>
</body>
</html>