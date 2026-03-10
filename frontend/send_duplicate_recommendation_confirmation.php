<?php
require_once __DIR__ . '/session_config.php';
checkBackendLogin();
require_once __DIR__ . '/../../Topics-frontend/frontend/config.php';
require_once __DIR__ . '/../../Topics-frontend/frontend/includes/email_functions.php';

header('Content-Type: application/json; charset=utf-8');

function normalize_text($v) {
    $s = trim((string)$v);
    if ($s === '') return '';
    $s = str_replace(["\r", "\n", "\t", ' '], '', $s);
    return mb_strtolower($s, 'UTF-8');
}

function normalize_phone($v) {
    $s = trim((string)$v);
    if ($s === '') return '';
    $s = preg_replace('/[^0-9]/', '', $s);
    if ($s === null) return '';
    return ltrim($s, '0');
}

function table_exists($conn, $table) {
    $safe = $conn->real_escape_string((string)$table);
    $rs = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $rs && $rs->num_rows > 0;
}

function table_columns_map($conn, $table) {
    $map = [];
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
    if ($safe === '') return $map;
    $rs = $conn->query("SHOW COLUMNS FROM `{$safe}`");
    if ($rs) {
        while ($row = $rs->fetch_assoc()) {
            $field = isset($row['Field']) ? trim((string)$row['Field']) : '';
            if ($field !== '') $map[$field] = true;
        }
    }
    return $map;
}

function pick_expr($candidates, $existingColumns, $alias) {
    foreach ($candidates as $c) {
        if (isset($existingColumns[$c])) return "{$alias}.{$c}";
    }
    return '';
}

function coalesce_expr($exprs) {
    $valid = [];
    foreach ((array)$exprs as $e) {
        $v = trim((string)$e);
        if ($v !== '') $valid[] = $v;
    }
    $valid[] = "''";
    return 'COALESCE(' . implode(', ', $valid) . ')';
}

function ensure_recommendation_approval_links_table($conn) {
    if (!$conn) return;
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS recommendation_approval_links (
            id INT AUTO_INCREMENT PRIMARY KEY,
            recommendation_id INT NOT NULL,
            token VARCHAR(64) NOT NULL UNIQUE,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            signature_path VARCHAR(255) DEFAULT NULL,
            signer_name VARCHAR(100) DEFAULT NULL,
            reject_reason VARCHAR(255) DEFAULT NULL,
            confirmed_by_email VARCHAR(255) DEFAULT NULL,
            group_ids TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            signed_at TIMESTAMP NULL DEFAULT NULL,
            INDEX idx_rec_id (recommendation_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $chk_rr = $conn->query("SHOW COLUMNS FROM recommendation_approval_links LIKE 'reject_reason'");
        if (!$chk_rr || $chk_rr->num_rows === 0) {
            $conn->query("ALTER TABLE recommendation_approval_links ADD COLUMN reject_reason VARCHAR(255) DEFAULT NULL");
        }
        $chk_group = $conn->query("SHOW COLUMNS FROM recommendation_approval_links LIKE 'group_ids'");
        if (!$chk_group || $chk_group->num_rows === 0) {
            $conn->query("ALTER TABLE recommendation_approval_links ADD COLUMN group_ids TEXT NULL");
        }
    } catch (Exception $e) {
        // ignore
    }
}

function build_approval_link_from_token($token) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $protocol . '://' . $host . '/Topics-frontend/frontend/recommendation_approval.php?token=' . urlencode((string)$token);
}

