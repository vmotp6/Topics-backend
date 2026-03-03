<?php
/**
 * 技藝班－國中生每週回饋（老師查看）
 */
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/includes/department_session_access.php';
checkBackendLogin();
require_once '../../Topics-frontend/frontend/config.php';

$page_title = '技藝班學生回饋';
$conn = getDatabaseConnection();
$current_user_id = getOrFetchCurrentUserId($conn);
$session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;

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

$feedback = [];
$r = $conn->query("
    SELECT f.*, a.student_name, a.school_name
    FROM skill_class_student_feedback f
    INNER JOIN skill_class_student_assignments a ON a.id = f.student_assignment_id
    WHERE f.session_id = " . (int)$session_id . "
    ORDER BY f.week_number ASC, a.student_name
");
if ($r) {
    while ($row = $r->fetch_assoc()) $feedback[] = $row;
}
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
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #f0f0f0; }
        .table th { background: #fafafa; font-weight: 600; }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include __DIR__ . '/sidebar.php'; ?>
        <div class="main-content" id="mainContent">
            <?php include __DIR__ . '/header.php'; ?>
            <div class="content">
    <div class="breadcrumb"><a href="index.php">首頁</a> / <a href="skillsclass.php">技藝班管理</a> / <?php echo htmlspecialchars($session['session_name']); ?> － 學生回饋</div>
    <div class="card">
        <h3>國中生每週回饋</h3>
        <table class="table">
            <thead>
                <tr><th>週次</th><th>學生</th><th>學校</th><th>回饋內容</th><th>填寫時間</th></tr>
            </thead>
            <tbody>
                <?php foreach ($feedback as $f): ?>
                <tr>
                    <td>第 <?php echo (int)$f['week_number']; ?> 週</td>
                    <td><?php echo htmlspecialchars($f['student_name'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($f['school_name'] ?? '—'); ?></td>
                    <td><?php echo nl2br(htmlspecialchars($f['feedback_content'] ?? '—')); ?></td>
                    <td><?php echo $f['created_at'] ? date('Y/m/d H:i', strtotime($f['created_at'])) : '—'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (empty($feedback)): ?>
        <p style="padding:16px; color:#8c8c8c;">尚無學生回饋資料。（國中生填寫的回饋將顯示於此）</p>
        <?php endif; ?>
    </div>
            </div>
        </div>
    </div>
</body>
</html>
