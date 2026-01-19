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

// 獲取該場次的報名者列表及出席紀錄
$stmt = $conn->prepare("
    SELECT 
        aa.student_name,
        aa.email,
        aa.contact_phone,
        sd.name as school_name_display,
        aa.school,
        ar.attendance_status,
        ar.check_in_time,
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

$conn->close();

// 設定檔案名稱
$filename = '出席紀錄_' . htmlspecialchars($session['session_name']) . '_' . date('Ymd') . '.csv';
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

// 輸出標題行
fputcsv($output, ['姓名', 'Email', '電話', '就讀學校', '出席狀態', '簽到時間', '備註'], ',');

// 輸出資料行
foreach ($registrations as $reg) {
    $status = '未到';
    if (isset($reg['attendance_status']) && $reg['attendance_status'] == 1) {
        $status = '已到';
    }
    
    $check_in_time = '';
    if (isset($reg['check_in_time']) && $reg['check_in_time']) {
        $check_in_time = date('Y/m/d H:i', strtotime($reg['check_in_time']));
    }
    
    $school_name = $reg['school_name_display'] ?? $reg['school'] ?? '';
    $notes = $reg['attendance_notes'] ?? '';
    
    fputcsv($output, [
        $reg['student_name'],
        $reg['email'],
        $reg['contact_phone'] ?? '',
        $school_name,
        $status,
        $check_in_time,
        $notes
    ], ',');
}

fclose($output);
exit;
?>


