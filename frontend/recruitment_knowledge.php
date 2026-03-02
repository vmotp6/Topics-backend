<?php
/**
 * 官方招生知識庫 (KM)
 * 一筆知識 = 問題 + 官方回答 + 多個附件
 */
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/includes/department_session_access.php';
checkBackendLogin();
require_once '../../Topics-frontend/frontend/config.php';

$page_title = '官方招生知識庫 (KM)';
$conn = getDatabaseConnection();

$normalized_role = normalizeBackendRole($_SESSION['role'] ?? '');
$current_user_id = getOrFetchCurrentUserId($conn);
$user_department = null;
$is_staff   = in_array($normalized_role, ['ADM', 'STA'], true);
$is_admin   = ($normalized_role === 'ADM');
$is_director = in_array($normalized_role, ['DI', 'IM'], true);

if ($is_director && $current_user_id) {
    $user_department = getCurrentUserDepartmentCode($conn, $current_user_id);
}

$can_manage = $is_staff || $is_admin || ($is_director && !empty($user_department));
if (!$can_manage) {
    header('Location: index.php');
    exit;
}

$upload_dir = __DIR__ . '/uploads/recruitment/';
if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0755, true);
}

$message = '';
$messageType = '';

// 檢查資料表是否存在
$has_tables = false;
$tr = $conn->query("SHOW TABLES LIKE 'recruitment_knowledge'");
if ($tr && $tr->num_rows > 0) {
    $t2 = $conn->query("SHOW TABLES LIKE 'recruitment_knowledge_files'");
    if ($t2 && $t2->num_rows > 0) {
        $has_tables = true;
    }
}

