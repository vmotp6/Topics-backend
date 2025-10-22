<?php
session_start();

// 檢查是否已登入
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: login.php");
    exit;
}

// 引入資料庫設定
require_once '../../Topics-frontend/frontend/config.php';

// 設置頁面標題
$page_title = '場次設定';

// 建立資料庫連接
$conn = getDatabaseConnection();

$message = "";
$messageType = "";

// 處理表單提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            // 場次管理
            case 'add_session':
                $sql = "INSERT INTO admission_sessions (session_name, session_date, session_type, max_participants, description, is_active) VALUES (?, ?, ?, ?, ?, 1)";
                $stmt = $conn->prepare($sql);
                $max_participants = !empty($_POST['max_participants']) ? $_POST['max_participants'] : null;
                $stmt->bind_param("sssis", $_POST['session_name'], $_POST['session_date'], $_POST['session_type'], $max_participants, $_POST['description']);
                if ($stmt->execute()) {
                    $message = "場次新增成功！"; $messageType = "success";
                }
                break;

            case 'update_session':
                $sql = "UPDATE admission_sessions SET session_name = ?, session_date = ?, session_type = ?, max_participants = ?, description = ?, is_active = ? WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $max_participants = !empty($_POST['max_participants']) ? $_POST['max_participants'] : null;
                $stmt->bind_param("sssissi", $_POST['session_name'], $_POST['session_date'], $_POST['session_type'], $max_participants, $_POST['description'], $_POST['is_active'], $_POST['session_id']);
                if ($stmt->execute()) {
                    $message = "場次更新成功！"; $messageType = "success";
                }
                break;

            case 'delete_session':
                $sql = "DELETE FROM admission_sessions WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $_POST['session_id']);
                if ($stmt->execute()) {
                    $message = "場次刪除成功！"; $messageType = "success";
                }
                break;

            // 課程管理
            case 'add_course':
                $sql = "INSERT INTO admission_courses (course_name, is_active, sort_order) VALUES (?, 1, 0)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("s", $_POST['course_name']);
                if ($stmt->execute()) {
                    $message = "課程新增成功！"; $messageType = "success";
                }
                break;

            case 'update_course':
                $sql = "UPDATE admission_courses SET course_name = ?, is_active = ? WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sii", $_POST['course_name'], $_POST['is_active'], $_POST['course_id']);
                if ($stmt->execute()) {
                    $message = "課程更新成功！"; $messageType = "success";
                }
                break;

            case 'delete_course':
                $sql = "DELETE FROM admission_courses WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $_POST['course_id']);
                if ($stmt->execute()) {
                    $message = "課程刪除成功！"; $messageType = "success";
                }
                break;
        }
        if (isset($stmt) && $stmt->error) {
            throw new Exception($stmt->error);
        }
    } catch (Exception $e) {
        $message = "操作失敗：" . $e->getMessage();
        $messageType = "error";
    }
}

// 獲取所有資料
$sessions = $conn->query("SELECT s.*, COUNT(a.id) as registration_count FROM admission_sessions s LEFT JOIN admission_applications a ON s.id = a.session_choice GROUP BY s.id ORDER BY s.session_date DESC")->fetch_all(MYSQLI_ASSOC);
$sessions_sql = "
    SELECT s.*, 
           COUNT(a.id) as registration_count,
           (s.max_participants - COUNT(a.id)) as remaining_slots
    FROM admission_sessions s
    LEFT JOIN admission_applications a ON s.id = a.session_id GROUP BY s.id ORDER BY s.session_date DESC";
$sessions = $conn->query($sessions_sql)->fetch_all(MYSQLI_ASSOC);;
$courses = $conn->query("SELECT * FROM admission_courses ORDER BY sort_order, id")->fetch_all(MYSQLI_ASSOC);

