<?php
// 強制 JSON 回應：避免任何 PHP warning/notice 或 include 輸出混入，導致前端 JSON.parse 失敗
ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/session_config.php';
checkBackendLogin();

require_once '../../Topics-frontend/frontend/config.php';

$user_role = $_SESSION['role'] ?? '';
if (!in_array($user_role, ['ADM', 'STA'], true)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    @ob_clean();
    echo json_encode(['success' => false, 'message' => '權限不足'], JSON_UNESCAPED_UNICODE);
    exit;
}

date_default_timezone_set('Asia/Taipei');

try {
    $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
    // 避免使用 getDatabaseConnection()（連線失敗會 die，無法被 try/catch 捕捉）
    $conn = @new mysqli(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME);
    if ($conn->connect_error) {
        throw new Exception("資料庫連接失敗: " . $conn->connect_error);
    }
    if (!$conn->set_charset(DB_CHARSET)) {
        throw new Exception("設定資料庫字元集失敗: " . $conn->error);
    }

    // 科系名額（用於顯示附註）
    $quotaMap = [];
    try {
        $q = $conn->query("SELECT department_code, total_quota FROM department_quotas WHERE is_active = 1");
        if ($q) {
            while ($r = $q->fetch_assoc()) {
                $quotaMap[(string)$r['department_code']] = (int)($r['total_quota'] ?? 0);
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    // 撈出已決定結果（正取/備取）者
    // admission_rank 欄位在部分舊資料庫不存在，這裡先偵測後再決定 SQL
    $hasRank = false;
    try {
        $col = $conn->query("SHOW COLUMNS FROM continued_admission LIKE 'admission_rank'");
        $hasRank = ($col && $col->num_rows > 0);
    } catch (Throwable $e) {
        $hasRank = false;
    }

    $rankSelect = $hasRank ? ", ca.admission_rank" : ", NULL AS admission_rank";
    $rankOrder = $hasRank ? "ca.admission_rank ASC," : "";

    $stmt = $conn->prepare("
        SELECT
            ca.apply_no,
            ca.id_number,
            ca.name,
            ca.assigned_department AS dept_code,
            d.name AS dept_name,
            ca.status
            {$rankSelect}
        FROM continued_admission ca
        LEFT JOIN departments d ON ca.assigned_department = d.code
        WHERE ca.assigned_department IS NOT NULL AND ca.assigned_department != ''
          AND LEFT(ca.apply_no, 4) = ?
          AND ca.status IN ('approved','AP','waitlist','AD')
        ORDER BY ca.assigned_department,
                 FIELD(ca.status, 'AP','approved','AD','waitlist'),
                 {$rankOrder}
                 ca.apply_no ASC
    ");
    if (!$stmt) {
        throw new Exception('資料庫查詢準備失敗');
    }

    // LEFT(..., 4) 是字串，這裡用 s 以避免不同 MySQL 設定下的型別比較問題
    $yearStr = (string)$year;
    $stmt->bind_param("s", $yearStr);
    if (!$stmt->execute()) {
        throw new Exception("資料庫查詢執行失敗: " . $stmt->error);
    }
    $rs = $stmt->get_result();
    if (!$rs) {
        // 若環境缺少 mysqlnd，get_result() 會回傳 null
        throw new Exception("無法取得查詢結果（mysqli_stmt::get_result 不可用）。請確認 PHP 已啟用 mysqlnd。");
    }

    $departments = [];
    while ($row = $rs->fetch_assoc()) {
        $deptCode = (string)($row['dept_code'] ?? '');
        if ($deptCode === '') continue;

        if (!isset($departments[$deptCode])) {
            $departments[$deptCode] = [
                'department_code' => $deptCode,
                'department_name' => (string)($row['dept_name'] ?? $deptCode),
                'total_quota' => $quotaMap[$deptCode] ?? 0,
                'approved' => [],
                'waitlist' => []
            ];
        }

        $status = (string)($row['status'] ?? '');
        $item = [
            'apply_no' => (string)($row['apply_no'] ?? ''),
            'id_number' => (string)($row['id_number'] ?? ''),
            'name' => (string)($row['name'] ?? ''),
            'status' => $status,
            'admission_rank' => isset($row['admission_rank']) ? (int)$row['admission_rank'] : null,
        ];

        if ($status === 'AP' || $status === 'approved') {
            $departments[$deptCode]['approved'][] = $item;
        } else {
            $departments[$deptCode]['waitlist'][] = $item;
        }
    }
    $stmt->close();
    $conn->close();

    $now = new DateTime('now', new DateTimeZone('Asia/Taipei'));
    $gregYear = (int)$now->format('Y');
    $rocYear = $gregYear - 1911;

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    @ob_clean();
    echo json_encode([
        'success' => true,
        'year' => $year,
        'generated_at' => $now->format('Y-m-d H:i:s'),
        'roc_year' => $rocYear,
        'departments' => array_values($departments)
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    @ob_clean();
    echo json_encode([
        'success' => false,
        'message' => '榜單資料端點發生錯誤',
        'detail' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}