/* 處理表單動作 */
if ($has_tables && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $action = $_POST['action'];

        // 新增一筆 QA
        if ($action === 'add_qa') {
            $question = trim($_POST['question'] ?? '');
            $answer   = trim($_POST['answer'] ?? '');
            if ($question === '' || $answer === '') {
                throw new Exception('請填寫問題與回答。');
            }

            $source_type  = ($is_staff || $is_admin) ? 'staff' : 'department';
            $source_label = ($is_staff || $is_admin) ? '招生中心' : '科系主任';
            $dept         = ($is_staff || $is_admin) ? null : $user_department;

            if ($is_director && !empty($user_department)) {
                $d = $conn->query("SELECT name FROM departments WHERE code = '" . $conn->real_escape_string($user_department) . "' LIMIT 1");
                if ($d && $row = $d->fetch_assoc()) {
                    $source_label = $row['name'] . '科主任';
                }
            }

            $stmt = $conn->prepare(
                "INSERT INTO recruitment_knowledge
                 (question, answer, source_type, source_label, department_code, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('sssssi', $question, $answer, $source_type, $source_label, $dept, $current_user_id);
            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }
            $kid = (int)$conn->insert_id;
            $stmt->close();

            // 上傳多個附件
            $allowed_ext = ['pdf','doc','docx','ppt','pptx','xls','xlsx','txt'];
            if (!empty($_FILES['files']['name'][0])) {
                $ins = $conn->prepare(
                    "INSERT INTO recruitment_knowledge_files
                     (knowledge_id, file_path, file_original_name, file_size)
                     VALUES (?, ?, ?, ?)"
                );
                foreach ($_FILES['files']['name'] as $i => $name) {
                    if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK || !$name) continue;
                    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowed_ext, true)) continue;
                    $safe = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $name);
                    $filename = date('YmdHis') . '_' . $i . '_' . $safe;
                    $path = $upload_dir . $filename;
                    if (!move_uploaded_file($_FILES['files']['tmp_name'][$i], $path)) continue;
                    $rel  = 'uploads/recruitment/' . $filename;
                    $size = (int)@filesize($path);
                    $ins->bind_param('issi', $kid, $rel, $name, $size);
                    $ins->execute();
                }
                $ins->close();
            }

            $message = '已新增一筆知識（問題、回答、附件）。';
            $messageType = 'success';

        // 編輯 QA（問題與回答）- 僅建立者可編輯
        } elseif ($action === 'edit_qa') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('無效的項目。');

            $chk = $conn->prepare(
                "SELECT id, created_by FROM recruitment_knowledge WHERE id = ? LIMIT 1"
            );
            $chk->bind_param('i', $id);
            $chk->execute();
            $chkRow = $chk->get_result()->fetch_assoc();
            $chk->close();
            if (!$chkRow) throw new Exception('無效的項目。');
            if ((int)$chkRow['created_by'] !== (int)$current_user_id) {
                throw new Exception('僅建立者可編輯此筆知識。');
            }
            if (!$is_staff && !$is_admin) {
                $chk2 = $conn->prepare(
                    "SELECT id FROM recruitment_knowledge WHERE id = ? AND department_code = ? LIMIT 1"
                );
                $chk2->bind_param('is', $id, $user_department);
                $chk2->execute();
                if (!$chk2->get_result()->fetch_assoc()) {
                    throw new Exception('權限不足。');
                }
                $chk2->close();
            }

            $question = trim($_POST['question'] ?? '');
            $answer   = trim($_POST['answer'] ?? '');
            if ($question === '' || $answer === '') {
                throw new Exception('請填寫問題與回答。');
            }

            $stmt = $conn->prepare(
                "UPDATE recruitment_knowledge
                 SET question = ?, answer = ?, updated_at = NOW()
                 WHERE id = ?"
            );
            $stmt->bind_param('ssi', $question, $answer, $id);
            if (!$stmt->execute()) {
                throw new Exception($stmt->error);
            }
            $stmt->close();
            $message = '已更新。';
            $messageType = 'success';

        // 刪除 QA（含附件與實體檔案）- 僅建立者可刪除
        } elseif ($action === 'delete_item') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('無效的項目。');

            $chk = $conn->prepare(
                "SELECT id, created_by FROM recruitment_knowledge WHERE id = ? LIMIT 1"
            );
            $chk->bind_param('i', $id);
            $chk->execute();
            $chkRow = $chk->get_result()->fetch_assoc();
            $chk->close();
            if (!$chkRow) throw new Exception('無效的項目。');
            if ((int)$chkRow['created_by'] !== (int)$current_user_id) {
                throw new Exception('僅建立者可刪除此筆知識。');
            }
            if (!$is_staff && !$is_admin) {
                $chk2 = $conn->prepare(
                    "SELECT id FROM recruitment_knowledge WHERE id = ? AND department_code = ? LIMIT 1"
                );
                $chk2->bind_param('is', $id, $user_department);
                $chk2->execute();
                if (!$chk2->get_result()->fetch_assoc()) {
                    throw new Exception('權限不足。');
                }
                $chk2->close();
            }

            // 刪除附件實體檔
            $fres = $conn->query(
                "SELECT file_path FROM recruitment_knowledge_files WHERE knowledge_id = {$id}"
            );
            if ($fres) {
                while ($f = $fres->fetch_assoc()) {
                    if (!empty($f['file_path'])) {
                        $p = __DIR__ . '/' . $f['file_path'];
                        if (is_file($p)) @unlink($p);
                    }
                }
            }

            $del = $conn->prepare("DELETE FROM recruitment_knowledge WHERE id = ?");
            $del->bind_param('i', $id);
            if (!$del->execute()) {
                throw new Exception($del->error);
            }
            $del->close();
            $message = '已刪除。';
            $messageType = 'success';
        }
    } catch (Exception $e) {
        $message = '操作失敗：' . $e->getMessage();
        $messageType = 'error';
    }
}

