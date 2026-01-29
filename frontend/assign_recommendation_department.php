<?php
require_once __DIR__ . '/session_config.php';

checkBackendLogin();

// 檢查權限：招生中心/管理員可分配推薦學生至主任
$user_role = $_SESSION['role'] ?? '';
$allowed_roles = ['STA', '行政人員', '學校行政人員', 'ADM', '管理員'];
if (!in_array($user_role, $allowed_roles, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '權限不足，只有招生中心/管理員可以進行此操作']);
    exit;
}

// 僅允許 POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '不支援的請求方法']);
    exit;
}

$recommendation_id = isset($_POST['recommendation_id']) ? intval($_POST['recommendation_id']) : 0;
$department_account = isset($_POST['department']) ? trim($_POST['department']) : '';

// 僅允許指定的部門帳號
$allowed_departments = ['IMD', 'FLD'];
if ($recommendation_id <= 0 || !in_array($department_account, $allowed_departments, true)) {
    echo json_encode(['success' => false, 'message' => '無效的參數']);
    exit;
}

require_once '../../Topics-frontend/frontend/config.php';
require_once __DIR__ . '/../../Topics-frontend/frontend/includes/email_functions.php';

try {
    $conn = getDatabaseConnection();

    // 檢查 admission_recommendations 是否存在欄位 assigned_department
    $check_column = $conn->query("SHOW COLUMNS FROM admission_recommendations LIKE 'assigned_department'");
    if ($check_column->num_rows == 0) {
        // 在 enrollment_status 之後添加
        $alter_sql = "ALTER TABLE admission_recommendations ADD COLUMN assigned_department VARCHAR(50) NULL AFTER enrollment_status";
        $conn->query($alter_sql);
    }

    // 檢查推薦記錄是否存在，並取得必要資訊
    $has_recommended_table = false;
    $table_check_rec = $conn->query("SHOW TABLES LIKE 'recommended'");
    if ($table_check_rec && $table_check_rec->num_rows > 0) $has_recommended_table = true;

    $ar_has = function($col) use ($conn) {
        $r = $conn->query("SHOW COLUMNS FROM admission_recommendations LIKE '$col'");
        return ($r && $r->num_rows > 0);
    };

    $ar_has_status = $ar_has('status');
    $ar_has_student_name = $ar_has('student_name');
    $ar_has_student_school = $ar_has('student_school');
    $ar_has_student_school_code = $ar_has('student_school_code');
    $ar_has_student_phone = $ar_has('student_phone');

    $select = "SELECT ar.id,
        " . ($ar_has_status ? "COALESCE(ar.status,'')" : "''") . " AS status,
        " . ($ar_has_student_name ? "COALESCE(ar.student_name,'')" : "''") . " AS ar_student_name,
        " . ($ar_has_student_school ? "COALESCE(ar.student_school,'')" : "''") . " AS ar_student_school,
        " . ($ar_has_student_school_code ? "COALESCE(ar.student_school_code,'')" : "''") . " AS ar_student_school_code,
        " . ($ar_has_student_phone ? "COALESCE(ar.student_phone,'')" : "''") . " AS ar_student_phone";

    if ($has_recommended_table) {
        $select .= ",
        COALESCE(red.name,'') AS red_name,
        COALESCE(red.school,'') AS red_school,
        COALESCE(red.phone,'') AS red_phone";
    } else {
        $select .= ",
        '' AS red_name,
        '' AS red_school,
        '' AS red_phone";
    }

    $from = " FROM admission_recommendations ar ";
    if ($has_recommended_table) {
        $from .= " LEFT JOIN recommended red ON ar.id = red.recommendations_id ";
    }

    $stmt = $conn->prepare($select . $from . " WHERE ar.id = ? LIMIT 1");
    $stmt->bind_param("i", $recommendation_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $recommendation = $result->fetch_assoc();
    if (!$recommendation) {
        echo json_encode(['success' => false, 'message' => '找不到指定的推薦記錄']);
        exit;
    }

    // 更新推薦記錄的部門分配
    $update = $conn->prepare("UPDATE admission_recommendations SET assigned_department = ? WHERE id = ?");
    $update->bind_param("si", $department_account, $recommendation_id);

    if ($update->execute()) {
        // 記錄分配日誌
        // 先檢查表是否存在，如果不存在則創建
        $table_check = $conn->query("SHOW TABLES LIKE 'recommendation_assignment_logs'");
        if ($table_check->num_rows == 0) {
            $conn->query("CREATE TABLE recommendation_assignment_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                recommendation_id INT NOT NULL,
                teacher_id INT NULL,
                assigned_by VARCHAR(100) NOT NULL,
                assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_recommendation_id (recommendation_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        // 插入日誌記錄
        $log_stmt = $conn->prepare("INSERT INTO recommendation_assignment_logs (recommendation_id, teacher_id, assigned_by, assigned_at) VALUES (?, NULL, ?, NOW())");
        $assigned_by = $_SESSION['username'];
        $log_stmt->bind_param("is", $recommendation_id, $assigned_by);
        $log_stmt->execute();

        // 若分配給資管科(IMD)且審核結果為不通過，寄信通知
        $status_code = trim((string)($recommendation['status'] ?? ''));
        $status_code_norm = strtolower($status_code);
        $is_rejected = in_array($status_code_norm, ['re', 'rejected', '不通過'], true);
        if ($department_account === 'IMD' && $is_rejected) {
            $student_name = trim((string)($recommendation['red_name'] ?? ''));
            if ($student_name === '') $student_name = trim((string)($recommendation['ar_student_name'] ?? ''));

            $student_school = trim((string)($recommendation['red_school'] ?? ''));
            if ($student_school === '') {
                $student_school = trim((string)($recommendation['ar_student_school'] ?? ''));
            }
            if ($student_school === '') {
                $student_school = trim((string)($recommendation['ar_student_school_code'] ?? ''));
            }

            $student_phone = trim((string)($recommendation['red_phone'] ?? ''));
            if ($student_phone === '') $student_phone = trim((string)($recommendation['ar_student_phone'] ?? ''));

            $to_email = '110511114@stu.ukn.edu.tw';
            $subject = '推薦學生重複推薦提醒';
            $body = "
                <div style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                    <p>此學生（被推薦學生姓名：{$student_name}、學校：{$student_school}、聯絡電話：{$student_phone}）已被其他人或其他科系老師/學生推薦，還請您確認後再告知我。</p>
                    <br>
                    <p>招生中心組長 高惠玲</p>
                    <p>聯絡電話：0900123123</p>
                    <p>分機：310</p>
                    <p>shirly02@g.ukn.edu.tw</p>
                </div>
            ";
            $altBody = "此學生（被推薦學生姓名：{$student_name}、學校：{$student_school}、聯絡電話：{$student_phone}）已被其他人或其他科系老師/學生推薦，還請您確認後再告知我。\n\n招生中心組長 高惠玲\n聯絡電話：0900123123\n分機：310\nshirly02@g.ukn.edu.tw";
            if (function_exists('sendEmail')) {
                @sendEmail($to_email, $subject, $body, $altBody);
            }
        }

        echo json_encode([
            'success' => true,
            'message' => '已分配推薦學生至主任帳號',
            'student_name' => $recommendation['ar_student_name'] ?? '',
            'department' => $department_account
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => '分配失敗：' . $conn->error]);
    }

    $conn->close();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '系統錯誤：' . $e->getMessage()]);
}
?>

