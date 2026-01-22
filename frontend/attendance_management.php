<?php
require_once __DIR__ . '/session_config.php';

// 檢查是否已登入
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: login.php");
    exit;
}

// 引入資料庫設定
require_once '../../Topics-frontend/frontend/config.php';

// 獲取場次ID
$session_id = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;
if ($session_id === 0) {
    header("Location: settings.php");
    exit;
}

// 建立資料庫連接
$conn = getDatabaseConnection();

$message = "";
$messageType = "";

// 處理表單提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            case 'update_attendance':
                // 批量更新出席紀錄（簽到和未到都要有時間記錄）
                if (isset($_POST['attendance']) && is_array($_POST['attendance'])) {
                    $conn->begin_transaction();
                    foreach ($_POST['attendance'] as $application_id => $status) {
                        $application_id = intval($application_id);
                        $attendance_status = intval($status);
                        $current_time = date('Y-m-d H:i:s');
                        // 簽到和未到都要有時間記錄
                        $check_in_time = $attendance_status == 1 ? $current_time : null;
                        $absent_time = $attendance_status == 0 ? $current_time : null;
                        
                        // 檢查是否已存在紀錄
                        $check_stmt = $conn->prepare("SELECT id FROM attendance_records WHERE session_id = ? AND application_id = ?");
                        $check_stmt->bind_param("ii", $session_id, $application_id);
                        $check_stmt->execute();
                        $exists = $check_stmt->get_result()->fetch_assoc();
                        $check_stmt->close();
                        
                        if ($exists) {
                            // 更新現有紀錄
                            $update_stmt = $conn->prepare("UPDATE attendance_records SET attendance_status = ?, check_in_time = ?, absent_time = ? WHERE session_id = ? AND application_id = ?");
                            $update_stmt->bind_param("isssii", $attendance_status, $check_in_time, $absent_time, $session_id, $application_id);
                            $update_stmt->execute();
                            $update_stmt->close();
                        } else {
                            // 新增紀錄
                            $insert_stmt = $conn->prepare("INSERT INTO attendance_records (session_id, application_id, attendance_status, check_in_time, absent_time) VALUES (?, ?, ?, ?, ?)");
                            $insert_stmt->bind_param("iiiss", $session_id, $application_id, $attendance_status, $check_in_time, $absent_time);
                            $insert_stmt->execute();
                            $insert_stmt->close();
                        }
                    }
                    $conn->commit();
                    $message = "出席紀錄更新成功！"; 
                    $messageType = "success";
                }
                break;

            case 'import_excel':
                // 處理 Excel/CSV 匯入
                if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === UPLOAD_ERR_OK) {
                    $file = $_FILES['excel_file']['tmp_name'];
                    $file_name = $_FILES['excel_file']['name'];
                    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                    
                    $rows = [];
                    
                    // 處理 CSV 檔案
                    if ($file_ext === 'csv') {
                        if (($handle = fopen($file, "r")) !== FALSE) {
                            // 處理 BOM
                            $bom = fread($handle, 3);
                            if ($bom !== "\xEF\xBB\xBF") {
                                rewind($handle);
                            }
                            
                            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                                $rows[] = $data;
                            }
                            fclose($handle);
                        }
                    } 
                    // 處理 Excel 檔案 (需要 PhpSpreadsheet)
                    else if (in_array($file_ext, ['xlsx', 'xls'])) {
                        // 嘗試載入 PhpSpreadsheet
                        $phpspreadsheet_path = __DIR__ . '/../vendor/autoload.php';
                        if (file_exists($phpspreadsheet_path)) {
                            require_once $phpspreadsheet_path;
                            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);
                            $worksheet = $spreadsheet->getActiveSheet();
                            $rows = $worksheet->toArray();
                        } else {
                            throw new Exception("請安裝 PhpSpreadsheet 套件以支援 Excel 檔案匯入，或將檔案轉換為 CSV 格式。執行: composer require phpoffice/phpspreadsheet");
                        }
                    } else {
                        throw new Exception("不支援的檔案格式，請上傳 CSV 或 Excel (.xlsx, .xls) 檔案");
                    }
                    
                    // 跳過標題行（第一行）
                    if (!empty($rows)) {
                        array_shift($rows);
                    }
                    
                    $conn->begin_transaction();
                    $success_count = 0;
                    $error_count = 0;
                    $error_details = [];
                    
                    foreach ($rows as $row_index => $row) {
                        if (empty($row[0])) continue; // 跳過空行
                        
                        // 假設格式：姓名, Email, 出席狀態(是/否/1/0/已到/出席)
                        $name = trim($row[0] ?? '');
                        $email = trim($row[1] ?? '');
                        $status_text = trim($row[2] ?? '');
                        
                        // 轉換出席狀態
                        $attendance_status = 0;
                        if (in_array(strtolower($status_text), ['是', 'yes', '1', '已到', '出席', 'true'])) {
                            $attendance_status = 1;
                        }
                        
                        // 根據姓名或 Email 查找報名紀錄
                        $find_stmt = $conn->prepare("SELECT id FROM admission_applications WHERE session_id = ? AND (student_name = ? OR email = ?)");
                        $find_stmt->bind_param("iss", $session_id, $name, $email);
                        $find_stmt->execute();
                        $result = $find_stmt->get_result();
                        $application = $result->fetch_assoc();
                        $find_stmt->close();
                        
                        if ($application) {
                            $application_id = $application['id'];
                            $current_time = date('Y-m-d H:i:s');
                            // 簽到和未到都要有時間記錄
                            $check_in_time = $attendance_status == 1 ? $current_time : null;
                            $absent_time = $attendance_status == 0 ? $current_time : null;
                            
                            // 檢查是否已存在紀錄
                            $check_stmt = $conn->prepare("SELECT id FROM attendance_records WHERE session_id = ? AND application_id = ?");
                            $check_stmt->bind_param("ii", $session_id, $application_id);
                            $check_stmt->execute();
                            $exists = $check_stmt->get_result()->fetch_assoc();
                            $check_stmt->close();
                            
                            if ($exists) {
                                $update_stmt = $conn->prepare("UPDATE attendance_records SET attendance_status = ?, check_in_time = ?, absent_time = ? WHERE session_id = ? AND application_id = ?");
                                $update_stmt->bind_param("isssii", $attendance_status, $check_in_time, $absent_time, $session_id, $application_id);
                                $update_stmt->execute();
                                $update_stmt->close();
                            } else {
                                $insert_stmt = $conn->prepare("INSERT INTO attendance_records (session_id, application_id, attendance_status, check_in_time, absent_time) VALUES (?, ?, ?, ?, ?)");
                                $insert_stmt->bind_param("iiiss", $session_id, $application_id, $attendance_status, $check_in_time, $absent_time);
                                $insert_stmt->execute();
                                $insert_stmt->close();
                            }
                            $success_count++;
                        } else {
                            $error_count++;
                            $error_details[] = "第 " . ($row_index + 2) . " 行：找不到報名者（姓名：{$name}，Email：{$email}）";
                        }
                    }
                    
                    $conn->commit();
                    $message = "Excel 匯入完成！成功：{$success_count} 筆，失敗：{$error_count} 筆";
                    if ($error_count > 0 && count($error_details) <= 10) {
                        $message .= "<br><small>" . implode("<br>", $error_details) . "</small>";
                    }
                    $messageType = $error_count > 0 ? "warning" : "success";
                } else {
                    throw new Exception("請選擇有效的 Excel 或 CSV 檔案");
                }
                break;
        }
    } catch (Exception $e) {
        // 使用兼容方式检查事务状态
        if (method_exists($conn, 'in_transaction') && $conn->in_transaction()) {
            $conn->rollback();
        } else {
            // 如果没有 in_transaction 方法，直接尝试回滚
            @$conn->rollback();
        }
        $message = "操作失敗：" . $e->getMessage();
        $messageType = "error";
    }
}

