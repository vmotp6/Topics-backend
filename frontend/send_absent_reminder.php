<?php
/**
 * 發送未到警示提醒郵件
 * 場次結束後，自動檢查有報名但未簽到的學生，並發送 Email 給這些學生
 * 可以手動觸發或設定定時任務自動執行
 */

require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/../../Topics-frontend/frontend/config.php';
require_once __DIR__ . '/../../Topics-frontend/frontend/includes/email_functions.php';

// 如果是手動觸發，需要檢查登入狀態
// 如果是定時任務自動執行，可以跳過登入檢查（通過參數控制）
$is_cron = isset($_GET['cron']) && $_GET['cron'] === '1';

if (!$is_cron) {
    // 手動觸發需要檢查登入
    if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '無權限執行此操作']);
        exit;
    }

    $user_role = $_SESSION['role'] ?? '';
    $is_admin = in_array($user_role, ['ADM', '管理員']);
    $is_staff = in_array($user_role, ['STA', '行政人員']);

    if (!$is_admin && !$is_staff) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '無權限執行此操作']);
        exit;
    }
}

$conn = getDatabaseConnection();

// 檢查是否需要創建 absent_notification_logs 表來記錄已發送的郵件
$table_check = $conn->query("SHOW TABLES LIKE 'absent_notification_logs'");
if (!$table_check || $table_check->num_rows == 0) {
    $create_table_sql = "CREATE TABLE IF NOT EXISTS `absent_notification_logs` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `session_id` int(11) NOT NULL COMMENT '場次ID',
      `application_id` int(11) NOT NULL COMMENT '報名ID',
      `student_email` varchar(255) NOT NULL COMMENT '學生Email',
      `sent_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT '發送時間',
      PRIMARY KEY (`id`),
      UNIQUE KEY `idx_session_application` (`session_id`, `application_id`),
      KEY `idx_sent_at` (`sent_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='未到警示郵件發送記錄表'";
    
    $conn->query($create_table_sql);
}

// 獲取當前年份和時間
$current_year = (int)date('Y');
$current_datetime = date('Y-m-d H:i:s');

// 查詢已結束但未簽到的學生
// 條件：場次已結束（session_end_date <= 現在），有報名但未簽到
$sql = "
    SELECT 
        s.id as session_id,
        s.session_name,
        s.session_date,
        s.session_end_date,
        s.session_link,
        aa.id as application_id,
        aa.student_name,
        aa.email,
        aa.contact_phone,
        sd.name as school_name
    FROM admission_sessions s
    INNER JOIN admission_applications aa ON s.id = aa.session_id
    LEFT JOIN school_data sd ON aa.school = sd.school_code
    LEFT JOIN attendance_records ar ON aa.id = ar.application_id 
        AND ar.session_id = s.id 
        AND ar.attendance_status = 1
    LEFT JOIN absent_notification_logs anl ON aa.id = anl.application_id 
        AND s.id = anl.session_id
    WHERE YEAR(aa.created_at) = ?
    AND YEAR(s.session_date) = ?
    AND s.session_end_date IS NOT NULL
    AND s.session_end_date <= ?
    AND ar.id IS NULL
    AND (aa.notes IS NULL OR aa.notes NOT LIKE '%未報名但有來%')
    AND anl.id IS NULL
    AND aa.email IS NOT NULL
    AND aa.email != ''
    ORDER BY s.session_end_date DESC, aa.student_name ASC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("準備 SQL 語句失敗：" . $conn->error);
}

$stmt->bind_param("iis", $current_year, $current_year, $current_datetime);
$stmt->execute();
$result = $stmt->get_result();
$absent_students = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (empty($absent_students)) {
    $conn->close();
    echo json_encode([
        'success' => true,
        'message' => '目前沒有需要發送提醒的未到記錄',
        'sent_count' => 0
    ]);
    exit;
}

$sent_count = 0;
$failed_count = 0;
$errors = [];

