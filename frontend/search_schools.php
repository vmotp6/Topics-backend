<?php
/**
 * 學校搜尋 API：依關鍵字查詢 school_data，供就讀學校自動完成使用
 * GET ?q=關鍵字，回傳 JSON [{ school_code, name }, ...]
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
    echo json_encode([]);
    exit;
}
require_once $config_path;

if (!function_exists('getDatabaseConnection')) {
    echo json_encode([]);
    exit;
}

$conn = getDatabaseConnection();
if (!$conn) {
    echo json_encode([]);
    exit;
}

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$limit = min(max((int)(isset($_GET['limit']) ? $_GET['limit'] : 20), 1), 50);

$list = [];
$table_check = $conn->query("SHOW TABLES LIKE 'school_data'");
if ($table_check && $table_check->num_rows > 0) {
    if ($q === '') {
        $stmt = $conn->prepare("SELECT school_code, name FROM school_data ORDER BY name LIMIT ?");
        $stmt->bind_param("i", $limit);
    } else {
        $like = '%' . $conn->real_escape_string($q) . '%';
        $stmt = $conn->prepare("SELECT school_code, name FROM school_data WHERE name LIKE ? OR school_code LIKE ? ORDER BY name LIMIT ?");
        $stmt->bind_param("ssi", $like, $like, $limit);
    }
    if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $list[] = ['school_code' => $row['school_code'], 'name' => $row['name']];
        }
        $stmt->close();
    }
}
$conn->close();

echo json_encode($list, JSON_UNESCAPED_UNICODE);
