<?php
require_once __DIR__ . '/session_config.php';

// 檢查是否已登入
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: login.php");
    exit;
}

// 引入資料庫設定
require_once '../../Topics-frontend/frontend/config.php';

// 獲取用戶角色和權限
$user_role = $_SESSION['role'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;
$is_director = in_array($user_role, ['DI', '主任', 'ADM', '管理員', 'STA', '行政人員']);
$is_teacher = in_array($user_role, ['TEA', '老師']);

// 獲取年份參數（預設為今年）
$selected_year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$last_year = $selected_year - 1;

// 建立資料庫連接
$conn = getDatabaseConnection();

// 獲取老師負責的場次（如果用戶是老師）
$teacher_sessions = [];
$has_teacher_sessions = false;
if ($is_teacher && !$is_director && $user_id) {
    // 查詢老師負責的場次（需要根據實際的場次分配表來查詢）
    // 這裡假設有 session_teachers 表或類似的關聯表
    // 如果沒有，可能需要從其他表獲取
    $teacher_sessions_query = "SELECT DISTINCT session_id FROM session_teachers WHERE teacher_id = ?";
    $table_check = $conn->query("SHOW TABLES LIKE 'session_teachers'");
    if ($table_check && $table_check->num_rows > 0) {
        $stmt = $conn->prepare($teacher_sessions_query);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $teacher_sessions[] = $row['session_id'];
        }
        $stmt->close();
        $has_teacher_sessions = !empty($teacher_sessions);
    }
    // 如果沒有 session_teachers 表或沒有分配場次，老師可以查看所有場次
    // 但會顯示提示訊息
}

// 獲取所有場次（根據權限篩選）
$sessions_where = "";
$sessions_params = [];
$sessions_types = "";

if ($is_teacher && !$is_director && $has_teacher_sessions && !empty($teacher_sessions)) {
    // 老師只能查看自己負責的場次
    $placeholders = implode(',', array_fill(0, count($teacher_sessions), '?'));
    $sessions_where = "WHERE s.id IN ($placeholders)";
    $sessions_params = $teacher_sessions;
    $sessions_types = str_repeat('i', count($teacher_sessions));
}
// 如果老師沒有分配場次，可以查看所有場次（但會顯示提示）

// 獲取當前年份的場次實到人數統計
// 注意：只計算與場次年份相同的簽到記錄
$current_year_sessions_sql = "
    SELECT 
        s.id,
        s.session_name,
        s.session_date,
        s.session_type,
        COUNT(DISTINCT CASE WHEN ar.attendance_status = 1 AND YEAR(ar.check_in_time) = YEAR(s.session_date) THEN ar.application_id END) as attendance_count_from_records,
        COUNT(DISTINCT CASE WHEN YEAR(oc.check_in_time) = YEAR(s.session_date) THEN oc.id END) as attendance_count_from_checkin
    FROM admission_sessions s
    LEFT JOIN attendance_records ar ON s.id = ar.session_id AND ar.attendance_status = 1
    LEFT JOIN online_check_in_records oc ON s.id = oc.session_id
    $sessions_where " . (!empty($sessions_where) ? "AND" : "WHERE") . " YEAR(s.session_date) = ?
    GROUP BY s.id, s.session_name, s.session_date, s.session_type
    ORDER BY s.session_date DESC
";

