<?php
/**
 * 依姓名、電話查詢該場次報名資料，回傳學校與年級供簽到表自動帶入
 * GET session_id= & name= & phone=
 * 回傳 JSON: { found: bool, school: code, school_display: name, grade: code, grade_display: name }
 */
header('Content-Type: application/json; charset=utf-8');

$config_paths = [
    __DIR__ . '/config.php',
    __DIR__ . '/../../Topics-frontend/frontend/config.php',
];
$config_path = null;
foreach ($config_paths as $p) {
    if (file_exists($p)) { $config_path = $p; break; }
}
if (!$config_path || !file_exists($config_path)) {
    echo json_encode(['found' => false]);
    exit;
}
require_once $config_path;

if (!function_exists('getDatabaseConnection')) {
    echo json_encode(['found' => false]);
    exit;
}

$conn = getDatabaseConnection();
if (!$conn) {
    echo json_encode(['found' => false]);
    exit;
}

$session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
$name = isset($_GET['name']) ? trim($_GET['name']) : '';
$phone = isset($_GET['phone']) ? trim($_GET['phone']) : '';
$normalized_phone = preg_replace('/\D+/', '', $phone);

$out = ['found' => false, 'school' => '', 'school_display' => '', 'grade' => '', 'grade_display' => ''];

if ($session_id <= 0 || $name === '' || $normalized_phone === '') {
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

$current_year = (int)date('Y');
$stmt = $conn->prepare("
    SELECT aa.school, aa.grade,
           sd.name AS school_name,
           io.name AS grade_name
    FROM admission_applications aa
    LEFT JOIN school_data sd ON aa.school = sd.school_code
    LEFT JOIN identity_options io ON aa.grade = io.code
    WHERE aa.session_id = ?
    AND aa.student_name = ?
    AND REPLACE(REPLACE(REPLACE(REPLACE(aa.contact_phone, '-', ''), ' ', ''), '(', ''), ')', '') = ?
    AND YEAR(aa.created_at) = ?
    LIMIT 1
");
if (!$stmt) {
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    $conn->close();
    exit;
}
$stmt->bind_param("issi", $session_id, $name, $normalized_phone, $current_year);
$stmt->execute();
$res = $stmt->get_result();
if ($res && $res->num_rows > 0) {
    $row = $res->fetch_assoc();
    $out['found'] = true;
    $out['school'] = (string)($row['school'] ?? '');
    $out['school_display'] = (string)($row['school_name'] ?? $row['school'] ?? '');
    $out['grade'] = (string)($row['grade'] ?? '');
    $out['grade_display'] = (string)($row['grade_name'] ?? $row['grade'] ?? '');
}
$stmt->close();
$conn->close();

echo json_encode($out, JSON_UNESCAPED_UNICODE);
