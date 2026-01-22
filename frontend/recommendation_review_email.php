<?php
// 推薦審核結果通知（通過/不通過）共用函式

require_once __DIR__ . '/../../Topics-frontend/frontend/config.php';
require_once __DIR__ . '/../../Topics-frontend/frontend/config/email_notification_config.php';

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

function get_bonus_total_amount($conn) {
    $amount = 1500;
    if (!$conn) return $amount;
    try {
        $t = $conn->query("SHOW TABLES LIKE 'bonus_settings'");
        if ($t && $t->num_rows > 0) {
            $r = $conn->query("SELECT amount FROM bonus_settings WHERE id = 1 LIMIT 1");
            if ($r && $row = $r->fetch_assoc()) {
                $amount = (int)($row['amount'] ?? 1500);
                if ($amount <= 0) $amount = 1500;
            }
        }
    } catch (Exception $e) {
        // ignore
    }
    return $amount;
}

// 同名通過者獎金分攤（若同名策略未啟用，通常只有 1 人通過）
function compute_bonus_amount_for_recommendation($conn, $recommendation_id) {
    $total = get_bonus_total_amount($conn);
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
        $q = $conn->prepare("SELECT ar.id, ar.created_at
            FROM admission_recommendations ar
            LEFT JOIN recommended red ON ar.id = red.recommendations_id
            WHERE red.name = ? AND ar.status IN ('AP','approved','APPROVED')
            ORDER BY ar.created_at ASC, ar.id ASC");
    } else {
        $q = $conn->prepare("SELECT ar.id, ar.created_at
            FROM admission_recommendations ar
            WHERE ar.student_name = ? AND ar.status IN ('AP','approved','APPROVED')
            ORDER BY ar.created_at ASC, ar.id ASC");
    }

    if ($q) {
        $q->bind_param('s', $student_name);
        $q->execute();
        $res = $q->get_result();
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $rows_ap[] = ['id' => (int)$r['id'], 'created_at' => (string)$r['created_at']];
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
            COALESCE(sg.name,'') AS student_grade";
        $joins .= " LEFT JOIN recommended red ON ar.id = red.recommendations_id";
        if ($has_school_data) $joins .= " LEFT JOIN school_data sd ON red.school = sd.school_code";
        if ($has_identity) $joins .= " LEFT JOIN identity_options sg ON red.grade = sg.code";
    } else {
        $select .= ",
            COALESCE(ar.student_name,'') AS student_name,
            COALESCE(ar.student_school,'') AS student_school,
            COALESCE(ar.student_grade,'') AS student_grade";
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
    }
    $stmt->close();
    return $data;
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

