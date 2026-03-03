<?php
/**
 * 技藝班－總覽（主任查看自己科系場次之執行狀況）
 */
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/includes/department_session_access.php';
checkBackendLogin();
require_once '../../Topics-frontend/frontend/config.php';

$page_title = '技藝班總覽';
$conn = getDatabaseConnection();
$normalized_role = normalizeBackendRole($_SESSION['role'] ?? '');
$current_user_id = getOrFetchCurrentUserId($conn);
$user_department_code = getCurrentUserDepartmentCode($conn, $current_user_id);
$is_director = in_array($normalized_role, ['DI', 'IM']);
$is_staff = in_array($normalized_role, ['ADM', 'STA']);
$session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;

if ($session_id <= 0) {
    header('Location: skillsclass.php');
    exit;
}

$stmt = $conn->prepare("SELECT s.*, d.name AS department_name FROM skill_class_sessions s LEFT JOIN departments d ON d.code = s.department_code WHERE s.id = ?");
$stmt->bind_param('i', $session_id);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$session) {
    header('Location: skillsclass.php');
    exit;
}
if ($is_director && $session['department_code'] !== $user_department_code) {
    header('Location: skillsclass.php');
    exit;
}

$students = [];
$r = $conn->prepare("SELECT * FROM skill_class_student_assignments WHERE session_id = ? ORDER BY id");
$r->bind_param('i', $session_id);
$r->execute();
$res = $r->get_result();
while ($row = $res->fetch_assoc()) $students[] = $row;
$r->close();

$teachers = [];
$r2 = $conn->prepare("SELECT ta.teacher_user_id, u.name FROM skill_class_teacher_assignments ta LEFT JOIN user u ON u.id = ta.teacher_user_id WHERE ta.session_id = ?");
$r2->bind_param('i', $session_id);
$r2->execute();
$res2 = $r2->get_result();
while ($row = $res2->fetch_assoc()) $teachers[] = $row;
$r2->close();

$weeks = [];
$r3 = $conn->prepare("SELECT * FROM skill_class_weekly_content WHERE session_id = ? ORDER BY week_number");
$r3->bind_param('i', $session_id);
$r3->execute();
$res3 = $r3->get_result();
while ($row = $res3->fetch_assoc()) $weeks[] = $row;
$r3->close();

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
        .card h3 { margin-bottom: 12px; font-size: 16px; }
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
    <div class="breadcrumb"><a href="index.php">首頁</a> / <a href="skillsclass.php">技藝班管理</a> / <?php echo htmlspecialchars($session['session_name']); ?> － 總覽</div>
    <div class="card">
        <h3>場次資訊</h3>
        <p>科系：<?php echo htmlspecialchars($session['department_name'] ?? $session['department_code']); ?></p>
        <p>日期：<?php echo $session['session_date'] ? date('Y/m/d', strtotime($session['session_date'])) : '—'; ?></p>
    </div>
    <div class="card">
        <h3>負責老師</h3>
        <p><?php echo count($teachers) > 0 ? implode('、', array_map(function($t){ return $t['name'] ?? '—'; }, $teachers)) : '尚未分配'; ?></p>
    </div>
    <div class="card">
        <h3>國中生分配（<?php echo count($students); ?> 人）</h3>
        <table class="table">
            <thead><tr><th>姓名</th><th>學校</th><th>聯絡電話</th></tr></thead>
            <tbody>
                <?php foreach ($students as $s): ?>
                <tr><td><?php echo htmlspecialchars($s['student_name']); ?></td><td><?php echo htmlspecialchars($s['school_name'] ?? '—'); ?></td><td><?php echo htmlspecialchars($s['contact_phone'] ?? '—'); ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card">
        <h3>每週上課內容（<?php echo count($weeks); ?> 週）</h3>
        <?php foreach ($weeks as $w): ?>
        <p><strong>第 <?php echo (int)$w['week_number']; ?> 週</strong> <?php echo nl2br(htmlspecialchars($w['content'] ?? '—')); ?></p>
        <?php endforeach; ?>
        <?php if (empty($weeks)): ?><p>尚無每週內容。</p><?php endif; ?>
    </div>
            </div>
        </div>
    </div>
</body>
</html>