$conn->close();
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Topics 後台管理系統</title>
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
        .breadcrumb { margin-bottom: 16px; font-size: 16px; color: var(--text-secondary-color); }
        .breadcrumb a { color: var(--primary-color); text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }
        
        .tabs { display: flex; background: var(--card-background-color); border-radius: 8px 8px 0 0; box-shadow: 0 1px 2px rgba(0,0,0,0.03); border: 1px solid var(--border-color); border-bottom: none; margin-bottom: 0; overflow: hidden; }
        .tab { flex: 1; padding: 16px 24px; background: var(--card-background-color); border: none; cursor: pointer; font-size: 16px; font-weight: 500; color: var(--text-secondary-color); transition: all 0.3s; border-bottom: 3px solid transparent; }
        .tab.active { color: var(--primary-color); border-bottom-color: var(--primary-color); }
        .tab:hover { background: #f5f5f5; }
        
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        .card { background: var(--card-background-color); border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.03); border: 1px solid var(--border-color); margin-bottom: 24px; }
        .card-header { padding: 16px 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background: #fafafa; }
        .card-header h3 { font-size: 18px; font-weight: 600; color: var(--text-color); }
        .card-body { padding: 24px; }

        .table-container { overflow-x: auto; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 16px; text-align: left; border-bottom: 1px solid var(--border-color); }
        .table th { background: #fafafa; font-weight: 600; }
        .table tr:hover { background: #fafafa; }

        .btn { padding: 8px 16px; border: 1px solid #d9d9d9; border-radius: 6px; cursor: pointer; font-size: 14px; transition: all 0.3s; background: #fff; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .btn-primary { background: var(--primary-color); color: white; border-color: var(--primary-color); }
        .btn-primary:hover { background: #40a9ff; border-color: #40a9ff; }
        .btn-danger { background: var(--danger-color); color: white; border-color: var(--danger-color); }
        .btn-danger:hover { background: #ff7875; border-color: #ff7875; }
        .btn-info { background: #17a2b8; color: white; border-color: #17a2b8; }
        .btn-info:hover { background: #138496; border-color: #117a8b; }
        .btn-sm { padding: 4px 10px; font-size: 12px; }

        .form-group { margin-bottom: 20px; }
        .form-label { display: block; margin-bottom: 8px; font-weight: 500; }
        .form-control { width: 100%; padding: 10px 12px; border: 1px solid #d9d9d9; border-radius: 6px; font-size: 14px; transition: border-color 0.3s; }
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
        .modal-content { background-color: #fff; margin: 5% auto; padding: 0; border-radius: 8px; width: 90%; max-width: 600px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .modal-header { padding: 16px 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }
        .modal-title { font-size: 16px; font-weight: 600; }
        .close { color: var(--text-secondary-color); font-size: 20px; font-weight: bold; cursor: pointer; }
        .close:hover { color: var(--text-color); }
        .modal-body { padding: 24px; }
        .modal-footer { padding: 16px 24px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 8px; background: #fafafa; }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include 'sidebar.php'; ?>
        <div class="main-content" id="mainContent">
            <?php include 'header.php'; ?>
            <div class="content">
                <div class="breadcrumb">
                    <a href="index.php">首頁</a> / <?php echo $page_title; ?>
                </div>

                <?php if ($message): ?>
                    <div class="message <?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
                <?php endif; ?>

                <div class="tabs">
                    <button class="tab active" onclick="switchTab('sessions')"><i class="fas fa-calendar-alt"></i> 說明會場次</button>
                    <button class="tab" onclick="switchTab('courses')"><i class="fas fa-book-open"></i> 體驗課程</button>
                </div>

                <!-- 場次管理 -->
                <div id="sessions" class="tab-content active">
                    <div class="card">
                        <div class="card-header">
                            <h3>場次列表</h3>
                            <button class="btn btn-primary" onclick="showModal('addSessionModal')"><i class="fas fa-plus"></i> 新增場次</button>
                        </div>
                        <div class="card-body table-container">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>場次名稱</th>
                                        <th>日期時間</th>
                                        <th>類型</th>
                                        <th>報名/上限</th>
                                        <th>剩餘名額</th>
                                        <th>狀態</th>
                                        <th>操作</th>                                       
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($sessions as $item): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['session_name']); ?></td>
                                        <td><?php echo date('Y/m/d H:i', strtotime($item['session_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($item['session_type']); ?></td>
                                        <td><?php echo $item['registration_count']; ?> / <?php echo $item['max_participants'] ?: '無限'; ?></td>
                                        <td>
                                            <?php 
                                            if ($item['max_participants']) {
                                                if ($item['remaining_slots'] <= 0) {
                                                    echo ' <span style="color: var(--danger-color);">(已額滿)</span>';
                                                } else {
                                                    echo '剩餘 ' . $item['remaining_slots'] . ' 位';
                                                }
                                            } else {
                                                echo '無限';
                                            }
                                            ?>
                                        </td>
                                        <td><span class="status-badge <?php echo $item['is_active'] ? 'status-active' : 'status-inactive'; ?>"><?php echo $item['is_active'] ? '啟用' : '停用'; ?></span></td>
                                        <td>
                                            <button class="btn btn-sm" onclick='editSession(<?php echo json_encode($item); ?>)'>編輯</button>
                                            <a href="view_registrations.php?session_id=<?php echo $item['id']; ?>" class="btn btn-info btn-sm">查看名單</a>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('確定要刪除此場次嗎？');">
                                                <input type="hidden" name="action" value="delete_session">
                                                <input type="hidden" name="session_id" value="<?php echo $item['id']; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm">刪除</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- 課程管理 -->
                <div id="courses" class="tab-content">
                    <div class="card">
                        <div class="card-header">
                            <h3>體驗課程列表</h3>
                            <button class="btn btn-primary" onclick="showModal('addCourseModal')"><i class="fas fa-plus"></i> 新增課程</button>
                        </div>
                        <div class="card-body table-container">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>課程名稱</th>
                                        <th>狀態</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($courses as $item): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['course_name']); ?></td>
                                        <td><span class="status-badge <?php echo $item['is_active'] ? 'status-active' : 'status-inactive'; ?>"><?php echo $item['is_active'] ? '啟用' : '停用'; ?></span></td>
                                        <td>
                                            <button class="btn btn-sm" onclick='editCourse(<?php echo json_encode($item); ?>)'>編輯</button>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('確定要刪除此課程嗎？');">
                                                <input type="hidden" name="action" value="delete_course">
                                                <input type="hidden" name="course_id" value="<?php echo $item['id']; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm">刪除</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 新增場次 Modal -->
    <div id="addSessionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">新增場次</h3>
                <span class="close" onclick="closeModal('addSessionModal')">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_session">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">場次名稱 *</label>
                        <input type="text" name="session_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">場次日期 *</label>
                        <input type="datetime-local" name="session_date" class="form-control" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">場次類型 *</label>
                            <select name="session_type" class="form-control" required>
                                <option value="實體">實體</option>
                                <option value="線上">線上</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">人數上限 (選填)</label>
                            <input type="number" name="max_participants" class="form-control" min="1">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">描述 (選填)</label>
                        <textarea name="description" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal('addSessionModal')">取消</button>
                    <button type="submit" class="btn btn-primary">新增</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 編輯場次 Modal -->
    <div id="editSessionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">編輯場次</h3>
                <span class="close" onclick="closeModal('editSessionModal')">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_session">
                <input type="hidden" name="session_id" id="edit_session_id">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">場次名稱 *</label>
                        <input type="text" name="session_name" id="edit_session_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">場次日期 *</label>
                        <input type="datetime-local" name="session_date" id="edit_session_date" class="form-control" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">場次類型 *</label>
                            <select name="session_type" id="edit_session_type" class="form-control" required>
                                <option value="實體">實體</option>
                                <option value="線上">線上</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">人數上限 (選填)</label>
                            <input type="number" name="max_participants" id="edit_max_participants" class="form-control" min="1">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">描述 (選填)</label>
                        <textarea name="description" id="edit_description" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">狀態 *</label>
                        <select name="is_active" id="edit_session_is_active" class="form-control" required>
                            <option value="1">啟用</option>
                            <option value="0">停用</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal('editSessionModal')">取消</button>
                    <button type="submit" class="btn btn-primary">儲存</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 新增課程 Modal -->
    <div id="addCourseModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">新增課程</h3>
                <span class="close" onclick="closeModal('addCourseModal')">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_course">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">課程名稱 *</label>
                        <input type="text" name="course_name" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal('addCourseModal')">取消</button>
                    <button type="submit" class="btn btn-primary">新增</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 編輯課程 Modal -->
    <div id="editCourseModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">編輯課程</h3>
                <span class="close" onclick="closeModal('editCourseModal')">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_course">
                <input type="hidden" name="course_id" id="edit_course_id">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">課程名稱 *</label>
                        <input type="text" name="course_name" id="edit_course_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">狀態 *</label>
                        <select name="is_active" id="edit_course_is_active" class="form-control" required>
                            <option value="1">啟用</option>
                            <option value="0">停用</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal('editCourseModal')">取消</button>
                    <button type="submit" class="btn btn-primary">儲存</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function switchTab(tabName) {
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
            document.getElementById(tabName).classList.add('active');
            event.currentTarget.classList.add('active');
        }

        function showModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }

        function editSession(item) {
            document.getElementById('edit_session_id').value = item.id;
            document.getElementById('edit_session_name').value = item.session_name;
            // 格式化日期以符合 datetime-local input
            const date = new Date(item.session_date.replace(' ', 'T'));
            const formattedDate = date.toISOString().slice(0, 16);
            document.getElementById('edit_session_date').value = formattedDate;
            document.getElementById('edit_session_type').value = item.session_type;
            document.getElementById('edit_max_participants').value = item.max_participants;
            document.getElementById('edit_description').value = item.description;
            document.getElementById('edit_session_is_active').value = item.is_active;
            showModal('editSessionModal');
        }

        function editCourse(item) {
            document.getElementById('edit_course_id').value = item.id;
            document.getElementById('edit_course_name').value = item.course_name;
            document.getElementById('edit_course_is_active').value = item.is_active;
            showModal('editCourseModal');
        }
    </script>
</body>
</html>