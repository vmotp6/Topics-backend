<?php
// 推薦審核結果通知（通過/不通過）共用函式

require_once __DIR__ . '/../../Topics-frontend/frontend/config.php';
require_once __DIR__ . '/../../Topics-frontend/frontend/config/email_notification_config.php';
require_once __DIR__ . '/../../Topics-frontend/frontend/includes/email_functions.php';

function rr_excel_col_letter($index) {
    $index = (int)$index + 1;
    $letters = '';
    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $letters = chr(65 + $mod) . $letters;
        $index = (int)(($index - 1) / 26);
    }
    return $letters;
}

function rr_excel_xml_escape($value) {
    $value = (string)$value;
    return str_replace(['&', '<', '>', '"', "'"], ['&amp;', '&lt;', '&gt;', '&quot;', '&apos;'], $value);
}

function rr_build_simple_xlsx($rows, $output_path) {
    if (!class_exists('ZipArchive')) return false;
    $zip = new ZipArchive();
    if ($zip->open($output_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return false;

    $sheet_rows = '';
    $row_num = 1;
    foreach ($rows as $row) {
        $sheet_rows .= '<row r="' . $row_num . '">';
        $col_num = 0;
        foreach ($row as $cell) {
            $col = rr_excel_col_letter($col_num);
            $cell_ref = $col . $row_num;
            $text = rr_excel_xml_escape($cell);
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

function rr_build_simple_xls($rows, $output_path) {
    $fh = @fopen($output_path, 'wb');
    if (!$fh) return false;
    $xml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<?mso-application progid="Excel.Sheet"?>'
        . '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
        . ' xmlns:o="urn:schemas-microsoft-com:office:office"'
        . ' xmlns:x="urn:schemas-microsoft-com:office:excel"'
        . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'
        . '<Styles><Style ss:ID="Header"><Font ss:Bold="1"/><Interior ss:Color="#F2F2F2" ss:Pattern="Solid"/></Style></Styles>'
        . '<Worksheet ss:Name="推薦內容"><Table>';
    foreach ($rows as $ridx => $row) {
        $xml .= '<Row>';
        foreach ($row as $cell) {
            $text = rr_excel_xml_escape((string)$cell);
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

function get_current_academic_year_roc() {
    // 學年度切換：8 月起算新學年度（例：2026/01 屬於 114 學年度；2026/09 屬於 115 學年度）
    $y = (int)date('Y');
    $m = (int)date('n');
    return ($m >= 8) ? ($y - 1911) : ($y - 1912);
}

function ensure_bonus_settings_yearly_table($conn) {
    if (!$conn) return;
    try {
        $t = $conn->query("SHOW TABLES LIKE 'bonus_settings_yearly'");
        if ($t && $t->num_rows > 0) return;
        $conn->query("CREATE TABLE bonus_settings_yearly (
            cohort_year INT PRIMARY KEY,
            amount INT NOT NULL DEFAULT 1500,
            updated_by VARCHAR(100) NOT NULL DEFAULT '',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Exception $e) {
        // ignore
    }
}

function ensure_bonus_year_row($conn, $cohort_year, $updated_by = '') {
    if (!$conn) return;
    $cohort_year = (int)$cohort_year;
    if ($cohort_year <= 0) return;
    ensure_bonus_settings_yearly_table($conn);
    try {
        $chk = $conn->prepare("SELECT cohort_year FROM bonus_settings_yearly WHERE cohort_year = ? LIMIT 1");
        if ($chk) {
            $chk->bind_param('i', $cohort_year);
            $chk->execute();
            $r = $chk->get_result();
            $exists = ($r && $r->num_rows > 0);
            $chk->close();
            if (!$exists) {
                $ins = $conn->prepare("INSERT INTO bonus_settings_yearly (cohort_year, amount, updated_by) VALUES (?, 1500, ?)");
                if ($ins) {
                    $ub = (string)$updated_by;
                    $ins->bind_param('is', $cohort_year, $ub);
                    @$ins->execute();
                    $ins->close();
                }
            }
        }
    } catch (Exception $e) {
        // ignore
    }
}

function get_bonus_amount_for_year($conn, $cohort_year) {
    $amount = 1500;
    if (!$conn) return $amount;
    $cohort_year = (int)$cohort_year;
    if ($cohort_year <= 0) return $amount;

    // 優先讀取年度表
    try {
        ensure_bonus_settings_yearly_table($conn);
        $r = $conn->prepare("SELECT amount FROM bonus_settings_yearly WHERE cohort_year = ? LIMIT 1");
        if ($r) {
            $r->bind_param('i', $cohort_year);
            $r->execute();
            $res = $r->get_result();
            if ($res && $row = $res->fetch_assoc()) {
                $amount = (int)($row['amount'] ?? 1500);
                if ($amount < 0) $amount = 1500;
                $r->close();
                return $amount;
            }
            $r->close();
        }
    } catch (Exception $e) {
        // ignore
    }

    // 相容舊表（bonus_settings id=1）
    try {
        $t = $conn->query("SHOW TABLES LIKE 'bonus_settings'");
        if ($t && $t->num_rows > 0) {
            $r = $conn->query("SELECT amount FROM bonus_settings WHERE id = 1 LIMIT 1");
            if ($r && $row = $r->fetch_assoc()) {
                $amount = (int)($row['amount'] ?? 1500);
                if ($amount < 0) $amount = 1500;
            }
        }
    } catch (Exception $e) {
        // ignore
    }

    return $amount;
}

function ensure_review_email_logs_table($conn) {
    if (!$conn) return;
    try {
        $t = $conn->query("SHOW TABLES LIKE 'recommendation_review_email_logs'");
        if ($t && $t->num_rows > 0) return;
        $conn->query("CREATE TABLE recommendation_review_email_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            recommendation_id INT NOT NULL,
            status_code VARCHAR(10) NOT NULL,
            recipient_email VARCHAR(255) NOT NULL DEFAULT '',
            template_name VARCHAR(50) NOT NULL DEFAULT '',
            bonus_amount INT NOT NULL DEFAULT 0,
            send_status VARCHAR(20) NOT NULL DEFAULT 'pending',
            error_message TEXT NULL,
            sent_by VARCHAR(100) NOT NULL DEFAULT '',
            sent_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_rec_status (recommendation_id, status_code),
            INDEX idx_rec (recommendation_id),
            INDEX idx_sent_at (sent_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Exception $e) {
        // ignore
    }
}

function get_bonus_total_amount($conn, $cohort_year = null) {
    if ($cohort_year === null) $cohort_year = get_current_academic_year_roc();
    return get_bonus_amount_for_year($conn, (int)$cohort_year);
}

// 同名通過者獎金分攤（若同名策略未啟用，通常只有 1 人通過）
function compute_bonus_amount_for_recommendation($conn, $recommendation_id) {
    $total = get_bonus_total_amount($conn, get_current_academic_year_roc());
    if (!$conn || $recommendation_id <= 0) return $total;

    $has_recommended = false;
    try {
        $t = $conn->query("SHOW TABLES LIKE 'recommended'");
        $has_recommended = ($t && $t->num_rows > 0);
    } catch (Exception $e) {
        $has_recommended = false;
    }

    $student_name = '';
    if ($has_recommended) {
        $s = $conn->prepare("SELECT COALESCE(red.name,'') AS student_name
            FROM admission_recommendations ar
            LEFT JOIN recommended red ON ar.id = red.recommendations_id
            WHERE ar.id = ? LIMIT 1");
        if ($s) {
            $s->bind_param('i', $recommendation_id);
            $s->execute();
            $r = $s->get_result();
            if ($r && $row = $r->fetch_assoc()) $student_name = trim((string)($row['student_name'] ?? ''));
            $s->close();
        }
    } else {
        $s = $conn->prepare("SELECT COALESCE(student_name,'') AS student_name FROM admission_recommendations WHERE id = ? LIMIT 1");
        if ($s) {
            $s->bind_param('i', $recommendation_id);
            $s->execute();
            $r = $s->get_result();
            if ($r && $row = $r->fetch_assoc()) $student_name = trim((string)($row['student_name'] ?? ''));
            $s->close();
        }
    }

    if ($student_name === '') return $total;

    $rows_ap = [];
    if ($has_recommended) {
        $q = $conn->prepare("SELECT ar.id
            FROM admission_recommendations ar
            LEFT JOIN recommended red ON ar.id = red.recommendations_id
            WHERE red.name = ? AND ar.status IN ('APD')
            ORDER BY ar.id ASC");
    } else {
        $q = $conn->prepare("SELECT ar.id
            FROM admission_recommendations ar
            WHERE ar.student_name = ? AND ar.status IN ('APD')
            ORDER BY ar.id ASC");
    }

    if ($q) {
        $q->bind_param('s', $student_name);
        $q->execute();
        $res = $q->get_result();
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $rows_ap[] = ['id' => (int)$r['id']];
            }
        }
        $q->close();
    }

    $n = max(1, count($rows_ap));
    if ($n === 1) return $total;

    $base = intdiv($total, $n);
    $rem = $total % $n;
    $idx = 0;
    for ($i = 0; $i < $n; $i++) {
        if ((int)$rows_ap[$i]['id'] === (int)$recommendation_id) { $idx = $i; break; }
    }
    return $base + (($idx < $rem) ? 1 : 0);
}

function fetch_recommendation_recommender_contact($conn, $recommendation_id) {
    $data = [
        'recommender_name' => '',
        'recommender_student_id' => '',
        'recommender_department' => '',
        'recommender_email' => '',
        'student_name' => '',
        'student_school' => '',
        'student_grade' => '',
        'student_phone' => '',
    ];
    if (!$conn || $recommendation_id <= 0) return $data;

    $has_recommender = false;
    $has_recommended = false;
    $has_school_data = false;
    $has_identity = false;
    $has_departments = false;
    try { $has_recommender = (($conn->query("SHOW TABLES LIKE 'recommender'"))->num_rows > 0); } catch (Exception $e) {}
    try { $has_recommended = (($conn->query("SHOW TABLES LIKE 'recommended'"))->num_rows > 0); } catch (Exception $e) {}
    try { $has_school_data = (($conn->query("SHOW TABLES LIKE 'school_data'"))->num_rows > 0); } catch (Exception $e) {}
    try { $has_identity = (($conn->query("SHOW TABLES LIKE 'identity_options'"))->num_rows > 0); } catch (Exception $e) {}
    try { $has_departments = (($conn->query("SHOW TABLES LIKE 'departments'"))->num_rows > 0); } catch (Exception $e) {}

    $select = "SELECT ar.id";
    $joins = " FROM admission_recommendations ar";

    if ($has_recommender) {
        $select .= ",
            COALESCE(rec.name,'') AS recommender_name,
            COALESCE(rec.id,'') AS recommender_student_id,
            COALESCE(rec.email,'') AS recommender_email,
            COALESCE(rd.name,'') AS recommender_department";
        $joins .= " LEFT JOIN recommender rec ON ar.id = rec.recommendations_id";
        if ($has_departments) $joins .= " LEFT JOIN departments rd ON rec.department = rd.code";
    } else {
        $select .= ",
            COALESCE(ar.recommender_name,'') AS recommender_name,
            COALESCE(ar.recommender_student_id,'') AS recommender_student_id,
            COALESCE(ar.recommender_email,'') AS recommender_email,
            COALESCE(ar.recommender_department,'') AS recommender_department";
    }

    if ($has_recommended) {
        $select .= ",
            COALESCE(red.name,'') AS student_name,
            COALESCE(sd.name,'') AS student_school,
            COALESCE(sg.name,'') AS student_grade,
            COALESCE(red.phone,'') AS student_phone";
        $joins .= " LEFT JOIN recommended red ON ar.id = red.recommendations_id";
        if ($has_school_data) $joins .= " LEFT JOIN school_data sd ON red.school = sd.school_code";
        if ($has_identity) $joins .= " LEFT JOIN identity_options sg ON red.grade = sg.code";
    } else {
        $select .= ",
            COALESCE(ar.student_name,'') AS student_name,
            COALESCE(ar.student_school,'') AS student_school,
            COALESCE(ar.student_grade,'') AS student_grade,
            COALESCE(ar.student_phone,'') AS student_phone";
    }

    $sql = $select . $joins . " WHERE ar.id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return $data;
    $stmt->bind_param('i', $recommendation_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $row = $res->fetch_assoc()) {
        $data['recommender_name'] = (string)($row['recommender_name'] ?? '');
        $data['recommender_student_id'] = (string)($row['recommender_student_id'] ?? '');
        $data['recommender_department'] = (string)($row['recommender_department'] ?? '');
        $data['recommender_email'] = (string)($row['recommender_email'] ?? '');
        $data['student_name'] = (string)($row['student_name'] ?? '');
        $data['student_school'] = (string)($row['student_school'] ?? '');
        $data['student_grade'] = (string)($row['student_grade'] ?? '');
        $data['student_phone'] = (string)($row['student_phone'] ?? '');
    }
    $stmt->close();
    return $data;
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
        if (!$chk_rr || $chk_rr->num_rows === 0) $conn->query("ALTER TABLE recommendation_approval_links ADD COLUMN reject_reason VARCHAR(255) DEFAULT NULL");
        $chk_group = $conn->query("SHOW COLUMNS FROM recommendation_approval_links LIKE 'group_ids'");
        if (!$chk_group || $chk_group->num_rows === 0) $conn->query("ALTER TABLE recommendation_approval_links ADD COLUMN group_ids TEXT NULL");
    } catch (Exception $e) {
        // ignore
    }
}

function send_review_result_email_once($conn, $recommendation_id, $status_code, $sent_by = '') {
    if (!$conn || $recommendation_id <= 0) return ['sent' => false, 'message' => 'invalid'];
    $status_code = trim((string)$status_code);
    if (!in_array($status_code, ['AP', 'RE'], true)) return ['sent' => false, 'message' => 'skip'];

    ensure_review_email_logs_table($conn);

    // 已成功寄過就跳過
    $chk = $conn->prepare("SELECT send_status FROM recommendation_review_email_logs WHERE recommendation_id = ? AND status_code = ? LIMIT 1");
    if ($chk) {
        $chk->bind_param('is', $recommendation_id, $status_code);
        $chk->execute();
        $r = $chk->get_result();
        if ($r && $row = $r->fetch_assoc()) {
            if (($row['send_status'] ?? '') === 'sent') {
                $chk->close();
                return ['sent' => false, 'message' => 'already_sent'];
            }
        }
        $chk->close();
    }

    $info = fetch_recommendation_recommender_contact($conn, $recommendation_id);
    $to_email = trim((string)($info['recommender_email'] ?? ''));
    if ($to_email === '') {
        return ['sent' => false, 'message' => 'no_email'];
    }

    $template_name = ($status_code === 'AP') ? 'approval_notification' : 'rejection_notification';
    $bonus_amount = ($status_code === 'AP') ? compute_bonus_amount_for_recommendation($conn, $recommendation_id) : 0;

    $payload = [
        'recommender_name' => $info['recommender_name'] ?? '',
        'recommender_student_id' => $info['recommender_student_id'] ?? '',
        'recommender_department' => $info['recommender_department'] ?? '',
        'student_name' => $info['student_name'] ?? '',
        'student_school' => $info['student_school'] ?? '',
        'student_grade' => $info['student_grade'] ?? '',
        'approval_time' => date('Y-m-d H:i:s'),
        'review_time' => date('Y-m-d H:i:s'),
        'bonus_amount' => (string)$bonus_amount,
    ];

    $ok = sendNotificationEmail($to_email, (string)($info['recommender_name'] ?? ''), $template_name, $payload);
    $send_status = $ok ? 'sent' : 'failed';
    $err = $ok ? null : 'email_send_failed';

    $up = $conn->prepare("INSERT INTO recommendation_review_email_logs
        (recommendation_id, status_code, recipient_email, template_name, bonus_amount, send_status, error_message, sent_by, sent_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            recipient_email = VALUES(recipient_email),
            template_name = VALUES(template_name),
            bonus_amount = VALUES(bonus_amount),
            send_status = VALUES(send_status),
            error_message = VALUES(error_message),
            sent_by = VALUES(sent_by),
            sent_at = VALUES(sent_at)");
    if ($up) {
        $rid = $recommendation_id;
        $sc = $status_code;
        $re = $to_email;
        $tn = $template_name;
        $ba = (int)$bonus_amount;
        $ss = $send_status;
        $em = $err;
        $sb = (string)$sent_by;
        $up->bind_param('isssisss', $rid, $sc, $re, $tn, $ba, $ss, $em, $sb);
        @$up->execute();
        $up->close();
    }

    return ['sent' => $ok, 'message' => $ok ? 'sent' : 'failed', 'bonus_amount' => $bonus_amount];
}

function send_director_approved_email_once($conn, $recommendation_id, $sent_by = '', $status_updated_at = '') {
    if (!$conn || $recommendation_id <= 0) return ['sent' => false, 'message' => 'invalid'];
    $status_code = 'APD';
    // 依需求先改為固定收件人，便於驗證自動寄信流程
    $to_email = '110534236@stu.ukn.edu.tw';

    ensure_review_email_logs_table($conn);

    $chk = $conn->prepare("SELECT send_status, recipient_email, sent_at FROM recommendation_review_email_logs WHERE recommendation_id = ? AND status_code = ? LIMIT 1");
    if ($chk) {
        $chk->bind_param('is', $recommendation_id, $status_code);
        $chk->execute();
        $r = $chk->get_result();
        if ($r && $row = $r->fetch_assoc()) {
            $already_recipient = trim((string)($row['recipient_email'] ?? ''));
            $already_sent = (($row['send_status'] ?? '') === 'sent' && strcasecmp($already_recipient, $to_email) === 0);
            if ($already_sent) {
                $status_ts = strtotime((string)$status_updated_at);
                $sent_ts = strtotime((string)($row['sent_at'] ?? ''));
                $status_has_newer_change = ($status_ts !== false && $sent_ts !== false && $status_ts > $sent_ts);
                // 若 APD 狀態在上次寄信後有再更新（例如 PE -> APD），允許重寄一次。
                if (!$status_has_newer_change) {
                    $chk->close();
                    return ['sent' => false, 'message' => 'already_sent'];
                }
            }
        }
        $chk->close();
    }

    $info = fetch_recommendation_recommender_contact($conn, $recommendation_id);
    if ($to_email === '') {
        return ['sent' => false, 'message' => 'no_email'];
    }

    $recommender_name = trim((string)($info['recommender_name'] ?? ''));
    $student_name = trim((string)($info['student_name'] ?? ''));
    $student_school = trim((string)($info['student_school'] ?? ''));
    $student_grade = trim((string)($info['student_grade'] ?? ''));
    $student_phone = trim((string)($info['student_phone'] ?? ''));
    $bonus_amount = compute_bonus_amount_for_recommendation($conn, $recommendation_id);

    $approval_link = '';
    ensure_recommendation_approval_links_table($conn);
    try {
        $token = function_exists('random_bytes') ? bin2hex(random_bytes(16)) : bin2hex(uniqid('', true));
        $ins = $conn->prepare("INSERT INTO recommendation_approval_links (recommendation_id, token, confirmed_by_email, group_ids) VALUES (?, ?, ?, ?)");
        if ($ins) {
            $group_ids = (string)$recommendation_id;
            $ins->bind_param('isss', $recommendation_id, $token, $to_email, $group_ids);
            @$ins->execute();
            $ins->close();
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $approval_link = $protocol . '://' . $host . '/Topics-frontend/frontend/recommendation_approval.php?token=' . urlencode($token);
        }
    } catch (Exception $e) {
        // ignore
    }

    $subject = '推薦學生審核通過（可發放獎金）通知';
    $body_lines = [];
    $body_lines[] = ($recommender_name !== '') ? ($recommender_name . ' 您好：') : '您好：';
    $body_lines[] = '您推薦的學生資訊已經完成主任簽核並審核通過。';
    if ($student_name !== '' || $student_school !== '' || $student_grade !== '') {
        $body_lines[] = '';
        if ($student_name !== '') $body_lines[] = '學生姓名：' . $student_name;
        if ($student_school !== '') $body_lines[] = '就讀學校：' . $student_school;
        if ($student_grade !== '') $body_lines[] = '年級：' . $student_grade;
        if ($student_phone !== '') $body_lines[] = '聯絡電話：' . $student_phone;
    }
    $body_lines[] = '可發放獎金金額：$' . number_format((int)$bonus_amount);
    if ($approval_link !== '') {
        $body_lines[] = '';
        $body_lines[] = '線上簽核連結：';
        $body_lines[] = $approval_link;
    }
    $body_lines[] = '';
    $body_lines[] = '感謝您的推薦與協助。';
    $body_text = implode("\n", $body_lines);
    $body_html = nl2br(htmlspecialchars($body_text, ENT_QUOTES, 'UTF-8'));

    $xlsx_supported = class_exists('ZipArchive');
    $excel_ext = $xlsx_supported ? 'xlsx' : 'xls';
    $excel_mime = $xlsx_supported
        ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        : 'application/vnd.ms-excel';
    $safe_name = preg_replace('/[^\w\-]+/u', '_', ($student_name !== '' ? $student_name : '被推薦人'));
    $rows = [[
        '被推薦人姓名', '聯絡電話', '學校', '可發放獎金金額', '線上簽核連結', '通知時間'
    ], [
        $student_name,
        $student_phone,
        $student_school,
        (string)$bonus_amount,
        $approval_link,
        date('Y-m-d H:i:s')
    ]];
    $attachment_path = '';
    $attachments = [];
    $tmp = tempnam(sys_get_temp_dir(), 'apd_mail_');
    if ($tmp !== false) {
        $attachment_path = $tmp . '.' . $excel_ext;
        @rename($tmp, $attachment_path);
        $ok_build = $xlsx_supported ? rr_build_simple_xlsx($rows, $attachment_path) : rr_build_simple_xls($rows, $attachment_path);
        if ($ok_build) {
            $attachments[] = [
                'path' => $attachment_path,
                'name' => '推薦審核通過_' . $safe_name . '_' . date('Ymd_His') . '.' . $excel_ext,
                'mime' => $excel_mime,
            ];
        }
    }

    $ok = false;
    if (function_exists('sendEmail')) {
        $ok = sendEmail($to_email, $subject, $body_html, $body_text, $attachments);
    }
    if ($attachment_path !== '' && file_exists($attachment_path)) @unlink($attachment_path);
    $send_status = $ok ? 'sent' : 'failed';
    $err = $ok ? null : 'email_send_failed';

    $up = $conn->prepare("INSERT INTO recommendation_review_email_logs
        (recommendation_id, status_code, recipient_email, template_name, bonus_amount, send_status, error_message, sent_by, sent_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            recipient_email = VALUES(recipient_email),
            template_name = VALUES(template_name),
            bonus_amount = VALUES(bonus_amount),
            send_status = VALUES(send_status),
            error_message = VALUES(error_message),
            sent_by = VALUES(sent_by),
            sent_at = VALUES(sent_at)");
    if ($up) {
        $rid = $recommendation_id;
        $sc = $status_code;
        $re = $to_email;
        $tn = 'director_approved_notification';
        $ba = (int)$bonus_amount;
        $ss = $send_status;
        $em = $err;
        $sb = (string)$sent_by;
        $up->bind_param('isssisss', $rid, $sc, $re, $tn, $ba, $ss, $em, $sb);
        @$up->execute();
        $up->close();
    }

    return ['sent' => $ok, 'message' => $ok ? 'sent' : 'failed'];
}

function ensure_bonus_send_email_logs_table($conn) {
    if (!$conn) return;
    try {
        $t = $conn->query("SHOW TABLES LIKE 'bonus_send_email_logs'");
        if ($t && $t->num_rows > 0) return;
        $conn->query("CREATE TABLE bonus_send_email_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            recommendation_id INT NOT NULL,
            recipient_email VARCHAR(255) NOT NULL DEFAULT '',
            amount INT NOT NULL DEFAULT 0,
            split_count INT NOT NULL DEFAULT 1,
            template_name VARCHAR(50) NOT NULL DEFAULT '',
            send_status VARCHAR(20) NOT NULL DEFAULT 'pending',
            error_message TEXT NULL,
            sent_by VARCHAR(100) NOT NULL DEFAULT '',
            sent_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_rec (recommendation_id),
            INDEX idx_sent_at (sent_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Exception $e) {
        // ignore
    }
}

function send_bonus_sent_email_once($conn, $recommendation_id, $amount, $split_count = 1, $sent_by = '') {
    if (!$conn || $recommendation_id <= 0) return ['sent' => false, 'message' => 'invalid'];
    $amount = (int)$amount;
    if ($amount < 0) $amount = 0;
    $split_count = max(1, (int)$split_count);

    ensure_bonus_send_email_logs_table($conn);

    // 已成功寄過就跳過
    $chk = $conn->prepare("SELECT send_status FROM bonus_send_email_logs WHERE recommendation_id = ? LIMIT 1");
    if ($chk) {
        $chk->bind_param('i', $recommendation_id);
        $chk->execute();
        $r = $chk->get_result();
        if ($r && $row = $r->fetch_assoc()) {
            if (($row['send_status'] ?? '') === 'sent') {
                $chk->close();
                return ['sent' => false, 'message' => 'already_sent'];
            }
        }
        $chk->close();
    }

    $info = fetch_recommendation_recommender_contact($conn, $recommendation_id);
    $to_email = trim((string)($info['recommender_email'] ?? ''));
    if ($to_email === '') return ['sent' => false, 'message' => 'no_email'];

    $template_name = 'bonus_sent_notification';
    $payload = [
        'recommender_name' => $info['recommender_name'] ?? '',
        'recommender_student_id' => $info['recommender_student_id'] ?? '',
        'recommender_department' => $info['recommender_department'] ?? '',
        'student_name' => $info['student_name'] ?? '',
        'student_school' => $info['student_school'] ?? '',
        'student_grade' => $info['student_grade'] ?? '',
        'bonus_amount' => (string)$amount,
        'split_count' => (string)$split_count,
        'sent_time' => date('Y-m-d H:i:s'),
    ];

    $ok = sendNotificationEmail($to_email, (string)($info['recommender_name'] ?? ''), $template_name, $payload);
    $send_status = $ok ? 'sent' : 'failed';
    $err = $ok ? null : 'email_send_failed';

    $up = $conn->prepare("INSERT INTO bonus_send_email_logs
        (recommendation_id, recipient_email, amount, split_count, template_name, send_status, error_message, sent_by, sent_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            recipient_email = VALUES(recipient_email),
            amount = VALUES(amount),
            split_count = VALUES(split_count),
            template_name = VALUES(template_name),
            send_status = VALUES(send_status),
            error_message = VALUES(error_message),
            sent_by = VALUES(sent_by),
            sent_at = VALUES(sent_at)");
    if ($up) {
        $rid = $recommendation_id;
        $re = $to_email;
        $am = $amount;
        $sc = $split_count;
        $tn = $template_name;
        $ss = $send_status;
        $em = $err;
        $sb = (string)$sent_by;
        $up->bind_param('isiissss', $rid, $re, $am, $sc, $tn, $ss, $em, $sb);
        @$up->execute();
        $up->close();
    }

    return ['sent' => $ok, 'message' => $ok ? 'sent' : 'failed'];
}