foreach ($absent_students as $student) {
    $student_name = htmlspecialchars($student['student_name']);
    $student_email = trim($student['email']);
    
    // 驗證 Email 格式
    if (empty($student_email) || !filter_var($student_email, FILTER_VALIDATE_EMAIL)) {
        $failed_count++;
        $errors[] = "學生「{$student_name}」：Email 格式不正確或為空";
        continue;
    }
    
    $session_name = htmlspecialchars($student['session_name']);
    $session_date = !empty($student['session_date']) ? date('Y年m月d日 H:i', strtotime($student['session_date'])) : '';
    $session_end_date = !empty($student['session_end_date']) ? date('Y年m月d日 H:i', strtotime($student['session_end_date'])) : '';
    
    // 生成下一場次報名連結（如果有 session_link，可以作為參考）
    $next_session_url = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/settings.php";
    
    $subject = "【活動提醒】{$session_name} - 我們期待您的參與";
    
    $body = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #1890ff; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
            .content { background: #f9f9f9; padding: 20px; border: 1px solid #e0e0e0; }
            .info-box { background: white; padding: 15px; margin: 15px 0; border-radius: 6px; border-left: 4px solid #1890ff; }
            .warning-box { background: #fffbe6; padding: 15px; margin: 15px 0; border-radius: 6px; border-left: 4px solid #faad14; }
            .button { display: inline-block; padding: 12px 24px; background: #1890ff; color: white; text-decoration: none; border-radius: 6px; margin: 10px 5px; }
            .footer { text-align: center; color: #999; font-size: 12px; margin-top: 20px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>活動提醒</h2>
            </div>
            <div class='content'>
                <p>親愛的 {$student_name}：</p>
                
                <p>感謝您報名參加「<strong>{$session_name}</strong>」活動！</p>
                
                <div class='info-box'>
                    <h3 style='margin-top: 0; color: #1890ff;'>📋 活動資訊</h3>
                    <p><strong>活動名稱：</strong>{$session_name}</p>
                    <p><strong>活動日期：</strong>{$session_date}</p>
                    <p><strong>活動結束時間：</strong>{$session_end_date}</p>
                </div>
                
                <div class='warning-box'>
                    <p style='margin: 0; font-weight: 600; color: #faad14;'>
                        <i class='fas fa-info-circle'></i> 
                        我們注意到您尚未完成簽到。如果您因故無法參加本次活動，我們非常理解。
                    </p>
                </div>
                
                <p>如果您仍對我們的活動感興趣，歡迎報名參加下一場次：</p>
                
                <div style='text-align: center; margin-top: 20px;'>
                    <a href='{$next_session_url}' class='button'>
                        <i class='fas fa-calendar'></i> 查看其他場次
                    </a>
                </div>
                
                <p style='color: #666; font-size: 14px; margin-top: 20px;'>
                    如有任何問題或需要協助，歡迎隨時與我們聯繫。期待下次能與您相見！
                </p>
            </div>
            <div class='footer'>
                <p>此為系統自動發送的郵件，請勿直接回覆。</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $altBody = "親愛的 {$student_name}：\n\n感謝您報名參加「{$session_name}」活動！\n\n活動日期：{$session_date}\n活動結束時間：{$session_end_date}\n\n我們注意到您尚未完成簽到。如果您因故無法參加本次活動，我們非常理解。\n\n如果您仍對我們的活動感興趣，歡迎報名參加下一場次：{$next_session_url}\n\n如有任何問題或需要協助，歡迎隨時與我們聯繫。期待下次能與您相見！";
    
    if (function_exists('sendEmail')) {
        if (sendEmail($student_email, $subject, $body, $altBody)) {
            // 記錄已發送
            $log_stmt = $conn->prepare("
                INSERT INTO absent_notification_logs 
                (session_id, application_id, student_email, sent_at) 
                VALUES (?, ?, ?, NOW())
            ");
            $log_stmt->bind_param("iis", 
                $student['session_id'], 
                $student['application_id'], 
                $student_email
            );
            $log_stmt->execute();
            $log_stmt->close();
            
            $sent_count++;
        } else {
            $failed_count++;
            $errors[] = "學生「{$student_name}」({$student_email})：郵件發送失敗";
        }
    } else {
        $failed_count++;
        $errors[] = "學生「{$student_name}」({$student_email})：Email 發送功能未啟用";
    }
}

$conn->close();

// 如果是通過 AJAX 調用或定時任務，返回 JSON
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest' || $is_cron) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $failed_count == 0,
        'sent_count' => $sent_count,
        'failed_count' => $failed_count,
        'errors' => $errors,
        'message' => "已發送 {$sent_count} 封未到提醒郵件給學生" . ($failed_count > 0 ? "，{$failed_count} 封發送失敗" : "")
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 否則返回 HTML 頁面
echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>發送未到警示</title></head><body>";
echo "<h2>發送未到提醒郵件給學生</h2>";
echo "<p>已發送：{$sent_count} 封</p>";
echo "<p>失敗：{$failed_count} 封</p>";
if (!empty($errors)) {
    echo "<ul>";
    foreach ($errors as $error) {
        echo "<li>" . htmlspecialchars($error) . "</li>";
    }
    echo "</ul>";
}
echo "<p><a href='absent_reminder.php'>前往未到警示頁面</a></p>";
echo "</body></html>";
?>