$stmt = $conn->prepare($current_year_sessions_sql);
if (!empty($sessions_params)) {
    $bind_params = array_merge($sessions_params, [$selected_year]);
    $bind_types = $sessions_types . 'i';
    $stmt->bind_param($bind_types, ...$bind_params);
} else {
    $stmt->bind_param("i", $selected_year);
}
$stmt->execute();
$current_year_sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 獲取去年同期的場次實到人數統計（用於預估）
// 改為從簽到記錄表中根據簽到時間的年份來統計，不依賴會更新的場次時間
// 先從 attendance_records 和 online_check_in_records 中找出有去年簽到記錄的場次
$last_year_sessions_base_sql = "
    SELECT 
        s.id,
        s.session_name,
        s.session_type,
        MIN(COALESCE(ar.check_in_time, oc.check_in_time)) as session_date,
        YEAR(COALESCE(ar.check_in_time, oc.check_in_time)) as actual_year,
        MONTH(COALESCE(ar.check_in_time, oc.check_in_time)) as session_month,
        DAY(COALESCE(ar.check_in_time, oc.check_in_time)) as session_day,
        COUNT(DISTINCT CASE WHEN ar.attendance_status = 1 AND YEAR(ar.check_in_time) = ? THEN ar.application_id END) as attendance_count_from_records,
        COUNT(DISTINCT CASE WHEN YEAR(oc.check_in_time) = ? THEN oc.id END) as attendance_count_from_checkin
    FROM admission_sessions s
    INNER JOIN (
        SELECT DISTINCT session_id 
        FROM attendance_records 
        WHERE check_in_time IS NOT NULL AND YEAR(check_in_time) = ?
        UNION
        SELECT DISTINCT session_id 
        FROM online_check_in_records 
        WHERE check_in_time IS NOT NULL AND YEAR(check_in_time) = ?
    ) as sessions_with_data ON s.id = sessions_with_data.session_id
    LEFT JOIN attendance_records ar ON s.id = ar.session_id AND ar.attendance_status = 1 AND YEAR(ar.check_in_time) = ?
    LEFT JOIN online_check_in_records oc ON s.id = oc.session_id AND YEAR(oc.check_in_time) = ?
";

// 添加老師權限過濾
if ($is_teacher && !$is_director && $has_teacher_sessions && !empty($teacher_sessions)) {
    $placeholders = implode(',', array_fill(0, count($teacher_sessions), '?'));
    $last_year_sessions_sql = $last_year_sessions_base_sql . " WHERE s.id IN ($placeholders) 
    GROUP BY s.id, s.session_name, s.session_type, 
             YEAR(COALESCE(ar.check_in_time, oc.check_in_time)),
             MONTH(COALESCE(ar.check_in_time, oc.check_in_time)),
             DAY(COALESCE(ar.check_in_time, oc.check_in_time))
    ORDER BY actual_year DESC, session_month DESC, session_day DESC";
} else {
    $last_year_sessions_sql = $last_year_sessions_base_sql . " 
    GROUP BY s.id, s.session_name, s.session_type, 
             YEAR(COALESCE(ar.check_in_time, oc.check_in_time)),
             MONTH(COALESCE(ar.check_in_time, oc.check_in_time)),
             DAY(COALESCE(ar.check_in_time, oc.check_in_time))
    ORDER BY actual_year DESC, session_month DESC, session_day DESC";
}

$stmt = $conn->prepare($last_year_sessions_sql);
if (!empty($sessions_params)) {
    // 需要綁定多個 last_year 參數（用於多個地方）+ 老師的場次ID
    $bind_params = array_merge([$last_year, $last_year, $last_year, $last_year, $last_year, $last_year], $sessions_params);
    $bind_types = 'iiiiii' . $sessions_types;
    $stmt->bind_param($bind_types, ...$bind_params);
} else {
    // 需要綁定 6 個 last_year 參數
    $stmt->bind_param("iiiiii", $last_year, $last_year, $last_year, $last_year, $last_year, $last_year);
}
$stmt->execute();
$last_year_sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 建立去年場次的映射表（用於預估）
// 根據場次名稱+類型，以及月份日期+類型來匹配
$last_year_sessions_map = [];
$last_year_count = count($last_year_sessions);

if ($last_year_count > 0) {
    foreach ($last_year_sessions as $session) {
        $attendance = max(
            intval($session['attendance_count_from_records'] ?? 0),
            intval($session['attendance_count_from_checkin'] ?? 0)
        );
        
        $session_name = $session['session_name'] ?? '';
        $session_type = intval($session['session_type'] ?? 2);
        $session_month = isset($session['session_month']) ? intval($session['session_month']) : 0;
        $session_day = isset($session['session_day']) ? intval($session['session_day']) : 0;
        
        // 使用場次名稱+類型作為主要鍵值（格式：場次名稱|類型）
        if (!empty($session_name)) {
            $name_type_key = $session_name . '|' . $session_type;
            $last_year_sessions_map[$name_type_key] = [
                'attendance' => $attendance,
                'session_type' => $session_type,
                'month' => $session_month,
                'day' => $session_day
            ];
        }
        
        // 也使用月份和日期+類型作為備用鍵值（格式：MM-DD|類型）
        if ($session_month > 0 && $session_day > 0) {
            $date_type_key = sprintf('%02d-%02d|%d', $session_month, $session_day, $session_type);
            if (!isset($last_year_sessions_map[$date_type_key]) || $last_year_sessions_map[$date_type_key]['attendance'] < $attendance) {
                $last_year_sessions_map[$date_type_key] = [
                    'attendance' => $attendance,
                    'session_type' => $session_type,
                    'month' => $session_month,
                    'day' => $session_day
                ];
            }
        }
    }
}

