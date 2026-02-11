<?php
require_once __DIR__ . '/session_config.php';

checkBackendLogin();

// 僅招生中心/管理員可人工寄送
$user_role = $_SESSION['role'] ?? '';
$allowed_roles = ['STA', '招生中心', '行政人員', '學校行政人員', 'ADM', '管理員'];
if (!in_array($user_role, $allowed_roles, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '權限不足，只有招生中心/管理員可以進行此操作']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '不支援的請求方法']);
    exit;
}

$ids_raw = isset($_POST['ids']) ? trim((string)$_POST['ids']) : '';
if ($ids_raw === '') {
    echo json_encode(['success' => false, 'message' => '未提供要寄送的資料']);
    exit;
}

$id_list = array_values(array_filter(array_map('intval', explode(',', $ids_raw)), function($v) {
    return $v > 0;
}));
if (empty($id_list)) {
    echo json_encode(['success' => false, 'message' => '未提供有效的資料']);
    exit;
}

$action = isset($_POST['action']) ? trim((string)$_POST['action']) : 'send';
$single_mail = ((string)($_POST['single_mail'] ?? '') === '1');

require_once '../../Topics-frontend/frontend/config.php';
require_once __DIR__ . '/../../Topics-frontend/frontend/includes/email_functions.php';

function excel_col_letter($index) {
    $index = (int)$index + 1;
    $letters = '';
    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $letters = chr(65 + $mod) . $letters;
        $index = (int)(($index - 1) / 26);
    }
    return $letters;
}

function excel_xml_escape($value) {
    $value = (string)$value;
    return str_replace(['&', '<', '>', '"', "'"], ['&amp;', '&lt;', '&gt;', '&quot;', '&apos;'], $value);
}

