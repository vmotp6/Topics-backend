<?php
require_once __DIR__ . '/session_config.php';

header('Content-Type: application/json; charset=utf-8');
// 避免 PHP warning/notice 破壞 JSON 輸出
@ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

function json_response(array $payload): void {
    $extra = trim((string)ob_get_clean());
    if ($extra !== '') {
        // 避免前端 JSON parse error，同時保留除錯線索
        $payload['_raw_output'] = $extra;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function ensure_enrollment_invitation_tables(mysqli $conn): void {
    // token 表：需要 UNIQUE(application_id) 才能 ON DUPLICATE KEY
    $conn->query("
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

    // 寄送紀錄：避免重複寄
    $conn->query("
        CREATE TABLE IF NOT EXISTS enrollment_invitation_email_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            application_id INT NOT NULL UNIQUE,
            session_id INT NOT NULL,
            student_email VARCHAR(255) NOT NULL,
            send_status VARCHAR(20) NOT NULL,
            error_message TEXT NULL,
            sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_session_id (session_id),
            INDEX idx_send_status (send_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

// 獲取場次ID
$session_id = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;
if ($session_id === 0) json_response(['success' => false, 'message' => '無效的場次ID']);

try {
    // 檢查是否已登入（自動觸發仍需後台登入狀態）
    if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
        json_response(['success' => false, 'message' => '未授權']);
    }

    // 引入資料庫設定
    require_once '../../Topics-frontend/frontend/config.php';

    $email_functions_path = __DIR__ . '/../../Topics-frontend/frontend/includes/email_functions.php';
    if (!file_exists($email_functions_path)) {
        json_response(['success' => false, 'message' => 'Email 功能檔案不存在：' . $email_functions_path]);
    }
    require_once $email_functions_path;
    if (!function_exists('sendEmail')) {
        json_response(['success' => false, 'message' => 'Email 發送功能未啟用（sendEmail 不存在）']);
    }

    // 建立資料庫連接
    $conn = getDatabaseConnection();
    ensure_enrollment_invitation_tables($conn);

    // 獲取場次資訊
    $stmt = $conn->prepare("SELECT * FROM admission_sessions WHERE id = ?");
    if (!$stmt) throw new Exception("準備 SQL 語句失敗：" . $conn->error);
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $session_result = $stmt->get_result();
    $session = $session_result->fetch_assoc();
    $stmt->close();

    if (!$session) {
        $conn->close();
        json_response(['success' => false, 'message' => '找不到場次資料']);
    }

    // 獲取符合條件的學生（國三；高意願會在 PHP 再判斷）
    $session_year = (int)date('Y', strtotime((string)$session['session_date']));
    $stmt = $conn->prepare("
    SELECT 
        aa.*, 
        aa.grade as grade_code,
        COALESCE(io.name, aa.grade) as grade,
        sd.name as school_name_display,
        ar.attendance_status,
        as_session.session_type
    FROM admission_applications aa
    LEFT JOIN school_data sd ON aa.school = sd.school_code
    LEFT JOIN identity_options io ON aa.grade = io.code
    LEFT JOIN admission_sessions as_session ON aa.session_id = as_session.id
    LEFT JOIN attendance_records ar ON aa.id = ar.application_id 
        AND ar.session_id = ? 
    LEFT JOIN enrollment_invitation_email_logs el 
        ON el.application_id = aa.id AND el.send_status = 'sent'
    WHERE aa.session_id = ? 
    AND YEAR(aa.created_at) = ?
    AND el.application_id IS NULL
    AND (
        (aa.grade LIKE '%國三%' OR aa.grade = '3' OR aa.grade LIKE 'G3%' OR aa.grade LIKE '%G3%' OR COALESCE(io.name, '') LIKE '%國三%')
        OR (aa.grade LIKE '%3%' AND (aa.grade = '3' OR aa.grade LIKE 'G3%'))
    )
");

    if (!$stmt) throw new Exception("準備 SQL 語句失敗：" . $conn->error);
    $stmt->bind_param("iii", $session_id, $session_id, $session_year);
    $stmt->execute();
    $result = $stmt->get_result();
    $students = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    // 計算意願度並篩選高意願學生
    $qualified_students = [];
    foreach ($students as $student) {
        $score = 0;
    
        // 1. 有報名：+1
        $score += 1;
    
        // 2. 有簽到：+3
        if (isset($student['attendance_status']) && (int)$student['attendance_status'] === 1) {
            $score += 3;
        }
    
        // 3. 實體場：+2
        if (isset($student['session_type']) && (int)$student['session_type'] === 2) {
            $score += 2;
        }
    
        // 4. 參加 2 場以上：+2
        $email = $student['email'] ?? '';
        $phone = $student['contact_phone'] ?? '';
        if (!empty($email) || !empty($phone)) {
            $count_stmt = $conn->prepare("
            SELECT COUNT(DISTINCT session_id) as session_count
            FROM admission_applications
            WHERE YEAR(created_at) = ? 
            AND (email = ? OR contact_phone = ?)
        ");
            if ($count_stmt) {
                $count_stmt->bind_param("iss", $session_year, $email, $phone);
                $count_stmt->execute();
                $count_result = $count_stmt->get_result();
                if ($count_result && ($count_row = $count_result->fetch_assoc())) {
                    if ((int)($count_row['session_count'] ?? 0) >= 2) {
                        $score += 2;
                    }
                }
                $count_stmt->close();
            }
        }
    
        // 5. 有選科系：+1
        if (!empty($student['course_priority_1']) || !empty($student['course_priority_2'])) {
            $score += 1;
        }
    
        // 6. 國三生：+1
        $grade_text = $student['grade'] ?? '';
        $grade_code = $student['grade_code'] ?? '';
        if (strpos($grade_text, '國三') !== false || 
            preg_match('/\b3\b/', (string)$grade_text) || 
            strpos((string)$grade_code, 'G3') !== false || 
            (string)$grade_code === '3' ||
            preg_match('/\b3\b/', (string)$grade_code)) {
            $score += 1;
        }
    
        // 只選擇高意願（分數 >= 6）
        if ($score >= 6) {
            $student['willingness_score'] = $score;
            $qualified_students[] = $student;
        }
    }

    // 發送 Email
    $sent_count = 0;
    $failed_count = 0;
    $errors = [];

    foreach ($qualified_students as $student) {
        $student_name = htmlspecialchars((string)($student['student_name'] ?? ''));
        $student_email = trim((string)($student['email'] ?? ''));
    
        // 驗證 Email 格式
        if ($student_email === '' || !filter_var($student_email, FILTER_VALIDATE_EMAIL)) {
            $failed_count++;
            $errors[] = "學生「{$student_name}」：Email 格式不正確或為空";
            continue;
        }
    
        // 生成確認連結的 token
        $token = bin2hex(random_bytes(32));
        $expires_at = date('Y-m-d H:i:s', strtotime('+7 days'));
    
        // 儲存 token（表已確保存在）
        $token_stmt = $conn->prepare("
            INSERT INTO enrollment_invitation_tokens (application_id, token, expires_at, created_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE token = VALUES(token), expires_at = VALUES(expires_at), created_at = NOW()
        ");
        if (!$token_stmt) {
            $failed_count++;
            $errors[] = "學生「{$student_name}」：建立 token 失敗（" . $conn->error . "）";
            continue;
        }
        $application_id = (int)($student['id'] ?? 0);
        $token_stmt->bind_param("iss", $application_id, $token, $expires_at);
        $token_stmt->execute();
        $token_stmt->close();
    
        // 生成確認連結
        $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
        $confirm_url = $base_url . dirname($_SERVER['PHP_SELF']) . '/confirm_enrollment_intention.php?token=' . $token;
    
        $session_name = htmlspecialchars((string)($session['session_name'] ?? ''));
    
        $subject = "【就讀意願邀請】{$session_name} - 邀請您加入就讀意願名單";
    
        $body = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #1890ff; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
            .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 8px 8px; }
            .button { display: inline-block; padding: 12px 30px; background: #1890ff; color: white; text-decoration: none; border-radius: 6px; margin: 20px 0; }
            .button:hover { background: #40a9ff; }
            .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #999; text-align: center; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>就讀意願邀請</h2>
            </div>
            <div class='content'>
                <p>親愛的 <strong>{$student_name}</strong>：</p>
                
                <p>感謝您參與「<strong>{$session_name}</strong>」活動！</p>
                
                <p>根據您在活動中的表現，我們認為您對本校有高度的就讀意願。因此，我們誠摯地邀請您加入我們的「就讀意願名單」。</p>
                
                <p>如果您同意，系統將自動將您的資料填入就讀意願名單，包括：</p>
                <ul>
                    <li>基本資料（姓名、聯絡方式等）</li>
                    <li>就讀說明會相關資訊</li>
                    <li>志願序（根據您報名時填寫的科系志願）</li>
                </ul>
                
                <p style='text-align: center;'>
                    <a href='{$confirm_url}' class='button'>我同意加入就讀意願名單</a>
                </p>
                
                <p style='color: #999; font-size: 12px;'>
                    此連結將在 7 天後失效。如果您不同意，無需進行任何操作。
                </p>
                
                <p>如有任何問題，歡迎隨時與我們聯繫。</p>
                
                <p>此致<br>敬禮</p>
            </div>
            <div class='footer'>
                <p>此為系統自動發送的郵件，請勿直接回覆。</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
        $altBody = "親愛的 {$student_name}：\n\n感謝您參與「{$session_name}」活動！\n\n根據您在活動中的表現，我們認為您對本校有高度的就讀意願。因此，我們誠摯地邀請您加入我們的「就讀意願名單」。\n\n如果您同意，請點擊以下連結：\n{$confirm_url}\n\n此連結將在 7 天後失效。\n\n如有任何問題，歡迎隨時與我們聯繫。";

        $ok = sendEmail($student_email, $subject, $body, $altBody);

        $log_stmt = $conn->prepare("
            INSERT INTO enrollment_invitation_email_logs
                (application_id, session_id, student_email, send_status, error_message, sent_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                send_status = VALUES(send_status),
                error_message = VALUES(error_message),
                sent_at = NOW()
        ");
        if ($log_stmt) {
            $send_status = $ok ? 'sent' : 'failed';
            $err_msg = $ok ? null : 'email_send_failed';
            $log_stmt->bind_param("iisss", $application_id, $session_id, $student_email, $send_status, $err_msg);
            $log_stmt->execute();
            $log_stmt->close();
        }

        if ($ok) {
            $sent_count++;
        } else {
            $failed_count++;
            $errors[] = "學生「{$student_name}」({$student_email})：郵件發送失敗";
        }
    }

    $conn->close();

    json_response([
        'success' => true,
        'sent_count' => $sent_count,
        'failed_count' => $failed_count,
        'total_qualified' => count($qualified_students),
        'errors' => $errors
    ]);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => '發送失敗：' . $e->getMessage()]);
}