// 獲取場次資訊
$stmt = $conn->prepare("SELECT * FROM admission_sessions WHERE id = ?");
$stmt->bind_param("i", $session_id);
$stmt->execute();
$session_result = $stmt->get_result();
$session = $session_result->fetch_assoc();
$stmt->close();

if (!$session) {
    header("Location: settings.php");
    exit;
}

// 獲取該場次的報名者列表及出席紀錄（包含未到時間）
$stmt = $conn->prepare("
    SELECT 
        aa.*, 
        sd.name as school_name_display,
        ar.attendance_status,
        ar.check_in_time,
        ar.absent_time,
        ar.notes as attendance_notes
    FROM admission_applications aa
    LEFT JOIN school_data sd ON aa.school = sd.school_code
    LEFT JOIN attendance_records ar ON aa.id = ar.application_id AND ar.session_id = ?
    WHERE aa.session_id = ? 
    ORDER BY aa.student_name ASC
");
$stmt->bind_param("ii", $session_id, $session_id);
$stmt->execute();
$registrations_result = $stmt->get_result();
$registrations = $registrations_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 判斷是否為歷史紀錄：以簽到時間作為基準，非今年份的區分到歷史資料
$current_year = date('Y');
$is_history = false;

// 檢查是否有簽到記錄，如果有則以簽到時間判斷，否則以場次日期判斷
$has_check_in = false;
$latest_check_in_year = null;

foreach ($registrations as $reg) {
    if (isset($reg['check_in_time']) && !empty($reg['check_in_time'])) {
        $has_check_in = true;
        $check_in_year = date('Y', strtotime($reg['check_in_time']));
        if ($latest_check_in_year === null || $check_in_year > $latest_check_in_year) {
            $latest_check_in_year = $check_in_year;
        }
    }
}

if ($has_check_in && $latest_check_in_year !== null) {
    // 如果有簽到記錄，以最新簽到時間的年份判斷
    $is_history = $latest_check_in_year < $current_year;
} else if (isset($session['session_date'])) {
    // 如果沒有簽到記錄，以場次日期判斷
    $session_year = date('Y', strtotime($session['session_date']));
    $is_history = $session_year < $current_year;
}

// 統計
$total_count = count($registrations);
$attended_count = 0;
$absent_count = 0;
foreach ($registrations as $reg) {
    if (isset($reg['attendance_status']) && $reg['attendance_status'] == 1) {
        $attended_count++;
    } else {
        $absent_count++;
    }
}

$conn->close();

// 設置頁面標題
$page_title = '出席紀錄管理 - ' . htmlspecialchars($session['session_name']);
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
        
        .stats-card {
            display: flex;
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-item {
            flex: 1;
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
        .stat-item.attended .value { color: var(--success-color); }
        .stat-item.absent .value { color: var(--danger-color); }
        
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
        
        .table-search { 
            display: flex; 
            gap: 8px; 
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
        
        .table-search input:focus {
            outline: none;
            border-color: #1890ff;
            box-shadow: 0 0 0 2px rgba(24,144,255,0.2);
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
        .btn-primary { background: var(--primary-color); color: white; border-color: var(--primary-color); }
        .btn-primary:hover { background: #40a9ff; border-color: #40a9ff; }
        .btn-secondary { background: #fff; color: #595959; border-color: #d9d9d9; }
        .btn-secondary:hover { background: #f5f5f5; border-color: #40a9ff; color: #40a9ff; }
        .btn-success { background: var(--success-color); color: white; border-color: var(--success-color); }
        .btn-success:hover { background: #73d13d; border-color: #73d13d; }

        .form-group { margin-bottom: 20px; }
        .form-label { display: block; margin-bottom: 8px; font-weight: 500; }
        .form-control { width: 100%; padding: 10px 12px; border: 1px solid #d9d9d9; border-radius: 6px; font-size: 14px; transition: border-color 0.3s; }
        .form-control:focus { outline: none; border-color: var(--primary-color); box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.2); }
        .form-row { display: flex; gap: 16px; }
        .form-row .form-group { flex: 1; }

        .message { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; font-weight: 500; }
        .message.success { background: #f6ffed; border: 1px solid #b7eb8f; color: var(--success-color); }
        .message.error { background: #fff2f0; border: 1px solid #ffccc7; color: var(--danger-color); }
        .message.warning { background: #fffbe6; border: 1px solid #ffe58f; color: var(--warning-color); }

        .status-badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500; }
        .status-attended { background: #f6ffed; color: var(--success-color); border: 1px solid #b7eb8f; }
        .status-absent { background: #fff2f0; color: var(--danger-color); border: 1px solid #ffccc7; }
        .status-unknown { background: #f5f5f5; color: var(--text-secondary-color); border: 1px solid #d9d9d9; }

        .attendance-select {
            padding: 6px 12px;
            border: 1px solid #d9d9d9;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
        }

        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.45); overflow-y: auto; }
        .modal-content { background-color: #fff; margin: 2% auto; padding: 0; border-radius: 8px; width: 90%; max-width: 700px; max-height: 95vh; box-shadow: 0 4px 12px rgba(0,0,0,0.15); display: flex; flex-direction: column; }
        .modal-header { padding: 16px 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; flex-shrink: 0; }
        .modal-title { font-size: 16px; font-weight: 600; }
        .close { color: var(--text-secondary-color); font-size: 20px; font-weight: bold; cursor: pointer; }
        .close:hover { color: var(--text-color); }
        .modal-body { padding: 24px; overflow-y: auto; flex: 1; min-height: 0; }
        .modal-footer { padding: 16px 24px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 8px; background: #fafafa; flex-shrink: 0; }
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
                        <a href="index.php">首頁</a> / <a href="settings.php">場次設定</a> / 出席紀錄管理
                        <?php if ($is_history): ?>
                            <span style="color: var(--warning-color); margin-left: 8px;">(歷史紀錄)</span>
                        <?php endif; ?>
                    </div>
                    <div class="table-search">
                        <input type="text" id="tableSearchInput" placeholder="搜尋姓名、Email..." onkeyup="filterTable()">
                        <a href="export_attendance_template.php?session_id=<?php echo $session_id; ?>" class="btn btn-secondary"><i class="fas fa-download"></i> 下載範本</a>
                        <a href="export_attendance.php?session_id=<?php echo $session_id; ?>" class="btn btn-secondary"><i class="fas fa-file-export"></i> 匯出出席紀錄</a>
                        <a href="activity_records.php?view=attendance" class="btn btn-secondary" style="background: var(--primary-color); color: white; border-color: var(--primary-color);"><i class="fas fa-chart-bar"></i> 出席統計圖</a>
                        <?php if (!$is_history): ?>
                            <button class="btn btn-success" onclick="showModal('uploadImageModal')"><i class="fas fa-image"></i> 上傳簽到表圖片</button>
                            <button class="btn btn-success" onclick="showModal('importExcelModal')"><i class="fas fa-file-excel"></i> 匯入 Excel</button>
                            <button class="btn btn-primary" onclick="saveAttendance()"><i class="fas fa-save"></i> 儲存變更</button>
                        <?php endif; ?>
                        <a href="settings.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> 返回</a>
                    </div>
                </div>

                <!-- 場次說明顯示 -->
                <?php if (!empty($session['description'])): ?>
                <div style="background: #e6f7ff; border: 1px solid #91d5ff; border-radius: 8px; padding: 16px; margin-bottom: 24px;">
                    <h4 style="margin: 0 0 8px 0; color: #1890ff; font-size: 16px; font-weight: 600;">
                        <i class="fas fa-info-circle"></i> 場次說明
                    </h4>
                    <p style="margin: 0; color: #595959; line-height: 1.6; white-space: pre-wrap;"><?php echo htmlspecialchars($session['description']); ?></p>
                </div>
                <?php endif; ?>

                <!-- 統計卡片 -->
                <div class="stats-card">
                    <div class="stat-item">
                        <h4>總報名人數</h4>
                        <div class="value"><?php echo $total_count; ?></div>
                    </div>
                    <div class="stat-item attended">
                        <h4>已出席</h4>
                        <div class="value"><?php echo $attended_count; ?></div>
                    </div>
                    <div class="stat-item absent">
                        <h4>未出席</h4>
                        <div class="value"><?php echo $absent_count; ?></div>
                    </div>
                </div>

                <div class="table-wrapper">
                    <div class="table-container">
                        <form id="attendanceForm" method="POST">
                            <input type="hidden" name="action" value="update_attendance">
                            <table class="table" id="attendanceTable">
                                <thead>
                                    <tr>
                                        <th>姓名</th>
                                        <th>Email</th>
                                        <th>電話</th>
                                        <th>就讀學校</th>
                                        <th>出席狀態</th>
                                        <th>簽到時間</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($registrations as $reg): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($reg['student_name']); ?></td>
                                        <td><?php echo htmlspecialchars($reg['email']); ?></td>
                                        <td><?php echo htmlspecialchars($reg['contact_phone']); ?></td>
                                        <td><?php echo htmlspecialchars($reg['school_name_display'] ?? $reg['school'] ?? '-'); ?></td>
                                        <td>
                                            <?php if ($is_history): ?>
                                                <span class="status-badge <?php echo (isset($reg['attendance_status']) && $reg['attendance_status'] == 1) ? 'status-attended' : 'status-absent'; ?>">
                                                    <?php echo (isset($reg['attendance_status']) && $reg['attendance_status'] == 1) ? '已到' : '未到'; ?>
                                                </span>
                                            <?php else: ?>
                                                <select name="attendance[<?php echo $reg['id']; ?>]" class="attendance-select">
                                                    <option value="0" <?php echo (!isset($reg['attendance_status']) || $reg['attendance_status'] == 0) ? 'selected' : ''; ?>>未到</option>
                                                    <option value="1" <?php echo (isset($reg['attendance_status']) && $reg['attendance_status'] == 1) ? 'selected' : ''; ?>>已到</option>
                                                </select>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php 
                                            // 顯示簽到時間或未到時間
                                            if (isset($reg['attendance_status']) && $reg['attendance_status'] == 1) {
                                                if (isset($reg['check_in_time']) && $reg['check_in_time']) {
                                                    echo '<span style="color: var(--success-color);">' . date('Y/m/d H:i', strtotime($reg['check_in_time'])) . '</span>';
                                                } else {
                                                    echo '-';
                                                }
                                            } else {
                                                if (isset($reg['absent_time']) && $reg['absent_time']) {
                                                    echo '<span style="color: var(--danger-color);">' . date('Y/m/d H:i', strtotime($reg['absent_time'])) . '</span>';
                                                } else {
                                                    echo '-';
                                                }
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </form>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- 上傳簽到表圖片 Modal -->
    <div id="uploadImageModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">上傳簽到表圖片</h3>
                <span class="close" onclick="closeModal('uploadImageModal')">&times;</span>
            </div>
            <form id="uploadImageForm" enctype="multipart/form-data">
                <input type="hidden" name="session_id" value="<?php echo $session_id; ?>">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">選擇簽到表圖片</label>
                        <input type="file" name="image" id="imageInput" class="form-control" accept="image/*" required>
                        <small style="color: var(--text-secondary-color); margin-top: 8px; display: block;">
                            支援格式：JPG、PNG、GIF、WebP（最大 10MB）<br>
                            <strong>簽到表格式說明：</strong><br>
                            • 每行格式：姓名 電話號碼（例如：無名 0900000000）<br>
                            • 系統會自動識別圖片中的文字<br>
                            • 根據姓名和電話號碼自動匹配報名者<br>
                            • 匹配成功者將自動標記為「已到」
                        </small>
                    </div>
                    <div id="imagePreview" style="margin-top: 16px; display: none;">
                        <img id="previewImg" src="" alt="預覽" style="max-width: 100%; max-height: 400px; border: 1px solid #d9d9d9; border-radius: 6px;">
                    </div>
                    <div id="uploadProgress" style="display: none; margin-top: 16px;">
                        <div style="background: #f0f0f0; border-radius: 4px; padding: 4px;">
                            <div id="progressBar" style="background: var(--primary-color); height: 20px; border-radius: 4px; width: 0%; transition: width 0.3s;"></div>
                        </div>
                        <p style="margin-top: 8px; color: var(--text-secondary-color);">正在識別圖片中的文字...</p>
                    </div>
                    <div id="uploadResult" style="margin-top: 16px; display: none;"></div>
                    <div id="ocrEditSection" style="margin-top: 16px; display: none; background: #fff3cd; padding: 16px; border-radius: 6px; border: 1px solid #ffc107;">
                        <div style="margin-bottom: 12px;">
                            <strong style="color: #856404;">OCR 識別結果不準確？</strong>
                            <p style="margin: 8px 0 0 0; color: #856404; font-size: 13px;">您可以手動編輯識別出的文字，然後重新解析和匹配。</p>
                        </div>
                        <div class="form-group" style="margin-bottom: 12px;">
                            <label class="form-label" style="font-weight: 600;">識別出的文字（可編輯）：</label>
                            <textarea id="ocrTextEdit" rows="8" style="width: 100%; padding: 8px; border: 1px solid #d9d9d9; border-radius: 4px; font-family: monospace; font-size: 13px; line-height: 1.6;" placeholder="每行格式：姓名 電話號碼&#10;例如：&#10;無名 0900000000&#10;張三 0912345678"></textarea>
                            <small style="color: #856404; margin-top: 4px; display: block;">
                                提示：每行一個記錄，格式為「姓名 電話號碼」，例如「無名 0900000000」
                            </small>
                        </div>
                        <div style="display: flex; gap: 8px;">
                            <button type="button" onclick="reparseOCRText()" class="btn btn-primary" style="flex: 1;">
                                <i class="fas fa-redo"></i> 重新解析並匹配
                            </button>
                            <button type="button" onclick="document.getElementById('ocrEditSection').style.display='none'" class="btn btn-secondary">
                                取消
                            </button>
                        </div>
                    </div>
                    <div id="debugInfo" style="margin-top: 16px; display: none; background: #f5f5f5; padding: 12px; border-radius: 6px; font-size: 12px; font-family: monospace; max-height: 300px; overflow-y: auto; border: 1px solid #d9d9d9;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <strong>調試信息：</strong>
                            <button onclick="document.getElementById('debugInfo').style.display='none'" style="background: none; border: none; color: #8c8c8c; cursor: pointer; font-size: 14px;">隱藏</button>
                        </div>
                        <pre id="debugContent" style="margin: 0; white-space: pre-wrap; word-wrap: break-word; line-height: 1.5;"></pre>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('uploadImageModal')">取消</button>
                    <button type="submit" class="btn btn-primary" id="uploadBtn">上傳並識別</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Excel 匯入 Modal -->
    <div id="importExcelModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">匯入 Excel</h3>
                <span class="close" onclick="closeModal('importExcelModal')">&times;</span>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_excel">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">選擇 Excel 檔案</label>
                        <input type="file" name="excel_file" class="form-control" accept=".xlsx,.xls,.csv" required>
                        <small style="color: var(--text-secondary-color); margin-top: 8px; display: block;">
                            支援格式：Excel (.xlsx, .xls) 或 CSV (.csv)<br>
                            檔案格式：第一行為標題，從第二行開始為資料<br>
                            欄位順序：姓名, Email, 出席狀態(是/否/1/0/已到/出席/true)<br>
                            <strong>注意：</strong>系統會根據姓名或 Email 自動匹配報名者
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('importExcelModal')">取消</button>
                    <button type="submit" class="btn btn-primary">匯入</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function filterTable() {
            const input = document.getElementById('tableSearchInput');
            const filter = input.value.toLowerCase();
            const table = document.getElementById('attendanceTable');
            
            if (!table) return;
            
            const tbody = table.getElementsByTagName('tbody')[0];
            if (!tbody) return;
            
            const rows = tbody.getElementsByTagName('tr');
            
            for (let i = 0; i < rows.length; i++) {
                const cells = rows[i].getElementsByTagName('td');
                let found = false;
                
                for (let j = 0; j < cells.length - 1; j++) { // 排除最後一列（簽到時間）
                    const cell = cells[j];
                    if (cell) {
                        const txtValue = cell.textContent || cell.innerText;
                        if (txtValue.toLowerCase().indexOf(filter) > -1) {
                            found = true;
                            break;
                        }
                    }
                }
                
                rows[i].style.display = found ? '' : 'none';
            }
        }

        function saveAttendance() {
            if (confirm('確定要儲存所有出席紀錄變更嗎？')) {
                document.getElementById('attendanceForm').submit();
            }
        }

        function showModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        function toggleDebugInfo() {
            const debugDiv = document.getElementById('debugInfo');
            if (debugDiv) {
                debugDiv.style.display = debugDiv.style.display === 'none' ? 'block' : 'none';
            }
        }
        
        function toggleErrorDebugInfo() {
            const debugDiv = document.getElementById('errorDebugInfo');
            if (debugDiv) {
                debugDiv.style.display = debugDiv.style.display === 'none' ? 'block' : 'none';
            }
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }

        // 圖片預覽功能
        document.getElementById('imageInput').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('previewImg').src = e.target.result;
                    document.getElementById('imagePreview').style.display = 'block';
                };
                reader.readAsDataURL(file);
            } else {
                document.getElementById('imagePreview').style.display = 'none';
            }
        });

        // 處理圖片上傳和 OCR 識別
        document.getElementById('uploadImageForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const uploadBtn = document.getElementById('uploadBtn');
            const uploadProgress = document.getElementById('uploadProgress');
            const uploadResult = document.getElementById('uploadResult');
            const imagePreview = document.getElementById('imagePreview');
            
            // 顯示進度條
            uploadProgress.style.display = 'block';
            uploadResult.style.display = 'none';
            uploadBtn.disabled = true;
            uploadBtn.textContent = '處理中...';
            
            // 模擬進度（實際進度由服務器控制）
            let progress = 0;
            const progressInterval = setInterval(() => {
                progress += 10;
                if (progress <= 90) {
                    document.getElementById('progressBar').style.width = progress + '%';
                }
            }, 200);
            
            fetch('process_attendance_image.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                clearInterval(progressInterval);
                document.getElementById('progressBar').style.width = '100%';
                
                setTimeout(() => {
                    uploadProgress.style.display = 'none';
                    
                    // 显示调试信息（如果有）
                    const debugInfo = document.getElementById('debugInfo');
                    const debugContent = document.getElementById('debugContent');
                    if (data.ocr_debug_info) {
                        let debugText = data.ocr_debug_info;
                        
                        // 如果有原始OCR文本，也显示
                        if (data.ocr_raw_text) {
                            debugText += '\n\n=== OCR 原始識別文本 ===\n';
                            debugText += data.ocr_raw_text;
                        }
                        
                        // 如果有解析后的数据，也显示
                        if (data.parsed_data && data.parsed_data.length > 0) {
                            debugText += '\n\n=== 解析後的數據 ===\n';
                            data.parsed_data.forEach((item, index) => {
                                debugText += `${index + 1}. 姓名：${item.name || '無名'}, 電話：${item.phone || '無'}\n`;
                            });
                        }
                        
                        debugContent.textContent = debugText;
                        debugInfo.style.display = 'block';
                    } else {
                        debugInfo.style.display = 'none';
                    }
                    
                    if (data.success) {
                        uploadResult.innerHTML = `
                            <div class="message success">
                                <strong>${data.message}</strong>
                                <div style="margin-top: 12px;">
                                    <p><strong>匹配成功：</strong>${data.matched_count} 筆</p>
                                    ${data.matched_count > 0 ? `
                                        <div style="max-height: 200px; overflow-y: auto; margin-top: 8px;">
                                            <table style="width: 100%; font-size: 12px;">
                                                <tr style="background: #fafafa;">
                                                    <th style="padding: 8px; text-align: left;">姓名</th>
                                                    <th style="padding: 8px; text-align: left;">電話</th>
                                                    <th style="padding: 8px; text-align: left;">匹配方式</th>
                                                </tr>
                                                ${data.matched_details.map(item => `
                                                    <tr>
                                                        <td style="padding: 6px;">${item.name}</td>
                                                        <td style="padding: 6px;">${item.phone}</td>
                                                        <td style="padding: 6px;">${item.matched_by === 'phone' ? '電話號碼' : '姓名'}</td>
                                                    </tr>
                                                `).join('')}
                                            </table>
                                        </div>
                                    ` : ''}
                                    ${data.unmatched_count > 0 ? `
                                        <p style="margin-top: 12px; color: var(--warning-color);">
                                            <strong>未匹配：</strong>${data.unmatched_count} 筆
                                        </p>
                                        ${data.unmatched_details.length <= 10 ? `
                                            <div style="max-height: 150px; overflow-y: auto; margin-top: 8px; font-size: 12px; color: var(--text-secondary-color);">
                                                ${data.unmatched_details.map(item => `
                                                    <div>${item.name || '未知'} - ${item.phone || '無電話'}</div>
                                                `).join('')}
                                            </div>
                                        ` : ''}
                                    ` : ''}
                                </div>
                            </div>
                        `;
                        uploadResult.style.display = 'block';
                        
                        // 3秒後刷新頁面以顯示更新後的數據
                        setTimeout(() => {
                            window.location.reload();
                        }, 3000);
                    } else {
                        let errorHtml = `
                            <div class="message error" style="max-height: 300px; overflow-y: auto; word-wrap: break-word;">
                                <strong>處理失敗：</strong><br>
                                <div style="margin-top: 8px; white-space: pre-wrap;">${data.message.replace(/\n/g, '<br>')}</div>
                            </div>
                        `;
                        
                        // 如果有调试信息，显示可展开的调试面板
                        if (data.ocr_debug_info) {
                            let debugContent = escapeHtml(data.ocr_debug_info);
                            
                            // 如果有原始OCR文本，也显示
                            if (data.ocr_raw_text) {
                                debugContent += '\n\n=== OCR 原始識別文本 ===\n';
                                debugContent += escapeHtml(data.ocr_raw_text);
                            }
                            
                            // 如果有解析后的数据，也显示
                            if (data.parsed_data && data.parsed_data.length > 0) {
                                debugContent += '\n\n=== 解析後的數據 ===\n';
                                data.parsed_data.forEach((item, index) => {
                                    debugContent += `${index + 1}. 姓名：${item.name || '無名'}, 電話：${item.phone || '無'}\n`;
                                });
                            }
                            
                            errorHtml += `
                                <div style="margin-top: 12px;">
                                    <button onclick="toggleErrorDebugInfo()" style="background: #f0f0f0; border: 1px solid #d9d9d9; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-size: 14px; width: 100%;">
                                        <i class="fas fa-info-circle"></i> 查看詳細調試信息
                                    </button>
                                    <div id="errorDebugInfo" style="display: none; margin-top: 12px; background: #f5f5f5; padding: 12px; border-radius: 6px; font-size: 11px; font-family: 'Courier New', monospace; max-height: 400px; overflow-y: auto; border: 1px solid #d9d9d9; word-wrap: break-word;">
                                        <pre style="margin: 0; white-space: pre-wrap; word-wrap: break-word; line-height: 1.6;">${debugContent}</pre>
                                    </div>
                                </div>
                            `;
                        }
                        
                        uploadResult.innerHTML = errorHtml;
                        uploadResult.style.display = 'block';
                    }
                    
                    uploadBtn.disabled = false;
                    uploadBtn.textContent = '上傳並識別';
                }, 500);
            })
            .catch(error => {
                clearInterval(progressInterval);
                uploadProgress.style.display = 'none';
                uploadResult.innerHTML = `
                    <div class="message error">
                        <strong>上傳失敗：</strong>${error.message || '網絡錯誤，請重試'}
                    </div>
                `;
                uploadResult.style.display = 'block';
                uploadBtn.disabled = false;
                uploadBtn.textContent = '上傳並識別';
            });
        });
    </script>
</body>
</html>

