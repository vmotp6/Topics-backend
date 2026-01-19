<?php
require_once __DIR__ . '/session_config.php';
checkBackendLogin();

require_once '../../Topics-frontend/frontend/config.php';

// 權限：沿用審核結果可視權限（username=12 & role=STA）
$username = isset($_SESSION['username']) ? $_SESSION['username'] : '';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$can_view_review_result = ($username === '12' && $user_role === 'STA');
if (!$can_view_review_result) {
    header('Location: admission_recommend_list.php');
    exit();
}

try {
    $conn = getDatabaseConnection();
} catch (Exception $e) {
    die("資料庫連接失敗: " . $e->getMessage());
}

// 確保表存在（若尚未發送過也能下載空檔）
try {
    $table_check = $conn->query("SHOW TABLES LIKE 'bonus_send_logs'");
    if ($table_check && $table_check->num_rows == 0) {
        $conn->query("CREATE TABLE bonus_send_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            recommendation_id INT NOT NULL,
            recommender_name VARCHAR(100) NOT NULL DEFAULT '',
            recommender_student_id VARCHAR(50) NOT NULL DEFAULT '',
            sent_by VARCHAR(100) NOT NULL DEFAULT '',
            sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_recommendation_id (recommendation_id),
            INDEX idx_sent_at (sent_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
} catch (Exception $e) {
    // ignore
}

$rows = [];
try {
    // 取得「查看詳情」需要的資訊：推薦人/被推薦人/推薦資訊（盡量 JOIN，表不存在則 fallback）
    $has_recommender = false;
    $has_recommended = false;
    $has_identity = false;
    $has_departments = false;
    $has_school_data = false;

    $t = $conn->query("SHOW TABLES LIKE 'recommender'");
    $has_recommender = ($t && $t->num_rows > 0);
    $t = $conn->query("SHOW TABLES LIKE 'recommended'");
    $has_recommended = ($t && $t->num_rows > 0);
    $t = $conn->query("SHOW TABLES LIKE 'identity_options'");
    $has_identity = ($t && $t->num_rows > 0);
    $t = $conn->query("SHOW TABLES LIKE 'departments'");
    $has_departments = ($t && $t->num_rows > 0);
    $t = $conn->query("SHOW TABLES LIKE 'school_data'");
    $has_school_data = ($t && $t->num_rows > 0);

    $select = "SELECT
        b.recommendation_id,
        b.sent_by,
        b.sent_at,
        COALESCE(b.amount, 1500) AS amount,
        COALESCE(ar.recommendation_reason,'') AS recommendation_reason,
        COALESCE(ar.created_at,'') AS recommendation_created_at";
    $joins = " FROM bonus_send_logs b
        LEFT JOIN admission_recommendations ar ON b.recommendation_id = ar.id";

    // 推薦人資訊
    if ($has_recommender) {
        $select .= ",
        COALESCE(rec.name,'') AS recommender_name,
        COALESCE(rec.id,'') AS recommender_student_id,
        COALESCE(rec.phone,'') AS recommender_phone,
        COALESCE(rec.email,'') AS recommender_email";
        $joins .= " LEFT JOIN recommender rec ON b.recommendation_id = rec.recommendations_id";
        if ($has_identity) {
            $select .= ", COALESCE(rg.name,'') AS recommender_grade";
            $joins .= " LEFT JOIN identity_options rg ON rec.grade = rg.code";
        } else {
            $select .= ", COALESCE(rec.grade,'') AS recommender_grade";
        }
        if ($has_departments) {
            $select .= ", COALESCE(rd.name,'') AS recommender_department";
            $joins .= " LEFT JOIN departments rd ON rec.department = rd.code";
        } else {
            $select .= ", COALESCE(rec.department,'') AS recommender_department";
        }
    } else {
        // fallback：以 log 內保存的姓名/學號為主，其他欄位留空
        $select .= ",
        COALESCE(b.recommender_name,'') AS recommender_name,
        COALESCE(b.recommender_student_id,'') AS recommender_student_id,
        '' AS recommender_phone,
        '' AS recommender_email,
        '' AS recommender_grade,
        '' AS recommender_department";
    }

    // 被推薦人資訊
    if ($has_recommended) {
        $select .= ",
        COALESCE(red.name,'') AS student_name,
        COALESCE(red.phone,'') AS student_phone,
        COALESCE(red.email,'') AS student_email,
        COALESCE(red.line_id,'') AS student_line_id";
        $joins .= " LEFT JOIN recommended red ON b.recommendation_id = red.recommendations_id";
        if ($has_identity) {
            $select .= ", COALESCE(sg.name,'') AS student_grade";
            $joins .= " LEFT JOIN identity_options sg ON red.grade = sg.code";
        } else {
            $select .= ", COALESCE(red.grade,'') AS student_grade";
        }
        if ($has_school_data) {
            $select .= ", COALESCE(sd.name,'') AS student_school";
            $joins .= " LEFT JOIN school_data sd ON red.school = sd.school_code";
        } else {
            $select .= ", COALESCE(red.school,'') AS student_school";
        }
    } else {
        // fallback：若 admission_recommendations 有舊欄位則取，否則空
        $select .= ",
        COALESCE(ar.student_name,'') AS student_name,
        COALESCE(ar.student_phone,'') AS student_phone,
        COALESCE(ar.student_email,'') AS student_email,
        COALESCE(ar.student_line_id,'') AS student_line_id,
        COALESCE(ar.student_grade,'') AS student_grade,
        COALESCE(ar.student_school,'') AS student_school";
    }

    // 學生興趣
    if ($has_departments) {
        $select .= ", COALESCE(di.name,'') AS student_interest";
        $joins .= " LEFT JOIN departments di ON ar.student_interest = di.code";
    } else {
        $select .= ", COALESCE(ar.student_interest,'') AS student_interest";
    }

    $sql = $select . $joins . " ORDER BY b.sent_at DESC";

    $res = $conn->query($sql);
    if ($res) {
        $rows = $res->fetch_all(MYSQLI_ASSOC);
    }
} catch (Exception $e) {
    error_log('bonus_send_export query error: ' . $e->getMessage());
    $rows = [];
}

$conn->close();

// 以 CSV 方式匯出（Excel 可直接開啟），加 BOM 避免中文亂碼
$filename = '已發送獎金名單_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
header('Pragma: no-cache');
header('Expires: 0');

// UTF-8 BOM
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');
if ($out === false) {
    exit;
}

// 表頭
fputcsv($out, [
    '推薦ID',
    '發送者',
    '發送時間',
    '獎金金額',
    // 推薦人資訊
    '推薦人姓名',
    '推薦人學號/編號',
    '推薦人年級',
    '推薦人科系',
    '推薦人聯絡電話',
    '推薦人電子郵件',
    // 被推薦人資訊
    '被推薦人姓名',
    '就讀學校',
    '年級',
    '電子郵件',
    '聯絡電話',
    'LINE ID',
    '學生興趣',
    // 推薦資訊
    '推薦理由',
    '推薦時間'
]);

foreach ($rows as $r) {
    fputcsv($out, [
        $r['recommendation_id'] ?? '',
        $r['sent_by'] ?? '',
        $r['sent_at'] ?? '',
        $r['amount'] ?? '',

        $r['recommender_name'] ?? '',
        $r['recommender_student_id'] ?? '',
        $r['recommender_grade'] ?? '',
        $r['recommender_department'] ?? '',
        $r['recommender_phone'] ?? '',
        $r['recommender_email'] ?? '',

        $r['student_name'] ?? '',
        $r['student_school'] ?? '',
        $r['student_grade'] ?? '',
        $r['student_email'] ?? '',
        $r['student_phone'] ?? '',
        $r['student_line_id'] ?? '',
        $r['student_interest'] ?? '',

        $r['recommendation_reason'] ?? '',
        $r['recommendation_created_at'] ?? ''
    ]);
}

fclose($out);
exit;

