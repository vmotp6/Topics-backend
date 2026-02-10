<?php
/**
 * 生物驗證設備註冊－Email 驗證連結點擊後，正式寫入憑證
 */
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/includes/webauthn_helpers.php';

$token = isset($_GET['token']) ? trim($_GET['token']) : '';
$error = '';
$success = false;

if ($token !== '') {
    $conn = getDatabaseConnection();
    $stmt = $conn->prepare("
        SELECT id, user_id, credential_id, public_key, device_name, device_type
        FROM webauthn_register_pending
        WHERE verify_token = ? AND verify_expires_at > NOW()
        LIMIT 1
    ");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    if ($row) {
        $ok = saveWebAuthnCredential(
            (int)$row['user_id'],
            $row['credential_id'],
            $row['public_key'],
            $row['device_name'],
            $row['device_type']
        );
        if ($ok) {
            $del = $conn->prepare("DELETE FROM webauthn_register_pending WHERE id = ?");
            $del->bind_param("i", $row['id']);
            $del->execute();
            $del->close();
            $success = true;
        } else {
            $error = '寫入憑證失敗，請重新註冊設備。';
        }
    } else {
        $error = '此連結已失效或過期，請回到簽名頁面重新註冊設備。';
    }
    $conn->close();
} else {
    $error = '無效的驗證連結。';
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>生物驗證設備註冊</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Microsoft JhengHei', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08);
            padding: 32px;
            max-width: 420px;
            width: 100%;
            text-align: center;
        }
        .card h1 { font-size: 20px; margin: 0 0 16px 0; color: #262626; }
        .msg { padding: 14px; border-radius: 8px; margin-bottom: 16px; text-align: left; }
        .msg.success { background: #f6ffed; border: 1px solid #b7eb8f; color: #389e0d; }
        .msg.error { background: #fff2f0; border: 1px solid #ffccc7; color: #a8071a; }
        .card a { color: #1890ff; text-decoration: none; }
        .card a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="card">
        <?php if ($success): ?>
            <div class="msg success">
                <i class="fas fa-check-circle"></i> 設備註冊完成！您現在可以使用此設備進行生物驗證簽名。
            </div>
            <p><a href="signature.php">返回簽名頁面</a></p>
        <?php else: ?>
            <h1><i class="fas fa-fingerprint"></i> 設備註冊驗證</h1>
            <div class="msg error">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
            <p><a href="signature.php">返回簽名頁面重新註冊</a></p>
        <?php endif; ?>
    </div>
</body>
</html>
