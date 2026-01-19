<?php
/**
 * 發送出席紀錄填寫提醒郵件
 * 當體驗課程時間結束後，發送email給建立場次的人填寫是否有簽到
 */

require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/../../Topics-frontend/frontend/config.php';
require_once __DIR__ . '/../../Topics-frontend/frontend/includes/email_functions.php';

// 檢查是否已登入（僅管理員可執行）
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '無權限執行此操作']);
    exit;
}

$conn = getDatabaseConnection();

// 獲取所有已結束但尚未發送提醒的場次
// 場次結束時間已過，且尚未發送提醒
$current_datetime = date('Y-m-d H:i:s');
$stmt = $conn->prepare("
    SELECT s.*, u.email as creator_email, u.name as creator_name
    FROM admission_sessions s
    LEFT JOIN users u ON s.created_by = u.id
    WHERE s.session_end_date IS NOT NULL 
    AND s.session_end_date <= ?
    AND (s.attendance_reminder_sent IS NULL OR s.attendance_reminder_sent = 0)
    AND s.created_by IS NOT NULL
");
$stmt->bind_param("s", $current_datetime);
$stmt->execute();
$result = $stmt->get_result();
$sessions = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$sent_count = 0;
$failed_count = 0;
$errors = [];

foreach ($sessions as $session) {
    if (empty($session['creator_email'])) {
        $failed_count++;
        $errors[] = "場次「{$session['session_name']}」：找不到建立者的Email";
        continue;
    }
    
    // 獲取報名人數統計
    $stats_stmt = $conn->prepare("
        SELECT 
            COUNT(DISTINCT aa.id) as total_registrations,
            COUNT(DISTINCT CASE WHEN ar.attendance_status = 1 THEN ar.id END) as attended_count,
            COUNT(DISTINCT CASE WHEN ar.attendance_status = 0 THEN ar.id END) as absent_count
        FROM admission_applications aa
        LEFT JOIN attendance_records ar ON aa.id = ar.application_id AND ar.session_id = ?
        WHERE aa.session_id = ?
    ");
    $stats_stmt->bind_param("ii", $session['id'], $session['id']);
    $stats_stmt->execute();
    $stats_result = $stats_stmt->get_result();
    $stats = $stats_result->fetch_assoc();
    $stats_stmt->close();
    
    $session_name = htmlspecialchars($session['session_name']);
    $session_date = date('Y年m月d日 H:i', strtotime($session['session_date']));
    $session_type = $session['session_type'] == 1 ? '線上' : '實體';
    $total_registrations = $stats['total_registrations'] ?? 0;
    $attended_count = $stats['attended_count'] ?? 0;
    $absent_count = $stats['absent_count'] ?? 0;
    $not_recorded = $total_registrations - $attended_count - $absent_count;
    
    $attendance_url = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/attendance_management.php?session_id=" . $session['id'];
    
    $subject = "【出席紀錄提醒】{$session_name} - 請填寫出席紀錄";
    
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
            .stats-box { background: #f0f7ff; padding: 15px; margin: 15px 0; border-radius: 6px; }
            .button { display: inline-block; padding: 12px 24px; background: #1890ff; color: white; text-decoration: none; border-radius: 6px; margin: 20px 0; }
            .footer { text-align: center; color: #999; font-size: 12px; margin-top: 20px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>出席紀錄填寫提醒</h2>
            </div>
            <div class='content'>
                <p>親愛的 {$session['creator_name']}：</p>
                
                <p>您建立的體驗課程場次「<strong>{$session_name}</strong>」已於 <strong>{$session_date}</strong> 結束。</p>
                
                <div class='info-box'>
                    <h3 style='margin-top: 0; color: #1890ff;'>📋 場次資訊</h3>
                    <p><strong>場次名稱：</strong>{$session_name}</p>
                    <p><strong>場次日期：</strong>{$session_date}</p>
                    <p><strong>場次類型：</strong>{$session_type}</p>
                </div>
                
                <div class='stats-box'>
                    <h3 style='margin-top: 0; color: #1890ff;'>📊 報名統計</h3>
                    <p><strong>總報名人數：</strong>{$total_registrations} 人</p>
                    <p><strong>已記錄出席：</strong>{$attended_count} 人</p>
                    <p><strong>已記錄未到：</strong>{$absent_count} 人</p>
                    <p><strong>尚未記錄：</strong>{$not_recorded} 人</p>
                </div>
                
                <p>請您儘快前往後台填寫出席紀錄，確認哪些報名者有到場，哪些未到場。</p>
                
                <div style='text-align: center;'>
                    <a href='{$attendance_url}' class='button'>前往填寫出席紀錄</a>
                </div>
                
                <p style='color: #666; font-size: 14px;'>
                    <strong>注意：</strong>簽到和未到都需要記錄時間，請確實填寫每位報名者的出席狀態。
                </p>
            </div>
            <div class='footer'>
                <p>此為系統自動發送的提醒郵件，請勿直接回覆。</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $altBody = "親愛的 {$session['creator_name']}：\n\n您建立的體驗課程場次「{$session_name}」已於 {$session_date} 結束。\n\n總報名人數：{$total_registrations} 人\n已記錄出席：{$attended_count} 人\n已記錄未到：{$absent_count} 人\n尚未記錄：{$not_recorded} 人\n\n請前往後台填寫出席紀錄：{$attendance_url}\n\n注意：簽到和未到都需要記錄時間，請確實填寫每位報名者的出席狀態。";
    
    if (sendEmail($session['creator_email'], $subject, $body, $altBody)) {
        // 標記為已發送
        $update_stmt = $conn->prepare("UPDATE admission_sessions SET attendance_reminder_sent = 1 WHERE id = ?");
        $update_stmt->bind_param("i", $session['id']);
        $update_stmt->execute();
        $update_stmt->close();
        $sent_count++;
    } else {
        $failed_count++;
        $errors[] = "場次「{$session['session_name']}」：郵件發送失敗";
    }
}

$conn->close();

// 如果是通過 AJAX 調用，返回 JSON
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $failed_count == 0,
        'sent_count' => $sent_count,
        'failed_count' => $failed_count,
        'errors' => $errors,
        'message' => "已發送 {$sent_count} 封提醒郵件" . ($failed_count > 0 ? "，{$failed_count} 封發送失敗" : "")
    ]);
    exit;
}

// 否則返回 HTML 頁面
echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>發送提醒郵件</title></head><body>";
echo "<h2>發送出席紀錄提醒郵件</h2>";
echo "<p>已發送：{$sent_count} 封</p>";
echo "<p>失敗：{$failed_count} 封</p>";
if (!empty($errors)) {
    echo "<ul>";
    foreach ($errors as $error) {
        echo "<li>{$error}</li>";
    }
    echo "</ul>";
}
echo "<p><a href='settings.php'>返回場次設定</a></p>";
echo "</body></html>";
?>


