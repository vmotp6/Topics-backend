<?php
require_once __DIR__ . '/session_config.php';
checkBackendLogin();
require_once __DIR__ . '/../../Topics-frontend/frontend/config.php';

header('Content-Type: application/json; charset=utf-8');

$recommendation_id = isset($_POST['recommendation_id']) ? (int)$_POST['recommendation_id'] : 0;
if ($recommendation_id <= 0) {
    echo json_encode(['success' => false, 'message' => '缺少 recommendation_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $conn = getDatabaseConnection();

    $hasColumn = function($table, $column) use ($conn) {
        $table = trim((string)$table);
        $column = trim((string)$column);
        if ($table === '' || $column === '') return false;
        $stmt = $conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        if (!$stmt) return false;
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $cnt = 0;
        $stmt->bind_result($cnt);
        $stmt->fetch();
        $stmt->close();
        return ((int)$cnt > 0);
    };

    $t = $conn->query("SHOW TABLES LIKE 'recommendation_approval_links'");
    if (!$t || $t->num_rows <= 0) {
        echo json_encode(['success' => true, 'has_result' => false], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $has_result_col = $hasColumn('recommendation_approval_links', 'decision_result_json');
    $has_reason_col = $hasColumn('recommendation_approval_links', 'decision_reason_json');
    $result_expr = $has_result_col ? "COALESCE(decision_result_json,'')" : "''";
    $reason_expr = $has_reason_col ? "COALESCE(decision_reason_json,'')" : "''";

    $sql = "SELECT id, status, COALESCE(group_ids,'') AS group_ids, COALESCE(reject_reason,'') AS reject_reason,
            {$result_expr} AS decision_result_json, {$reason_expr} AS decision_reason_json,
            DATE_FORMAT(COALESCE(signed_at, created_at), '%Y-%m-%d %H:%i:%s') AS signed_at
        FROM recommendation_approval_links
        WHERE (recommendation_id = ? OR FIND_IN_SET(?, COALESCE(group_ids,'')) > 0)
          AND status IN ('signed','rejected')
        ORDER BY COALESCE(signed_at, created_at) DESC, id DESC
        LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => '查詢準備失敗'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $rid_text = (string)$recommendation_id;
    $stmt->bind_param('is', $recommendation_id, $rid_text);
    $stmt->execute();
    $res = $stmt->get_result();
    $link = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$link) {
        echo json_encode(['success' => true, 'has_result' => false], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $decision_map = [];
    $reason_map = [];
    $decoded_result = json_decode((string)($link['decision_result_json'] ?? ''), true);
    if (is_array($decoded_result)) {
        foreach ($decoded_result as $k => $v) {
            $idv = (int)$k;
            $vv = trim((string)$v);
            if ($idv > 0 && in_array($vv, ['pass', 'fail'], true)) $decision_map[$idv] = $vv;
        }
    }
    $decoded_reason = json_decode((string)($link['decision_reason_json'] ?? ''), true);
    if (is_array($decoded_reason)) {
        foreach ($decoded_reason as $k => $v) {
            $idv = (int)$k;
            $vv = trim((string)$v);
            if ($idv > 0 && $vv !== '') $reason_map[$idv] = $vv;
        }
    }

    $target_ids = [];
    foreach (array_keys($decision_map) as $idv) $target_ids[(int)$idv] = true;
    $group_ids = trim((string)($link['group_ids'] ?? ''));
    if ($group_ids !== '') {
        foreach (explode(',', $group_ids) as $raw_id) {
            $idv = (int)trim((string)$raw_id);
            if ($idv > 0) $target_ids[$idv] = true;
        }
    }
    if (empty($target_ids)) $target_ids[$recommendation_id] = true;
    $id_list = array_values(array_keys($target_ids));

    $has_recommender_table = false;
    $t1 = $conn->query("SHOW TABLES LIKE 'recommender'");
    if ($t1 && $t1->num_rows > 0) $has_recommender_table = true;

    $ar_has_recommender_name = $hasColumn('admission_recommendations', 'recommender_name');
    $ar_has_recommender_student_id = $hasColumn('admission_recommendations', 'recommender_student_id');
    $ar_has_status = $hasColumn('admission_recommendations', 'status');

    $rec_name_expr = $has_recommender_table
        ? "COALESCE(rec.name, " . ($ar_has_recommender_name ? "ar.recommender_name" : "''") . ", '')"
        : ($ar_has_recommender_name ? "COALESCE(ar.recommender_name,'')" : "''");
    $rec_sid_expr = $has_recommender_table
        ? "COALESCE(rec.id, " . ($ar_has_recommender_student_id ? "ar.recommender_student_id" : "''") . ", '')"
        : ($ar_has_recommender_student_id ? "COALESCE(ar.recommender_student_id,'')" : "''");
    $status_expr = $ar_has_status ? "COALESCE(ar.status,'')" : "''";

    $ph = implode(',', array_fill(0, count($id_list), '?'));
    $sqlRows = "SELECT ar.id, {$rec_name_expr} AS recommender_name, {$rec_sid_expr} AS recommender_student_id, {$status_expr} AS status
        FROM admission_recommendations ar
        " . ($has_recommender_table ? "LEFT JOIN recommender rec ON ar.id = rec.recommendations_id" : "") . "
        WHERE ar.id IN ({$ph})
        ORDER BY ar.id ASC";
    $stmtRows = $conn->prepare($sqlRows);
    if (!$stmtRows) {
        echo json_encode(['success' => false, 'message' => '明細查詢準備失敗'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $types = str_repeat('i', count($id_list));
    $bindParams = [$types];
    foreach ($id_list as $idv) $bindParams[] = $idv;
    $refs = [];
    foreach ($bindParams as $k => $v) $refs[$k] = &$bindParams[$k];
    call_user_func_array([$stmtRows, 'bind_param'], $refs);
    $stmtRows->execute();
    $resRows = $stmtRows->get_result();
    $rows = [];
    if ($resRows) {
        while ($r = $resRows->fetch_assoc()) {
            $idv = (int)($r['id'] ?? 0);
            $decision = trim((string)($decision_map[$idv] ?? ''));
            if ($decision === '') {
                $st = strtolower(trim((string)($r['status'] ?? '')));
                if ($st === 'apd') $decision = 'pass';
                elseif ($st === 'apdr') $decision = 'fail';
            }
            $result_text = ($decision === 'pass') ? '通過' : (($decision === 'fail') ? '不通過' : '未填寫');
            $rows[] = [
                'recommendation_id' => $idv,
                'recommender_name' => (string)($r['recommender_name'] ?? ''),
                'recommender_student_id' => (string)($r['recommender_student_id'] ?? ''),
                'result' => $decision,
                'result_text' => $result_text,
                'reason' => (string)($reason_map[$idv] ?? ''),
            ];
        }
    }
    $stmtRows->close();

    echo json_encode([
        'success' => true,
        'has_result' => true,
        'signed_at' => (string)($link['signed_at'] ?? ''),
        'rows' => $rows
    ], JSON_UNESCAPED_UNICODE);
    $conn->close();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '系統錯誤：' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
