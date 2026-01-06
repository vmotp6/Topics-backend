<?php
// 改用共用的 session 設定，確保前台登入可同步到後台
require_once __DIR__ . '/session_config.php';

// 登入檢查（同時接受前台同步的登入狀態）
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: login.php");
    exit;
}

// 標準化角色判斷，與登入邏輯一致
$user_role = $_SESSION['role'] ?? '';
$user_role = strtoupper(trim($user_role));
if ($user_role === '管理員') $user_role = 'ADM';
if (in_array($user_role, ['行政人員', '學校行政人員'])) $user_role = 'STA';

$allowed_roles = ['ADM', 'STA', 'DI', 'TEA', 'STAM', 'IM', 'AS'];
if (!in_array($user_role, $allowed_roles)) {
    header("Location: index.php");
    exit;
}

require_once '../../Topics-frontend/frontend/config.php';
$conn = getDatabaseConnection();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// 初始化
$bulletin = [
    'title' => '',
    'content' => '',
    'status_code' => 'published',
    'is_pinned' => 0
];
$existing_urls = [];
$existing_files = [];
$page_title = '新增招生公告';

// 設定上傳目錄
$upload_dir = '../../Topics-frontend/frontend/uploads/bulletin/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// 編輯模式資料載入
if ($id > 0) {
    $stmt = $conn->prepare("SELECT * FROM bulletin_board WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows > 0) {
        $bulletin = $res->fetch_assoc();
        
        // 兼容舊資料欄位
        if (empty($bulletin['status_code']) && isset($bulletin['is_active'])) {
            $bulletin['status_code'] = $bulletin['is_active'] ? 'published' : 'draft';
        }
        
        $page_title = '編輯招生公告';
        
        // 抓取連結
        $url_stmt = $conn->prepare("SELECT * FROM bulletin_urls WHERE bulletin_id = ? ORDER BY display_order ASC");
        $url_stmt->bind_param("i", $id);
        $url_stmt->execute();
        $existing_urls = $url_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // 抓取檔案
        $file_stmt = $conn->prepare("SELECT * FROM bulletin_files WHERE bulletin_id = ? ORDER BY display_order ASC");
        $file_stmt->bind_param("i", $id);
        $file_stmt->execute();
        $existing_files = $file_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
    } else {
        header("Location: bulletin_board.php");
        exit;
    }
    $stmt->close();
}