// 獲取所有可用年份（用於年份選擇器）
// 包含所有有場次的年份，以及有簽到記錄的年份（可能場次在其他年份但簽到記錄在某年份）
$years_sql = "
    SELECT DISTINCT YEAR(session_date) as year 
    FROM admission_sessions 
    UNION
    SELECT DISTINCT YEAR(check_in_time) as year 
    FROM attendance_records 
    WHERE check_in_time IS NOT NULL
    UNION
    SELECT DISTINCT YEAR(check_in_time) as year 
    FROM online_check_in_records 
    WHERE check_in_time IS NOT NULL
    ORDER BY year DESC
";
$years_result = $conn->query($years_sql);
$available_years = [];
if ($years_result) {
    while ($row = $years_result->fetch_assoc()) {
        if ($row['year'] && $row['year'] > 0) {
            $available_years[] = $row['year'];
        }
    }
}
// 如果沒有找到任何年份，至少顯示當前年份
if (empty($available_years)) {
    $available_years[] = date('Y');
}

$conn->close();

// 設置頁面標題
$page_title = '活動場次實到人數統計與預估';
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
            margin-bottom: 24px; 
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
        
        .stats-card {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-item {
            background: var(--card-background-color);
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            border: 1px solid var(--border-color);
        }
        .stat-item h4 {
            font-size: 14px;
            color: var(--text-secondary-color);
            margin-bottom: 8px;
        }
        .stat-item .value {
            font-size: 32px;
            font-weight: 600;
            color: var(--text-color);
        }
        .stat-item .value.primary { color: var(--primary-color); }
        .stat-item .value.success { color: var(--success-color); }
        .stat-item .value.warning { color: var(--warning-color); }
        
        .table-wrapper {
            background: var(--card-background-color);
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
        }

        .table-container { overflow-x: auto; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 16px 24px; text-align: left; border-bottom: 1px solid var(--border-color); font-size: 14px; }
        .table th { 
            background: #fafafa; 
            font-weight: 600; 
            color: #262626; 
        }
        .table td { color: #595959; }
        .table tr:hover { background: #fafafa; }
        
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
        .btn-primary { background: var(--primary-color); color: white; border-color: var(--primary-color); }
        .btn-primary:hover { background: #40a9ff; border-color: #40a9ff; }
        .btn-secondary { background: #fff; color: #595959; border-color: #d9d9d9; }
        .btn-secondary:hover { background: #f5f5f5; border-color: #40a9ff; color: #40a9ff; }
        
        .form-control {
            padding: 8px 12px;
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            font-size: 14px;
            background: #fff;
        }
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(24,144,255,0.2);
        }
        
        .info-box {
            background: #e6f7ff;
            border: 1px solid #91d5ff;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 24px;
        }
        .info-box h4 {
            margin: 0 0 8px 0;
            color: #1890ff;
            font-size: 16px;
            font-weight: 600;
        }
        .info-box p {
            margin: 0;
            color: #595959;
            line-height: 1.6;
        }
        
        .estimate-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
            background: #fff7e6;
            color: var(--warning-color);
            border: 1px solid #ffe58f;
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
                        <a href="index.php">首頁</a> / <a href="settings.php">場次設定</a> / <?php echo $page_title; ?>
                    </div>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <label style="font-size: 14px; color: var(--text-secondary-color);">選擇年份：</label>
                        <select class="form-control" onchange="window.location.href='?year=' + this.value" style="width: 120px;">
                            <?php foreach ($available_years as $year): ?>
                                <option value="<?php echo $year; ?>" <?php echo $year == $selected_year ? 'selected' : ''; ?>>
                                    <?php echo $year; ?>年
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <a href="settings.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> 返回</a>
                    </div>
                </div>

                <div class="info-box">
                    <h4><i class="fas fa-info-circle"></i> 功能說明</h4>
                    <p>
                        此頁面統計每場活動的實際到場人數，並根據去年同期的歷史資料自動預估下一場活動可能的人數。
                        預估人數可幫助您準備小禮品、招生表單或其他現場資源，避免浪費或不足。
                    </p>
                    <?php if ($is_teacher && !$is_director && !$has_teacher_sessions): ?>
                    <p style="margin-top: 8px; color: var(--warning-color);">
                        <i class="fas fa-exclamation-triangle"></i> 提示：您目前沒有被分配特定場次，因此顯示所有場次的統計資料。
                    </p>
                    <?php endif; ?>
                </div>

                <!-- 統計卡片 -->
                <div class="stats-card">
                    <div class="stat-item">
                        <h4><?php echo $selected_year; ?>年場次總數</h4>
                        <div class="value primary"><?php echo count($current_year_sessions); ?></div>
                    </div>
                    <div class="stat-item">
                        <h4><?php echo $selected_year; ?>年總實到人數</h4>
                        <div class="value success">
                            <?php 
                            $total_attendance = 0;
                            foreach ($current_year_sessions as $session) {
                                $attendance = max(
                                    intval($session['attendance_count_from_records'] ?? 0),
                                    intval($session['attendance_count_from_checkin'] ?? 0)
                                );
                                $total_attendance += $attendance;
                            }
                            echo $total_attendance;
                            ?>
                        </div>
                    </div>
                    <div class="stat-item">
                        <h4><?php echo $last_year; ?>年同期場次數</h4>
                        <div class="value"><?php echo $last_year_count; ?></div>
                    </div>
                    <div class="stat-item">
                        <h4>預估下一場人數</h4>
                        <div class="value warning">
                            <?php 
                            // 計算有預估資料的場次數
                            $sessions_with_estimate = 0;
                            $total_estimate = 0;
                            foreach ($current_year_sessions as $s) {
                                $s_name = $s['session_name'] ?? '';
                                $s_type = intval($s['session_type'] ?? 2);
                                $estimate = 0;
                                
                                // 優先根據場次名稱+類型匹配
                                if (!empty($s_name)) {
                                    $name_type_key = $s_name . '|' . $s_type;
                                    if (isset($last_year_sessions_map[$name_type_key])) {
                                        $estimate = $last_year_sessions_map[$name_type_key]['attendance'];
                                    } else {
                                        // 如果沒有相同名稱+類型，嘗試根據月份和日期+類型匹配
                                        $s_date = isset($s['session_date']) ? strtotime($s['session_date']) : false;
                                        if ($s_date !== false) {
                                            $month = date('m', $s_date);
                                            $day = date('d', $s_date);
                                            $date_type_key = sprintf('%02d-%02d|%d', intval($month), intval($day), $s_type);
                                            if (isset($last_year_sessions_map[$date_type_key])) {
                                                $estimate = $last_year_sessions_map[$date_type_key]['attendance'];
                                            }
                                        }
                                    }
                                }
                                
                                if ($estimate > 0) {
                                    $sessions_with_estimate++;
                                    $total_estimate += $estimate;
                                }
                            }
                            
                            if ($sessions_with_estimate > 0) {
                                echo round($total_estimate / $sessions_with_estimate);
                            } else {
                                echo '無資料';
                            }
                            ?>
                        </div>
                        <small style="color: var(--text-secondary-color); font-size: 12px;">
                            (基於<?php echo $last_year; ?>年相同場次平均)
                        </small>
                    </div>
                </div>

                <!-- 場次統計表格 -->
                <div class="table-wrapper">
                    <div style="padding: 16px 24px; border-bottom: 1px solid var(--border-color); background: #fafafa;">
                        <h3 style="margin: 0; font-size: 16px; font-weight: 600;">
                            <i class="fas fa-chart-bar"></i> <?php echo $selected_year; ?>年場次實到人數統計
                        </h3>
                    </div>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>場次名稱</th>
                                    <th>日期時間</th>
                                    <th>類型</th>
                                    <th>實到人數</th>
                                    <th>預估人數</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($current_year_sessions)): ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; color: var(--text-secondary-color); padding: 40px;">
                                        <?php echo $selected_year; ?>年尚無場次資料
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($current_year_sessions as $session): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($session['session_name']); ?></td>
                                    <td><?php echo date('Y/m/d H:i', strtotime($session['session_date'])); ?></td>
                                    <td>
                                        <?php 
                                        $session_type_text = ($session['session_type'] == 1) ? '線上' : '實體';
                                        echo $session_type_text;
                                        ?>
                                    </td>
                                    <td>
                                        <span style="color: var(--success-color); font-weight: 600;">
                                            <?php 
                                            $attendance = max(
                                                intval($session['attendance_count_from_records'] ?? 0),
                                                intval($session['attendance_count_from_checkin'] ?? 0)
                                            );
                                            echo $attendance;
                                            ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php 
                                        $session_name = $session['session_name'] ?? '';
                                        $session_type = intval($session['session_type'] ?? 2);
                                        $estimate = 0;
                                        $estimate_source = '';
                                        
                                        // 優先根據場次名稱+類型匹配去年同一個場次
                                        if (!empty($session_name)) {
                                            $name_type_key = $session_name . '|' . $session_type;
                                            if (isset($last_year_sessions_map[$name_type_key])) {
                                                $estimate = $last_year_sessions_map[$name_type_key]['attendance'];
                                                $estimate_source = '相同場次名稱與類型';
                                            } else {
                                                // 如果沒有相同名稱+類型，嘗試根據月份和日期+類型匹配
                                                $session_date = isset($session['session_date']) ? strtotime($session['session_date']) : false;
                                                if ($session_date !== false) {
                                                    $month = date('m', $session_date);
                                                    $day = date('d', $session_date);
                                                    $date_type_key = sprintf('%02d-%02d|%d', intval($month), intval($day), $session_type);
                                                    
                                                    if (isset($last_year_sessions_map[$date_type_key])) {
                                                        $estimate = $last_year_sessions_map[$date_type_key]['attendance'];
                                                        $estimate_source = '相同日期與類型';
                                                    }
                                                }
                                            }
                                        }
                                        
                                        if ($estimate > 0): 
                                        ?>
                                            <span class="estimate-badge">
                                                <i class="fas fa-chart-line"></i> 預估 <?php echo $estimate; ?> 人
                                            </span>
                                            <br><small style="color: var(--text-secondary-color); font-size: 11px;">
                                                (基於<?php echo $last_year; ?>年<?php echo $estimate_source; ?>)
                                            </small>
                                        <?php else: ?>
                                            <span style="color: var(--text-secondary-color);">無歷史資料</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- 去年同期對比 -->
                <?php if (!empty($last_year_sessions)): ?>
                <div class="table-wrapper">
                    <div style="padding: 16px 24px; border-bottom: 1px solid var(--border-color); background: #fafafa;">
                        <h3 style="margin: 0; font-size: 16px; font-weight: 600;">
                            <i class="fas fa-history"></i> <?php echo $last_year; ?>年同期場次資料（用於預估參考）
                        </h3>
                    </div>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>場次名稱</th>
                                    <th>日期時間</th>
                                    <th>類型</th>
                                    <th>實到人數</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($last_year_sessions as $session): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($session['session_name']); ?></td>
                                    <td>
                                        <?php 
                                        // 使用 session_date（從簽到記錄中獲取的最早時間）或構建日期
                                        if (isset($session['session_date']) && !empty($session['session_date'])) {
                                            echo date('Y/m/d H:i', strtotime($session['session_date']));
                                        } elseif (isset($session['actual_year']) && isset($session['session_month']) && isset($session['session_day'])) {
                                            $date_str = sprintf('%04d-%02d-%02d', $session['actual_year'], $session['session_month'], $session['session_day']);
                                            echo date('Y/m/d', strtotime($date_str));
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $session_type_text = ($session['session_type'] == 1) ? '線上' : '實體';
                                        echo $session_type_text;
                                        ?>
                                    </td>
                                    <td>
                                        <span style="color: var(--success-color); font-weight: 600;">
                                            <?php 
                                            $attendance = max(
                                                intval($session['attendance_count_from_records'] ?? 0),
                                                intval($session['attendance_count_from_checkin'] ?? 0)
                                            );
                                            echo $attendance;
                                            ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
</body>
</html>

