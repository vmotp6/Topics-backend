<?php
/**
 * 續招錄取結果 Email 診斷：查看佇列狀態、失敗原因，並可寄送測試信
 * 需後台 ADM/STA 登入
 */
require_once __DIR__ . '/session_config.php';
checkBackendLogin();
$user_role = $_SESSION['role'] ?? '';
if (!in_array($user_role, ['ADM', 'STA'], true)) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../../Topics-frontend/frontend/config.php';
require_once __DIR__ . '/includes/continued_admission_committee_functions.php';
require_once __DIR__ . '/../../Topics-frontend/frontend/includes/email_functions.php';

$conn = getDatabaseConnection();
caEnsureCommitteeTables($conn);

$test_result = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_email'])) {
    $test_to = trim($_POST['test_email'] ?? '');
    if ($test_to !== '') {
        $test_subject = '【康寧大學續招】Email 測試信';
        $test_body = '<p>此為系統測試信，若您收到代表 SMTP 設定正常。</p><p>時間：' . date('Y-m-d H:i:s') . '</p>';
        try {
            $ok = sendEmail($test_to, $test_subject, $test_body, strip_tags($test_body));
            if ($ok) {
                $test_result = '<div class="msg ok">✅ 測試信已送出，請到收件匣（及垃圾郵件）檢查：' . htmlspecialchars($test_to) . '</div>';
            } else {
                $test_result = '<div class="msg err">❌ 寄送失敗，請檢查 config.php 的 SMTP 設定（Gmail 需使用「應用程式密碼」）及 PHP error log。</div>';
            }
        } catch (Throwable $e) {
            $test_result = '<div class="msg err">❌ 錯誤：' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    } else {
        $test_result = '<div class="msg err">請輸入收件信箱。</div>';
    }
}

// 佇列統計
$stats = ['pending' => 0, 'sent' => 0, 'failed' => 0];
$res = $conn->query("SELECT status, COUNT(*) as c FROM continued_admission_email_queue GROUP BY status");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $stats[$row['status']] = (int)$row['c'];
    }
}

// 待發送筆數（scheduled_at <= 現在）
$now = date('Y-m-d H:i:s');
$pending_now = $conn->query("SELECT COUNT(*) as c FROM continued_admission_email_queue WHERE status = 'pending' AND scheduled_at <= '" . $conn->real_escape_string($now) . "'");
$pending_ready = 0;
if ($pending_now && $row = $pending_now->fetch_assoc()) {
    $pending_ready = (int)$row['c'];
}