function get_or_create_approval_link($conn, $recommendation_id, $to_email, $group_ids_csv = '') {
    ensure_recommendation_approval_links_table($conn);
    $recommendation_id = (int)$recommendation_id;
    $to_email = trim((string)$to_email);
    $group_ids_csv = trim((string)$group_ids_csv);
    if ($recommendation_id <= 0 || $to_email === '') return '';

    try {
        $sel = $conn->prepare("SELECT id, token, COALESCE(group_ids,'') AS group_ids
            FROM recommendation_approval_links
            WHERE recommendation_id = ? AND confirmed_by_email = ? AND status = 'pending'
            ORDER BY id DESC
            LIMIT 1");
        if ($sel) {
            $sel->bind_param('is', $recommendation_id, $to_email);
            $sel->execute();
            $res = $sel->get_result();
            if ($res && $row = $res->fetch_assoc()) {
                $sel->close();
                $token = trim((string)($row['token'] ?? ''));
                $existing_group = trim((string)($row['group_ids'] ?? ''));
                if ($group_ids_csv !== '' && $existing_group !== $group_ids_csv) {
                    $upd = $conn->prepare("UPDATE recommendation_approval_links SET group_ids = ? WHERE id = ? LIMIT 1");
                    if ($upd) {
                        $id = (int)$row['id'];
                        $upd->bind_param('si', $group_ids_csv, $id);
                        @$upd->execute();
                        $upd->close();
                    }
                }
                if ($token !== '') return build_approval_link_from_token($token);
            } else {
                $sel->close();
            }
        }

        $token = function_exists('random_bytes')
            ? bin2hex(random_bytes(16))
            : bin2hex(uniqid('', true));
        $ins = $conn->prepare("INSERT INTO recommendation_approval_links (recommendation_id, token, confirmed_by_email, group_ids) VALUES (?, ?, ?, ?)");
        if ($ins) {
            $ins->bind_param('isss', $recommendation_id, $token, $to_email, $group_ids_csv);
            $ok = @$ins->execute();
            $ins->close();
            if ($ok) return build_approval_link_from_token($token);
        }
    } catch (Exception $e) {
        // ignore
    }
    return '';
}

function is_preliminary_fail_status($status) {
    $s = trim((string)($status ?? ''));
    if ($s === '') return false;
    $s_lower = strtolower($s);
    return ($s_lower === 'mc' || $s_lower === 'manual'
        || mb_strpos($s, '初審未通過（待科主任審核）') !== false
        || mb_strpos($s, '需人工審查') !== false);
}

function build_mail_payload($targetRow, $allRows, $approval_link = '') {
    $studentNameKey = normalize_text($targetRow['student_name'] ?? '');
    $studentPhoneKey = normalize_phone($targetRow['student_phone'] ?? '');
    $schoolNameKey = normalize_text($targetRow['student_school'] ?? '');
    $schoolCodeKey = normalize_text($targetRow['student_school_code'] ?? '');
    $schoolKey = $schoolCodeKey !== '' ? $schoolCodeKey : $schoolNameKey;

    $matched = [];
    foreach ($allRows as $row) {
        $nameK = normalize_text($row['student_name'] ?? '');
        $phoneK = normalize_phone($row['student_phone'] ?? '');
        $rowSchoolNameKey = normalize_text($row['student_school'] ?? '');
        $rowSchoolCodeKey = normalize_text($row['student_school_code'] ?? '');
        $rowSchoolKey = $rowSchoolCodeKey !== '' ? $rowSchoolCodeKey : $rowSchoolNameKey;
        if ($nameK !== $studentNameKey) continue;
        if ($phoneK !== '' && $studentPhoneKey !== '' && $phoneK !== $studentPhoneKey) continue;
        if ($schoolKey !== '' && $rowSchoolKey !== '' && $schoolKey !== $rowSchoolKey) continue;
        $matched[] = $row;
    }

    $uniq = [];
    foreach ($matched as $row) {
        $rName = trim((string)($row['recommender_name'] ?? ''));
        $rSid = trim((string)($row['recommender_student_id'] ?? ''));
        $rDept = trim((string)($row['recommender_department'] ?? ''));
        $k = mb_strtolower($rName, 'UTF-8') . '|' . mb_strtolower($rSid, 'UTF-8') . '|' . mb_strtolower($rDept, 'UTF-8');
        if (!isset($uniq[$k])) {
            $uniq[$k] = [
                'recommender_name' => $rName !== '' ? $rName : '未填寫',
                'recommender_department' => $rDept !== '' ? $rDept : '未填寫'
            ];
        }
    }
    $rows = array_values($uniq);
    $matchedIds = [];
    foreach ($matched as $row) {
        $rid = (int)($row['id'] ?? 0);
        if ($rid <= 0) continue;
        $matchedIds[$rid] = true;
    }
    $groupIdsCsv = implode(',', array_keys($matchedIds));
    if ($groupIdsCsv === '') $groupIdsCsv = (string)((int)($targetRow['id'] ?? 0));
    if (count($rows) === 0) {
        $rows[] = [
            'recommender_name' => trim((string)($targetRow['recommender_name'] ?? '')) !== '' ? trim((string)($targetRow['recommender_name'] ?? '')) : '未填寫',
            'recommender_department' => trim((string)($targetRow['recommender_department'] ?? '')) !== '' ? trim((string)($targetRow['recommender_department'] ?? '')) : '未填寫'
        ];
    }

    $tableRowsHtml = '';
    $tableRowsText = '';
    foreach ($rows as $r) {
        $nameHtml = htmlspecialchars($r['recommender_name'], ENT_QUOTES, 'UTF-8');
        $deptHtml = htmlspecialchars($r['recommender_department'], ENT_QUOTES, 'UTF-8');
        $tableRowsHtml .= "<tr><td style='border:1px solid #d9d9d9; padding:8px;'>{$nameHtml}</td><td style='border:1px solid #d9d9d9; padding:8px;'>{$deptHtml}</td></tr>";
        $tableRowsText .= "- {$r['recommender_name']} / {$r['recommender_department']}\n";
    }

    // 初審未通過（待科主任審核）時，請被推薦人審核學生資訊；否則請選擇推薦人
    $is_prelim_fail = false;
    foreach ($matched as $row) {
        if (is_preliminary_fail_status($row['recommendation_status'] ?? '')) {
            $is_prelim_fail = true;
            break;
        }
    }
    if (!$is_prelim_fail && is_preliminary_fail_status($targetRow['recommendation_status'] ?? '')) {
        $is_prelim_fail = true;
    }

    $promptText = $is_prelim_fail ? '請審核學生資訊是否正確?' : '請選擇哪一個推薦人跟你比較有聯絡?';
    $subject = $is_prelim_fail ? '請確認學生資訊' : '確認推薦對象';
    $introText = $is_prelim_fail ? '您的推薦資料需請您確認學生資訊是否正確。' : '有多名推薦人同時推薦你';
    $bodyHtml = "
        <div style='font-family: Arial, sans-serif; line-height: 1.8; color: #333;'>
            <p>{$introText}</p>
            <table style='border-collapse: collapse; width: 100%; max-width: 560px;'>
                <thead>
                    <tr>
                        <th style='border:1px solid #d9d9d9; padding:8px; background:#fafafa; text-align:left;'>推薦人姓名</th>
                        <th style='border:1px solid #d9d9d9; padding:8px; background:#fafafa; text-align:left;'>科系</th>
                    </tr>
                </thead>
                <tbody>
                    {$tableRowsHtml}
                </tbody>
            </table>
            <p style='margin-top:12px;'>{$promptText}</p>
            " . ($approval_link !== '' ? ("<p style='margin-top:10px;'>線上簽核連結：<a href='" . htmlspecialchars($approval_link, ENT_QUOTES, 'UTF-8') . "' target='_blank' rel='noopener'>" . htmlspecialchars($approval_link, ENT_QUOTES, 'UTF-8') . "</a></p>") : "") . "
        </div>
    ";
    $altBody = "{$introText}\n推薦人姓名 / 科系：\n{$tableRowsText}{$promptText}" . ($approval_link !== '' ? ("\n線上簽核連結：\n" . $approval_link) : '');

    return [
        'subject' => $subject,
        'body_html' => $bodyHtml,
        'alt_body' => $altBody,
        'rows' => $rows,
        'group_ids' => $groupIdsCsv
    ];
}

$action = isset($_POST['action']) ? strtolower(trim((string)$_POST['action'])) : 'preview';
$recommendation_id = isset($_POST['recommendation_id']) ? (int)$_POST['recommendation_id'] : 0;
if ($recommendation_id <= 0) {
    echo json_encode(['success' => false, 'message' => '缺少 recommendation_id']);
    exit;
}

try {
    $conn = getDatabaseConnection();

    $has_recommender = table_exists($conn, 'recommender');
    $has_recommended = table_exists($conn, 'recommended');
    $has_departments = table_exists($conn, 'departments');

    $ar_cols = table_columns_map($conn, 'admission_recommendations');
    $rec_cols = $has_recommender ? table_columns_map($conn, 'recommender') : [];
    $red_cols = $has_recommended ? table_columns_map($conn, 'recommended') : [];

    $ar_student_name = pick_expr(['student_name', 'name'], $ar_cols, 'ar');
    $ar_student_email = pick_expr(['student_email', 'email'], $ar_cols, 'ar');
    $ar_student_phone = pick_expr(['student_phone', 'phone_number', 'phone', 'parent_phone'], $ar_cols, 'ar');
    $ar_student_school = pick_expr(['student_school', 'school'], $ar_cols, 'ar');
    $ar_student_school_code = pick_expr(['student_school_code', 'school_code'], $ar_cols, 'ar');
    $ar_status = pick_expr(['status'], $ar_cols, 'ar');

    $ar_recommender_name = pick_expr(['recommender_name'], $ar_cols, 'ar');
    $ar_recommender_sid = pick_expr(['recommender_student_id', 'recommender_id'], $ar_cols, 'ar');
    $ar_recommender_dept_name = pick_expr(['recommender_department'], $ar_cols, 'ar');
    $ar_recommender_dept_code = pick_expr(['recommender_department_code'], $ar_cols, 'ar');

    $red_name = $has_recommended ? pick_expr(['name', 'student_name'], $red_cols, 'red') : '';
    $red_email = $has_recommended ? pick_expr(['email', 'student_email'], $red_cols, 'red') : '';
    $red_phone = $has_recommended ? pick_expr(['phone', 'mobile', 'parent_phone', 'student_phone'], $red_cols, 'red') : '';
    $red_school = $has_recommended ? pick_expr(['school', 'student_school'], $red_cols, 'red') : '';
    $red_school_code = $has_recommended ? pick_expr(['school_code', 'student_school_code'], $red_cols, 'red') : '';

    $rec_name = $has_recommender ? pick_expr(['name', 'recommender_name'], $rec_cols, 'rec') : '';
    $rec_sid = $has_recommender ? pick_expr(['id', 'student_id', 'recommender_student_id'], $rec_cols, 'rec') : '';
    $rec_dept = $has_recommender ? pick_expr(['department', 'recommender_department', 'department_code'], $rec_cols, 'rec') : '';

    $dept_join_key = coalesce_expr([$rec_dept, $ar_recommender_dept_code, $ar_recommender_dept_name]);
    $dept_name_expr = $has_departments
        ? "COALESCE(d.name, " . coalesce_expr([$rec_dept, $ar_recommender_dept_name, $ar_recommender_dept_code]) . ")"
        : coalesce_expr([$rec_dept, $ar_recommender_dept_name, $ar_recommender_dept_code]);

    $baseSql = "SELECT
        ar.id,
        " . coalesce_expr([$red_name, $ar_student_name]) . " AS student_name,
        " . coalesce_expr([$red_email, $ar_student_email]) . " AS student_email,
        " . coalesce_expr([$red_phone, $ar_student_phone]) . " AS student_phone,
        " . coalesce_expr([$red_school, $ar_student_school]) . " AS student_school,
        " . coalesce_expr([$red_school_code, $ar_student_school_code]) . " AS student_school_code,
        " . coalesce_expr([$rec_name, $ar_recommender_name]) . " AS recommender_name,
        " . coalesce_expr([$rec_sid, $ar_recommender_sid]) . " AS recommender_student_id,
        {$dept_name_expr} AS recommender_department,
        " . coalesce_expr([$ar_status]) . " AS recommendation_status
    FROM admission_recommendations ar
    " . ($has_recommender ? "LEFT JOIN recommender rec ON ar.id = rec.recommendations_id" : "") . "
    " . ($has_recommended ? "LEFT JOIN recommended red ON ar.id = red.recommendations_id" : "") . "
    " . ($has_departments ? "LEFT JOIN departments d ON {$dept_join_key} = d.code" : "");

    $sqlTarget = $baseSql . " WHERE ar.id = ? LIMIT 1";
    $stmt = $conn->prepare($sqlTarget);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => '查詢準備失敗']);
        exit;
    }
    $stmt->bind_param('i', $recommendation_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $targetRow = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$targetRow) {
        echo json_encode(['success' => false, 'message' => '找不到推薦資料']);
        exit;
    }

    $to_email = trim((string)($targetRow['student_email'] ?? ''));
    if ($to_email === '') {
        echo json_encode(['success' => false, 'message' => '被推薦人未填寫電子郵件']);
        exit;
    }

    $resAll = $conn->query($baseSql);
    $allRows = [];
    if ($resAll) {
        while ($r = $resAll->fetch_assoc()) {
            $allRows[] = $r;
        }
    }

    $payloadBase = build_mail_payload($targetRow, $allRows, '');
    $approval_link = get_or_create_approval_link(
        $conn,
        (int)($targetRow['id'] ?? 0),
        $to_email,
        (string)($payloadBase['group_ids'] ?? '')
    );
    $payload = build_mail_payload($targetRow, $allRows, $approval_link);
    if ($action === 'preview') {
        echo json_encode([
            'success' => true,
            'to_email' => $to_email,
            'subject' => $payload['subject'],
            'body_html' => $payload['body_html'],
            'alt_body' => $payload['alt_body']
        ], JSON_UNESCAPED_UNICODE);
        $conn->close();
        exit;
    }

    if ($action !== 'send') {
        echo json_encode(['success' => false, 'message' => '不支援的 action']);
        $conn->close();
        exit;
    }

    $subject = trim((string)($_POST['subject'] ?? ''));
    if ($subject === '') $subject = $payload['subject'];
    $bodyHtml = trim((string)($_POST['body_html'] ?? ''));
    if ($bodyHtml === '') $bodyHtml = $payload['body_html'];
    $altBody = trim((string)($_POST['alt_body'] ?? ''));
    if ($altBody === '') $altBody = $payload['alt_body'];

    if (!function_exists('sendEmail')) {
        echo json_encode(['success' => false, 'message' => '寄信函式不存在']);
        $conn->close();
        exit;
    }

    $ok = @sendEmail($to_email, $subject, $bodyHtml, $altBody);
    if (!$ok) {
        echo json_encode(['success' => false, 'message' => 'sendEmail 執行失敗']);
        $conn->close();
        exit;
    }

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    $conn->close();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '系統錯誤：' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
