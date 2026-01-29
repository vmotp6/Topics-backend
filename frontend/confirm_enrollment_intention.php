<?php
require_once __DIR__ . '/../../Topics-frontend/frontend/config.php';

function ensure_enrollment_invitation_tables(mysqli $conn): void {
    // 確保 token 表存在（避免點擊連結時因資料表不存在而 Fatal）
    @$conn->query("
        CREATE TABLE IF NOT EXISTS enrollment_invitation_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            application_id INT NOT NULL UNIQUE,
            token VARCHAR(64) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_token (token),
            INDEX idx_application_id (application_id),
            INDEX idx_expires_at (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function ensure_enrollment_intention_columns(mysqli $conn): void {
    // 參考 enrollment_list.php 的做法，確保必要欄位存在
    $cols = [
        'assigned_department' => "VARCHAR(50) NULL",
        'assigned_teacher_id' => "INT NULL",
        'graduation_year' => "INT NULL",
        'case_closed' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0=否,1=是(結案後顯示於歷史紀錄)'",
        'intention_level' => "VARCHAR(20) DEFAULT NULL",
        'follow_up_status' => "VARCHAR(30) DEFAULT 'tracking'",
    ];
    foreach ($cols as $col => $def) {
        $r = @$conn->query("SHOW COLUMNS FROM enrollment_intention LIKE '{$conn->real_escape_string($col)}'");
        if (!$r || $r->num_rows === 0) {
            @$conn->query("ALTER TABLE enrollment_intention ADD COLUMN {$col} {$def}");
        }
    }
}

function normalize_department_code(mysqli $conn, string $value): string {
    $v = trim($value);
    if ($v === '') return '';
    // 先當作 code
    $stmt = $conn->prepare("SELECT code FROM departments WHERE code = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $v);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $row = $res->fetch_assoc()) {
            $stmt->close();
            return $row['code'];
        }
        $stmt->close();
    }
    // 再當作 name
    $stmt = $conn->prepare("SELECT code FROM departments WHERE name = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $v);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $row = $res->fetch_assoc()) {
            $stmt->close();
            return $row['code'];
        }
        $stmt->close();
    }
    // 找不到就原樣寫入（至少不丟失）
    return $v;
}

// 獲取 token
$token = isset($_GET['token']) ? trim($_GET['token']) : '';

if (empty($token)) {
    die('無效的確認連結');
}

// 建立資料庫連接
$conn = getDatabaseConnection();
ensure_enrollment_invitation_tables($conn);

// admission_sessions 是否有 department_id 欄位（避免 prepare 因欄位不存在失敗）
$has_session_department_id = false;
$col_chk = @$conn->query("SHOW COLUMNS FROM admission_sessions LIKE 'department_id'");
if ($col_chk && $col_chk->num_rows > 0) {
    $has_session_department_id = true;
}

// 驗證 token
$select_session_dept = $has_session_department_id ? ", s.department_id" : "";
$stmt = $conn->prepare("
    SELECT t.*, aa.*, s.session_name, s.session_date{$select_session_dept}
    FROM enrollment_invitation_tokens t
    INNER JOIN admission_applications aa ON t.application_id = aa.id
    INNER JOIN admission_sessions s ON aa.session_id = s.id
    WHERE t.token = ? AND t.expires_at > NOW()
");
if (!$stmt) {
    $stmt_err = $conn->error;
    error_log('confirm_enrollment_intention.php prepare failed: ' . $stmt_err);
    $conn->close();
    die('系統忙碌中，請稍後再試（查詢失敗）：' . htmlspecialchars($stmt_err));
}
$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();
$data = $result->fetch_assoc();
$stmt->close();

if (!$data) {
    die('確認連結無效或已過期');
}

// 檢查是否已經加入就讀意願名單（根據 email 或電話匹配）
$email = trim($data['email'] ?? '');
$phone = trim($data['contact_phone'] ?? '');
$existing_enrollment = null;

if (!empty($email) || !empty($phone)) {
    $check_stmt = $conn->prepare("
        SELECT id FROM enrollment_intention 
        WHERE (email = ? OR phone1 = ? OR phone2 = ?)
        LIMIT 1
    ");
    $check_stmt->bind_param("sss", $email, $phone, $phone);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $existing_enrollment = $check_result->fetch_assoc();
    $check_stmt->close();
}

$message = '';
$success = false;

if ($existing_enrollment) {
    // 已存在：把舊資料補齊，避免因權限/篩選條件而在 enrollment_list.php 看不到
    $conn->begin_transaction();
    try {
        ensure_enrollment_intention_columns($conn);

        $enrollment_id = (int)($existing_enrollment['id'] ?? 0);
        if ($enrollment_id <= 0) {
            throw new Exception('找不到既有名單ID');
        }

        // 以場次科系作為歸屬科系（若沒有 department_id 欄位則會是空）
        $assigned_department = '';
        if (!empty($data['department_id'])) {
            $assigned_department = normalize_department_code($conn, (string)$data['department_id']);
        }

        // 志願序（也用來 fallback assigned_department）
        $priority1_raw = (string)($data['course_priority_1'] ?? '');
        $priority2_raw = (string)($data['course_priority_2'] ?? '');
        $priority1 = normalize_department_code($conn, $priority1_raw);
        $priority2 = normalize_department_code($conn, $priority2_raw);
        if ($assigned_department === '' && $priority1 !== '') {
            $assigned_department = $priority1;
        }

        // 國三應屆畢業年
        $current_month = (int)date('m');
        $current_year = (int)date('Y');
        $this_year_grad = ($current_month >= 8) ? $current_year + 1 : $current_year;
        $graduation_year = $this_year_grad;

        $intention_level = 'high';
        $follow_up_status = 'tracking';

        // 更新既有 enrollment_intention（只補空或關鍵欄位，不覆蓋使用者後續手動編輯）
        $upd = $conn->prepare("
            UPDATE enrollment_intention
            SET
                name = COALESCE(NULLIF(name,''), ?),
                email = COALESCE(NULLIF(email,''), ?),
                phone1 = COALESCE(NULLIF(phone1,''), ?),
                junior_high = COALESCE(NULLIF(junior_high,''), ?),
                current_grade = COALESCE(NULLIF(current_grade,''), ?),
                assigned_department = COALESCE(NULLIF(assigned_department,''), ?),
                intention_level = COALESCE(NULLIF(intention_level,''), ?),
                follow_up_status = COALESCE(NULLIF(follow_up_status,''), ?),
                graduation_year = COALESCE(graduation_year, ?)
            WHERE id = ?
            LIMIT 1
        ");
        if (!$upd) throw new Exception('更新既有名單失敗：' . $conn->error);
        $incoming_name = (string)($data['student_name'] ?? '');
        $incoming_email = trim((string)($data['email'] ?? ''));
        $incoming_phone = trim((string)($data['contact_phone'] ?? ''));
        $incoming_jh = trim((string)($data['school'] ?? ''));
        $incoming_grade = trim((string)($data['grade'] ?? ''));
        $upd->bind_param(
            "ssssssssii",
            $incoming_name,
            $incoming_email,
            $incoming_phone,
            $incoming_jh,
            $incoming_grade,
            $assigned_department,
            $intention_level,
            $follow_up_status,
            $graduation_year,
            $enrollment_id
        );
        $upd->execute();
        $upd->close();

        // 確保 enrollment_choices 有唯一鍵，讓 ON DUPLICATE KEY 正常工作
        @$conn->query("ALTER TABLE enrollment_choices ADD UNIQUE KEY uniq_enrollment_choice (enrollment_id, choice_order)");

        // 補上志願序（避免第一次志願為空導致主任/科系看不到）
        if ($priority1 !== '') {
            $c1 = $conn->prepare("
                INSERT INTO enrollment_choices (enrollment_id, choice_order, department_code, system_code)
                VALUES (?, 1, ?, NULL)
                ON DUPLICATE KEY UPDATE department_code = VALUES(department_code)
            ");
            if ($c1) {
                $c1->bind_param("is", $enrollment_id, $priority1);
                $c1->execute();
                $c1->close();
            }
        }
        if ($priority2 !== '') {
            $c2 = $conn->prepare("
                INSERT INTO enrollment_choices (enrollment_id, choice_order, department_code, system_code)
                VALUES (?, 2, ?, NULL)
                ON DUPLICATE KEY UPDATE department_code = VALUES(department_code)
            ");
            if ($c2) {
                $c2->bind_param("is", $enrollment_id, $priority2);
                $c2->execute();
                $c2->close();
            }
        }

        // token 用完就刪除（避免重複點擊）
        $delete_stmt = $conn->prepare("DELETE FROM enrollment_invitation_tokens WHERE token = ?");
        if ($delete_stmt) {
            $delete_stmt->bind_param("s", $token);
            $delete_stmt->execute();
            $delete_stmt->close();
        }

        $conn->commit();
        $message = '您已經在就讀意願名單中了！（已自動更新名單資料）';
        $success = true;
    } catch (Exception $e) {
        $conn->rollback();
        $message = '處理失敗：' . $e->getMessage();
        error_log('confirm_enrollment_intention.php existing update error: ' . $e->getMessage());
        $success = false;
    }
} else {
    // 開始事務
    $conn->begin_transaction();
    
    try {
        ensure_enrollment_intention_columns($conn);

        // 以場次科系作為歸屬科系（讓 enrollment_list 的系科/主任看得到）
        $assigned_department = '';
        if (!empty($data['department_id'])) {
            $assigned_department = normalize_department_code($conn, (string)$data['department_id']);
        }

        // 獲取學校代碼
        $school_code = $data['school'] ?? '';
        $school_name = '';
        if (!empty($school_code)) {
            $school_stmt = $conn->prepare("SELECT name FROM school_data WHERE school_code = ?");
            if (!$school_stmt) {
                throw new Exception('查詢學校資料失敗：' . $conn->error);
            }
            $school_stmt->bind_param("s", $school_code);
            $school_stmt->execute();
            $school_result = $school_stmt->get_result();
            if ($school_row = $school_result->fetch_assoc()) {
                $school_name = $school_row['name'];
            }
            $school_stmt->close();
        }
        
        // 設定畢業年份（國三應屆）
        $current_month = (int)date('m');
        $current_year = (int)date('Y');
        $this_year_grad = ($current_month >= 8) ? $current_year + 1 : $current_year;
        $graduation_year = $this_year_grad;

        // 插入到 enrollment_intention 表（補齊會影響列表顯示/權限的欄位）
        $insert_stmt = $conn->prepare("
            INSERT INTO enrollment_intention (
                name, email, phone1, phone2, junior_high, current_grade, 
                identity, gender, line_id, facebook, remarks,
                assigned_department, intention_level, follow_up_status, graduation_year,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        if (!$insert_stmt) {
            throw new Exception('準備寫入就讀意願資料失敗：' . $conn->error);
        }
        
        $name = $data['student_name'] ?? '';
        $phone1 = $data['contact_phone'] ?? '';
        $phone2 = '';
        $junior_high = $school_code;
        $current_grade = $data['grade'] ?? '';
        $identity = 1; // 預設為學生
        $gender = 0; // 0=未知（避免 NULL 綁定造成型別問題）
        $line_id = '';
        $facebook = '';
        $remarks = "來自場次：{$data['session_name']} (報名時間：" . date('Y-m-d H:i', strtotime($data['created_at'])) . ")";

        // 志願序（也用來 fallback assigned_department）
        $priority1_raw = (string)($data['course_priority_1'] ?? '');
        $priority2_raw = (string)($data['course_priority_2'] ?? '');
        $priority1 = normalize_department_code($conn, $priority1_raw);
        $priority2 = normalize_department_code($conn, $priority2_raw);

        if ($assigned_department === '' && $priority1 !== '') {
            $assigned_department = $priority1;
        }

        $intention_level = 'high'; // 本功能觸發條件為高意願
        $follow_up_status = 'tracking';

        // 15 個參數：6s + 2i + 6s + 1i = 15
        $insert_stmt->bind_param("ssssssii" . "ssssss" . "i",
            $name, $email, $phone1, $phone2, $junior_high, $current_grade,
            $identity, $gender, $line_id, $facebook, $remarks,
            $assigned_department, $intention_level, $follow_up_status, $graduation_year
        );
        
        if (!$insert_stmt->execute()) {
            throw new Exception('插入就讀意願資料失敗：' . $insert_stmt->error);
        }
        
        $enrollment_id = $conn->insert_id;
        $insert_stmt->close();
        
        // 插入志願序到 enrollment_choices 表
        // 從 course_priority_1 和 course_priority_2 取得志願
        if (!empty($priority1)) {
            $choice_stmt = $conn->prepare("
                INSERT INTO enrollment_choices (enrollment_id, choice_order, department_code, system_code)
                VALUES (?, 1, ?, NULL)
            ");
            if (!$choice_stmt) {
                throw new Exception('寫入志願1失敗：' . $conn->error);
            }
            $choice_stmt->bind_param("is", $enrollment_id, $priority1);
            $choice_stmt->execute();
            $choice_stmt->close();
        }
        
        if (!empty($priority2)) {
            $choice_stmt = $conn->prepare("
                INSERT INTO enrollment_choices (enrollment_id, choice_order, department_code, system_code)
                VALUES (?, 2, ?, NULL)
            ");
            if (!$choice_stmt) {
                throw new Exception('寫入志願2失敗：' . $conn->error);
            }
            $choice_stmt->bind_param("is", $enrollment_id, $priority2);
            $choice_stmt->execute();
            $choice_stmt->close();
        }
        
        // 標記 token 為已使用（可選，刪除 token）
        $delete_stmt = $conn->prepare("DELETE FROM enrollment_invitation_tokens WHERE token = ?");
        if (!$delete_stmt) {
            throw new Exception('刪除 token 失敗：' . $conn->error);
        }
        $delete_stmt->bind_param("s", $token);
        $delete_stmt->execute();
        $delete_stmt->close();
        
        $conn->commit();
        $message = '您的資料已成功加入就讀意願名單！';
        $success = true;
        
    } catch (Exception $e) {
        $conn->rollback();
        $message = '處理失敗：' . $e->getMessage();
        error_log('confirm_enrollment_intention.php 錯誤: ' . $e->getMessage());
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>就讀意願確認</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f0f2f5;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            padding: 40px;
            max-width: 600px;
            width: 100%;
            text-align: center;
        }
        .icon {
            font-size: 64px;
            margin-bottom: 20px;
        }
        .success .icon { color: #52c41a; }
        .error .icon { color: #ff4d4f; }
        h1 {
            font-size: 24px;
            margin-bottom: 20px;
            color: #262626;
        }
        p {
            font-size: 16px;
            line-height: 1.6;
            color: #595959;
            margin-bottom: 30px;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: #1890ff;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-size: 14px;
            transition: background 0.3s;
        }
        .btn:hover {
            background: #40a9ff;
        }
    </style>
</head>
<body>
    <div class="container <?php echo $success ? 'success' : 'error'; ?>">
        <div class="icon">
            <?php if ($success): ?>
                ✓
            <?php else: ?>
                ✗
            <?php endif; ?>
        </div>
        <h1><?php echo $success ? '確認成功' : '確認失敗'; ?></h1>
        <p><?php echo htmlspecialchars($message); ?></p>
        <?php if ($success): ?>
            <a href="javascript:window.close();" class="btn">關閉視窗</a>
        <?php endif; ?>
    </div>
</body>
</html>
