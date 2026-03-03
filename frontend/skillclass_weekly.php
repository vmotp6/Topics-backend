<?php
/**
 * 技藝班－每週上課內容（老師填寫）
 */
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/includes/department_session_access.php';
checkBackendLogin();
require_once '../../Topics-frontend/frontend/config.php';

$page_title = '技藝班每週內容';
$conn = getDatabaseConnection();
$current_user_id = getOrFetchCurrentUserId($conn);
$session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
$message = '';
$messageType = '';

if ($session_id <= 0 || !$current_user_id) {
    header('Location: skillsclass.php');
    exit;
}

$stmt = $conn->prepare("SELECT s.* FROM skill_class_sessions s INNER JOIN skill_class_teacher_assignments ta ON ta.session_id = s.id AND ta.teacher_user_id = ? WHERE s.id = ?");
$stmt->bind_param('ii', $current_user_id, $session_id);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$session) {
    header('Location: skillsclass.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_week') {
        $week_number = (int)($_POST['week_number'] ?? 0);
        $content = trim($_POST['content'] ?? '');
        $week_start_date = !empty($_POST['week_start_date']) ? date('Y-m-d', strtotime($_POST['week_start_date'])) : null;
        if ($week_number > 0) {
            $upsert = $conn->prepare("INSERT INTO skill_class_weekly_content (session_id, week_number, week_start_date, content, created_by) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE week_start_date = VALUES(week_start_date), content = VALUES(content), created_by = VALUES(created_by)");
            $upsert->bind_param('iissi', $session_id, $week_number, $week_start_date, $content, $current_user_id);
            if ($upsert->execute()) {
                $message = '已儲存第 ' . $week_number . ' 週內容。';
                $messageType = 'success';
            }
            $upsert->close();
        }
    }
}

$weeks = [];
$r = $conn->prepare("SELECT * FROM skill_class_weekly_content WHERE session_id = ? ORDER BY week_number");
$r->bind_param('i', $session_id);
$r->execute();
$res = $r->get_result();
while ($row = $res->fetch_assoc()) $weeks[] = $row;
$r->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - Topics 後台</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .dashboard { display: flex; min-height: 100vh; }
        .content { padding: 24px; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f0f2f5; }
        .breadcrumb { margin-bottom: 16px; }
        .breadcrumb a { color: #1890ff; text-decoration: none; }
        .card { background: #fff; border-radius: 8px; border: 1px solid #f0f0f0; padding: 20px; margin-bottom: 16px; }
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; margin-bottom: 6px; font-weight: 500; }
        .form-control { width: 100%; padding: 8px 12px; border: 1px solid #d9d9d9; border-radius: 6px; }
        .btn { padding: 8px 16px; border-radius: 6px; font-size: 14px; cursor: pointer; background: #1890ff; color: #fff; border: none; }
        .message { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; }
        .message.success { background: #f6ffed; border: 1px solid #b7eb8f; color: #52c41a; }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include __DIR__ . '/sidebar.php'; ?>
        <div class="main-content" id="mainContent">
            <?php include __DIR__ . '/header.php'; ?>
            <div class="content">
    <div class="breadcrumb"><a href="index.php">首頁</a> / <a href="skillsclass.php">技藝班管理</a> / <?php echo htmlspecialchars($session['session_name']); ?> － 每週內容</div>
    <?php if ($message): ?><div class="message <?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <?php foreach ($weeks as $w): ?>
    <div class="card">
        <h3>第 <?php echo (int)$w['week_number']; ?> 週</h3>
        <form method="POST">
            <input type="hidden" name="action" value="save_week">
            <input type="hidden" name="week_number" value="<?php echo (int)$w['week_number']; ?>">
            <div class="form-group">
                <label class="form-label">該週起始日</label>
                <input type="date" name="week_start_date" class="form-control" value="<?php echo $w['week_start_date'] ? substr($w['week_start_date'], 0, 10) : ''; ?>">
            </div>
            <div class="form-group">
                <label class="form-label">上課內容</label>
                <textarea name="content" class="form-control" rows="5"><?php echo htmlspecialchars($w['content'] ?? ''); ?></textarea>
            </div>
            <button type="submit" class="btn">儲存</button>
        </form>
    </div>
    <?php endforeach; ?>
    <div class="card">
        <h3>新增週次</h3>
        <form method="POST">
            <input type="hidden" name="action" value="save_week">
            <div class="form-group">
                <label class="form-label">第幾週</label>
                <input type="number" name="week_number" class="form-control" min="1" required>
            </div>
            <div class="form-group">
                <label class="form-label">該週起始日</label>
                <input type="date" name="week_start_date" class="form-control">
            </div>
            <div class="form-group">
                <label class="form-label">上課內容</label>
                <textarea name="content" class="form-control" rows="5"></textarea>
            </div>
            <button type="submit" class="btn">新增並儲存</button>
        </form>
    </div>
            </div>
        </div>
    </div>
</body>
</html>