function build_simple_xlsx($rows, $output_path) {
    if (!class_exists('ZipArchive')) return false;
    $zip = new ZipArchive();
    if ($zip->open($output_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return false;

    $sheet_rows = '';
    $row_num = 1;
    foreach ($rows as $row) {
        $sheet_rows .= '<row r="' . $row_num . '">';
        $col_num = 0;
        foreach ($row as $cell) {
            $col = excel_col_letter($col_num);
            $cell_ref = $col . $row_num;
            $text = excel_xml_escape($cell);
            $sheet_rows .= '<c r="' . $cell_ref . '" t="inlineStr"><is><t xml:space="preserve">' . $text . '</t></is></c>';
            $col_num++;
        }
        $sheet_rows .= '</row>';
        $row_num++;
    }

    $sheet_xml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>' . $sheet_rows . '</sheetData>'
        . '</worksheet>';

    $workbook_xml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $rels_xml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $workbook_rels_xml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '</Relationships>';

    $content_types_xml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '</Types>';

    $zip->addFromString('[Content_Types].xml', $content_types_xml);
    $zip->addFromString('_rels/.rels', $rels_xml);
    $zip->addFromString('xl/workbook.xml', $workbook_xml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbook_rels_xml);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet_xml);
    $zip->close();
    return file_exists($output_path);
}

function build_simple_xls($rows, $output_path) {
    $fh = @fopen($output_path, 'wb');
    if (!$fh) return false;
    $xml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<?mso-application progid="Excel.Sheet"?>'
        . '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
        . ' xmlns:o="urn:schemas-microsoft-com:office:office"'
        . ' xmlns:x="urn:schemas-microsoft-com:office:excel"'
        . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"'
        . ' xmlns:html="http://www.w3.org/TR/REC-html40">'
        . '<Styles><Style ss:ID="Header"><Font ss:Bold="1"/><Interior ss:Color="#F2F2F2" ss:Pattern="Solid"/></Style></Styles>'
        . '<Worksheet ss:Name="推薦內容"><Table>';
    foreach ($rows as $ridx => $row) {
        $xml .= '<Row>';
        foreach ($row as $cell) {
            $text = excel_xml_escape((string)$cell);
            if ($ridx === 0) $xml .= '<Cell ss:StyleID="Header"><Data ss:Type="String">' . $text . '</Data></Cell>';
            else $xml .= '<Cell><Data ss:Type="String">' . $text . '</Data></Cell>';
        }
        $xml .= '</Row>';
    }
    $xml .= '</Table></Worksheet></Workbook>';
    fwrite($fh, $xml);
    fclose($fh);
    return file_exists($output_path);
}

function html_to_text($html) {
    $text = strip_tags((string)$html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\s*\R\s*/u', "\n", $text);
    return trim($text);
}

function text_to_html_with_links($text) {
    $escaped = htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
    $escaped = preg_replace('~(https?://[^\s<]+)~i', '<a href="$1" target="_blank" rel="noopener">$1</a>', $escaped);
    return nl2br($escaped);
}

function collect_uploaded_attachments($files, $index) {
    $attachments = [];
    if (!is_array($files) || !isset($files['name'][$index])) return $attachments;
    $names = $files['name'][$index];
    $tmp_names = $files['tmp_name'][$index];
    $errors = $files['error'][$index];
    $types = $files['type'][$index] ?? [];
    if (!is_array($names)) return $attachments;
    foreach ($names as $i => $name) {
        $err = $errors[$i] ?? UPLOAD_ERR_NO_FILE;
        if ($err !== UPLOAD_ERR_OK) continue;
        $tmp = $tmp_names[$i] ?? '';
        if ($tmp === '' || !is_uploaded_file($tmp)) continue;
        $mime = $types[$i] ?? 'application/octet-stream';
        $attachments[] = ['path' => $tmp, 'name' => (string)$name, 'mime' => (string)$mime];
    }
    return $attachments;
}

try {
    $conn = getDatabaseConnection();

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
    if (!$chk_rr || $chk_rr->num_rows === 0) $conn->query("ALTER TABLE recommendation_approval_links ADD COLUMN reject_reason VARCHAR(255) DEFAULT NULL");
    $chk_group = $conn->query("SHOW COLUMNS FROM recommendation_approval_links LIKE 'group_ids'");
    if (!$chk_group || $chk_group->num_rows === 0) $conn->query("ALTER TABLE recommendation_approval_links ADD COLUMN group_ids TEXT NULL");

    $has_recommender_table = false;
    $t1 = $conn->query("SHOW TABLES LIKE 'recommender'");
    if ($t1 && $t1->num_rows > 0) $has_recommender_table = true;
    $has_recommended_table = false;
    $t2 = $conn->query("SHOW TABLES LIKE 'recommended'");
    if ($t2 && $t2->num_rows > 0) $has_recommended_table = true;
    $has_approval_links_table = false;
    $t3 = $conn->query("SHOW TABLES LIKE 'recommendation_approval_links'");
    if ($t3 && $t3->num_rows > 0) $has_approval_links_table = true;

    $ar_has = function($col) use ($conn) {
        $r = $conn->query("SHOW COLUMNS FROM admission_recommendations LIKE '$col'");
        return ($r && $r->num_rows > 0);
    };
    $ar_has_status = $ar_has('status');
    $ar_has_student_name = $ar_has('student_name');
    $ar_has_student_school = $ar_has('student_school');
    $ar_has_student_school_code = $ar_has('student_school_code');
    $ar_has_student_phone = $ar_has('student_phone');
    $ar_has_student_grade = $ar_has('student_grade');
    $ar_has_student_grade_code = $ar_has('student_grade_code');
    $ar_has_student_email = $ar_has('student_email');
    $ar_has_student_line_id = $ar_has('student_line_id');
    $ar_has_student_interest = $ar_has('student_interest');
    $ar_has_recommendation_reason = $ar_has('recommendation_reason');
    $ar_has_additional_info = $ar_has('additional_info');
    $ar_has_proof_evidence = $ar_has('proof_evidence');
    $ar_has_created_at = $ar_has('created_at');
    $ar_has_recommender_name = $ar_has('recommender_name');
    $ar_has_recommender_student_id = $ar_has('recommender_student_id');
    $ar_has_recommender_department_code = $ar_has('recommender_department_code');
    $ar_has_recommender_department = $ar_has('recommender_department');
    $ar_has_recommender_grade_code = $ar_has('recommender_grade_code');
    $ar_has_recommender_phone = $ar_has('recommender_phone');
    $ar_has_recommender_email = $ar_has('recommender_email');

    $table_has = function($table, $column) use ($conn) {
        $r = $conn->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'");
        return ($r && $r->num_rows > 0);
    };
    $rec_has_phone = $has_recommender_table ? $table_has('recommender', 'phone') : false;
    $rec_has_email = $has_recommender_table ? $table_has('recommender', 'email') : false;
    $red_has_grade = $has_recommended_table ? $table_has('recommended', 'grade') : false;
    $red_has_email = $has_recommended_table ? $table_has('recommended', 'email') : false;
    $red_has_line = $has_recommended_table ? $table_has('recommended', 'line_id') : false;

    $select = "SELECT ar.id,
        " . ($ar_has_status ? "COALESCE(ar.status,'')" : "''") . " AS status,
        " . ($ar_has_student_name ? "COALESCE(ar.student_name,'')" : "''") . " AS ar_student_name,
        " . ($ar_has_student_school ? "COALESCE(ar.student_school,'')" : "''") . " AS ar_student_school,
        " . ($ar_has_student_school_code ? "COALESCE(ar.student_school_code,'')" : "''") . " AS ar_student_school_code,
        " . ($ar_has_student_phone ? "COALESCE(ar.student_phone,'')" : "''") . " AS ar_student_phone,
        " . ($ar_has_student_grade ? "COALESCE(ar.student_grade,'')" : "''") . " AS ar_student_grade,
        " . ($ar_has_student_grade_code ? "COALESCE(ar.student_grade_code,'')" : "''") . " AS ar_student_grade_code,
        " . ($ar_has_student_email ? "COALESCE(ar.student_email,'')" : "''") . " AS ar_student_email,
        " . ($ar_has_student_line_id ? "COALESCE(ar.student_line_id,'')" : "''") . " AS ar_student_line_id,
        " . ($ar_has_student_interest ? "COALESCE(ar.student_interest,'')" : "''") . " AS ar_student_interest,
        " . ($ar_has_recommendation_reason ? "COALESCE(ar.recommendation_reason,'')" : "''") . " AS ar_recommendation_reason,
        " . ($ar_has_additional_info ? "COALESCE(ar.additional_info,'')" : "''") . " AS ar_additional_info,
        " . ($ar_has_proof_evidence ? "COALESCE(ar.proof_evidence,'')" : "''") . " AS ar_proof_evidence,
        " . ($ar_has_created_at ? "ar.created_at" : "NULL") . " AS ar_created_at";
    if ($has_approval_links_table) $select .= ", COALESCE(ral.status,'') AS director_review_status";
    else $select .= ", '' AS director_review_status";

    if ($has_recommender_table) {
        $select .= ", COALESCE(rec.name,'') AS rec_name, COALESCE(rec.id,'') AS rec_student_id, COALESCE(rec.department,'') AS rec_department, COALESCE(rec.grade,'') AS rec_grade, "
            . ($rec_has_phone ? "COALESCE(rec.phone,'')" : "''") . " AS rec_phone, "
            . ($rec_has_email ? "COALESCE(rec.email,'')" : "''") . " AS rec_email";
    } else {
        $select .= ", " . ($ar_has_recommender_name ? "COALESCE(ar.recommender_name,'')" : "''") . " AS rec_name,
            " . ($ar_has_recommender_student_id ? "COALESCE(ar.recommender_student_id,'')" : "''") . " AS rec_student_id,
            " . ($ar_has_recommender_department_code ? "COALESCE(ar.recommender_department_code,'')" : ($ar_has_recommender_department ? "COALESCE(ar.recommender_department,'')" : "''")) . " AS rec_department,
            " . ($ar_has_recommender_grade_code ? "COALESCE(ar.recommender_grade_code,'')" : "''") . " AS rec_grade,
            " . ($ar_has_recommender_phone ? "COALESCE(ar.recommender_phone,'')" : "''") . " AS rec_phone,
            " . ($ar_has_recommender_email ? "COALESCE(ar.recommender_email,'')" : "''") . " AS rec_email";
    }

    if ($has_recommended_table) {
        $select .= ", COALESCE(red.name,'') AS red_name, COALESCE(red.school,'') AS red_school, COALESCE(red.phone,'') AS red_phone, "
            . ($red_has_grade ? "COALESCE(red.grade,'')" : "''") . " AS red_grade, "
            . ($red_has_email ? "COALESCE(red.email,'')" : "''") . " AS red_email, "
            . ($red_has_line ? "COALESCE(red.line_id,'')" : "''") . " AS red_line_id";
    } else {
        $select .= ", '' AS red_name, '' AS red_school, '' AS red_phone, '' AS red_grade, '' AS red_email, '' AS red_line_id";
    }

    $from = " FROM admission_recommendations ar ";
    if ($has_recommended_table) $from .= " LEFT JOIN recommended red ON ar.id = red.recommendations_id ";
    if ($has_recommender_table) $from .= " LEFT JOIN recommender rec ON ar.id = rec.recommendations_id ";
    if ($has_approval_links_table) {
        $from .= " LEFT JOIN (
            SELECT r1.*
            FROM recommendation_approval_links r1
            INNER JOIN (
                SELECT recommendation_id, MAX(id) AS max_id
                FROM recommendation_approval_links
                GROUP BY recommendation_id
            ) r2 ON r1.id = r2.max_id
        ) ral ON ral.recommendation_id = ar.id ";
    }

    $stmt = $conn->prepare($select . $from . " WHERE ar.id = ? LIMIT 1");
    if (!$stmt) throw new Exception('查詢準備失敗');

    $groups = [];
    $errors = [];
    $sent = 0;
    $skipped = 0;

    foreach ($id_list as $recommendation_id) {
        $stmt->bind_param("i", $recommendation_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $recommendation = $res ? $res->fetch_assoc() : null;
        if (!$recommendation) {
            $errors[] = "ID {$recommendation_id} 找不到資料";
            continue;
        }

        $status_code = trim((string)($recommendation['status'] ?? ''));
        $status_code_norm = strtolower($status_code);
        // 僅待科主任審核可人工寄送（AP / MC）
        $is_preliminary_pass = in_array($status_code_norm, ['ap', 'approved'], true) || (mb_strpos($status_code, '已通過初審') !== false);
        $is_preliminary_fail_wait_director = in_array($status_code_norm, ['mc', 'manual'], true)
            || (mb_strpos($status_code, '初審未通過（待科主任審核）') !== false)
            || (mb_strpos($status_code, '需人工審查') !== false);

        if (!$is_preliminary_pass && !$is_preliminary_fail_wait_director) {
            $skipped++;
            continue;
        }

        $student_name = trim((string)($recommendation['red_name'] ?? ''));
        if ($student_name === '') $student_name = trim((string)($recommendation['ar_student_name'] ?? ''));
        $student_school = trim((string)($recommendation['red_school'] ?? ''));
        if ($student_school === '') $student_school = trim((string)($recommendation['ar_student_school'] ?? ''));
        if ($student_school === '') $student_school = trim((string)($recommendation['ar_student_school_code'] ?? ''));
        $student_phone = trim((string)($recommendation['red_phone'] ?? ''));
        if ($student_phone === '') $student_phone = trim((string)($recommendation['ar_student_phone'] ?? ''));
        $student_grade = trim((string)($recommendation['red_grade'] ?? ''));
        if ($student_grade === '') $student_grade = trim((string)($recommendation['ar_student_grade'] ?? ''));
        if ($student_grade === '') $student_grade = trim((string)($recommendation['ar_student_grade_code'] ?? ''));
        $student_email = trim((string)($recommendation['red_email'] ?? ''));
        if ($student_email === '') $student_email = trim((string)($recommendation['ar_student_email'] ?? ''));
        $student_line_id = trim((string)($recommendation['red_line_id'] ?? ''));
        if ($student_line_id === '') $student_line_id = trim((string)($recommendation['ar_student_line_id'] ?? ''));

        $rec_name = trim((string)($recommendation['rec_name'] ?? ''));
        $rec_sid = trim((string)($recommendation['rec_student_id'] ?? ''));
        $rec_dept = trim((string)($recommendation['rec_department'] ?? ''));
        $rec_grade = trim((string)($recommendation['rec_grade'] ?? ''));
        $rec_phone = trim((string)($recommendation['rec_phone'] ?? ''));
        $rec_email = trim((string)($recommendation['rec_email'] ?? ''));

        $rec_key = ($rec_name !== '' || $rec_sid !== '') ? mb_strtolower($rec_name . '|' . $rec_sid, 'UTF-8') : ('id:' . (string)$recommendation_id);
        if (!isset($groups[$rec_key])) {
            $groups[$rec_key] = [
                'rec_name' => $rec_name, 'rec_sid' => $rec_sid, 'rec_dept' => $rec_dept, 'rec_grade' => $rec_grade,
                'rec_phone' => $rec_phone, 'rec_email' => $rec_email, 'items' => [],
                'has_approved' => false, 'has_rejected' => false, 'first_approved_id' => 0, 'rec_list' => []
            ];
        }

        $status_label = $is_preliminary_pass ? '已通過初審（待科主任審核）' : '初審未通過（待科主任審核）';
        $groups[$rec_key]['items'][] = [
            'id' => $recommendation_id, 'status' => $status_label,
            'student_name' => $student_name, 'student_school' => $student_school, 'student_phone' => $student_phone,
            'student_grade' => $student_grade, 'student_email' => $student_email, 'student_line_id' => $student_line_id,
            'student_interest' => (string)($recommendation['ar_student_interest'] ?? ''),
            'recommendation_reason' => (string)($recommendation['ar_recommendation_reason'] ?? ''),
            'additional_info' => (string)($recommendation['ar_additional_info'] ?? ''),
            'proof_evidence' => (string)($recommendation['ar_proof_evidence'] ?? ''),
            'created_at' => (string)($recommendation['ar_created_at'] ?? ''),
            'rec_name' => $rec_name, 'rec_sid' => $rec_sid, 'rec_dept' => $rec_dept, 'rec_grade' => $rec_grade, 'rec_phone' => $rec_phone, 'rec_email' => $rec_email
        ];
        if ($is_preliminary_pass) {
            $groups[$rec_key]['has_approved'] = true;
            if ((int)$groups[$rec_key]['first_approved_id'] <= 0) $groups[$rec_key]['first_approved_id'] = (int)$recommendation_id;
        } else {
            $groups[$rec_key]['has_rejected'] = true;
        }
    }
    $stmt->close();

    if ($single_mail && !empty($groups)) {
        $merged = ['rec_name' => '推薦人彙整', 'rec_sid' => '', 'rec_dept' => '', 'rec_grade' => '', 'rec_phone' => '', 'rec_email' => '',
            'items' => [], 'has_approved' => false, 'has_rejected' => false, 'first_approved_id' => 0, 'rec_list' => []];
        $seen = [];
        foreach ($groups as $g) {
            $rk = mb_strtolower(trim((string)$g['rec_name']) . '|' . trim((string)$g['rec_sid']), 'UTF-8');
            if (!isset($seen[$rk]) && (!empty($g['rec_name']) || !empty($g['rec_sid']))) {
                $seen[$rk] = true;
                $merged['rec_list'][] = ['name' => (string)$g['rec_name'], 'sid' => (string)$g['rec_sid']];
            }
            $merged['items'] = array_merge($merged['items'], $g['items'] ?? []);
            if (!empty($g['has_approved'])) {
                $merged['has_approved'] = true;
                if ((int)$merged['first_approved_id'] <= 0) $merged['first_approved_id'] = (int)($g['first_approved_id'] ?? 0);
            }
            if (!empty($g['has_rejected'])) $merged['has_rejected'] = true;
        }
        $groups = ['__single__' => $merged];
    }

    $to_email = '110534236@stu.ukn.edu.tw';
    $xlsx_supported = class_exists('ZipArchive');
    $excel_attachment_ext = $xlsx_supported ? 'xlsx' : 'xls';
    $excel_attachment_mime = $xlsx_supported
        ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        : 'application/vnd.ms-excel';

    $build_rec_summary = function($group) {
        $lines = [];
        $rows = [];
        if (!empty($group['rec_list']) && is_array($group['rec_list'])) {
            foreach ($group['rec_list'] as $r) {
                $nm = trim((string)($r['name'] ?? ''));
                $sid = trim((string)($r['sid'] ?? ''));
                if ($nm !== '' || $sid !== '') {
                    if ($nm !== '') $lines[] = '• 推薦人姓名：' . $nm;
                    if ($sid !== '') $lines[] = '• 推薦人學號：' . $sid;
                    $rows[] = [htmlspecialchars($nm, ENT_QUOTES, 'UTF-8'), htmlspecialchars($sid, ENT_QUOTES, 'UTF-8')];
                }
            }
        } else {
            $nm = trim((string)($group['rec_name'] ?? ''));
            $sid = trim((string)($group['rec_sid'] ?? ''));
            if ($nm !== '') $lines[] = '• 推薦人姓名：' . $nm;
            if ($sid !== '') $lines[] = '• 推薦人學號：' . $sid;
            if ($nm !== '' || $sid !== '') $rows[] = [htmlspecialchars($nm, ENT_QUOTES, 'UTF-8'), htmlspecialchars($sid, ENT_QUOTES, 'UTF-8')];
        }
        $text = implode("\n", $lines);
        $html = '';
        if (!empty($rows)) {
            $html_rows = '';
            foreach ($rows as $row) {
                $html_rows .= '<tr><td style="border:1px solid #d9d9d9;padding:6px 10px;">' . $row[0] . '</td><td style="border:1px solid #d9d9d9;padding:6px 10px;">' . $row[1] . '</td></tr>';
            }
            $html = '<table style="border-collapse:collapse;width:100%;max-width:520px;"><thead><tr><th style="border:1px solid #d9d9d9;padding:6px 10px;text-align:left;background:#fafafa;">推薦人姓名</th><th style="border:1px solid #d9d9d9;padding:6px 10px;text-align:left;background:#fafafa;">推薦人學號</th></tr></thead><tbody>' . $html_rows . '</tbody></table>';
        }
        return [$text, $html];
    };

    if ($action === 'preview') {
        $emails = [];
        foreach ($groups as $rec_key => $group) {
            if (empty($group['items'])) continue;
            if (empty($group['has_approved']) && empty($group['has_rejected'])) continue;
            [$recText, $recHtml] = $build_rec_summary($group);

            if (!empty($group['has_approved'])) {
                $subject = '推薦學生審核通過通知';
                $body = '<p>主任您好：</p><p>本信件為招生中心通知，請點擊下方連結進行線上審核與簽核：<br>【網頁連結】</p><p>推薦人資料摘要如下：</p>' . $recHtml . '<p>推薦內容相關資訊已整理於附件（Excel）。</p>';
                $altBody = "主任您好：\n本信件為招生中心通知，請點擊下方連結進行線上審核與簽核：\n【網頁連結】\n推薦人資料摘要如下：\n{$recText}\n推薦內容相關資訊已整理於附件（Excel）。";
            } else {
                $subject = '推薦學生重複推薦提醒';
                $body = '<p>以下推薦人之推薦資料被判定為不通過/重複推薦，還請您確認後再告知我。</p><p>推薦人資料摘要如下：</p>' . $recHtml . '<p>推薦內容相關資訊已整理於附件（Excel）。</p>';
                $altBody = "以下推薦人之推薦資料被判定為不通過/重複推薦，還請您確認後再告知我。\n推薦人資料摘要如下：\n{$recText}\n推薦內容相關資訊已整理於附件（Excel）。";
            }

            $safe_name = preg_replace('/[^\w\-]+/u', '_', (string)($group['rec_name'] ?: '推薦人'));
            $emails[] = [
                'rec_key' => $rec_key,
                'rec_name' => (string)$group['rec_name'],
                'rec_sid' => (string)$group['rec_sid'],
                'subject' => $subject,
                'body' => $body,
                'alt_body' => $altBody,
                'attachment_name' => '推薦內容_' . $safe_name . '_' . date('Ymd_His') . '.' . $excel_attachment_ext,
                'include_generated' => true,
                'xlsx_supported' => $xlsx_supported,
                'attachment_ext' => $excel_attachment_ext,
            ];
        }
        echo json_encode(['success' => true, 'emails' => $emails]);
        $conn->close();
        exit;
    }

    if ($action === 'send_custom') {
        $emails_payload = json_decode((string)($_POST['emails'] ?? '[]'), true);
        if (!is_array($emails_payload) || empty($emails_payload)) {
            echo json_encode(['success' => false, 'message' => '未提供信件內容']);
            $conn->close();
            exit;
        }

        foreach ($emails_payload as $idx => $payload) {
            if (!is_array($payload)) continue;
            $rec_key = (string)($payload['rec_key'] ?? '');
            if ($rec_key === '' || !isset($groups[$rec_key])) continue;
            $group = $groups[$rec_key];
            if (empty($group['items'])) continue;

            $subject = (string)($payload['subject'] ?? '');
            $body = (string)($payload['body'] ?? '');
            $altBody = (string)($payload['alt_body'] ?? '');
            $body_is_html = ($body !== '' && preg_match('/<[^>]+>/', $body));
            if ($altBody === '') $altBody = html_to_text($body);
            $include_generated = !isset($payload['include_generated']) || $payload['include_generated'];

            $rec_name = (string)($group['rec_name'] ?? '');
            $rec_sid = (string)($group['rec_sid'] ?? '');
            $rec_dept = (string)($group['rec_dept'] ?? '');
            $rec_grade = (string)($group['rec_grade'] ?? '');
            $rec_phone = (string)($group['rec_phone'] ?? '');
            $rec_email = (string)($group['rec_email'] ?? '');

            $attachments = collect_uploaded_attachments($_FILES['custom_files'] ?? [], $idx);
            $attachment_path = '';
            if ($include_generated) {
                $tmp = tempnam(sys_get_temp_dir(), 'rec_excel_');
                if ($tmp !== false) {
                    $attachment_path = $tmp . '.' . $excel_attachment_ext;
                    @rename($tmp, $attachment_path);
                    $rows = [[
                        '推薦編號', '審核結果', '被推薦人姓名', '就讀學校', '年級', '電子郵件', '聯絡電話', 'LINE ID',
                        '學生興趣', '推薦理由', '其他補充資訊', '證明文件', '推薦時間', '推薦人姓名', '推薦人學號',
                        '推薦人科系', '推薦人年級', '推薦人聯絡電話', '推薦人電子郵件',
                    ]];
                    foreach ($group['items'] as $it) {
                        $rows[] = [
                            $it['id'], $it['status'], $it['student_name'], $it['student_school'], $it['student_grade'],
                            $it['student_email'], $it['student_phone'], $it['student_line_id'], $it['student_interest'],
                            $it['recommendation_reason'], $it['additional_info'], $it['proof_evidence'], $it['created_at'],
                            $it['rec_name'] ?? $rec_name, $it['rec_sid'] ?? $rec_sid, $it['rec_dept'] ?? $rec_dept,
                            $it['rec_grade'] ?? $rec_grade, $it['rec_phone'] ?? $rec_phone, $it['rec_email'] ?? $rec_email,
                        ];
                    }
                    $built = $xlsx_supported ? build_simple_xlsx($rows, $attachment_path) : build_simple_xls($rows, $attachment_path);
                    if ($built) {
                        $safe_name = preg_replace('/[^\w\-]+/u', '_', $rec_name ?: '推薦人');
                        $attachments[] = [
                            'path' => $attachment_path,
                            'name' => '推薦內容_' . $safe_name . '_' . date('Ymd_His') . '.' . $excel_attachment_ext,
                            'mime' => $excel_attachment_mime,
                        ];
                    }
                }
            }

            if (function_exists('sendEmail')) {
                if (!empty($group['has_approved'])) {
                    $approval_link = '';
                    $recommendation_id = (int)($group['first_approved_id'] ?? 0);
                    if ($recommendation_id > 0) {
                        $token = function_exists('random_bytes') ? bin2hex(random_bytes(16)) : bin2hex(uniqid('', true));
                        $group_ids = '';
                        if (!empty($group['items'])) {
                            $id_vals = [];
                            foreach ($group['items'] as $it) {
                                $idv = (int)($it['id'] ?? 0);
                                if ($idv > 0) $id_vals[] = $idv;
                            }
                            $id_vals = array_values(array_unique($id_vals));
                            $group_ids = !empty($id_vals) ? implode(',', $id_vals) : '';
                        }
                        $ins = $conn->prepare("INSERT INTO recommendation_approval_links (recommendation_id, token, confirmed_by_email, group_ids) VALUES (?, ?, ?, ?)");
                        if ($ins) {
                            $ins->bind_param('isss', $recommendation_id, $token, $to_email, $group_ids);
                            @$ins->execute();
                            $ins->close();
                        }
                        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                        $approval_link = $protocol . '://' . $host . '/Topics-frontend/frontend/recommendation_approval.php?token=' . urlencode($token);
                    }
                    if ($approval_link !== '') {
                        $link_html = '<a href="' . htmlspecialchars($approval_link, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($approval_link, ENT_QUOTES, 'UTF-8') . '</a>';
                        if (mb_strpos($body, '【網頁連結】') !== false) {
                            $body = str_replace('【網頁連結】', '【網頁連結】' . ($body_is_html ? $link_html : $approval_link), $body);
                        } else {
                            $body .= $body_is_html ? '<br>【網頁連結】' . $link_html : "\n【網頁連結】" . $approval_link;
                        }
                    }
                }
                $body_html = $body_is_html ? $body : text_to_html_with_links($body);
                @sendEmail($to_email, $subject, $body_html, $altBody, $attachments);
                $sent++;
            }

            if ($attachment_path !== '' && file_exists($attachment_path)) @unlink($attachment_path);
        }

        echo json_encode(['success' => true, 'message' => "已寄送 {$sent} 筆 Gmail"]);
        $conn->close();
        exit;
    }

    // 兼容：未指定 action 時，沿用 send_custom 基本行為
    echo json_encode(['success' => true, 'message' => '請使用預覽後發送']);
    $conn->close();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '系統錯誤：' . $e->getMessage()]);
}
?>

