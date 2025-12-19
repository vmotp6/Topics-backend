<?php
session_start();

// 檢查是否已登入
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: login.php");
    exit;
}

// 檢查角色權限 - 允許管理員與行政人員訪問
$user_role = $_SESSION['role'] ?? '';
$allowed_roles = ['ADM', 'STA', '管理員', '行政人員'];

if (!in_array($user_role, $allowed_roles)) {
    header("Location: index.php");
    exit;
}

// 引入資料庫設定
require_once '../../Topics-frontend/frontend/config.php';

// 建立資料庫連接
$conn = getDatabaseConnection();

$message = "";
$messageType = "";
$is_edit = false;
$video = null;

// 獲取視頻 ID
$video_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($video_id > 0) {
    // 編輯模式
    $is_edit = true;
    $stmt = $conn->prepare("SELECT * FROM videos WHERE id = ?");
    $stmt->bind_param("i", $video_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $video = $result->fetch_assoc();
    $stmt->close();
    
    if (!$video) {
        header("Location: video_management.php");
        exit;
    }
}

// === 設定上傳路徑 ===
$backend_frontend = dirname(__FILE__);
$backend_dir = dirname($backend_frontend);
$topics_root = dirname($backend_dir);
$frontend_root = $topics_root . DIRECTORY_SEPARATOR . 'Topics-frontend' . DIRECTORY_SEPARATOR . 'frontend';
$upload_dir = $frontend_root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'vid' . DIRECTORY_SEPARATOR . 'videos' . DIRECTORY_SEPARATOR;
$thumb_dir = $frontend_root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'vid' . DIRECTORY_SEPARATOR . 'thumbnails' . DIRECTORY_SEPARATOR;

// 網頁路徑：儲存在資料庫的路徑
$web_upload_path = 'uploads/vid/videos/';
$web_thumb_path = 'uploads/vid/thumbnails/';

// 確保上傳目錄存在
if (!is_dir($upload_dir)) { @mkdir($upload_dir, 0755, true); }
if (!is_dir($thumb_dir)) { @mkdir($thumb_dir, 0755, true); }

// 處理表單提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $category_id = intval($_POST['category_id'] ?? 0);
        $published = intval($_POST['published'] ?? 0);
        $duration = trim($_POST['duration'] ?? ''); // 接收前端自動計算的值
        
        if (empty($title)) throw new Exception('影片標題不能為空');
        if ($category_id <= 0) throw new Exception('請選擇影片分類');
        
        $video_url = $video['video_url'] ?? '';
        $thumbnail_url = $video['thumbnail_url'] ?? '';
        
        // 1. 處理影片文件上傳
        if (isset($_FILES['video_file']) && $_FILES['video_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['video_file'];
            $allowed_types = ['video/mp4', 'video/webm', 'video/ogg'];
            if (!in_array($file['type'], $allowed_types)) throw new Exception('只允許上傳 MP4, WebM, Ogg 格式的影片');
            if ($file['size'] > 500 * 1024 * 1024) throw new Exception('影片文件不能超過 500MB');
            
            $file_ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $file_name = 'video_' . time() . '_' . rand(1000, 9999) . '.' . $file_ext;
            $file_path = $upload_dir . $file_name;
            
            // 刪除舊文件
            if (!empty($video['video_url'])) {
                $old_file = $frontend_root . '/' . ltrim($video['video_url'], '/');
                if (file_exists($old_file)) @unlink($old_file);
            }
            
            if (!move_uploaded_file($file['tmp_name'], $file_path)) throw new Exception('影片上傳失敗，請檢查資料夾權限');
            
            $video_url = $web_upload_path . $file_name;
        }
        
        // 2. 處理縮圖 (優先順序: 手動上傳 > 自動截圖 > 舊縮圖)
        if (isset($_FILES['thumbnail_file']) && $_FILES['thumbnail_file']['error'] === UPLOAD_ERR_OK) {
            // A. 使用者手動上傳了縮圖
            $file = $_FILES['thumbnail_file'];
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!in_array($file['type'], $allowed_types)) throw new Exception('只允許上傳 JPG, PNG, GIF, WebP 格式的圖片');
            if ($file['size'] > 10 * 1024 * 1024) throw new Exception('縮圖文件不能超過 10MB');
            
            $file_ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $file_name = 'thumb_' . time() . '_' . rand(1000, 9999) . '.' . $file_ext;
            $file_path = $thumb_dir . $file_name;
            
            // 刪除舊文件
            if (!empty($video['thumbnail_url'])) {
                $old_file = $frontend_root . '/' . ltrim($video['thumbnail_url'], '/');
                if (file_exists($old_file)) @unlink($old_file);
            }
            
            if (!move_uploaded_file($file['tmp_name'], $file_path)) throw new Exception('縮圖上傳失敗');
            
            $thumbnail_url = $web_thumb_path . $file_name;

        } elseif (isset($_POST['auto_thumbnail']) && !empty($_POST['auto_thumbnail'])) {
            // B. 使用者沒上傳縮圖，但有前端自動截取的畫面 (Base64)
            $data_uri = $_POST['auto_thumbnail'];
            
            // 簡單驗證並分離 Base64 Header (例如: data:image/jpeg;base64,....)
            if (preg_match('/^data:image\/(\w+);base64,/', $data_uri, $matches)) {
                $image_type = strtolower($matches[1]); // jpg, png...
                $base64_data = substr($data_uri, strpos($data_uri, ',') + 1);
                $decoded_data = base64_decode($base64_data);
                
                if ($decoded_data !== false) {
                    $file_ext = ($image_type == 'jpeg') ? 'jpg' : $image_type;
                    $file_name = 'thumb_auto_' . time() . '_' . rand(1000, 9999) . '.' . $file_ext;
                    $file_path = $thumb_dir . $file_name;

                    // 刪除舊文件 (如果是換影片觸發的自動截圖，應刪除舊縮圖)
                    if (!empty($video['thumbnail_url'])) {
                        $old_file = $frontend_root . '/' . ltrim($video['thumbnail_url'], '/');
                        if (file_exists($old_file)) @unlink($old_file);
                    }

                    if (file_put_contents($file_path, $decoded_data)) {
                        $thumbnail_url = $web_thumb_path . $file_name;
                    }
                }
            }
        }
        
        // 驗證是否已有影片
        if (empty($video_url)) {
            throw new Exception('請上傳影片文件');
        }
        
        if ($is_edit) {
            // 更新影片
            $stmt = $conn->prepare("UPDATE videos SET title = ?, description = ?, category_id = ?, video_url = ?, thumbnail_url = ?, duration = ?, published = ? WHERE id = ?");
            $stmt->bind_param("ssisssii", $title, $description, $category_id, $video_url, $thumbnail_url, $duration, $published, $video_id);
            
            if ($stmt->execute()) {
                $message = "影片更新成功！";
                $messageType = "success";
                // 重新獲取資料
                $stmt2 = $conn->prepare("SELECT * FROM videos WHERE id = ?");
                $stmt2->bind_param("i", $video_id);
                $stmt2->execute();
                $video = $stmt2->get_result()->fetch_assoc();
                $stmt2->close();
            } else {
                throw new Exception($stmt->error);
            }
            $stmt->close();
        } else {
            // 新增影片
            $stmt = $conn->prepare("INSERT INTO videos (title, description, category_id, video_url, thumbnail_url, duration, published, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
            $stmt->bind_param("ssisssi", $title, $description, $category_id, $video_url, $thumbnail_url, $duration, $published);
            
            if ($stmt->execute()) {
                $message = "影片新增成功！";
                $messageType = "success";
                $video_id = $stmt->insert_id;
                $is_edit = true;
                $stmt2 = $conn->prepare("SELECT * FROM videos WHERE id = ?");
                $stmt2->bind_param("i", $video_id);
                $stmt2->execute();
                $video = $stmt2->get_result()->fetch_assoc();
                $stmt2->close();
            } else {
                throw new Exception($stmt->error);
            }
            $stmt->close();
        }
    } catch (Exception $e) {
        $message = "操作失敗：" . $e->getMessage();
        $messageType = "error";
    }
}

