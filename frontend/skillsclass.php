<?php
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/includes/department_session_access.php';

checkBackendLogin();

require_once '../../Topics-frontend/frontend/config.php';

$page_title = '技藝班管理';

$conn = getDatabaseConnection();

$normalized_role = normalizeBackendRole($_SESSION['role'] ?? '');
$user_id = $_SESSION['user_id'] ?? 0;
$current_user_id = getOrFetchCurrentUserId($conn);
$user_department_code = null;
$is_department_director = false;

/* 角色 | 代碼 | 功能
   -----|------|------
   主任 | DI   | 分配老師、監督技藝班（僅見自己科系場次）
   招生中心 | STA | 新增技藝班場次、註記國中生分配到各科系
   老師 | TEA  | 管理每週上課內容、查看國中生每週回饋
*/
$is_admin    = in_array($normalized_role, ['ADM']);
$is_staff    = in_array($normalized_role, ['ADM', 'STA']);  // 招生中心 + 管理員
$is_director = in_array($normalized_role, ['DI', 'IM']);
$is_teacher  = in_array($normalized_role, ['TEA']);

if ($is_director && $current_user_id) {
    $user_department_code = getCurrentUserDepartmentCode($conn, $current_user_id);
    if (!empty($user_department_code)) {
        $is_department_director = true;
    }
}

$message = '';
$messageType = '';

// 檢查技藝班資料表是否存在
$tables_check = $conn->query("SHOW TABLES LIKE 'skill_class_sessions'");
$skill_class_tables_exist = $tables_check && $tables_check->num_rows > 0;

if ($skill_class_tables_exist && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            case 'add_skill_session':
                if (!$is_staff || !$current_user_id) {
                    throw new Exception('權限不足，僅招生中心可新增技藝班場次。');
                }
                $sql = "INSERT INTO skill_class_sessions (session_name, department_code, description, session_date, session_end_date, is_active, created_by) VALUES (?, ?, ?, ?, ?, 1, ?)";
                $stmt = $conn->prepare($sql);
                if (!$stmt) throw new Exception($conn->error);
                $session_name = trim($_POST['session_name'] ?? '');
                $department_code = trim($_POST['department_code'] ?? '');
                $description = !empty($_POST['description']) ? trim($_POST['description']) : null;
                $session_date = !empty($_POST['session_date']) ? date('Y-m-d', strtotime($_POST['session_date'])) : null;
                $session_end_date = !empty($_POST['session_end_date']) ? date('Y-m-d', strtotime($_POST['session_end_date'])) : null;
                if ($session_name === '' || $department_code === '') {
                    throw new Exception('場次名稱與科系為必填。');
                }
                $stmt->bind_param('sssssi', $session_name, $department_code, $description, $session_date, $session_end_date, $current_user_id);
                if ($stmt->execute()) {
                    $message = '技藝班場次新增成功！';
                    $messageType = 'success';
                } else {
                    throw new Exception($stmt->error);
                }
                $stmt->close();
                break;

            case 'update_skill_session':
                if (!$is_staff) {
                    throw new Exception('權限不足。');
                }
                $sid = (int)($_POST['session_id'] ?? 0);
                if ($sid <= 0) throw new Exception('無效的場次。');
                $session_name = trim($_POST['session_name'] ?? '');
                $department_code = trim($_POST['department_code'] ?? '');
                $description = !empty($_POST['description']) ? trim($_POST['description']) : null;
                $session_date = !empty($_POST['session_date']) ? date('Y-m-d', strtotime($_POST['session_date'])) : null;
                $session_end_date = !empty($_POST['session_end_date']) ? date('Y-m-d', strtotime($_POST['session_end_date'])) : null;
                $is_active = (int)($_POST['is_active'] ?? 1);
                if ($session_name === '' || $department_code === '') {
                    throw new Exception('場次名稱與科系為必填。');
                }
                $stmt = $conn->prepare("UPDATE skill_class_sessions SET session_name=?, department_code=?, description=?, session_date=?, session_end_date=?, is_active=? WHERE id=?");
                if (!$stmt) throw new Exception($conn->error);
                $stmt->bind_param('sssssii', $session_name, $department_code, $description, $session_date, $session_end_date, $is_active, $sid);
                if ($stmt->execute()) {
                    $message = '技藝班場次更新成功！';
                    $messageType = 'success';
                } else {
                    throw new Exception($stmt->error);
                }
                $stmt->close();
                break;

            case 'delete_skill_session':
                if (!$is_staff) {
                    throw new Exception('權限不足。');
                }
                $sid = (int)($_POST['session_id'] ?? 0);
                if ($sid <= 0) throw new Exception('無效的場次。');
                $stmt = $conn->prepare("DELETE FROM skill_class_sessions WHERE id=?");
                if (!$stmt) throw new Exception($conn->error);
                $stmt->bind_param('i', $sid);
                if ($stmt->execute()) {
                    $message = '技藝班場次已刪除。';
                    $messageType = 'success';
                } else {
                    throw new Exception($stmt->error);
                }
                $stmt->close();
                break;
        }
    } catch (Exception $e) {
        $message = '操作失敗：' . $e->getMessage();
        $messageType = 'error';
    }
}

