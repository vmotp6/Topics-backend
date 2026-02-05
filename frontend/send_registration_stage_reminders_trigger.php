<?php
/**
 * 後台網頁觸發：發送報名階段提醒（老師：提醒去提醒學生；學生：提醒去報名）
 * 排除：已在其他階段報名或結案的學生
 * 同一階段期別只發送一次（記錄於 registration_stage_reminder_log）
 */
require_once __DIR__ . '/session_config.php';
checkBackendLogin();

// 發送多封郵件可能較久，延長執行時間避免逾時中斷（學生會先發、再發老師）
@set_time_limit(300);
@ignore_user_abort(true);

header('Content-Type: application/json; charset=utf-8');

$config_path = '../../Topics-frontend/frontend/config.php';
if (!file_exists($config_path)) {
    $config_path = __DIR__ . '/../../Topics-frontend/frontend/config.php';
}
if (!file_exists($config_path)) {
    echo json_encode(['success' => false, 'message' => '找不到 config.php', 'error' => 'config not found']);
    exit;
}
require_once $config_path;

$email_functions_path = '../../Topics-frontend/frontend/includes/email_functions.php';
if (!file_exists($email_functions_path)) {
    $email_functions_path = __DIR__ . '/../../Topics-frontend/frontend/includes/email_functions.php';
}
if (!file_exists($email_functions_path)) {
    echo json_encode(['success' => false, 'message' => '找不到 email_functions.php', 'error' => 'email_functions not found']);
    exit;
}
require_once $email_functions_path;

require_once __DIR__ . '/send_registration_stage_reminders.php';

$conn = getDatabaseConnection();
ensureStageReminderLogTable($conn);
$info = getCurrentStagePeriodKey($conn);
if (!$info) {
    $conn->close();
    echo json_encode(['success' => true, 'message' => '目前非報名期間，無需發送。', 'already_sent' => false]);
    exit;
}
$stage = $info['stage'];
$period_key = $info['period_key'];
// 同一階段期別只發送一次：先嘗試寫入記錄，若已存在則不發送
$stmt = $conn->prepare("INSERT IGNORE INTO registration_stage_reminder_log (stage, period_key, sent_at) VALUES (?, ?, NOW())");
if ($stmt) {
    $stmt->bind_param("ss", $stage, $period_key);
    $stmt->execute();
    $inserted = ($conn->affected_rows > 0);
    $stmt->close();
} else {
    $inserted = true; // 若表有問題仍嘗試發送
}
$conn->close();

if (!$inserted) {
    echo json_encode(['success' => true, 'message' => '本階段已發送過，不再重複發送。', 'already_sent' => true]);
    exit;
}

$result = runRegistrationStageReminders();
$result['already_sent'] = false;
echo json_encode($result);