// 獲取分類
$categories = [];
$catSql = "SELECT id, name FROM video_categories ORDER BY name";
if ($result = $conn->query($catSql)) {
    while ($row = $result->fetch_assoc()) $categories[] = $row;
    $result->free();
}
$conn->close();

$page_title = $is_edit && $video ? '編輯影片' : '新增影片';
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
        .main-content { flex: 1; margin-left: 250px; background: #f0f2f5; transition: all 0.3s ease; }
        .main-content.expanded { margin-left: 60px; }
        .content { padding: 24px; max-width: 100%; }
        .page-controls { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        
        .message { padding: 12px 16px; border-radius: 4px; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
        .message.success { background: #f6ffed; color: #274e2b; border: 1px solid #b7eb8f; }
        .message.error { background: #fff1f0; color: #5c2c2a; border: 1px solid #ffccc7; }

        .form-wrapper { background: var(--card-background-color); border-radius: 4px; box-shadow: 0 1px 2px rgba(0,0,0,0.03); border: 1px solid var(--border-color); padding: 24px; max-width: 900px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 8px; color: var(--text-color); }
        .form-group .required { color: var(--danger-color); }
        .form-group input[type="text"], .form-group textarea, .form-group select { width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: 4px; font-size: 14px; transition: border-color 0.3s; }
        .form-group input[readonly] { background-color: #fafafa; color: #888; cursor: not-allowed; }
        .form-group textarea { resize: vertical; min-height: 100px; }
        .form-group input:focus:not([readonly]), .form-group textarea:focus, .form-group select:focus { outline: none; border-color: var(--primary-color); box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.1); }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .help-text { font-size: 12px; color: var(--text-secondary-color); margin-top: 4px; }

        .upload-area { border: 2px dashed var(--border-color); border-radius: 4px; padding: 24px; text-align: center; cursor: pointer; transition: all 0.3s; background: #fafafa; }
        .upload-area:hover { border-color: var(--primary-color); background: #f5f7ff; }
        .upload-area.dragging { border-color: var(--primary-color); background: #f5f7ff; }
        .upload-area i { font-size: 32px; color: var(--primary-color); margin-bottom: 8px; }
        .file-input { display: none; }
        .preview-container { margin-top: 12px; }
        .preview-image { max-width: 100%; max-height: 200px; border-radius: 4px; }
        .preview-video { max-width: 100%; max-height: 300px; border-radius: 4px; }

        .checkbox-group { display: flex; align-items: center; gap: 8px; }
        .checkbox-group input[type="checkbox"] { width: 16px; height: 16px; cursor: pointer; }
        .form-actions { display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px; padding-top: 24px; border-top: 1px solid var(--border-color); }
        .btn { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: all 0.3s; }
        .btn-primary { background: var(--primary-color); color: white; }
        .btn-primary:hover { background: #1677e8; }
        .btn-secondary { background: #fafafa; color: var(--text-color); border: 1px solid var(--border-color); }
        .btn-secondary:hover { background: #f5f5f5; }
        .section-title { font-size: 16px; font-weight: 600; color: var(--text-color); margin-bottom: 16px; padding-bottom: 12px; border-bottom: 2px solid var(--border-color); }
        .section { margin-bottom: 24px; }

        @media (max-width: 768px) {
            .main-content { margin-left: 0; }
            .form-row { grid-template-columns: 1fr; }
            .form-actions { flex-direction: column; }
            .form-wrapper { padding: 16px; }
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <?php include 'sidebar.php'; ?>
        <div class="main-content" id="mainContent">
            <?php include 'header.php'; ?>
            <div class="content">
                <div class="page-controls">
                     <a href="video_management.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> 返回列表</a>
                </div>

                <?php if (!empty($message)): ?>
                    <div class="message <?php echo $messageType; ?>">
                        <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" class="form-wrapper">
                    <input type="hidden" name="auto_thumbnail" id="auto_thumbnail">

                    <div class="section">
                        <div class="section-title">基本信息</div>
                        <div class="form-group">
                            <label for="title">影片標題 <span class="required">*</span></label>
                            <input type="text" id="title" name="title" required value="<?php echo htmlspecialchars($video['title'] ?? ''); ?>" placeholder="輸入影片標題">
                        </div>
                        <div class="form-group">
                            <label for="description">影片描述</label>
                            <textarea id="description" name="description" placeholder="輸入影片描述（可選）"><?php echo htmlspecialchars($video['description'] ?? ''); ?></textarea>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="category_id">分類 <span class="required">*</span></label>
                                <select id="category_id" name="category_id" required>
                                    <option value="">請選擇分類</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo $cat['id']; ?>" <?php echo isset($video['category_id']) && $video['category_id'] == $cat['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="duration">時長 (自動讀取)</label>
                                <input type="text" id="duration" name="duration" value="<?php echo htmlspecialchars($video['duration'] ?? ''); ?>" readonly placeholder="上傳影片後自動計算">
                                <div class="help-text">系統將於選取檔案時自動讀取影片長度</div>
                            </div>
                        </div>
                    </div>

                    <div class="section">
                        <div class="section-title">影片文件</div>
                        <div class="form-group">
                            <label for="video_file">上傳影片 <?php echo $is_edit ? '' : '<span class="required">*</span>'; ?></label>
                            <div class="upload-area" id="videoUploadArea">
                                <i class="fas fa-cloud-upload-alt"></i>
                                <p>點擊或拖放影片文件到此區域</p>
                                <p style="font-size: 12px; color: var(--text-secondary-color); margin-top: 8px;">支持格式：MP4, WebM, Ogg | 最大 500MB</p>
                            </div>
                            <input type="file" id="video_file" name="video_file" class="file-input" accept="video/*" <?php echo $is_edit ? '' : 'required'; ?>>
                            <?php if (isset($video['video_url']) && !empty($video['video_url'])): ?>
                                <div class="help-text" style="margin-top: 12px;">
                                    ✓ 已上傳：<a href="../../Topics-frontend/frontend/<?php echo htmlspecialchars($video['video_url']); ?>" target="_blank"><?php echo basename($video['video_url']); ?></a>
                                </div>
                            <?php endif; ?>
                            <div id="videoPreview" class="preview-container"></div>
                        </div>
                    </div>

                    <div class="section">
                        <div class="section-title">縮圖 (選填)</div>
                        <div class="form-group">
                            <label for="thumbnail_file">上傳縮圖 (若未上傳，系統將自動截取影片畫面)</label>
                            <div class="upload-area" id="thumbnailUploadArea">
                                <i class="fas fa-cloud-upload-alt"></i>
                                <p>點擊或拖放圖片文件到此區域</p>
                                <p style="font-size: 12px; color: var(--text-secondary-color); margin-top: 8px;">支持格式：JPG, PNG, GIF, WebP | 最大 10MB</p>
                            </div>
                            <input type="file" id="thumbnail_file" name="thumbnail_file" class="file-input" accept="image/*">
                            <?php if (isset($video['thumbnail_url']) && !empty($video['thumbnail_url'])): ?>
                                <div class="preview-container">
                                    <div style="margin-bottom:4px;font-size:12px;color:#666">目前縮圖：</div>
                                    <img src="../../Topics-frontend/frontend/<?php echo htmlspecialchars($video['thumbnail_url']); ?>" alt="縮圖預覽" class="preview-image">
                                </div>
                            <?php endif; ?>
                            <div id="thumbnailPreview" class="preview-container"></div>
                        </div>
                    </div>

                    <div class="section">
                        <div class="section-title">發布設置</div>
                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="published" name="published" value="1" <?php echo isset($video['published']) && $video['published'] == 1 ? 'checked' : ''; ?>>
                                <label for="published">發布此影片（取消勾選為草稿）</label>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <a href="video_management.php" class="btn btn-secondary">取消</a>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?php echo $is_edit && $video ? '更新影片' : '新增影片'; ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // 格式化秒數為 HH:MM:SS
        function formatDuration(seconds) {
            const h = Math.floor(seconds / 3600);
            const m = Math.floor((seconds % 3600) / 60);
            const s = Math.floor(seconds % 60);
            return [h, m, s].map(v => v < 10 ? '0' + v : v).join(':');
        }

        const videoUploadArea = document.getElementById('videoUploadArea');
        const videoInput = document.getElementById('video_file');
        const videoPreview = document.getElementById('videoPreview');
        const durationInput = document.getElementById('duration');
        const autoThumbnailInput = document.getElementById('auto_thumbnail');

        setupUploadArea(videoUploadArea, videoInput, videoPreview, 'video');

        const thumbnailUploadArea = document.getElementById('thumbnailUploadArea');
        const thumbnailInput = document.getElementById('thumbnail_file');
        const thumbnailPreview = document.getElementById('thumbnailPreview');

        setupUploadArea(thumbnailUploadArea, thumbnailInput, thumbnailPreview, 'image');

        function setupUploadArea(uploadArea, fileInput, preview, type) {
            uploadArea.addEventListener('click', () => fileInput.click());

            uploadArea.addEventListener('dragover', (e) => {
                e.preventDefault();
                uploadArea.classList.add('dragging');
            });

            uploadArea.addEventListener('dragleave', () => {
                uploadArea.classList.remove('dragging');
            });

            uploadArea.addEventListener('drop', (e) => {
                e.preventDefault();
                uploadArea.classList.remove('dragging');
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    fileInput.files = files;
                    handleFileSelect(fileInput, preview, type);
                }
            });

            fileInput.addEventListener('change', () => {
                handleFileSelect(fileInput, preview, type);
            });
        }

        function handleFileSelect(input, preview, type) {
            const file = input.files[0];
            if (!file) return;

            preview.innerHTML = '';

            if (type === 'image') {
                const reader = new FileReader();
                reader.onload = (e) => {
                    const img = document.createElement('img');
                    img.src = e.target.result;
                    img.className = 'preview-image';
                    preview.appendChild(img);
                };
                reader.readAsDataURL(file);
            } else if (type === 'video') {
                const objectUrl = URL.createObjectURL(file);
                
                // 1. 隱藏的 video 元素，用於計算時長和截圖
                const tempVideo = document.createElement('video');
                tempVideo.preload = 'auto'; // 確保載入數據
                tempVideo.src = objectUrl;
                tempVideo.muted = true;
                
                // 元數據載入後：獲取時長，並設定時間點以觸發 seeked
                tempVideo.onloadedmetadata = function() {
                    const duration = tempVideo.duration;
                    if (isFinite(duration)) {
                        durationInput.value = formatDuration(duration);
                    }
                    // 跳到 0.5 秒或影片的一半位置（如果影片很短），避免截到黑畫面
                    tempVideo.currentTime = Math.min(0.5, duration > 0 ? duration / 2 : 0);
                };
                
                // Seek 完成後：截圖
                tempVideo.onseeked = function() {
                    const canvas = document.createElement('canvas');
                    canvas.width = tempVideo.videoWidth;
                    canvas.height = tempVideo.videoHeight;
                    const ctx = canvas.getContext('2d');
                    ctx.drawImage(tempVideo, 0, 0, canvas.width, canvas.height);
                    
                    // 轉為 JPEG Base64
                    const dataURL = canvas.toDataURL('image/jpeg', 0.85);
                    
                    // 填入隱藏欄位
                    autoThumbnailInput.value = dataURL;
                    
                    // 只有當使用者「沒有」選擇手動縮圖時，才在預覽區顯示自動截圖
                    if (!thumbnailInput.files.length) {
                        thumbnailPreview.innerHTML = '<div style="margin-bottom:4px;font-size:12px;color:#666">（預覽）系統自動擷取的畫面：</div>';
                        const img = document.createElement('img');
                        img.src = dataURL;
                        img.className = 'preview-image';
                        thumbnailPreview.appendChild(img);
                    }
                };

                // 2. 顯示給使用者看的預覽影片控件
                const video = document.createElement('video');
                video.src = objectUrl; 
                video.className = 'preview-video';
                video.controls = true;
                preview.appendChild(video);
            }
        }
    </script>
</body>
</html>