// 取得科系列表（供下拉選單）
$departments = [];
if ($skill_class_tables_exist) {
    $dept_res = $conn->query("SELECT code, name FROM departments ORDER BY code");
    if ($dept_res) {
        while ($row = $dept_res->fetch_assoc()) {
            $departments[] = $row;
        }
    }
}

// 取得場次列表（依角色篩選）
$sessions = [];
if ($skill_class_tables_exist) {
    if ($is_staff) {
        $sessions_sql = "SELECT s.*, d.name AS department_name,
                        (SELECT COUNT(*) FROM skill_class_student_assignments a WHERE a.session_id = s.id) AS student_count,
                        (SELECT GROUP_CONCAT(u.name) FROM skill_class_teacher_assignments ta JOIN user u ON ta.teacher_user_id = u.id WHERE ta.session_id = s.id) AS teacher_names
                        FROM skill_class_sessions s
                        LEFT JOIN departments d ON s.department_code = d.code
                        ORDER BY s.session_date DESC, s.id DESC";
        $res = $conn->query($sessions_sql);
    } elseif ($is_department_director && $user_department_code) {
        $stmt = $conn->prepare("
            SELECT s.*, d.name AS department_name,
                   (SELECT COUNT(*) FROM skill_class_student_assignments a WHERE a.session_id = s.id) AS student_count,
                   (SELECT GROUP_CONCAT(u.name) FROM skill_class_teacher_assignments ta JOIN user u ON ta.teacher_user_id = u.id WHERE ta.session_id = s.id) AS teacher_names
            FROM skill_class_sessions s
            LEFT JOIN departments d ON s.department_code = d.code
            WHERE s.department_code = ?
            ORDER BY s.session_date DESC, s.id DESC
        ");
        $stmt->bind_param('s', $user_department_code);
        $stmt->execute();
        $res = $stmt->get_result();
    } elseif ($is_teacher && $current_user_id) {
        $stmt = $conn->prepare("
            SELECT s.*, d.name AS department_name,
                   (SELECT COUNT(*) FROM skill_class_student_assignments a WHERE a.session_id = s.id) AS student_count,
                   (SELECT GROUP_CONCAT(u.name) FROM skill_class_teacher_assignments ta JOIN user u ON ta.teacher_user_id = u.id WHERE ta.session_id = s.id) AS teacher_names
            FROM skill_class_sessions s
            INNER JOIN skill_class_teacher_assignments ta ON ta.session_id = s.id AND ta.teacher_user_id = ?
            LEFT JOIN departments d ON s.department_code = d.code
            ORDER BY s.session_date DESC, s.id DESC
        ");
        $stmt->bind_param('i', $current_user_id);
        $stmt->execute();
        $res = $stmt->get_result();
    } else {
        $res = null;
    }
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $sessions[] = $row;
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - Topics 後台管理系統</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1890ff;
            --success-color: #52c41a;
            --warning-color: #faad14;
            --danger-color: #ff4d4f;
            --text-color: #262626;
            --text-secondary-color: #8c8c8c;
            --border-color: #f0f0f0;
            --background-color: #f0f2f5;
            --card-background-color: #fff;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background: var(--background-color); color: var(--text-color); overflow-x: hidden; }
        .dashboard { display: flex; min-height: 100vh; }
        .content { padding: 24px; }

        .page-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            gap: 16px;
        }
        .breadcrumb {
            margin-bottom: 0;
            font-size: 16px;
            color: var(--text-secondary-color);
        }
        .breadcrumb a {
            color: var(--primary-color);
            text-decoration: none;
        }
        .breadcrumb a:hover { text-decoration: underline; }

        .table-wrapper {
            background: var(--card-background-color);
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
        }
        .table-container { overflow-x: auto; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 16px 24px; text-align: left; border-bottom: 1px solid var(--border-color); font-size: 16px; }
        .table th:first-child, .table td:first-child { padding-left: 24px; }
        .table th {
            background: #fafafa;
            font-weight: 600;
            color: #262626;
        }
        .table td { color: #595959; }
        .table tr:hover { background: #fafafa; }

        .btn { padding: 8px 16px; border: 1px solid #d9d9d9; border-radius: 6px; cursor: pointer; font-size: 14px; transition: all 0.3s; background: #fff; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 6px; }
        .btn-primary { background: var(--primary-color); color: white; border-color: var(--primary-color); }
        .btn-primary:hover { background: #40a9ff; border-color: #40a9ff; }
        .action-buttons { display: flex; gap: 8px; flex-wrap: wrap; }
        .btn-action { padding: 4px 12px; border-radius: 4px; font-size: 14px; text-decoration: none; display: inline-block; transition: all 0.3s; background: #fff; border: 1px solid; }
        .btn-edit { color: var(--success-color); border-color: var(--success-color); }
        .btn-edit:hover { background: var(--success-color); color: white; }
        .btn-view-list { color: var(--primary-color); border-color: var(--primary-color); }
        .btn-view-list:hover { background: var(--primary-color); color: white; }
        .btn-delete { color: var(--danger-color); border-color: var(--danger-color); }
        .btn-delete:hover { background: var(--danger-color); color: white; }

        .form-group { margin-bottom: 20px; }
        .form-label { display: block; margin-bottom: 8px; font-weight: 500; }
        .form-control { width: 100%; padding: 10px 12px; border: 1px solid #d9d9d9; border-radius: 6px; font-size: 14px; }
        .form-control:focus { outline: none; border-color: var(--primary-color); box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.2); }
        .form-row { display: flex; gap: 16px; }
        .form-row .form-group { flex: 1; }

        .message { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; font-weight: 500; }
        .message.success { background: #f6ffed; border: 1px solid #b7eb8f; color: var(--success-color); }
        .message.error { background: #fff2f0; border: 1px solid #ffccc7; color: var(--danger-color); }

        .status-badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500; }
        .status-active { background: #f6ffed; color: var(--success-color); border: 1px solid #b7eb8f; }
        .status-inactive { background: #fff2f0; color: var(--danger-color); border: 1px solid #ffccc7; }

        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.45); }
        .modal-content { background: #fff; margin: 2% auto; padding: 0; border-radius: 8px; width: 90%; max-width: 600px; max-height: 90vh; box-shadow: 0 4px 12px rgba(0,0,0,0.15); display: flex; flex-direction: column; }
        .modal-header { padding: 16px 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }
        .modal-title { font-size: 16px; font-weight: 600; }
        .close { color: var(--text-secondary-color); font-size: 20px; font-weight: bold; cursor: pointer; }
        .close:hover { color: var(--text-color); }
        .modal-body { padding: 24px; overflow-y: auto; flex: 1; }
        .modal-footer { padding: 16px 24px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 8px; background: #fafafa; flex-shrink: 0; }
        .required-asterisk { color: var(--danger-color); margin-right: 4px; }

        .table-search input { padding: 8px 12px; border: 1px solid #d9d9d9; border-radius: 6px; width: 240px; font-size: 14px; }
        .empty-state { padding: 48px 24px; text-align: center; color: var(--text-secondary-color); }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include 'sidebar.php'; ?>
        <div class="main-content" id="mainContent">
            <?php include 'header.php'; ?>
            <div class="content">
                <?php if ($message): ?>
                    <div class="message <?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
                <?php endif; ?>

                <?php if (!$skill_class_tables_exist): ?>
                    <div class="table-wrapper">
                        <div class="empty-state">
                            <p>請先執行資料表建立腳本：<code>scripts/database/create_skill_class_tables.sql</code></p>
                        </div>
                    </div>
                <?php elseif (!$is_staff && !$is_department_director && !$is_teacher): ?>
                    <div class="table-wrapper">
                        <div class="empty-state">
                            <p>您目前沒有存取技藝班管理的權限。</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="page-controls">
                        <div class="breadcrumb">
                            <a href="index.php">首頁</a> / <?php echo htmlspecialchars($page_title); ?>
                        </div>
                        <div class="table-search">
                            <input type="text" id="tableSearchInput" placeholder="搜尋場次..." onkeyup="filterTable()">
                            <?php if ($is_staff): ?>
                                <button class="btn btn-primary" onclick="document.getElementById('addSessionModal').style.display='block'"><i class="fas fa-plus"></i> 新增技藝班場次</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="table-wrapper">
                        <div class="table-container">
                            <table class="table" id="sessionTable">
                                <thead>
                                    <tr>
                                        <th>場次名稱</th>
                                        <th>科系</th>
                                        <th>日期</th>
                                        <?php if ($is_staff): ?>
                                            <th>國中生人數</th>
                                        <?php endif; ?>
                                        <?php if ($is_department_director || $is_staff): ?>
                                            <th>負責老師</th>
                                        <?php endif; ?>
                                        <?php if ($is_staff): ?>
                                            <th>狀態</th>
                                        <?php endif; ?>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($sessions as $item): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['session_name']); ?></td>
                                        <td><?php echo htmlspecialchars($item['department_name'] ?? $item['department_code']); ?></td>
                                        <td><?php echo $item['session_date'] ? date('Y/m/d', strtotime($item['session_date'])) : '—'; ?></td>
                                        <?php if ($is_staff): ?>
                                            <td><?php echo (int)($item['student_count'] ?? 0); ?></td>
                                        <?php endif; ?>
                                        <?php if ($is_department_director || $is_staff): ?>
                                            <td><?php echo htmlspecialchars($item['teacher_names'] ?? '—'); ?></td>
                                        <?php endif; ?>
                                        <?php if ($is_staff): ?>
                                            <td><span class="status-badge <?php echo $item['is_active'] ? 'status-active' : 'status-inactive'; ?>"><?php echo $item['is_active'] ? '啟用' : '停用'; ?></span></td>
                                        <?php endif; ?>
                                        <td>
                                            <div class="action-buttons">
                                                <?php if ($is_staff): ?>
                                                    <button type="button" class="btn-action btn-edit" onclick='editSession(<?php echo json_encode($item); ?>)'>編輯</button>
                                                    <a href="skillclass_students.php?session_id=<?php echo (int)$item['id']; ?>" class="btn-action btn-view-list">國中生分配</a>
                                                <?php endif; ?>
                                                <?php if ($is_department_director): ?>
                                                    <a href="skillclass_assign_teacher.php?session_id=<?php echo (int)$item['id']; ?>" class="btn-action btn-edit">分配老師</a>
                                                    <a href="skillclass_overview.php?session_id=<?php echo (int)$item['id']; ?>" class="btn-action btn-view-list">總覽</a>
                                                <?php endif; ?>
                                                <?php if ($is_teacher): ?>
                                                    <a href="skillclass_weekly.php?session_id=<?php echo (int)$item['id']; ?>" class="btn-action btn-edit">每週內容</a>
                                                    <a href="skillclass_feedback.php?session_id=<?php echo (int)$item['id']; ?>" class="btn-action btn-view-list">學生回饋</a>
                                                <?php endif; ?>
                                                <?php if ($is_staff): ?>
                                                    <form method="POST" style="display:inline;" onsubmit="return confirm('確定要刪除此技藝班場次嗎？');">
                                                        <input type="hidden" name="action" value="delete_skill_session">
                                                        <input type="hidden" name="session_id" value="<?php echo (int)$item['id']; ?>">
                                                        <button type="submit" class="btn-action btn-delete">刪除</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if (empty($sessions)): ?>
                            <div class="empty-state">目前沒有技藝班場次。</div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($skill_class_tables_exist && $is_staff): ?>
    <!-- 新增技藝班場次 Modal -->
    <div id="addSessionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">新增技藝班場次</h3>
                <span class="close" onclick="document.getElementById('addSessionModal').style.display='none'">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_skill_session">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label"><span class="required-asterisk">*</span>場次名稱</label>
                        <input type="text" name="session_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><span class="required-asterisk">*</span>科系</label>
                        <select name="department_code" class="form-control" required>
                            <option value="">請選擇科系</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?php echo htmlspecialchars($d['code']); ?>"><?php echo htmlspecialchars($d['name'] ?? $d['code']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">開始日期</label>
                            <input type="date" name="session_date" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label">結束日期</label>
                            <input type="date" name="session_end_date" class="form-control">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">說明</label>
                        <textarea name="description" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="document.getElementById('addSessionModal').style.display='none'">取消</button>
                    <button type="submit" class="btn btn-primary">新增</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 編輯技藝班場次 Modal -->
    <div id="editSessionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">編輯技藝班場次</h3>
                <span class="close" onclick="document.getElementById('editSessionModal').style.display='none'">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_skill_session">
                <input type="hidden" name="session_id" id="edit_session_id">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label"><span class="required-asterisk">*</span>場次名稱</label>
                        <input type="text" name="session_name" id="edit_session_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><span class="required-asterisk">*</span>科系</label>
                        <select name="department_code" id="edit_department_code" class="form-control" required>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?php echo htmlspecialchars($d['code']); ?>"><?php echo htmlspecialchars($d['name'] ?? $d['code']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">開始日期</label>
                            <input type="date" name="session_date" id="edit_session_date" class="form-control">
                        </div>
                        <div class="form-group">
                            <label class="form-label">結束日期</label>
                            <input type="date" name="session_end_date" id="edit_session_end_date" class="form-control">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">說明</label>
                        <textarea name="description" id="edit_description" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">狀態</label>
                        <select name="is_active" id="edit_is_active" class="form-control">
                            <option value="1">啟用</option>
                            <option value="0">停用</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="document.getElementById('editSessionModal').style.display='none'">取消</button>
                    <button type="submit" class="btn btn-primary">儲存</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script>
        function editSession(item) {
            document.getElementById('edit_session_id').value = item.id || '';
            document.getElementById('edit_session_name').value = item.session_name || '';
            document.getElementById('edit_department_code').value = item.department_code || '';
            document.getElementById('edit_session_date').value = item.session_date ? item.session_date.substring(0, 10) : '';
            document.getElementById('edit_session_end_date').value = item.session_end_date ? item.session_end_date.substring(0, 10) : '';
            document.getElementById('edit_description').value = item.description || '';
            document.getElementById('edit_is_active').value = item.is_active != null ? item.is_active : 1;
            document.getElementById('editSessionModal').style.display = 'block';
        }
        function filterTable() {
            var q = document.getElementById('tableSearchInput').value.toLowerCase();
            var rows = document.querySelectorAll('#sessionTable tbody tr');
            rows.forEach(function(row) {
                var text = row.textContent.toLowerCase();
                row.style.display = text.indexOf(q) > -1 ? '' : 'none';
            });
        }
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) event.target.style.display = 'none';
        };
    </script>
</body>
</html>
