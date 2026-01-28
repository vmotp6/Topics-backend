<?php
require_once __DIR__ . '/session_config.php';
checkBackendLogin();

header('Content-Type: application/json; charset=utf-8');

// 權限：沿用審核結果可視權限（username=12 & role=STA）
$username = isset($_SESSION['username']) ? $_SESSION['username'] : '';
$can_view_review_result = (isStaff() || isAdmin());
if (!$can_view_review_result) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '權限不足'], JSON_UNESCAPED_UNICODE);
    exit;
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => '無效的推薦ID'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once '../../Topics-frontend/frontend/config.php';

try {
    $conn = getDatabaseConnection();

    // 表存在性檢查
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

    $joins = "";
    $select = "SELECT ar.id,
        COALESCE(ar.recommendation_reason,'') AS recommendation_reason,
        COALESCE(ar.created_at,'') AS created_at";

    if ($has_recommender) {
        $select .= ",
        COALESCE(rec.name,'') AS recommender_name,
        COALESCE(rec.id,'') AS recommender_student_id,
        COALESCE(rec.phone,'') AS recommender_phone,
        COALESCE(rec.email,'') AS recommender_email,
        COALESCE(rec.grade,'') AS recommender_grade_code,
        COALESCE(rec.department,'') AS recommender_department_code";
        $joins .= " LEFT JOIN recommender rec ON ar.id = rec.recommendations_id";
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
        // fallback 舊欄位
        $select .= ",
        COALESCE(ar.recommender_name,'') AS recommender_name,
        COALESCE(ar.recommender_student_id,'') AS recommender_student_id,
        COALESCE(ar.recommender_phone,'') AS recommender_phone,
        COALESCE(ar.recommender_email,'') AS recommender_email,
        COALESCE(ar.recommender_grade,'') AS recommender_grade,
        COALESCE(ar.recommender_department,'') AS recommender_department";
    }

    if ($has_recommended) {
        $select .= ",
        COALESCE(red.name,'') AS student_name,
        COALESCE(red.phone,'') AS student_phone,
        COALESCE(red.email,'') AS student_email,
        COALESCE(red.line_id,'') AS student_line_id,
        COALESCE(red.grade,'') AS student_grade_code,
        COALESCE(red.school,'') AS student_school_code";
        $joins .= " LEFT JOIN recommended red ON ar.id = red.recommendations_id";
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
        // fallback 舊欄位
        $select .= ",
        COALESCE(ar.student_name,'') AS student_name,
        COALESCE(ar.student_phone,'') AS student_phone,
        COALESCE(ar.student_email,'') AS student_email,
        COALESCE(ar.student_line_id,'') AS student_line_id,
        COALESCE(ar.student_grade,'') AS student_grade,
        COALESCE(ar.student_school,'') AS student_school";
    }

    // 學生興趣（主表 student_interest）
    if ($has_departments) {
        $select .= ", COALESCE(di.name,'') AS student_interest";
        $joins .= " LEFT JOIN departments di ON ar.student_interest = di.code";
    } else {
        $select .= ", COALESCE(ar.student_interest,'') AS student_interest";
    }

    $sql = $select . " FROM admission_recommendations ar " . $joins . " WHERE ar.id = ? LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('SQL 準備失敗: ' . $conn->error);
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    $conn->close();

    if (!$row) {
        echo json_encode(['success' => false, 'message' => '找不到資料'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 統一輸出欄位（前端直接用）
    $data = [
        'id' => $id,
        'student_name' => $row['student_name'] ?? '',
        'student_school' => $row['student_school'] ?? '',
        'student_grade' => $row['student_grade'] ?? '',
        'student_email' => $row['student_email'] ?? '',
        'student_phone' => $row['student_phone'] ?? '',
        'student_line_id' => $row['student_line_id'] ?? '',
        'student_interest' => $row['student_interest'] ?? '',
        'recommender_name' => $row['recommender_name'] ?? '',
        'recommender_student_id' => $row['recommender_student_id'] ?? '',
        'recommender_grade' => $row['recommender_grade'] ?? '',
        'recommender_department' => $row['recommender_department'] ?? '',
        'recommender_phone' => $row['recommender_phone'] ?? '',
        'recommender_email' => $row['recommender_email'] ?? '',
        'recommendation_reason' => $row['recommendation_reason'] ?? '',
        'created_at' => $row['created_at'] ?? '',
    ];

    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '系統錯誤：' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

