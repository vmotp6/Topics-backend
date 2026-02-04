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
                        if ($check_stmt === false) {
                            throw new Exception("準備 SQL 語句失敗：" . $conn->error);
                        }
                        $check_stmt->bind_param("ii", $session_id, $application_id);
                        $check_stmt->execute();
                        $exists = $check_stmt->get_result()->fetch_assoc();
                        $check_stmt->close();
                        
                        if ($exists) {
                            // 更新現有紀錄
                            $update_stmt = $conn->prepare("UPDATE attendance_records SET attendance_status = ?, check_in_time = ?, absent_time = ? WHERE session_id = ? AND application_id = ?");
                            if ($update_stmt === false) {
                                throw new Exception("準備 SQL 語句失敗：" . $conn->error);
                            }
                            $update_stmt->bind_param("issii", $attendance_status, $check_in_time, $absent_time, $session_id, $application_id);
                            $update_stmt->execute();
                            $update_stmt->close();
                        } else {
                            // 新增紀錄
                            $insert_stmt = $conn->prepare("INSERT INTO attendance_records (session_id, application_id, attendance_status, check_in_time, absent_time) VALUES (?, ?, ?, ?, ?)");
                            if ($insert_stmt === false) {
                                throw new Exception("準備 SQL 語句失敗：" . $conn->error);
                            }
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
                        if ($find_stmt === false) {
                            throw new Exception("準備 SQL 語句失敗：" . $conn->error);
                        }
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
                            if ($check_stmt === false) {
                                throw new Exception("準備 SQL 語句失敗：" . $conn->error);
                            }
                            $check_stmt->bind_param("ii", $session_id, $application_id);
                            $check_stmt->execute();
                            $exists = $check_stmt->get_result()->fetch_assoc();
                            $check_stmt->close();
                            
                            if ($exists) {
                                $update_stmt = $conn->prepare("UPDATE attendance_records SET attendance_status = ?, check_in_time = ?, absent_time = ? WHERE session_id = ? AND application_id = ?");
                                if ($update_stmt === false) {
                                    throw new Exception("準備 SQL 語句失敗：" . $conn->error);
                                }
                                $update_stmt->bind_param("issii", $attendance_status, $check_in_time, $absent_time, $session_id, $application_id);
                                $update_stmt->execute();
                                $update_stmt->close();
                            } else {
                                $insert_stmt = $conn->prepare("INSERT INTO attendance_records (session_id, application_id, attendance_status, check_in_time, absent_time) VALUES (?, ?, ?, ?, ?)");
                                if ($insert_stmt === false) {
                                    throw new Exception("準備 SQL 語句失敗：" . $conn->error);
                                }
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
        // 使用兼容方式檢查事物狀態
        if (method_exists($conn, 'in_transaction') && $conn->in_transaction()) {
            $conn->rollback();
        } else {
            // 如果沒有 in_transaction 方法，直接嘗試回滾
            @$conn->rollback();
        }
        $message = "操作失敗：" . $e->getMessage();
        $messageType = "error";
    }
}

