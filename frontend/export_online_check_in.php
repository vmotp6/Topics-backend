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

// 獲取線上簽到記錄
// 注意：只顯示當前年份的記錄，簽到時間從 attendance_records 資料表抓取
$current_year = date('Y');
$online_check_ins = [];
$check_table_exists = $conn->query("SHOW TABLES LIKE 'online_check_in_records'");
if ($check_table_exists && $check_table_exists->num_rows > 0) {
    $oc_has_school = false;
    $oc_has_grade = false;
    $oc_cols = $conn->query("SHOW COLUMNS FROM online_check_in_records");
    if ($oc_cols) {
        while ($c = $oc_cols->fetch_assoc()) {
            if (($c['Field'] ?? '') === 'school') $oc_has_school = true;
            if (($c['Field'] ?? '') === 'grade') $oc_has_grade = true;
        }
        $oc_cols->free();
    }
    $school_grade_sel = ($oc_has_school ? 'oc.school,' : '') . ($oc_has_grade ? 'oc.grade,' : '');
    $school_join = $oc_has_school ? ' LEFT JOIN school_data sd_oc ON oc.school = sd_oc.school_code' : '';
    $school_display_sel = $oc_has_school ? 'COALESCE(sd_oc.name, oc.school) AS school_display,' : '';
    $grade_join = $oc_has_grade ? ' LEFT JOIN identity_options io_oc ON oc.grade = io_oc.code' : '';
    $grade_display_sel = $oc_has_grade ? 'COALESCE(io_oc.name, oc.grade) AS grade_display,' : '';
    $check_in_stmt = $conn->prepare("
        SELECT 
            oc.id,
            oc.name,
            oc.email,
            oc.phone,
            " . $school_grade_sel . $school_display_sel . $grade_display_sel . "
            oc.is_registered,
            oc.application_id,
            oc.notes,
            oc.created_at as oc_created_at,
            ar.check_in_time,
            aa.student_name as registered_name,
            aa.email as registered_email,
            aa.contact_phone as registered_phone
        FROM online_check_in_records oc
        " . $school_join . $grade_join . "
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
    $check_in_stmt->bind_param("iii", $session_id, $current_year, $current_year);
    $check_in_stmt->execute();
    $check_in_result = $check_in_stmt->get_result();
    $online_check_ins = $check_in_result->fetch_all(MYSQLI_ASSOC);
    $check_in_stmt->close();
}

$conn->close();

// 設定檔案名稱
$filename = '線上簽到記錄_' . htmlspecialchars($session['session_name']) . '_' . date('Ymd') . '.csv';
$filename = mb_convert_encoding($filename, 'Big5', 'UTF-8');

// 設定 HTTP headers
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// 開啟輸出緩衝
$output = fopen('php://output', 'w');

// 輸出 BOM (讓 Excel 正確顯示 UTF-8)
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// 輸出標題行（含就讀學校、年級）
fputcsv($output, ['姓名', 'Email', '電話', '就讀學校', '年級', '報名狀態', '簽到時間', '備註'], ',');

// 輸出資料行
foreach ($online_check_ins as $check_in) {
    $status = $check_in['is_registered'] ? '有報名' : '未報名';
    $check_in_time = '';
    if (!empty($check_in['check_in_time'])) {
        $check_in_time = date('Y/m/d H:i', strtotime($check_in['check_in_time']));
    } elseif (!empty($check_in['oc_created_at'])) {
        $check_in_time = date('Y/m/d H:i', strtotime($check_in['oc_created_at']));
    }
    $notes = $check_in['notes'] ?? '';
    $school_display = isset($check_in['school_display']) ? ($check_in['school_display'] ?? '') : (isset($check_in['school']) ? ($check_in['school'] ?? '') : '');
    $grade_display = isset($check_in['grade_display']) ? ($check_in['grade_display'] ?? '') : (isset($check_in['grade']) ? ($check_in['grade'] ?? '') : '');
    fputcsv($output, [
        $check_in['name'],
        $check_in['email'] ?? '',
        $check_in['phone'] ?? '',
        $school_display,
        $grade_display,
        $status,
        $check_in_time,
        $notes
    ], ',');
}

fclose($output);
exit;
?>