// 最近幾筆佇列（含失敗的 error）
$list = [];
$list_res = $conn->query("SELECT id, application_id, to_email, to_name, status, scheduled_at, sent_at, error FROM continued_admission_email_queue ORDER BY id DESC LIMIT 20");
if ($list_res) {
    while ($row = $list_res->fetch_assoc()) {
        $list[] = $row;
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>續招 Email 診斷</title>
    <style>
        body { font-family: sans-serif; margin: 20px; background: #f5f5f5; }
        .box { background: #fff; padding: 16px; margin-bottom: 16px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        h1 { margin-top: 0; color: #333; }
        h2 { font-size: 16px; color: #666; margin-bottom: 12px; }
        table { border-collapse: collapse; width: 100%; font-size: 14px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f0f0f0; }
        .msg { padding: 12px; border-radius: 6px; margin-bottom: 12px; }
        .msg.ok { background: #f6ffed; border: 1px solid #b7eb8f; color: #389e0d; }
        .msg.err { background: #fff2f0; border: 1px solid #ffccc7; color: #cf1322; }
        input[type=email] { padding: 8px; width: 280px; }
        button { padding: 8px 16px; background: #1890ff; color: #fff; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background: #40a9ff; }
        .stat { display: inline-block; margin-right: 20px; padding: 8px 12px; background: #e6f7ff; border-radius: 4px; }
        a { color: #1890ff; }
    </style>
</head>
<body>
    <div class="box">
        <h1>續招錄取結果 Email 診斷</h1>
        <div style="background:#fff7e6; border:1px solid #ffd591; padding:12px; border-radius:6px; margin-bottom:12px;">
            <strong>⚠️ 時間到了不會自動寄信</strong><br>
            系統不會在「公告時間」到時自己發信，必須：
            <ul style="margin:8px 0 0 0;">
                <li><strong>方式一</strong>：時間到後手動開「<a href="send_continued_admission_result_emails.php" target="_blank">執行發送</a>」此頁；或</li>
                <li><strong>方式二</strong>：用 Windows「工作排程器」每 5～10 分鐘執行一次 <code>run_send_continued_admission_emails.bat</code>（與本頁同目錄），時間到後的那次執行就會寄出。</li>
            </ul>
        </div>
        <p>
            <a href="continued_admission_committee.php">← 回委員會頁面</a> |
            <a href="send_continued_admission_result_emails.php" target="_blank">執行發送（僅發送「已到公告時間」的）</a> |
            <a href="send_continued_admission_result_emails.php?ignore_schedule=1" target="_blank"><strong>立即發送（忽略公告時間，測試用）</strong></a>
        </p>
    </div>

    <?php echo $test_result; ?>

    <div class="box">
        <h2>寄送測試信（檢查 SMTP 是否正常）</h2>
        <form method="post">
            <label>收件信箱：</label>
            <input type="email" name="test_email" placeholder="your@email.com" value="<?php echo htmlspecialchars($_POST['test_email'] ?? ''); ?>">
            <button type="submit">寄送測試信</button>
        </form>
        <p style="color:#666; font-size:12px; margin-top:8px;">若測試信也收不到，請檢查：1) Topics-frontend/frontend/config.php 的 SMTP 設定 2) Gmail 需使用「應用程式密碼」 3) 垃圾郵件匣</p>
    </div>

    <div class="box">
        <h2>佇列狀態</h2>
        <p>
            <span class="stat">待發送 (pending)：<?php echo $stats['pending']; ?></span>
            <span class="stat">已到時可發：<?php echo $pending_ready; ?></span>
            <span class="stat">已寄出 (sent)：<?php echo $stats['sent']; ?></span>
            <span class="stat">失敗 (failed)：<?php echo $stats['failed']; ?></span>
        </p>
        <p style="color:#666; font-size:12px;">
            「已到時可發」= status=pending 且 scheduled_at ≤ 現在。正式流程會等<strong>錄取榜單公告時間</strong>到才寄信。<br>
            若要先測試正式信：點上方「<strong>立即發送（忽略公告時間，測試用）</strong>」會無視 scheduled_at 把目前待發的一併寄出。
        </p>
    </div>

    <div class="box">
        <h2>最近 20 筆佇列</h2>
        <?php if (empty($list)): ?>
            <p>尚無資料。請在委員會第三步驟「建立寄信佇列」後再來查看。</p>
        <?php else: ?>
            <table>
                <tr>
                    <th>ID</th>
                    <th>收件人</th>
                    <th>狀態</th>
                    <th>預約時間</th>
                    <th>寄出時間</th>
                    <th>錯誤訊息</th>
                </tr>
                <?php foreach ($list as $r): ?>
                <tr>
                    <td><?php echo (int)$r['id']; ?></td>
                    <td><?php echo htmlspecialchars($r['to_email']); ?></td>
                    <td><?php echo htmlspecialchars($r['status']); ?></td>
                    <td><?php echo htmlspecialchars($r['scheduled_at'] ?? '-'); ?></td>
                    <td><?php echo htmlspecialchars($r['sent_at'] ?? '-'); ?></td>
                    <td style="<?php echo $r['status'] === 'failed' ? 'color:#c00;' : ''; ?>"><?php echo $r['error'] ? htmlspecialchars($r['error']) : '-'; ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
</body>
</html>
