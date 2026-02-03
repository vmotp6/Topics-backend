<?php
require_once __DIR__ . '/session_config.php';

checkBackendLogin();

// 檢查權限：招生中心/管理員可分配推薦學生至主任
$user_role = $_SESSION['role'] ?? '';
$allowed_roles = ['STA', '招生中心', '行政人員', '學校行政人員', 'ADM', '管理員'];
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

    // 簽核連結表（用於線上簽名確認）
    $conn->query("CREATE TABLE IF NOT EXISTS recommendation_approval_links (
        id INT AUTO_INCREMENT PRIMARY KEY,
        recommendation_id INT NOT NULL,
        token VARCHAR(64) NOT NULL UNIQUE,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        signature_path VARCHAR(255) DEFAULT NULL,
        signer_name VARCHAR(100) DEFAULT NULL,
        reject_reason VARCHAR(255) DEFAULT NULL,
        confirmed_by_email VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        signed_at TIMESTAMP NULL DEFAULT NULL,
        INDEX idx_rec_id (recommendation_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $chk_rr = $conn->query("SHOW COLUMNS FROM recommendation_approval_links LIKE 'reject_reason'");
    if (!$chk_rr || $chk_rr->num_rows === 0) {
        $conn->query("ALTER TABLE recommendation_approval_links ADD COLUMN reject_reason VARCHAR(255) DEFAULT NULL");
    }

    // 檢查 admission_recommendations 是否存在欄位 assigned_department
    $check_column = $conn->query("SHOW COLUMNS FROM admission_recommendations LIKE 'assigned_department'");
    if ($check_column->num_rows == 0) {
        // 在 enrollment_status 之後添加
        $alter_sql = "ALTER TABLE admission_recommendations ADD COLUMN assigned_department VARCHAR(50) NULL AFTER enrollment_status";
        $conn->query($alter_sql);
    }

    // 檢查推薦記錄是否存在，並取得必要資訊
    $has_recommender_table = false;
    $table_check_recommender = $conn->query("SHOW TABLES LIKE 'recommender'");
    if ($table_check_recommender && $table_check_recommender->num_rows > 0) $has_recommender_table = true;
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
    $ar_has_recommender_name = $ar_has('recommender_name');
    $ar_has_recommender_student_id = $ar_has('recommender_student_id');
    $ar_has_recommender_department_code = $ar_has('recommender_department_code');
    $ar_has_recommender_department = $ar_has('recommender_department');
    $ar_has_recommender_grade_code = $ar_has('recommender_grade_code');

    $select = "SELECT ar.id,
        " . ($ar_has_status ? "COALESCE(ar.status,'')" : "''") . " AS status,
        " . ($ar_has_student_name ? "COALESCE(ar.student_name,'')" : "''") . " AS ar_student_name,
        " . ($ar_has_student_school ? "COALESCE(ar.student_school,'')" : "''") . " AS ar_student_school,
        " . ($ar_has_student_school_code ? "COALESCE(ar.student_school_code,'')" : "''") . " AS ar_student_school_code,
        " . ($ar_has_student_phone ? "COALESCE(ar.student_phone,'')" : "''") . " AS ar_student_phone";

    if ($has_recommender_table) {
        $select .= ",
        COALESCE(rec.name,'') AS rec_name,
        COALESCE(rec.id,'') AS rec_student_id,
        COALESCE(rec.department,'') AS rec_department,
        COALESCE(rec.grade,'') AS rec_grade";
    } else {
        $select .= ",
        " . ($ar_has_recommender_name ? "COALESCE(ar.recommender_name,'')" : "''") . " AS rec_name,
        " . ($ar_has_recommender_student_id ? "COALESCE(ar.recommender_student_id,'')" : "''") . " AS rec_student_id,
        " . ($ar_has_recommender_department_code ? "COALESCE(ar.recommender_department_code,'')" : ($ar_has_recommender_department ? "COALESCE(ar.recommender_department,'')" : "''")) . " AS rec_department,
        " . ($ar_has_recommender_grade_code ? "COALESCE(ar.recommender_grade_code,'')" : "''") . " AS rec_grade";
    }

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
    if ($has_recommender_table) {
        $from .= " LEFT JOIN recommender rec ON ar.id = rec.recommendations_id ";
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

        // 若分配給資管科(IMD)且審核結果為不通過/通過，寄信通知
        $status_code = trim((string)($recommendation['status'] ?? ''));
        $status_code_norm = strtolower($status_code);
        $is_rejected = in_array($status_code_norm, ['re', 'rejected', '不通過'], true);
        $is_approved = in_array($status_code_norm, ['ap', 'approved', '通過'], true);
        if ($department_account === 'IMD' && ($is_rejected || $is_approved)) {
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

            $to_email = '110534236@stu.ukn.edu.tw';
            $approval_link = '';
            if ($is_approved) {
                // 產生簽核連結
                $token = '';
                if (function_exists('random_bytes')) {
                    $token = bin2hex(random_bytes(16));
                } elseif (function_exists('openssl_random_pseudo_bytes')) {
                    $token = bin2hex(openssl_random_pseudo_bytes(16));
                } else {
                    $token = bin2hex(uniqid('', true));
                }
                $ins = $conn->prepare("INSERT INTO recommendation_approval_links (recommendation_id, token, confirmed_by_email) VALUES (?, ?, ?)");
                $confirm_email = '110534236@stu.ukn.edu.tw';
                if ($ins) {
                    $ins->bind_param('iss', $recommendation_id, $token, $confirm_email);
                    @$ins->execute();
                    $ins->close();
                }
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $approval_link = $protocol . '://' . $host . '/Topics-frontend/frontend/recommendation_approval.php?token=' . urlencode($token);

                // 取得主任姓名（user 表 name）
                $director_name = '';
                $has_director_table = false;
                $has_user_table = false;
                $td = $conn->query("SHOW TABLES LIKE 'director'");
                if ($td && $td->num_rows > 0) $has_director_table = true;
                $tu = $conn->query("SHOW TABLES LIKE 'user'");
                if ($tu && $tu->num_rows > 0) $has_user_table = true;
                if ($has_director_table && $has_user_table) {
                    $stmt_dir = $conn->prepare("SELECT u.name FROM director d JOIN user u ON d.user_id = u.id WHERE d.department = ? LIMIT 1");
                    if ($stmt_dir) {
                        $stmt_dir->bind_param('s', $department_account);
                        if ($stmt_dir->execute()) {
                            $res_dir = $stmt_dir->get_result();
                            if ($res_dir && ($row_dir = $res_dir->fetch_assoc())) {
                                $director_name = trim((string)($row_dir['name'] ?? ''));
                            }
                        }
                        $stmt_dir->close();
                    }
                }
                if ($director_name === '' && $has_user_table) {
                    $stmt_dir = $conn->prepare("SELECT name FROM user WHERE role IN ('DI','主任','資管科主任') AND (username = ? OR username = ?) LIMIT 1");
                    if ($stmt_dir) {
                        $u1 = 'IMD';
                        $u2 = 'IM';
                        $stmt_dir->bind_param('ss', $u1, $u2);
                        if ($stmt_dir->execute()) {
                            $res_dir = $stmt_dir->get_result();
                            if ($res_dir && ($row_dir = $res_dir->fetch_assoc())) {
                                $director_name = trim((string)($row_dir['name'] ?? ''));
                            }
                        }
                        $stmt_dir->close();
                    }
                }
                $greeting = ($director_name !== '') ? ($director_name . '主任您好：') : '主任您好：';

                // 推薦人資訊
                $rec_name = trim((string)($recommendation['rec_name'] ?? ''));
                $rec_sid = trim((string)($recommendation['rec_student_id'] ?? ''));
                $rec_dept = trim((string)($recommendation['rec_department'] ?? ''));
                $rec_grade = trim((string)($recommendation['rec_grade'] ?? ''));

                $rec_identity = '教師';
                if ($rec_sid !== '' || preg_match('/^F[1-5]$/', $rec_grade)) {
                    $rec_identity = '在校生';
                }

                $dept_label = $department_account;
                if ($department_account === 'IMD') $dept_label = '資管科';
                if ($department_account === 'FLD') $dept_label = '應用外語科';

                $subject = '推薦學生審核通過通知';
                $body = "
                    <div style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                        <p>{$greeting}</p>
                        <p>本信件為招生中心通知</p>
                        <p>近日收到一筆「招生推薦」資料，該筆資料已由招生中心完成初步審核並確認無誤，現需再請主任協助進行最終資訊確認並完成線上簽名</p>
                        <p>推薦學生資料摘要如下：</p>
                        <p>• 推薦學生學號和姓名：{$rec_sid} {$rec_name}</p>
                        <p>• 推薦學生科系：{$rec_dept}</p>
                        <p>• 被推薦人姓名：{$student_name}</p>
                        <p>• 就讀國中：{$student_school}</p>
                        <p>• 聯絡電話：{$student_phone}</p>
                        <p>• 推薦科系：{$dept_label}</p>
                        <p>• 推薦人身分：{$rec_identity}</p>
                        <p>請主任點擊下方連結進行線上審核與簽核：</p>
                        <p><a href='{$approval_link}' target='_blank' rel='noopener'>【網頁連結】</a></p>
                        <p>若資料無誤，請於系統中完成線上簽核；如資料有誤，可於系統中填寫不通過原因退回。</p>
                        <p>本審核結果將回傳至招生中心，作為後續獎金核發與招生統計之依據。</p>
                        <p>感謝主任的協助與配合。</p>
                        <br>
                        <p>敬祝</p>
                        <p>教安</p>
                        <p>招生中心組長 高惠玲</p>
                        <p>聯絡電話：0900123123</p>
                        <p>分機：310</p>
                        <p>（本信件為系統自動發送，請勿直接回覆）</p>
                    </div>
                ";
                $altBody = "{$greeting}\n本信件為招生中心通知\n近日收到一筆「招生推薦」資料，該筆資料已由招生中心完成初步審核並確認無誤，現需再請主任協助進行最終資訊確認並完成線上簽名\n推薦學生資料摘要如下：\n• 推薦學生學號和姓名：{$rec_sid} {$rec_name}\n• 推薦學生科系：{$rec_dept}\n• 被推薦人姓名：{$student_name}\n• 就讀國中：{$student_school}\n• 聯絡電話：{$student_phone}\n• 推薦科系：{$dept_label}\n• 推薦人身分：{$rec_identity}\n請主任點擊下方連結進行線上審核與簽核：\n【網頁連結】{$approval_link}\n若資料無誤，請於系統中完成線上簽核；如資料有誤，可於系統中填寫不通過原因退回。\n本審核結果將回傳至招生中心，作為後續獎金核發與招生統計之依據。\n感謝主任的協助與配合。\n\n敬祝\n教安\n招生中心組長 高惠玲\n聯絡電話：0900123123\n分機：310\n（本信件為系統自動發送，請勿直接回覆）";
            } else {
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
            }
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

