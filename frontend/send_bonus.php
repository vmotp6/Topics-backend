<?php
require_once __DIR__ . '/session_config.php';
checkBackendLogin();

header('Content-Type: application/json; charset=utf-8');

// 僅允許 POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '不支援的請求方法'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 權限：沿用審核結果可視權限（username=12 & role=STA）
$username = isset($_SESSION['username']) ? $_SESSION['username'] : '';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$can_view_review_result = ($username === '12' && $user_role === 'STA');
if (!$can_view_review_result) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '權限不足'], JSON_UNESCAPED_UNICODE);
    exit;
}

$recommendation_id = isset($_POST['recommendation_id']) ? intval($_POST['recommendation_id']) : 0;
if ($recommendation_id <= 0) {
    echo json_encode(['success' => false, 'message' => '無效的推薦ID'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once '../../Topics-frontend/frontend/config.php';

try {
    $conn = getDatabaseConnection();

    // 建立 log 表（若不存在）
    $table_check = $conn->query("SHOW TABLES LIKE 'bonus_send_logs'");
    if ($table_check && $table_check->num_rows == 0) {
        $conn->query("CREATE TABLE bonus_send_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            recommendation_id INT NOT NULL,
            recommender_name VARCHAR(100) NOT NULL DEFAULT '',
            recommender_student_id VARCHAR(50) NOT NULL DEFAULT '',
            amount INT NOT NULL DEFAULT 1500,
            sent_by VARCHAR(100) NOT NULL DEFAULT '',
            sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_recommendation_id (recommendation_id),
            INDEX idx_sent_at (sent_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } else {
        // 舊表補欄位
        $c = $conn->query("SHOW COLUMNS FROM bonus_send_logs LIKE 'amount'");
        if ($c && $c->num_rows == 0) {
            @$conn->query("ALTER TABLE bonus_send_logs ADD COLUMN amount INT NOT NULL DEFAULT 1500 AFTER recommender_student_id");
        }
    }

    // 建立設定表（若不存在）：獎金金額可調整
    $tset = $conn->query("SHOW TABLES LIKE 'bonus_settings'");
    if ($tset && $tset->num_rows == 0) {
        $conn->query("CREATE TABLE bonus_settings (
            id INT PRIMARY KEY,
            amount INT NOT NULL DEFAULT 1500,
            updated_by VARCHAR(100) NOT NULL DEFAULT '',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    // 確保 id=1 存在
    $exists = $conn->query("SELECT id FROM bonus_settings WHERE id = 1 LIMIT 1");
    if (!$exists || $exists->num_rows == 0) {
        $stmt_ins = $conn->prepare("INSERT INTO bonus_settings (id, amount, updated_by) VALUES (1, 1500, ?)");
        if ($stmt_ins) {
            $stmt_ins->bind_param('s', $username);
            @$stmt_ins->execute();
            $stmt_ins->close();
        }
    }
    // 讀取目前金額
    $bonus_amount = 1500;
    $rset = $conn->query("SELECT amount FROM bonus_settings WHERE id = 1 LIMIT 1");
    if ($rset && $rr = $rset->fetch_assoc()) {
        $bonus_amount = intval($rr['amount'] ?? 1500);
        if ($bonus_amount < 0) $bonus_amount = 1500;
    }

    // 取得推薦人資料（優先 recommender 表）與被推薦人姓名（用於獎金平分）
    $has_recommender_table = false;
    $t = $conn->query("SHOW TABLES LIKE 'recommender'");
    $has_recommender_table = ($t && $t->num_rows > 0);
    $has_recommended_table = false;
    $t2 = $conn->query("SHOW TABLES LIKE 'recommended'");
    $has_recommended_table = ($t2 && $t2->num_rows > 0);

    $recommender_name = '';
    $recommender_student_id = '';
    $student_name = '';
    $created_at = '';

    // 同時帶出學生姓名（recommended.name 或 ar.student_name 向後相容）與 created_at（用於平分餘數排序）
    if ($has_recommender_table && $has_recommended_table) {
        $stmt = $conn->prepare("SELECT 
                COALESCE(rec.name,'') AS recommender_name,
                COALESCE(rec.id,'') AS recommender_student_id,
                COALESCE(ar.status,'') AS status,
                COALESCE(red.name,'') AS student_name,
                COALESCE(ar.created_at,'') AS created_at
            FROM admission_recommendations ar
            LEFT JOIN recommender rec ON ar.id = rec.recommendations_id
            LEFT JOIN recommended red ON ar.id = red.recommendations_id
            WHERE ar.id = ? LIMIT 1");
    } elseif ($has_recommender_table) {
        $stmt = $conn->prepare("SELECT 
                COALESCE(rec.name,'') AS recommender_name,
                COALESCE(rec.id,'') AS recommender_student_id,
                COALESCE(ar.status,'') AS status,
                COALESCE(ar.student_name,'') AS student_name,
                COALESCE(ar.created_at,'') AS created_at
            FROM admission_recommendations ar
            LEFT JOIN recommender rec ON ar.id = rec.recommendations_id
            WHERE ar.id = ? LIMIT 1");
    } elseif ($has_recommended_table) {
        $stmt = $conn->prepare("SELECT 
                COALESCE(ar.recommender_name,'') AS recommender_name,
                COALESCE(ar.recommender_student_id,'') AS recommender_student_id,
                COALESCE(ar.status,'') AS status,
                COALESCE(red.name,'') AS student_name,
                COALESCE(ar.created_at,'') AS created_at
            FROM admission_recommendations ar
            LEFT JOIN recommended red ON ar.id = red.recommendations_id
            WHERE ar.id = ? LIMIT 1");
    } else {
        $stmt = $conn->prepare("SELECT 
                COALESCE(ar.recommender_name,'') AS recommender_name,
                COALESCE(ar.recommender_student_id,'') AS recommender_student_id,
                COALESCE(ar.status,'') AS status,
                COALESCE(ar.student_name,'') AS student_name,
                COALESCE(ar.created_at,'') AS created_at
            FROM admission_recommendations ar
            WHERE ar.id = ? LIMIT 1");
    }

    if (!$stmt) {
        throw new Exception('SQL 準備失敗: ' . $conn->error);
    }
    $stmt->bind_param('i', $recommendation_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        echo json_encode(['success' => false, 'message' => '找不到指定的推薦記錄'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $recommender_name = trim((string)($row['recommender_name'] ?? ''));
    $recommender_student_id = trim((string)($row['recommender_student_id'] ?? ''));
    $status = trim((string)($row['status'] ?? ''));
    $student_name = trim((string)($row['student_name'] ?? ''));
    $created_at = (string)($row['created_at'] ?? '');

    // 只允許「通過」(AP) 發送（向後相容 approved）
    if (!in_array($status, ['AP', 'approved', 'APPROVED'], true)) {
        echo json_encode(['success' => false, 'message' => '僅通過名單可發送獎金'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 若已存在則拒絕重複發送
    $check = $conn->prepare("SELECT id, sent_at FROM bonus_send_logs WHERE recommendation_id = ? LIMIT 1");
    $check->bind_param('i', $recommendation_id);
    $check->execute();
    $chkRes = $check->get_result();
    if ($chkRes && $chkRes->num_rows > 0) {
        $exist = $chkRes->fetch_assoc();
        $check->close();
        echo json_encode([
            'success' => false,
            'message' => '此筆已發送過獎金，不能重複發送',
            'recommender_name' => $recommender_name,
            'sent_at' => $exist['sent_at'] ?? ''
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $check->close();

    // -----------------------------
    // 同名且通過者獎金平分（餘數依 created_at/ID 由早到晚分配 +1）
    // -----------------------------
    $final_amount = $bonus_amount;
    $split_count = 1;
    if ($student_name !== '') {
        $rows_ap = [];
        if ($has_recommended_table) {
            $q = $conn->prepare("SELECT ar.id, ar.created_at
                FROM admission_recommendations ar
                LEFT JOIN recommended red ON ar.id = red.recommendations_id
                WHERE red.name = ? AND ar.status IN ('AP','approved','APPROVED')
                ORDER BY ar.created_at ASC, ar.id ASC");
            if ($q) {
                $q->bind_param('s', $student_name);
                $q->execute();
                $res2 = $q->get_result();
                if ($res2) {
                    while ($r2 = $res2->fetch_assoc()) {
                        $rows_ap[] = ['id' => (int)$r2['id'], 'created_at' => (string)$r2['created_at']];
                    }
                }
                $q->close();
            }
        } else {
            // 向後相容：若沒有 recommended 表，改用 admission_recommendations.student_name
            $q = $conn->prepare("SELECT ar.id, ar.created_at
                FROM admission_recommendations ar
                WHERE ar.student_name = ? AND ar.status IN ('AP','approved','APPROVED')
                ORDER BY ar.created_at ASC, ar.id ASC");
            if ($q) {
                $q->bind_param('s', $student_name);
                $q->execute();
                $res2 = $q->get_result();
                if ($res2) {
                    while ($r2 = $res2->fetch_assoc()) {
                        $rows_ap[] = ['id' => (int)$r2['id'], 'created_at' => (string)$r2['created_at']];
                    }
                }
                $q->close();
            }
        }

        $split_count = max(1, count($rows_ap));
        if ($split_count > 1) {
            $base = intdiv($bonus_amount, $split_count);
            $rem = $bonus_amount % $split_count;
            $idx = -1;
            for ($i = 0; $i < $split_count; $i++) {
                if ((int)$rows_ap[$i]['id'] === (int)$recommendation_id) { $idx = $i; break; }
            }
            if ($idx < 0) $idx = 0;
            $final_amount = $base + (($idx < $rem) ? 1 : 0);
        } else {
            $final_amount = $bonus_amount;
        }
    }

    $ins = $conn->prepare("INSERT INTO bonus_send_logs (recommendation_id, recommender_name, recommender_student_id, amount, sent_by, sent_at)
        VALUES (?, ?, ?, ?, ?, NOW())");
    $sent_by = (string)$username;
    $ins->bind_param('issis', $recommendation_id, $recommender_name, $recommender_student_id, $final_amount, $sent_by);
    if (!$ins->execute()) {
        throw new Exception('寫入失敗: ' . $ins->error);
    }
    $ins->close();

    echo json_encode([
        'success' => true,
        'message' => '獎金已標記為發送',
        'recommender_name' => $recommender_name,
        'amount' => $final_amount,
        'split_count' => $split_count,
        'student_name' => $student_name
    ], JSON_UNESCAPED_UNICODE);

    $conn->close();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '系統錯誤：' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