/* 讀取列表資料 */
$items = [];
if ($has_tables) {
    $where = "k.is_active = 1";
    $params = [];
    $types  = '';

    if (!$is_staff && !$is_admin && !empty($user_department)) {
        $where .= " AND (k.source_type = 'staff' OR (k.source_type = 'department' AND k.department_code = ?))";
        $params[] = $user_department;
        $types   .= 's';
    }

    $sql = "
        SELECT
            k.*,
            d.name AS dept_name,
            COALESCE(u.name, u.username, '') AS created_by_name,
            (SELECT COUNT(*) FROM recruitment_knowledge_files f WHERE f.knowledge_id = k.id) AS file_count
        FROM recruitment_knowledge k
        LEFT JOIN user u ON u.id = k.created_by
        LEFT JOIN departments d ON k.department_code = d.code AND k.source_type = 'department'
        WHERE {$where}
        ORDER BY k.updated_at DESC, k.id DESC
    ";

    if ($params) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
    } else {
        $res = $conn->query($sql);
    }
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $items[] = $row;
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
            --danger-color: #ff4d4f;
            --text-color: #262626;
            --text-secondary-color: #8c8c8c;
            --border-color: #f0f0f0;
            --background-color: #f0f2f5;
            --card-background-color: #fff;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--background-color);
            color: var(--text-color);
        }
        .dashboard { display: flex; min-height: 100vh; }
        .content   { padding: 24px; }
        .breadcrumb {
            font-size: 16px;
            color: var(--text-secondary-color);
            margin-bottom: 16px;
        }
        .breadcrumb a { color: var(--primary-color); text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }
        .page-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            gap: 16px;
        }
        .table-wrapper {
            background: var(--card-background-color);
            border-radius: 8px;
            border: 1px solid var(--border-color);
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            margin-bottom: 24px;
        }
        .table-container { overflow-x: auto; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td {
            padding: 14px 20px;
            border-bottom: 1px solid var(--border-color);
            text-align: left;
            font-size: 14px;
        }
        .table th { background: #fafafa; font-weight: 600; }
        .table tr:hover { background: #fafafa; }
        .btn {
            padding: 8px 16px;
            border-radius: 6px;
            border: 1px solid #d9d9d9;
            background: #fff;
            font-size: 14px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
        }
        .btn-primary {
            background: var(--primary-color);
            color: #fff;
            border-color: var(--primary-color);
        }
        .btn-primary:hover { background: #40a9ff; }
        .action-buttons { display: flex; gap: 8px; flex-wrap: wrap; }
        .btn-action {
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 13px;
            border: 1px solid;
            background: #fff;
        }
        .btn-edit { color: var(--success-color); border-color: var(--success-color); }
        .btn-edit:hover { background: var(--success-color); color: #fff; }
        .btn-delete { color: var(--danger-color); border-color: var(--danger-color); }
        .btn-delete:hover { background: var(--danger-color); color: #fff; }
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; margin-bottom: 6px; font-weight: 500; }
        .form-control {
            width: 100%;
            padding: 10px 12px;
            border-radius: 6px;
            border: 1px solid #d9d9d9;
            font-size: 14px;
        }
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(24,144,255,0.2);
        }
        .message {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 16px;
        }
        .message.success {
            background: #f6ffed;
            border: 1px solid #b7eb8f;
            color: var(--success-color);
        }
        .message.error {
            background: #fff2f0;
            border: 1px solid #ffccc7;
            color: var(--danger-color);
        }
        .table-search input {
            width: 240px;
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid #d9d9d9;
            font-size: 14px;
        }
        .empty-state {
            padding: 36px 24px;
            text-align: center;
            color: var(--text-secondary-color);
        }
        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.45);
            z-index: 2000;
        }
        .modal-content {
            background: #fff;
            margin: 3% auto;
            border-radius: 8px;
            width: 90%;
            max-width: 640px;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
        }
        .modal-header,
        .modal-footer {
            padding: 16px 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-footer {
            border-top: 1px solid var(--border-color);
            border-bottom: none;
            justify-content: flex-end;
            gap: 8px;
        }
        .modal-body { padding: 20px 24px; overflow-y: auto; }
        .modal-title { font-size: 16px; font-weight: 600; }
        .close { cursor: pointer; color: var(--text-secondary-color); font-size: 20px; }
    </style>
</head>
<body>
<div class="dashboard">
    <?php include 'sidebar.php'; ?>
    <div class="main-content" id="mainContent">
        <?php include 'header.php'; ?>
        <div class="content">
            <?php if ($message): ?>
                <div class="message <?php echo $messageType; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <?php if (!$has_tables): ?>
                <div class="table-wrapper">
                    <div class="empty-state">
                        找不到招生知識庫資料表，請先在資料庫中建立
                        <code>recruitment_knowledge</code> 與
                        <code>recruitment_knowledge_files</code>。
                    </div>
                </div>
            <?php else: ?>
                <div class="page-controls">
                    <div class="breadcrumb">
                        <a href="index.php">首頁</a> / <?php echo htmlspecialchars($page_title); ?>
                    </div>
                    <div class="table-search">
                        <input type="text" id="searchInput" placeholder="搜尋問題、來源..." onkeyup="filterTable()">
                        <button type="button" class="btn btn-primary"
                                onclick="document.getElementById('addQAModal').style.display='block'">
                            <i class="fas fa-plus"></i> 新增一筆知識
                        </button>
                    </div>
                </div>

                <div class="table-wrapper">
                    <div class="table-container">
                        <table class="table" id="dataTable">
                            <thead>
                            <tr>
                                <th>問題／主題</th>
                                <th>回答摘要</th>
                                <th>來源</th>
                                <th>附件</th>
                                <th>建立者</th>
                                <th>建立時間</th>
                                <th>操作</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($items as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['question'] ?? ''); ?></td>
                                    <td>
                                        <?php
                                        $ans = (string)($row['answer'] ?? '');
                                        $short = mb_substr($ans, 0, 60);
                                        echo htmlspecialchars($short);
                                        if (mb_strlen($ans) > 60) echo '…';
                                        ?>
                                    </td>
                                    <td><?php
                                        $slabel = (($row['source_type'] ?? '') === 'department' && !empty($row['dept_name']))
                                            ? $row['dept_name'] . '科主任'
                                            : ($row['source_label'] ?? '');
                                        echo htmlspecialchars($slabel);
                                    ?></td>
                                    <td><?php echo (int)($row['file_count'] ?? 0); ?></td>
                                    <td><?php echo htmlspecialchars($row['created_by_name'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['created_at'] ?? ''); ?></td>
                                    <td>
                                        <?php $is_owner = ((int)($row['created_by'] ?? 0) === (int)$current_user_id); ?>
                                        <?php if ($is_owner): ?>
                                        <div class="action-buttons">
                                            <button type="button"
                                                    class="btn-action btn-edit"
                                                    onclick='editQA(<?php echo json_encode($row, JSON_UNESCAPED_UNICODE); ?>)'>
                                                編輯
                                            </button>
                                            <form method="POST" style="display:inline;"
                                                  onsubmit="return confirm('確定刪除此筆知識？');">
                                                <input type="hidden" name="action" value="delete_item">
                                                <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                                <button type="submit" class="btn-action btn-delete">刪除</button>
                                            </form>
                                        </div>
                                        <?php else: ?>
                                        <span style="color:#8c8c8c">僅供查看</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (empty($items)): ?>
                        <div class="empty-state">
                            尚無資料，請點「新增一筆知識」建立問題與官方回答。
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 新增 QA Modal -->
<div id="addQAModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">新增一筆知識（問題 → 回答 → 附件）</h3>
            <span class="close" onclick="document.getElementById('addQAModal').style.display='none'">&times;</span>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_qa">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label"><span style="color:#ff4d4f">*</span>問題／主題</label>
                    <input type="text" name="question" class="form-control"
                           placeholder="例如：康寧大學學費說明、五專部學費與減免" required>
                </div>
                <div class="form-group">
                    <label class="form-label"><span style="color:#ff4d4f">*</span>官方回答</label>
                    <textarea name="answer" class="form-control" rows="6"
                              placeholder="填寫給老師／家長看的正式說明" required></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">附件檔案（選填，可多選）</label>
                    <input type="file" name="files[]" class="form-control"
                           accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt" multiple>
                    <small style="color:#8c8c8c">支援：PDF、Word、簡報、Excel、TXT</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn"
                        onclick="document.getElementById('addQAModal').style.display='none'">取消</button>
                <button type="submit" class="btn btn-primary">新增</button>
            </div>
        </form>
    </div>
</div>

<!-- 編輯 QA Modal -->
<div id="editQAModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">編輯問題與回答</h3>
            <span class="close" onclick="document.getElementById('editQAModal').style.display='none'">&times;</span>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit_qa">
            <input type="hidden" name="id" id="edit_id">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label"><span style="color:#ff4d4f">*</span>問題／主題</label>
                    <input type="text" name="question" id="edit_question" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label"><span style="color:#ff4d4f">*</span>官方回答</label>
                    <textarea name="answer" id="edit_answer" class="form-control" rows="6" required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn"
                        onclick="document.getElementById('editQAModal').style.display='none'">取消</button>
                <button type="submit" class="btn btn-primary">儲存</button>
            </div>
        </form>
    </div>
</div>

<script>
    function editQA(row) {
        document.getElementById('edit_id').value       = row.id || '';
        document.getElementById('edit_question').value = row.question || '';
        document.getElementById('edit_answer').value   = row.answer || '';
        document.getElementById('editQAModal').style.display = 'block';
    }

    function filterTable() {
        var q = document.getElementById('searchInput').value.toLowerCase();
        document.querySelectorAll('#dataTable tbody tr').forEach(function (tr) {
            tr.style.display = tr.textContent.toLowerCase().indexOf(q) > -1 ? '' : 'none';
        });
    }

    window.onclick = function (e) {
        if (e.target.classList && e.target.classList.contains('modal')) {
            e.target.style.display = 'none';
        }
    };
</script>
</body>
</html>