// 處理表單提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);
    $status_code = isset($_POST['is_active']) ? 'published' : 'draft';
    $is_pinned = isset($_POST['is_pinned']) ? 1 : 0;
    $user_id = $_SESSION['user_id'] ?? 0;
    // 若 user_id 為空，嘗試用帳號查詢 user 表補足，避免外鍵失敗
    if (empty($user_id) && !empty($_SESSION['username'])) {
        $lookup_stmt = $conn->prepare("SELECT id FROM user WHERE username = ? LIMIT 1");
        $lookup_stmt->bind_param("s", $_SESSION['username']);
        if ($lookup_stmt->execute()) {
            $res = $lookup_stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $user_id = (int)$row['id'];
                $_SESSION['user_id'] = $user_id; // 補寫回 session
            }
        }
        $lookup_stmt->close();
    }

    // 如果仍找不到，使用第一個管理員帳號作為 fallback，避免外鍵中斷
    if (empty($user_id)) {
        $fallback_stmt = $conn->query("SELECT id FROM user WHERE role IN ('ADM','管理員') ORDER BY id ASC LIMIT 1");
        if ($fallback_stmt && $fallback_stmt->num_rows > 0) {
            $user_id = (int)$fallback_stmt->fetch_assoc()['id'];
        }
    }
    
    if (empty($title) || empty($content)) {
        $error = "標題和內容不能為空";
    } else {
        $conn->begin_transaction();
        try {
            if ($id > 0) {
                // 更新
                $stmt = $conn->prepare("UPDATE bulletin_board SET title = ?, content = ?, status_code = ?, is_pinned = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param("sssii", $title, $content, $status_code, $is_pinned, $id);
                $stmt->execute();
                $current_bulletin_id = $id;
            } else {
                // 新增
                $stmt = $conn->prepare("INSERT INTO bulletin_board (user_id, title, content, status_code, is_pinned, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->bind_param("isssi", $user_id, $title, $content, $status_code, $is_pinned);
                $stmt->execute();
                $current_bulletin_id = $conn->insert_id;
            }
            
            // 處理連結刪除與新增
            if (isset($_POST['delete_urls']) && is_array($_POST['delete_urls'])) {
                foreach ($_POST['delete_urls'] as $del_url_id) {
                    $del_stmt = $conn->prepare("DELETE FROM bulletin_urls WHERE id = ? AND bulletin_id = ?");
                    $del_stmt->bind_param("ii", $del_url_id, $current_bulletin_id);
                    $del_stmt->execute();
                }
            }
            if (isset($_POST['new_urls']) && is_array($_POST['new_urls'])) {
                $new_titles = $_POST['new_url_titles'] ?? [];
                $insert_url_stmt = $conn->prepare("INSERT INTO bulletin_urls (bulletin_id, url, title, display_order) VALUES (?, ?, ?, ?)");
                foreach ($_POST['new_urls'] as $idx => $url) {
                    $url = trim($url);
                    if (!empty($url)) {
                        $url_title = $new_titles[$idx] ?? '';
                        $order = $idx; 
                        $insert_url_stmt->bind_param("issi", $current_bulletin_id, $url, $url_title, $order);
                        $insert_url_stmt->execute();
                    }
                }
            }
            
            // 處理檔案刪除與上傳
            if (isset($_POST['delete_files']) && is_array($_POST['delete_files'])) {
                foreach ($_POST['delete_files'] as $del_file_id) {
                    $path_stmt = $conn->prepare("SELECT file_path FROM bulletin_files WHERE id = ? AND bulletin_id = ?");
                    $path_stmt->bind_param("ii", $del_file_id, $current_bulletin_id);
                    $path_stmt->execute();
                    $file_res = $path_stmt->get_result();
                    if ($f_row = $file_res->fetch_assoc()) {
                        if (file_exists($f_row['file_path'])) {
                            unlink($f_row['file_path']);
                        }
                    }
                    $del_file_stmt = $conn->prepare("DELETE FROM bulletin_files WHERE id = ? AND bulletin_id = ?");
                    $del_file_stmt->bind_param("ii", $del_file_id, $current_bulletin_id);
                    $del_file_stmt->execute();
                }
            }
            if (isset($_FILES['new_files']) && !empty($_FILES['new_files']['name'][0])) {
                $files = $_FILES['new_files'];
                $count = count($files['name']);
                $insert_file_stmt = $conn->prepare("INSERT INTO bulletin_files (bulletin_id, file_path, original_filename, file_size, file_type, display_order) VALUES (?, ?, ?, ?, ?, ?)");
                
                for ($i = 0; $i < $count; $i++) {
                    if ($files['error'][$i] === UPLOAD_ERR_OK) {
                        $tmp_name = $files['tmp_name'][$i];
                        $original_name = $files['name'][$i];
                        $size = $files['size'][$i];
                        $type = $files['type'][$i];
                        $ext = pathinfo($original_name, PATHINFO_EXTENSION);
                        $new_filename = uniqid() . '_' . time() . '.' . $ext;
                        $destination = $upload_dir . $new_filename;
                        
                        if (move_uploaded_file($tmp_name, $destination)) {
                            $order = $i;
                            $insert_file_stmt->bind_param("issisi", $current_bulletin_id, $destination, $original_name, $size, $type, $order);
                            $insert_file_stmt->execute();
                        }
                    }
                }
            }
            
            $conn->commit();
            header("Location: bulletin_board.php?msg=saved");
            exit;
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = "儲存失敗：" . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - 後台系統</title>
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
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--background-color); color: var(--text-color); }
        
        /* Dashboard 佈局設定 */
        .dashboard { display: flex; min-height: 100vh; }
        .content { padding: 24px; }
        /* 注意：不要在此定義 .main-content */
        
        .page-controls { margin-bottom: 16px; }
        .breadcrumb { margin-bottom: 0; font-size: 16px; color: var(--text-secondary-color); }
        .breadcrumb a { color: var(--primary-color); text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }
        
        /* Card 容器 */
        .card-wrapper { 
            background: var(--card-background-color); 
            border-radius: 8px; 
            box-shadow: 0 1px 2px rgba(0,0,0,0.03); 
            border: 1px solid var(--border-color); 
            padding: 30px; 
            max-width: 800px; 
            margin: 0 auto; 
        }
        
        .form-group { margin-bottom: 24px; }
        .form-label { display: block; margin-bottom: 8px; font-weight: 500; color: #262626; }
        .form-control { width: 100%; padding: 10px 12px; border: 1px solid #d9d9d9; border-radius: 6px; font-size: 14px; transition: all 0.3s; }
        .form-control:focus { outline: none; border-color: var(--primary-color); box-shadow: 0 0 0 2px rgba(24,144,255,0.2); }
        textarea.form-control { min-height: 200px; resize: vertical; line-height: 1.6; }
        
        .btn { padding: 8px 24px; border-radius: 6px; border: 1px solid #d9d9d9; cursor: pointer; font-size: 14px; margin-right: 10px; transition: all 0.3s; display: inline-block; text-decoration: none;}
        .btn-primary { background: var(--primary-color); color: #fff; border-color: var(--primary-color); }
        .btn-primary:hover { background: #40a9ff; border-color: #40a9ff; }
        .btn-secondary { background: #fff; color: #595959; }
        .btn-secondary:hover { color: var(--primary-color); border-color: var(--primary-color); }
        
        .checkbox-wrapper { display: flex; align-items: center; gap: 8px; margin-top: 5px; cursor: pointer; user-select: none;}
        
        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-weight: 500; }
        .alert-error { background: #fff2f0; border: 1px solid #ffccc7; color: var(--danger-color); }
        
        /* 動態列表樣式 */
        .dynamic-list { margin-top: 10px; border: 1px solid var(--border-color); padding: 15px; border-radius: 6px; background: #fafafa; }
        .dynamic-item { display: flex; gap: 10px; margin-bottom: 10px; align-items: center; }
        .btn-sm { padding: 4px 10px; font-size: 12px; }
        .btn-danger { background: #fff; color: var(--danger-color); border-color: var(--danger-color); }
        .btn-danger:hover { background: var(--danger-color); color: white; }
        .btn-add { background: #fff; color: var(--success-color); border-color: var(--success-color); margin-top: 5px; }
        .btn-add:hover { background: var(--success-color); color: white; }
        .existing-item { padding: 8px; background: #fff; border: 1px solid #eee; margin-bottom: 5px; display: flex; justify-content: space-between; align-items: center; border-radius: 4px; }
        
        .required-asterisk { color: var(--danger-color); margin-right: 4px; }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include 'sidebar.php'; ?>
        <div class="main-content" id="mainContent">
            <?php include 'header.php'; ?>
            
            <div class="content">
                <div class="page-controls">
                    <div class="breadcrumb">
                        <a href="index.php">首頁</a> / <a href="bulletin_board.php">招生公告管理</a> / <?= $page_title ?>
                    </div>
                </div>

                <div class="card-wrapper">
                    <h2 style="margin-bottom: 24px; color: var(--text-color); border-bottom: 1px solid var(--border-color); padding-bottom: 15px; font-size: 20px;"><?= $page_title ?></h2>
                    
                    <?php if(isset($error)): ?>
                        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <form method="POST" enctype="multipart/form-data">
                        <div class="form-group">
                            <label class="form-label"><span class="required-asterisk">*</span>公告標題</label>
                            <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($bulletin['title']) ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label"><span class="required-asterisk">*</span>公告內容</label>
                            <textarea name="content" class="form-control" required><?= htmlspecialchars($bulletin['content']) ?></textarea>
                        </div>

                        <div class="form-group">
                            <label class="form-label">相關連結 (選填)</label>
                            <div class="dynamic-list">
                                <?php if(!empty($existing_urls)): ?>
                                    <div style="margin-bottom:10px; font-size:13px; color:#888;">現有連結 (勾選刪除)：</div>
                                    <?php foreach($existing_urls as $url): ?>
                                        <div class="existing-item">
                                            <span><i class="fas fa-link"></i> <?= htmlspecialchars($url['title'] ?: $url['url']) ?></span>
                                            <label><input type="checkbox" name="delete_urls[]" value="<?= $url['id'] ?>"> 刪除</label>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>

                                <div id="url-container"></div>
                                <button type="button" class="btn btn-sm btn-add" onclick="addUrlField()"><i class="fas fa-plus"></i> 新增連結欄位</button>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">相關附件 (選填)</label>
                            <div class="dynamic-list">
                                <?php if(!empty($existing_files)): ?>
                                    <div style="margin-bottom:10px; font-size:13px; color:#888;">現有檔案 (勾選刪除)：</div>
                                    <?php foreach($existing_files as $file): ?>
                                        <div class="existing-item">
                                            <span><i class="fas fa-file"></i> <?= htmlspecialchars($file['original_filename']) ?> (<?= round($file['file_size']/1024, 1) ?> KB)</span>
                                            <label><input type="checkbox" name="delete_files[]" value="<?= $file['id'] ?>"> 刪除</label>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>

                                <div style="margin-top: 10px;">
                                    <input type="file" name="new_files[]" multiple class="form-control" style="background: #fff;">
                                    <small style="color: #888; display:block; margin-top:5px;">按住 Ctrl 鍵可一次選擇多個檔案</small>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">設定選項</label>
                            <div style="display: flex; gap: 20px;">
                                <label class="checkbox-wrapper">
                                    <input type="checkbox" name="is_active" <?= ($bulletin['status_code'] === 'published') ? 'checked' : '' ?>>
                                    啟用顯示 (發布)
                                </label>
                                
                                <label class="checkbox-wrapper">
                                    <input type="checkbox" name="is_pinned" <?= $bulletin['is_pinned'] ? 'checked' : '' ?>>
                                    置頂公告
                                </label>
                            </div>
                        </div>

                        <div style="margin-top: 30px; border-top: 1px solid var(--border-color); padding-top: 20px; display: flex; justify-content: flex-end;">
                            <a href="bulletin_board.php" class="btn btn-secondary">取消</a>
                            <button type="submit" class="btn btn-primary">儲存</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        function addUrlField() {
            const container = document.getElementById('url-container');
            const div = document.createElement('div');
            div.className = 'dynamic-item';
            div.innerHTML = `
                <input type="text" name="new_url_titles[]" placeholder="連結標題 (選填)" class="form-control" style="width:30%">
                <input type="url" name="new_urls[]" placeholder="URL (http://...)" class="form-control" style="flex:1" required>
                <button type="button" class="btn btn-sm btn-danger" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
            `;
            container.appendChild(div);
        }
    </script>
</body>
</html>