// 獲取場次資訊
$stmt = $conn->prepare("SELECT * FROM admission_sessions WHERE id = ?");
if ($stmt === false) {
    die("準備 SQL 語句失敗：" . $conn->error);
}
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
// 注意：只顯示與場次年份相同的報名記錄和簽到記錄
$session_year = date('Y', strtotime($session['session_date']));
$stmt = $conn->prepare("
    SELECT 
        aa.*, 
        aa.grade as grade_code,
        COALESCE(io.name, aa.grade) as grade,
        aa.notes as application_notes,
        sd.name as school_name_display,
        ar.attendance_status,
        ar.check_in_time,
        ar.absent_time,
        ar.notes as attendance_notes,
        as_session.session_type
    FROM admission_applications aa
    LEFT JOIN school_data sd ON aa.school = sd.school_code
    LEFT JOIN identity_options io ON aa.grade = io.code
    LEFT JOIN admission_sessions as_session ON aa.session_id = as_session.id
    LEFT JOIN attendance_records ar ON aa.id = ar.application_id 
        AND ar.session_id = ? 
        AND (
            (ar.check_in_time IS NOT NULL AND YEAR(ar.check_in_time) = ?)
            OR (ar.check_in_time IS NULL AND ar.absent_time IS NOT NULL AND YEAR(ar.absent_time) = ?)
            OR (ar.check_in_time IS NULL AND ar.absent_time IS NULL)
        )
    WHERE aa.session_id = ? 
    AND YEAR(aa.created_at) = ?
    ORDER BY 
        CASE 
            WHEN aa.grade IS NOT NULL AND (aa.grade LIKE '%國三%' OR aa.grade = '3' OR aa.grade LIKE 'G3%' OR aa.grade LIKE '%G3%' OR COALESCE(io.name, '') LIKE '%國三%') THEN 1
            WHEN aa.grade IS NOT NULL AND (aa.grade LIKE '%國二%' OR aa.grade = '2' OR aa.grade LIKE 'G2%' OR aa.grade LIKE '%G2%' OR COALESCE(io.name, '') LIKE '%國二%') THEN 2
            WHEN aa.grade IS NOT NULL AND (aa.grade LIKE '%國一%' OR aa.grade = '1' OR aa.grade LIKE 'G1%' OR aa.grade LIKE '%G1%' OR COALESCE(io.name, '') LIKE '%國一%') THEN 3
            ELSE 4
        END ASC,
        aa.student_name ASC
");
if ($stmt === false) {
    die("準備 SQL 語句失敗：" . $conn->error);
}
$stmt->bind_param("iiiii", $session_id, $session_year, $session_year, $session_id, $session_year);
$stmt->execute();
$registrations_result = $stmt->get_result();
$registrations = $registrations_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 計算每個學生的意願度分數
foreach ($registrations as &$reg) {
    $score = 0;
    
    // 1. 有報名：+1
    $score += 1;
    
    // 2. 有簽到：+3
    if (isset($reg['attendance_status']) && $reg['attendance_status'] == 1) {
        $score += 3;
    }
    
    // 3. 實體場：+2
    if (isset($reg['session_type']) && $reg['session_type'] == 2) {
        $score += 2;
    }
    
    // 4. 參加 2 場以上：+2（需要查詢該學生報名的場次數，使用 email 或電話匹配）
    $email = $reg['email'] ?? '';
    $phone = $reg['contact_phone'] ?? '';
    if (!empty($email) || !empty($phone)) {
        $count_stmt = $conn->prepare("
            SELECT COUNT(DISTINCT session_id) as session_count
            FROM admission_applications
            WHERE YEAR(created_at) = ? 
            AND (email = ? OR contact_phone = ?)
        ");
        if ($count_stmt) {
            $count_stmt->bind_param("iss", $session_year, $email, $phone);
            $count_stmt->execute();
            $count_result = $count_stmt->get_result();
            if ($count_row = $count_result->fetch_assoc()) {
                if ($count_row['session_count'] >= 2) {
                    $score += 2;
                }
            }
            $count_stmt->close();
        }
    }
    
    // 5. 有選科系：+1
    if (!empty($reg['course_priority_1']) || !empty($reg['course_priority_2'])) {
        $score += 1;
    }
    
    // 6. 國三生：+1
    $grade_text = $reg['grade'] ?? '';
    $grade_code = $reg['grade_code'] ?? '';
    if (strpos($grade_text, '國三') !== false || 
        preg_match('/\b3\b/', $grade_text) || 
        strpos($grade_code, 'G3') !== false || 
        $grade_code === '3' ||
        preg_match('/\b3\b/', $grade_code)) {
        $score += 1;
    }
    
    // 判斷意願度等級
    if ($score >= 6) {
        $reg['willingness_level'] = '高意願';
        $reg['willingness_score'] = $score;
        $reg['willingness_order'] = 1; // 用於排序：1=高, 2=中, 3=低
    } elseif ($score >= 4) {
        $reg['willingness_level'] = '中意願';
        $reg['willingness_score'] = $score;
        $reg['willingness_order'] = 2;
    } else {
        $reg['willingness_level'] = '低意願';
        $reg['willingness_score'] = $score;
        $reg['willingness_order'] = 3;
    }
    
    // 計算年級排序順序：國三優先
    $grade_text = $reg['grade'] ?? '';
    $grade_code = $reg['grade_code'] ?? '';
    if (strpos($grade_text, '國三') !== false || 
        preg_match('/\b3\b/', $grade_text) || 
        strpos($grade_code, 'G3') !== false || 
        $grade_code === '3' ||
        preg_match('/\b3\b/', $grade_code)) {
        $reg['grade_order'] = 1; // 國三
    } elseif (strpos($grade_text, '國二') !== false || 
              preg_match('/\b2\b/', $grade_text) || 
              strpos($grade_code, 'G2') !== false || 
              $grade_code === '2' ||
              preg_match('/\b2\b/', $grade_code)) {
        $reg['grade_order'] = 2; // 國二
    } elseif (strpos($grade_text, '國一') !== false || 
              preg_match('/\b1\b/', $grade_text) || 
              strpos($grade_code, 'G1') !== false || 
              $grade_code === '1' ||
              preg_match('/\b1\b/', $grade_code)) {
        $reg['grade_order'] = 3; // 國一
    } else {
        $reg['grade_order'] = 4; // 其他
    }
}
unset($reg); // 解除引用

// 排序：先按年級（國三優先），再按意願度（高>中>低）
usort($registrations, function($a, $b) {
    // 先按年級排序
    if ($a['grade_order'] != $b['grade_order']) {
        return $a['grade_order'] - $b['grade_order'];
    }
    // 再按意願度排序
    if ($a['willingness_order'] != $b['willingness_order']) {
        return $a['willingness_order'] - $b['willingness_order'];
    }
    // 最後按姓名排序
    return strcmp($a['student_name'], $b['student_name']);
});

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

// 獲取線上簽到記錄（不管有沒有報名）
// 注意：簽到時間從 attendance_records 資料表抓取，而不是 online_check_in_records
// 只顯示當前年份的記錄
$current_year = date('Y');
$online_check_ins = [];
$check_table_exists = $conn->query("SHOW TABLES LIKE 'online_check_in_records'");
if ($check_table_exists && $check_table_exists->num_rows > 0) {
    $check_in_stmt = $conn->prepare("
        SELECT 
            oc.id,
            oc.name,
            oc.email,
            oc.phone,
            oc.is_registered,
            oc.application_id,
            oc.notes,
            oc.created_at as oc_created_at,
            ar.check_in_time,
            aa.student_name as registered_name,
            aa.email as registered_email,
            aa.contact_phone as registered_phone,
            aa.notes as application_notes
        FROM online_check_in_records oc
        LEFT JOIN admission_applications aa ON oc.application_id = aa.id
        LEFT JOIN attendance_records ar ON oc.session_id = ar.session_id 
            AND oc.application_id = ar.application_id
            AND ar.attendance_status = 1
        WHERE oc.session_id = ?
        AND (
            (ar.check_in_time IS NOT NULL AND YEAR(ar.check_in_time) = ?)
            OR (ar.check_in_time IS NULL AND YEAR(oc.created_at) = ?)
        )
        ORDER BY COALESCE(ar.check_in_time, oc.created_at) DESC
    ");
    if ($check_in_stmt === false) {
        // 如果準備語句失敗，記錄錯誤但不中斷執行
        error_log("準備線上簽到記錄 SQL 語句失敗：" . $conn->error);
    } else {
        $check_in_stmt->bind_param("iii", $session_id, $current_year, $current_year);
        $check_in_stmt->execute();
        $check_in_result = $check_in_stmt->get_result();
        $online_check_ins = $check_in_result->fetch_all(MYSQLI_ASSOC);
        $check_in_stmt->close();
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
        .btn-success { background: #dd9606d6; color: white; border-color: #dd9606d6; }
        .btn-success:hover { background:#dd9606d6; border-color: #dd9606d6; }
        .btn-excel { background: #73d13d; color: white; border-color: #73d13d;  }
        .btn-excel:hover { background:#73d13d;  border-color: #73d13d;  }

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
        
        .grade-badge { padding: 4px 10px; border-radius: 4px; font-size: 13px; font-weight: 600; display: inline-block; }
        .grade-g3 { background: #e6f7ff; color: #1890ff; border: 1px solid #91d5ff; }
        .grade-g2 { background: #f6ffed; color: #52c41a; border: 1px solid #b7eb8f; }
        .grade-g1 { background: #fff7e6; color: #fa8c16; border: 1px solid #ffd591; }
        .grade-other { background: #f5f5f5; color: var(--text-secondary-color); border: 1px solid #d9d9d9; }
        
        .willingness-badge { padding: 4px 10px; border-radius: 4px; font-size: 13px; font-weight: 600; display: inline-block; }
        .willingness-high { background: #f6ffed; color: #52c41a; border: 1px solid #b7eb8f; }
        .willingness-medium { background: #fff7e6; color: #fa8c16; border: 1px solid #ffd591; }
        .willingness-low { background: #fff2f0; color: #ff4d4f; border: 1px solid #ffccc7; }

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
                        
                        <a href="export_attendance.php?session_id=<?php echo $session_id; ?>" class="btn btn-secondary"><i class="fas fa-file-export"></i> 匯出出席紀錄</a>
                        <!--<a href="activity_records.php?view=attendance" class="btn btn-secondary" style="background: var(--primary-color); color: white; border-color: var(--primary-color);"><i class="fas fa-chart-bar"></i> 出席統計圖</a>-->
                        <?php if (!$is_history): ?>
                            <a href="online_check_in.php?session_id=<?php echo $session_id; ?>" target="_blank" class="btn btn-success"><i class="fas fa-check-circle"></i> 線上簽到表單</a>
                            <button class="btn btn-secondary" onclick="toggleCheckInRecords()"><i class="fas fa-list"></i> 查看簽到表記錄</button>
                            <?php if (!empty($online_check_ins)): ?>
                                <a href="export_online_check_in.php?session_id=<?php echo $session_id; ?>" class="btn btn-excel"><i class="fas fa-file-excel"></i> 下載簽到表 Excel</a>
                                <button class="btn btn-primary" onclick="syncCheckInRecords()"><i class="fas fa-sync-alt"></i> 比對並同步簽到記錄</button>
                            <?php endif; ?>
                            <button class="btn btn-primary" onclick="saveAttendance()"><i class="fas fa-save"></i> 儲存變更</button>
                            <a href="absent_reminder.php?session_id=<?php echo $session_id; ?>" class="btn" style="background: var(--danger-color); color: white; border-color: var(--danger-color);">
                                <i class="fas fa-exclamation-triangle"></i> 未到警示
                            </a>
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
                <div class="filter-group" style="margin-bottom:15px; margin-left:1275px;">
                        <select id="filterGrade" class="form-control" style="width: auto; padding: 8px 12px; margin: 0;" onchange="filterTable()">
                            <option value="">全部年級</option>
                            <option value="國三">國三</option>
                            <option value="國二">國二</option>
                            <option value="國一">國一</option>
                            <option value="其他">其他</option>
                        </select>
                        <select id="filterWillingness" class="form-control" style="width: auto; padding: 8px 12px; margin: 0;" onchange="filterTable()">
                            <option value="">全部意願度</option>
                            <option value="高意願">高意願</option>
                            <option value="中意願">中意願</option>
                            <option value="低意願">低意願</option>
                        </select>
                        <select id="filterAttendance" class="form-control" style="width: auto; padding: 8px 12px; margin: 0;" onchange="filterTable()">
                            <option value="">全部狀態</option>
                            <option value="已到">已到</option>
                            <option value="未到">未到</option>
                        </select>
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
                                        <th>年級</th>
                                        <th>意願度</th>
                                        <th>出席狀態</th>
                                        <th>簽到時間</th>
                                        <th>備註</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($registrations as $reg): ?>
                                    <?php
                                    // 判斷年級類別用於篩選
                                    $grade_text = $reg['grade'] ?? '';
                                    $grade_code = $reg['grade_code'] ?? '';
                                    $grade_filter = '其他';
                                    if (strpos($grade_text, '國三') !== false || 
                                        preg_match('/\b3\b/', $grade_text) || 
                                        strpos($grade_code, 'G3') !== false || 
                                        $grade_code === '3' ||
                                        preg_match('/\b3\b/', $grade_code)) {
                                        $grade_filter = '國三';
                                    } elseif (strpos($grade_text, '國二') !== false || 
                                             preg_match('/\b2\b/', $grade_text) || 
                                             strpos($grade_code, 'G2') !== false || 
                                             $grade_code === '2' ||
                                             preg_match('/\b2\b/', $grade_code)) {
                                        $grade_filter = '國二';
                                    } elseif (strpos($grade_text, '國一') !== false || 
                                             preg_match('/\b1\b/', $grade_text) || 
                                             strpos($grade_code, 'G1') !== false || 
                                             $grade_code === '1' ||
                                             preg_match('/\b1\b/', $grade_code)) {
                                        $grade_filter = '國一';
                                    }
                                    
                                    // 判斷出席狀態
                                    $attendance_status_text = '未到';
                                    if (isset($reg['attendance_status']) && $reg['attendance_status'] == 1) {
                                        $attendance_status_text = '已到';
                                    }
                                    
                                    // 意願度
                                    $willingness_level = $reg['willingness_level'] ?? '低意願';
                                    ?>
                                    <tr data-grade="<?php echo htmlspecialchars($grade_filter); ?>" 
                                        data-willingness="<?php echo htmlspecialchars($willingness_level); ?>" 
                                        data-attendance="<?php echo htmlspecialchars($attendance_status_text); ?>">
                                        <td><?php echo htmlspecialchars($reg['student_name']); ?></td>
                                        <td><?php echo htmlspecialchars($reg['email']); ?></td>
                                        <td><?php echo htmlspecialchars($reg['contact_phone']); ?></td>
                                        <td><?php echo htmlspecialchars($reg['school_name_display'] ?? $reg['school'] ?? '-'); ?></td>
                                        <td>
                                            <?php
                                            $grade_text = $reg['grade'] ?? '';
                                            $grade_code = $reg['grade_code'] ?? '';
                                            $grade_class = 'grade-other';
                                            
                                            if (!empty($grade_text) || !empty($grade_code)) {
                                                // 檢查國三
                                                if (strpos($grade_text, '國三') !== false || 
                                                    preg_match('/\b3\b/', $grade_text) || 
                                                    strpos($grade_code, 'G3') !== false || 
                                                    $grade_code === '3' ||
                                                    preg_match('/\b3\b/', $grade_code)) {
                                                    $grade_class = 'grade-g3';
                                                } 
                                                // 檢查國二
                                                elseif (strpos($grade_text, '國二') !== false || 
                                                       preg_match('/\b2\b/', $grade_text) || 
                                                       strpos($grade_code, 'G2') !== false || 
                                                       $grade_code === '2' ||
                                                       preg_match('/\b2\b/', $grade_code)) {
                                                    $grade_class = 'grade-g2';
                                                } 
                                                // 檢查國一
                                                elseif (strpos($grade_text, '國一') !== false || 
                                                       preg_match('/\b1\b/', $grade_text) || 
                                                       strpos($grade_code, 'G1') !== false || 
                                                       $grade_code === '1' ||
                                                       preg_match('/\b1\b/', $grade_code)) {
                                                    $grade_class = 'grade-g1';
                                                }
                                            }
                                            ?>
                                            <span class="grade-badge <?php echo $grade_class; ?>">
                                                <?php echo !empty($grade_text) ? htmlspecialchars($grade_text) : (!empty($grade_code) ? htmlspecialchars($grade_code) : '-'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                            $willingness_level = $reg['willingness_level'] ?? '低意願';
                                            $willingness_score = $reg['willingness_score'] ?? 0;
                                            $willingness_class = 'willingness-low';
                                            
                                            if ($willingness_level === '高意願') {
                                                $willingness_class = 'willingness-high';
                                            } elseif ($willingness_level === '中意願') {
                                                $willingness_class = 'willingness-medium';
                                            }
                                            ?>
                                            <span class="willingness-badge <?php echo $willingness_class; ?>" title="分數：<?php echo $willingness_score; ?>">
                                                <?php echo htmlspecialchars($willingness_level); ?> 
                                            </span>
                                        </td>
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
                                        <td>
                                            <?php 
                                            // 顯示備註，優先顯示 application_notes（用於標記未報名但有來）
                                            $notes = $reg['application_notes'] ?? $reg['attendance_notes'] ?? '';
                                            if (!empty($notes)) {
                                                // 如果是未報名但有來的標記，用特殊樣式顯示
                                                if (strpos($notes, '未報名但有來') !== false || strpos($notes, '無報名但有來') !== false) {
                                                    echo '<span style="color: var(--warning-color); font-weight: 500; background: #fffbe6; padding: 4px 8px; border-radius: 4px; border: 1px solid #ffe58f; display: inline-block;">';
                                                    echo '<i class="fas fa-exclamation-triangle"></i> ' . htmlspecialchars($notes);
                                                    echo '</span>';
                                                } else {
                                                    echo '<span style="color: var(--text-secondary-color);">' . htmlspecialchars($notes) . '</span>';
                                                }
                                            } else {
                                                echo '<span style="color: var(--text-secondary-color);">-</span>';
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

                <!-- 線上簽到記錄區塊 -->
                <?php if (!empty($online_check_ins)): ?>
                <div class="table-wrapper" id="checkInRecordsSection" style="margin-top: 24px; display: none;">
                    <div style="padding: 16px 24px; border-bottom: 1px solid var(--border-color); background: #fafafa;">
                        <h3 style="margin: 0; font-size: 16px; font-weight: 600;">
                            <i class="fas fa-check-circle"></i> 線上簽到記錄
                            <span style="font-size: 14px; font-weight: normal; color: var(--text-secondary-color); margin-left: 8px;">
                                (共 <?php echo count($online_check_ins); ?> 筆)
                            </span>
                        </h3>
                        <p style="margin: 8px 0 0 0; font-size: 13px; color: var(--text-secondary-color);">
                            此區塊顯示所有透過線上簽到表單簽到的記錄，包含有報名和未報名的人員
                        </p>
                    </div>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>姓名</th>
                                    <th>Email</th>
                                    <th>電話</th>
                                    <th>報名狀態</th>
                                    <th>簽到時間</th>
                                    <th>備註</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($online_check_ins as $check_in): ?>
                                <tr>
                                    <td>
                                        <?php echo htmlspecialchars($check_in['name']); ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($check_in['email'] ?: '-'); ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($check_in['phone'] ?: '-'); ?>
                                    </td>
                                    <td>
                                        <?php 
                                        // 判斷是否為真正報名：必須 is_registered = 1 且 application_id 存在且對應的報名記錄存在
                                        // 且報名記錄的 notes 不包含「未報名但有來」（表示不是自動創建的）
                                        $is_truly_registered = false;
                                        if ($check_in['is_registered'] && !empty($check_in['application_id'])) {
                                            $app_notes = $check_in['application_notes'] ?? '';
                                            // 如果報名記錄存在且 notes 不包含「未報名但有來」，才是真正報名
                                            if (!empty($check_in['registered_name']) && strpos($app_notes, '未報名但有來') === false) {
                                                $is_truly_registered = true;
                                            }
                                        }
                                        ?>
                                        <?php if ($is_truly_registered): ?>
                                            <span class="status-badge status-attended">
                                                <i class="fas fa-check"></i> 有報名
                                            </span>
                                        <?php else: ?>
                                            <span class="status-badge" style="background: #fffbe6; color: var(--warning-color); border: 1px solid #ffe58f;">
                                                <i class="fas fa-exclamation-triangle"></i> 未報名
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span style="color: var(--success-color);">
                                            <?php 
                                            // 優先使用 attendance_records 的簽到時間，如果沒有則使用 online_check_in_records 的建立時間
                                            $check_in_time = $check_in['check_in_time'] ?? $check_in['oc_created_at'] ?? null;
                                            if ($check_in_time) {
                                                echo date('Y/m/d H:i', strtotime($check_in_time));
                                            } else {
                                                echo '-';
                                            }
                                            ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($check_in['notes'] ?: '-'); ?>
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
            const searchFilter = input.value.toLowerCase();
            const gradeFilter = document.getElementById('filterGrade').value;
            const willingnessFilter = document.getElementById('filterWillingness').value;
            const attendanceFilter = document.getElementById('filterAttendance').value;
            
            const table = document.getElementById('attendanceTable');
            if (!table) return;
            
            const tbody = table.getElementsByTagName('tbody')[0];
            if (!tbody) return;
            
            const rows = tbody.getElementsByTagName('tr');
            let visibleCount = 0;
            
            for (let i = 0; i < rows.length; i++) {
                const row = rows[i];
                let show = true;
                
                // 文字搜尋篩選
                if (searchFilter) {
                    const cells = row.getElementsByTagName('td');
                    let found = false;
                    for (let j = 0; j < cells.length; j++) {
                        const cell = cells[j];
                        if (cell) {
                            const txtValue = cell.textContent || cell.innerText;
                            if (txtValue.toLowerCase().indexOf(searchFilter) > -1) {
                                found = true;
                                break;
                            }
                        }
                    }
                    if (!found) {
                        show = false;
                    }
                }
                
                // 年級篩選
                if (show && gradeFilter) {
                    const rowGrade = row.getAttribute('data-grade');
                    if (rowGrade !== gradeFilter) {
                        show = false;
                    }
                }
                
                // 意願度篩選
                if (show && willingnessFilter) {
                    const rowWillingness = row.getAttribute('data-willingness');
                    if (rowWillingness !== willingnessFilter) {
                        show = false;
                    }
                }
                
                // 出席狀態篩選
                if (show && attendanceFilter) {
                    const rowAttendance = row.getAttribute('data-attendance');
                    if (rowAttendance !== attendanceFilter) {
                        show = false;
                    }
                }
                
                row.style.display = show ? '' : 'none';
                if (show) visibleCount++;
            }
            
            // 更新顯示的記錄數（可選）
            // console.log('顯示 ' + visibleCount + ' 筆記錄');
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
        
        function toggleCheckInRecords() {
            const section = document.getElementById('checkInRecordsSection');
            if (section) {
                if (section.style.display === 'none') {
                    section.style.display = 'block';
                } else {
                    section.style.display = 'none';
                }
            }
        }
        
        function syncCheckInRecords() {
            if (!confirm('確定要比對並同步簽到記錄嗎？系統會根據姓名和電話比對線上簽到記錄與報名資料，並自動更新出席狀態。')) {
                return;
            }
            
            const sessionId = <?php echo $session_id; ?>;
            const btn = event.target.closest('button');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 比對中...';
            
            fetch(`sync_check_in_records.php?session_id=${sessionId}`)
                .then(response => response.json())
                .then(data => {
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                    
                    if (data.success) {
                        alert(data.message);
                        // 刷新頁面以顯示更新後的資料
                        setTimeout(() => {
                            window.location.reload();
                        }, 1000);
                    } else {
                        alert('比對失敗：' + data.message);
                    }
                })
                .catch(error => {
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                    alert('發生錯誤：' + error.message);
                });
        }

        // 自動發送：進入頁面即對「國三＋高意願」且尚未寄過者發送
        document.addEventListener('DOMContentLoaded', function () {
            const isHistory = <?php echo $is_history ? 'true' : 'false'; ?>;
            if (isHistory) return;

            const sessionId = <?php echo (int)$session_id; ?>;
            fetch(`send_enrollment_invitation.php?session_id=${sessionId}`)
                .then(response => response.json())
                .then(data => {
                    if (!data || !data.success) {
                        // 不阻斷操作，只提示失敗
                        const msg = (data && data.message) ? data.message : '未知錯誤';
                        alert('自動發送就讀意願邀請失敗：' + msg);
                        return;
                    }
                    // 僅在有失敗時提示（成功或 0 筆就靜默）
                    if ((data.failed_count || 0) > 0) {
                        const details = (data.errors && data.errors.length) ? ('\n\n錯誤詳情：\n' + data.errors.join('\n')) : '';
                        alert(`自動發送完成（部分失敗）\n成功：${data.sent_count || 0} 筆\n失敗：${data.failed_count || 0} 筆${details}`);
                    }
                })
                .catch(err => {
                    alert('自動發送就讀意願邀請發生錯誤：' + (err && err.message ? err.message : String(err)));
                });
        });
        
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>

