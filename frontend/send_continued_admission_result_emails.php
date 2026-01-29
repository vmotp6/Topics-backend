<?php
/**
 * 續招錄取結果：發送到期郵件（可由 Windows 工作排程器 / cron 定時執行）
 *
 * - Web 模式：需要後台登入（避免被外部濫用）
 * - CLI 模式：可用工作排程器直接執行（不需 session）
 */
date_default_timezone_set('Asia/Taipei');

$is_cli = (PHP_SAPI === 'cli');

if (!$is_cli) {
    require_once __DIR__ . '/session_config.php';
    checkBackendLogin();
    header('Content-Type: text/plain; charset=utf-8');

    $user_role = $_SESSION['role'] ?? '';
    if (!in_array($user_role, ['ADM', 'STA'], true)) {
        http_response_code(403);
        echo "權限不足\n";
        exit;
    }
}

require_once '../../Topics-frontend/frontend/config.php';
require_once __DIR__ . '/includes/continued_admission_committee_functions.php';
require_once __DIR__ . '/../../Topics-frontend/frontend/includes/email_functions.php';

$conn = getDatabaseConnection();
caEnsureCommitteeTables($conn);

$limit = 50;
if ($is_cli) {
    // 支援：php send_continued_admission_result_emails.php --limit=50
    global $argv;
    foreach (($argv ?? []) as $arg) {
        if (strpos($arg, '--limit=') === 0) {
            $v = (int)substr($arg, 8);
            if ($v > 0) $limit = $v;
        }
    }
} else {
    $limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 50;
}
$now = date('Y-m-d H:i:s');

echo "[{$now}] 開始發送到期續招錄取結果郵件（limit={$limit}）...\n";

$stmt = $conn->prepare("
    SELECT id, to_email, to_name, subject, body
    FROM continued_admission_email_queue
    WHERE status = 'pending' AND scheduled_at <= ?
    ORDER BY scheduled_at ASC, id ASC
    LIMIT ?
");
$stmt->bind_param("si", $now, $limit);
$stmt->execute();
$rs = $stmt->get_result();

$sent = 0; $failed = 0;
while ($row = $rs->fetch_assoc()) {
    $qid = (int)$row['id'];
    $to = (string)$row['to_email'];
    $subject = (string)$row['subject'];
    $body = (string)$row['body'];
    $ok = false;
    $err = null;

    try {
        $ok = sendEmail($to, $subject, $body, strip_tags($body));
    } catch (Throwable $e) {
        $ok = false;
        $err = $e->getMessage();
    }

    if ($ok) {
        $u = $conn->prepare("UPDATE continued_admission_email_queue SET status='sent', sent_at=NOW(), error=NULL WHERE id=?");
        $u->bind_param("i", $qid);
        $u->execute();
        $u->close();
        $sent++;
        echo "✅ sent #{$qid} -> {$to}\n";
    } else {
        $failed++;
        $err = $err ?: 'sendEmail() failed';
        $u = $conn->prepare("UPDATE continued_admission_email_queue SET status='failed', error=? WHERE id=?");
        $u->bind_param("si", $err, $qid);
        $u->execute();
        $u->close();
        echo "❌ failed #{$qid} -> {$to} ({$err})\n";
    }
}
$stmt->close();

echo "完成：sent={$sent}, failed={$failed}\n";
$conn->close();


