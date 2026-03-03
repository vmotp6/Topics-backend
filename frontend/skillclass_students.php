<?php
/**
 * 技藝班－國中生分配（招生中心註記哪些國中生分配到各科系）
 */
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/includes/department_session_access.php';
checkBackendLogin();
require_once '../../Topics-frontend/frontend/config.php';

$page_title = '技藝班國中生分配';
$conn = getDatabaseConnection();
$normalized_role = normalizeBackendRole($_SESSION['role'] ?? '');
$is_staff = in_array($normalized_role, ['ADM', 'STA']);
$session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
$message = '';
$messageType = '';

if (!$is_staff || $session_id <= 0) {
    header('Location: skillsclass.php');
    exit;
}

$session = null;
$stmt = $conn->prepare("SELECT * FROM skill_class_sessions WHERE id = ?");
$stmt->bind_param('i', $session_id);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$session) {
    header('Location: skillsclass.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add_student') {
        $student_name = trim($_POST['student_name'] ?? '');
        $department_code = trim($_POST['department_code'] ?? $session['department_code']);
        $school_name = trim($_POST['school_name'] ?? '');
        $contact_phone = trim($_POST['contact_phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        if ($student_name !== '') {
            $ins = $conn->prepare("INSERT INTO skill_class_student_assignments (session_id, department_code, student_name, school_name, contact_phone, email, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $ins->bind_param('issssss', $session_id, $department_code, $student_name, $school_name, $contact_phone, $email, $notes);
            if ($ins->execute()) {
                $message = '已新增國中生分配。';
                $messageType = 'success';
            } else {
                $message = '新增失敗：' . $ins->error;
                $messageType = 'error';
            }
            $ins->close();
        } else {
            $message = '請填寫學生姓名。';
            $messageType = 'error';
        }
    } elseif ($action === 'delete_assignment') {
        $aid = (int)($_POST['assignment_id'] ?? 0);
        if ($aid > 0) {
            $del = $conn->prepare("DELETE FROM skill_class_student_assignments WHERE id = ? AND session_id = ?");
            $del->bind_param('ii', $aid, $session_id);
            if ($del->execute()) {
                $message = '已移除該筆分配。';
                $messageType = 'success';
            }
            $del->close();
        }
    }
}

$assignments = [];
$res = $conn->prepare("SELECT * FROM skill_class_student_assignments WHERE session_id = ? ORDER BY id ASC");
$res->bind_param('i', $session_id);
$res->execute();
$result = $res->get_result();
while ($row = $result->fetch_assoc()) {
    $assignments[] = $row;
}
$res->close();

$departments = [];
$dr = $conn->query("SELECT code, name FROM departments ORDER BY code");
if ($dr) {
    while ($r = $dr->fetch_assoc()) $departments[] = $r;
}
$conn->close();

$dept_name = $session['department_code'];
foreach ($departments as $d) {
    if ($d['code'] === $session['department_code']) {
        $dept_name = $d['name'];
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - Topics 後台</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { --primary-color: #1890ff; --danger-color: #ff4d4f; --success-color: #52c41a; --text-secondary-color: #8c8c8c; --border-color: #f0f0f0; --background-color: #f0f2f5; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        .dashboard { display: flex; min-height: 100vh; }
        .content { padding: 24px; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: var(--background-color); }
        .breadcrumb { margin-bottom: 16px; font-size: 14px; color: var(--text-secondary-color); }
        .breadcrumb a { color: var(--primary-color); text-decoration: none; }
        .table-wrapper { background: #fff; border-radius: 8px; border: 1px solid var(--border-color); overflow: hidden; margin-bottom: 24px; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 12px 16px; text-align: left; border-bottom: 1px solid var(--border-color); }
        .table th { background: #fafafa; font-weight: 600; }
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 6px; font-size: 14px; cursor: pointer; text-decoration: none; border: 1px solid #d9d9d9; background: #fff; color: #262626; }
        .btn-primary { background: var(--primary-color); color: #fff; border-color: var(--primary-color); }
        .btn-delete { color: var(--danger-color); border-color: var(--danger-color); }
        .message { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; }
        .message.success { background: #f6ffed; border: 1px solid #b7eb8f; color: var(--success-color); }
        .message.error { background: #fff2f0; border: 1px solid #ffccc7; color: var(--danger-color); }
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; margin-bottom: 6px; font-weight: 500; }
        .form-control { width: 100%; padding: 8px 12px; border: 1px solid #d9d9d9; border-radius: 6px; }
        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.45); }
        .modal-content { background: #fff; margin: 5% auto; padding: 0; border-radius: 8px; max-width: 520px; }
        .modal-header { padding: 16px 20px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }
        .modal-body { padding: 20px; }
        .modal-footer { padding: 12px 20px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 8px; }
        .page-controls { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include __DIR__ . '/sidebar.php'; ?>
        <div class="main-content" id="mainContent">
            <?php include __DIR__ . '/header.php'; ?>
            <div class="content">
    <div class="breadcrumb"><a href="index.php">首頁</a> / <a href="skillsclass.php">技藝班管理</a> / <?php echo htmlspecialchars($session['session_name']); ?> － 國中生分配</div>
    <?php if ($message): ?><div class="message <?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <div class="page-controls">
        <strong>場次：<?php echo htmlspecialchars($session['session_name']); ?></strong>（科系：<?php echo htmlspecialchars($dept_name); ?>）
        <button type="button" class="btn btn-primary" onclick="document.getElementById('addModal').style.display='block'"><i class="fas fa-plus"></i> 新增國中生分配</button>
    </div>
    <div class="table-wrapper">
        <table class="table">
            <thead>
                <tr><th>姓名</th><th>科系</th><th>學校</th><th>聯絡電話</th><th>Email</th><th>備註</th><th>操作</th></tr>
            </thead>
            <tbody>
                <?php foreach ($assignments as $a): ?>
                <tr>
                    <td><?php echo htmlspecialchars($a['student_name']); ?></td>
                    <td><?php echo htmlspecialchars($a['department_code']); ?></td>
                    <td><?php echo htmlspecialchars($a['school_name'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($a['contact_phone'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($a['email'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($a['notes'] ?? '—'); ?></td>
                    <td>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('確定移除？');">
                            <input type="hidden" name="action" value="delete_assignment">
                            <input type="hidden" name="assignment_id" value="<?php echo (int)$a['id']; ?>">
                            <button type="submit" class="btn btn-delete">移除</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (empty($assignments)): ?>
        <p style="padding: 24px; color: var(--text-secondary-color);">尚無國中生分配，請點「新增國中生分配」。</p>
        <?php endif; ?>
    </div>

    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header"><h3>新增國中生分配</h3><span style="cursor:pointer;" onclick="document.getElementById('addModal').style.display='none'">&times;</span></div>
            <form method="POST">
                <input type="hidden" name="action" value="add_student">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">學生姓名 <span style="color:var(--danger-color);">*</span></label>
                        <input type="text" name="student_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">科系</label>
                        <select name="department_code" class="form-control">
                            <?php foreach ($departments as $d): ?>
                                <option value="<?php echo htmlspecialchars($d['code']); ?>" <?php echo $d['code'] === $session['department_code'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['name'] ?? $d['code']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">學校名稱</label>
                        <input type="text" name="school_name" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">聯絡電話</label>
                        <input type="text" name="contact_phone" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">備註</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="document.getElementById('addModal').style.display='none'">取消</button>
                    <button type="submit" class="btn btn-primary">新增</button>
                </div>
            </form>
        </div>
    </div>
    <script>window.onclick = function(e){ if(e.target.classList.contains('modal')) e.target.style.display='none'; };</script>
            </div>
        </div>
    </div>
</body>
</html>
