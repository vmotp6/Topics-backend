<?php
/**
 * 技藝班－分配老師（主任僅能為自己科系場次分配老師）
 */
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/includes/department_session_access.php';
checkBackendLogin();
require_once '../../Topics-frontend/frontend/config.php';

$page_title = '技藝班分配老師';
$conn = getDatabaseConnection();
$normalized_role = normalizeBackendRole($_SESSION['role'] ?? '');
$current_user_id = getOrFetchCurrentUserId($conn);
$user_department_code = null;
$is_department_director = false;
if (in_array($normalized_role, ['DI', 'IM']) && $current_user_id) {
    $user_department_code = getCurrentUserDepartmentCode($conn, $current_user_id);
    if (!empty($user_department_code)) $is_department_director = true;
}
$is_staff = in_array($normalized_role, ['ADM', 'STA']);
$session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
$message = '';
$messageType = '';

if ($session_id <= 0 || (!$is_department_director && !$is_staff)) {
    header('Location: skillsclass.php');
    exit;
}

$stmt = $conn->prepare("SELECT * FROM skill_class_sessions WHERE id = ?");
$stmt->bind_param('i', $session_id);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$session) {
    header('Location: skillsclass.php');
    exit;
}
if ($is_department_director && $session['department_code'] !== $user_department_code) {
    header('Location: skillsclass.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'assign_teacher') {
        $teacher_user_id = (int)($_POST['teacher_user_id'] ?? 0);
        if ($teacher_user_id > 0) {
            $ins = $conn->prepare("INSERT IGNORE INTO skill_class_teacher_assignments (session_id, teacher_user_id, assigned_by) VALUES (?, ?, ?)");
            $ins->bind_param('iii', $session_id, $teacher_user_id, $current_user_id);
            if ($ins->execute() && $ins->affected_rows > 0) {
                $message = '已分配老師。';
                $messageType = 'success';
            } else {
                $message = '該老師已在名單中或新增失敗。';
                $messageType = 'error';
            }
            $ins->close();
        }
    } elseif ($action === 'remove_teacher') {
        $tid = (int)($_POST['teacher_user_id'] ?? 0);
        if ($tid > 0) {
            $del = $conn->prepare("DELETE FROM skill_class_teacher_assignments WHERE session_id = ? AND teacher_user_id = ?");
            $del->bind_param('ii', $session_id, $tid);
            $del->execute();
            $del->close();
            $message = '已移除該老師。';
            $messageType = 'success';
        }
    }
}

$assigned = [];
$r = $conn->prepare("SELECT ta.teacher_user_id, u.username, u.name FROM skill_class_teacher_assignments ta LEFT JOIN user u ON u.id = ta.teacher_user_id WHERE ta.session_id = ?");
$r->bind_param('i', $session_id);
$r->execute();
$res = $r->get_result();
while ($row = $res->fetch_assoc()) $assigned[] = $row;
$r->close();

$teachers = [];
$dept = $session['department_code'];
$st = $conn->prepare("SELECT id, username, name FROM user WHERE id IN (SELECT user_id FROM teacher WHERE department = ?) OR id IN (SELECT user_id FROM director WHERE department = ?) ORDER BY name, username");
$st->bind_param('ss', $dept, $dept);
$st->execute();
$tr = $st->get_result();
while ($row = $tr->fetch_assoc()) $teachers[] = $row;
$st->close();
if (empty($teachers)) {
    $tr2 = $conn->query("SELECT id, username, name FROM user WHERE role IN ('TEA','老師','teacher','DI','主任','director') ORDER BY name, username");
    if ($tr2) while ($row = $tr2->fetch_assoc()) $teachers[] = $row;
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
        :root { --primary-color: #1890ff; --danger-color: #ff4d4f; --success-color: #52c41a; }
        .dashboard { display: flex; min-height: 100vh; }
        .content { padding: 24px; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f0f2f5; }
        .breadcrumb { margin-bottom: 16px; font-size: 14px; }
        .breadcrumb a { color: var(--primary-color); text-decoration: none; }
        .table-wrapper { background: #fff; border-radius: 8px; border: 1px solid #f0f0f0; margin-bottom: 24px; overflow: hidden; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #f0f0f0; }
        .table th { background: #fafafa; font-weight: 600; }
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 6px; font-size: 14px; cursor: pointer; text-decoration: none; border: 1px solid #d9d9d9; background: #fff; }
        .btn-primary { background: var(--primary-color); color: #fff; border-color: var(--primary-color); }
        .btn-delete { color: var(--danger-color); border-color: var(--danger-color); }
        .message { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; }
        .message.success { background: #f6ffed; border: 1px solid #b7eb8f; color: var(--success-color); }
        .message.error { background: #fff2f0; border: 1px solid #ffccc7; color: var(--danger-color); }
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; margin-bottom: 6px; font-weight: 500; }
        .form-control { width: 100%; max-width: 320px; padding: 8px 12px; border: 1px solid #d9d9d9; border-radius: 6px; }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include __DIR__ . '/sidebar.php'; ?>
        <div class="main-content" id="mainContent">
            <?php include __DIR__ . '/header.php'; ?>
            <div class="content">
    <div class="breadcrumb"><a href="index.php">首頁</a> / <a href="skillsclass.php">技藝班管理</a> / <?php echo htmlspecialchars($session['session_name']); ?> － 分配老師</div>
    <?php if ($message): ?><div class="message <?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <div class="table-wrapper">
        <table class="table">
            <thead><tr><th>已分配老師</th><th>操作</th></tr></thead>
            <tbody>
                <?php foreach ($assigned as $a): ?>
                <tr>
                    <td><?php echo htmlspecialchars($a['name'] ?? $a['username'] ?? 'ID:'.$a['teacher_user_id']); ?></td>
                    <td>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('確定移除？');">
                            <input type="hidden" name="action" value="remove_teacher">
                            <input type="hidden" name="teacher_user_id" value="<?php echo (int)$a['teacher_user_id']; ?>">
                            <button type="submit" class="btn btn-delete">移除</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <form method="POST" style="margin-top:16px;">
        <input type="hidden" name="action" value="assign_teacher">
        <div class="form-group">
            <label class="form-label">新增負責老師</label>
            <select name="teacher_user_id" class="form-control" required>
                <option value="">請選擇老師</option>
                <?php
                $assigned_ids = array_column($assigned, 'teacher_user_id');
                foreach ($teachers as $t):
                    if (in_array((int)$t['id'], $assigned_ids)) continue;
                ?>
                <option value="<?php echo (int)$t['id']; ?>"><?php echo htmlspecialchars($t['name'] ?? $t['username']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">加入</button>
    </form>
            </div>
        </div>
    </div>
</body>
</html>
