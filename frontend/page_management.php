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
$page_title = '頁面管理';
$current_page = 'page_management';

// 建立資料庫連接
$conn = getDatabaseConnection();


$message = "";
$messageType = "";

// 處理文件上傳
function handleImageUpload($file, $existing_url = '') {
    $upload_dir = '../../Topics-frontend/frontend/uploads/carousel/';
    
    // 確保目錄存在
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    // 如果沒有上傳新文件，返回現有 URL
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return $existing_url;
    }
    
    // 檢查文件類型
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    $file_type = mime_content_type($file['tmp_name']);
    
    if (!in_array($file_type, $allowed_types)) {
        throw new Exception('不支援的圖片格式，請上傳 JPG、PNG、GIF 或 WebP 格式的圖片。');
    }
    
    // 檢查文件大小（限制為 5MB）
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new Exception('圖片大小不能超過 5MB。');
    }
    
    // 生成唯一檔名
    $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $new_filename = 'carousel_' . time() . '_' . uniqid() . '.' . $file_extension;
    $upload_path = $upload_dir . $new_filename;
    
    // 移動上傳的文件
    if (move_uploaded_file($file['tmp_name'], $upload_path)) {
        // 刪除舊的圖片（如果存在且不是外部 URL）
        if (!empty($existing_url) && strpos($existing_url, 'uploads/carousel/') !== false) {
            $old_file_path = '../../Topics-frontend/frontend/' . $existing_url;
            if (file_exists($old_file_path)) {
                @unlink($old_file_path);
            }
        }
        // 返回相對路徑（從 frontend 目錄開始）
        return 'uploads/carousel/' . $new_filename;
    } else {
        throw new Exception('圖片上傳失敗，請重試。');
    }
}

// 處理表單提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            // 新增輪播項目
            case 'add_carousel':
                $title = trim($_POST['title'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $button_text = trim($_POST['button_text'] ?? '');
                $button_link = trim($_POST['button_link'] ?? '');
                $display_order = intval($_POST['display_order'] ?? 0);
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                
                
                
                // 處理圖片上傳
                $image_url = '';
                if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                    $image_url = handleImageUpload($_FILES['image']);
                } elseif (!empty($_POST['image_url'])) {
                    // 使用外部 URL
                    $image_url = trim($_POST['image_url']);
                } else {
                    throw new Exception('請上傳圖片或輸入圖片 URL');
                }
                
                $sql = "INSERT INTO carousel_items (title, description, image_url, button_text, button_link, display_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sssssii", $title, $description, $image_url, $button_text, $button_link, $display_order, $is_active);
                
                if ($stmt->execute()) {
                    $message = "輪播項目新增成功！";
                    $messageType = "success";
                } else {
                    throw new Exception("新增失敗: " . $stmt->error);
                }
                break;

            // 更新輪播項目
            case 'update_carousel':
                $carousel_id = intval($_POST['carousel_id']);
                $title = trim($_POST['title'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $button_text = trim($_POST['button_text'] ?? '');
                $button_link = trim($_POST['button_link'] ?? '');
                $display_order = intval($_POST['display_order'] ?? 0);
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                
                
                // 獲取現有的圖片 URL
                $stmt_get = $conn->prepare("SELECT image_url FROM carousel_items WHERE id = ?");
                $stmt_get->bind_param("i", $carousel_id);
                $stmt_get->execute();
                $result = $stmt_get->get_result();
                $existing_item = $result->fetch_assoc();
                $existing_url = $existing_item['image_url'] ?? '';
                
                // 處理圖片上傳
                $image_url = $existing_url;
                if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                    $image_url = handleImageUpload($_FILES['image'], $existing_url);
                } elseif (!empty($_POST['image_url']) && $_POST['image_url'] !== $existing_url) {
                    // 使用外部 URL（如果改變了）
                    $image_url = trim($_POST['image_url']);
                }
                
                $sql = "UPDATE carousel_items SET title = ?, description = ?, image_url = ?, button_text = ?, button_link = ?, display_order = ?, is_active = ? WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sssssiii", $title, $description, $image_url, $button_text, $button_link, $display_order, $is_active, $carousel_id);
                
                if ($stmt->execute()) {
                    $message = "輪播項目更新成功！";
                    $messageType = "success";
                } else {
                    throw new Exception("更新失敗: " . $stmt->error);
                }
                break;

            // 刪除輪播項目
            case 'delete_carousel':
                $carousel_id = intval($_POST['carousel_id']);
                
                // 獲取圖片路徑以便刪除文件
                $stmt_get = $conn->prepare("SELECT image_url FROM carousel_items WHERE id = ?");
                $stmt_get->bind_param("i", $carousel_id);
                $stmt_get->execute();
                $result = $stmt_get->get_result();
                $item = $result->fetch_assoc();
                
                if ($item && strpos($item['image_url'], 'uploads/carousel/') !== false) {
                    $file_path = '../../Topics-frontend/frontend/' . $item['image_url'];
                    if (file_exists($file_path)) {
                        @unlink($file_path);
                    }
                }
                
                $sql = "DELETE FROM carousel_items WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $carousel_id);
                
                if ($stmt->execute()) {
                    $message = "輪播項目刪除成功！";
                    $messageType = "success";
                } else {
                    throw new Exception("刪除失敗: " . $stmt->error);
                }
                break;
        }
    } catch (Exception $e) {
        $message = "操作失敗：" . $e->getMessage();
        $messageType = "error";
    }
}

// 獲取所有輪播項目
$carousel_sql = "SELECT * FROM carousel_items ORDER BY display_order ASC, id DESC";
$carousel_list = $conn->query($carousel_sql)->fetch_all(MYSQLI_ASSOC);

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
        .breadcrumb a:hover { 
            text-decoration: underline; 
        }
        
        .table-wrapper {
            background: var(--card-background-color);
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
            display: flex;
            flex-direction: column;
        }

        .table-container { 
            overflow-x: auto;
            flex: 1;
        }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { 
            padding: 16px 24px; 
            text-align: left; 
            border-bottom: 1px solid var(--border-color); 
            font-size: 16px; 
        }
        .table th:first-child, .table td:first-child {
            padding-left: 60px;
        }
        .table th { 
            background: #fafafa; 
            font-weight: 600; 
            color: #262626; 
            cursor: pointer; 
            user-select: none; 
            position: relative; 
        }
        .table th:hover { 
            background: #f0f0f0; 
        }
        .table td { color: #595959; }
        .table tr:hover { background: #fafafa; }

        .btn { 
            padding: 8px 16px; 
            border: 1px solid #d9d9d9; 
            border-radius: 6px; 
            cursor: pointer; 
            font-size: 14px; 
            transition: all 0.3s; 
            background: #fff; 
            text-decoration: none; 
            display: inline-flex; 
            align-items: center; 
            justify-content: center; 
            gap: 6px; 
        }
        .btn-primary { 
            background: var(--primary-color); 
            color: white; 
            border-color: var(--primary-color); 
        }
        .btn-primary:hover { 
            background: #40a9ff; 
            border-color: #40a9ff; 
        }

        .action-buttons { display: flex; gap: 8px; }
        .btn-action {
            padding: 4px 12px; border-radius: 4px; font-size: 14px;
            text-decoration: none; display: inline-block; transition: all 0.3s;
            background: #fff;
        }
        .btn-edit { color: var(--success-color); border: 1px solid var(--success-color); }
        .btn-edit:hover { background: var(--success-color); color: white; }
        .btn-delete { color: var(--danger-color); border: 1px solid var(--danger-color); }
        .btn-delete:hover { background: var(--danger-color); color: white; }
        
        .form-group { margin-bottom: 20px; }
        .form-label { display: block; margin-bottom: 8px; font-weight: 500; }
        .form-control { 
            width: 100%; 
            padding: 10px 12px; 
            border: 1px solid #d9d9d9; 
            border-radius: 6px; 
            font-size: 14px; 
            transition: border-color 0.3s; 
        }
        .form-control:focus { 
            outline: none; 
            border-color: var(--primary-color); 
            box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.2); 
        }
        textarea.form-control {
            min-height: 120px;
            resize: vertical;
        }

        .message { 
            padding: 12px 16px; 
            border-radius: 6px; 
            margin-bottom: 16px; 
            font-weight: 500; 
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

        .status-badge { 
            padding: 4px 8px; 
            border-radius: 4px; 
            font-size: 12px; 
            font-weight: 500; 
        }
        .status-active { 
            background: #f6ffed; 
            color: var(--success-color); 
            border: 1px solid #b7eb8f; 
        }
        .status-inactive { 
            background: #fff2f0; 
            color: var(--danger-color); 
            border: 1px solid #ffccc7; 
        }

        .modal { 
            display: none; 
            position: fixed; 
            z-index: 2000; 
            left: 0; 
            top: 0; 
            width: 100%; 
            height: 100%; 
            background-color: rgba(0,0,0,0.45); 
        }
        .modal-content { 
            background-color: #fff; 
            margin: 5% auto; 
            padding: 0; 
            border-radius: 8px; 
            width: 90%; 
            max-width: 800px; 
            box-shadow: 0 4px 12px rgba(0,0,0,0.15); 
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-header { 
            padding: 16px 24px; 
            border-bottom: 1px solid var(--border-color); 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
        }
        .modal-title { font-size: 16px; font-weight: 600; }
        .close { 
            color: var(--text-secondary-color); 
            font-size: 20px; 
            font-weight: bold; 
            cursor: pointer; 
        }
        .close:hover { color: var(--text-color); }
        .modal-body { padding: 24px; }
        .modal-footer { 
            padding: 16px 24px; 
            border-top: 1px solid var(--border-color); 
            display: flex; 
            justify-content: flex-end; 
            gap: 8px; 
            background: #fafafa; 
        }
        .required-asterisk { color: var(--danger-color); margin-right: 4px; }
        
        .carousel-image-preview {
            max-width: 300px;
            max-height: 200px;
            margin-top: 10px;
            border-radius: 6px;
            border: 1px solid var(--border-color);
        }
        
        .image-upload-section {
            margin-bottom: 20px;
        }
        
        .image-url-input {
            margin-top: 10px;
        }
        
        .image-option-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
        }
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

                <div class="page-controls">
                    <div class="breadcrumb">
                        <a href="index.php">首頁</a> / <?php echo $page_title; ?>
                        <?php if (!empty($carousel_list)): ?>
                        <span style="margin-left: 16px; color: var(--text-secondary-color); font-size: 14px;">
                            (共 <?php echo count($carousel_list); ?> 筆資料)
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="table-search">
                        <button class="btn btn-primary" onclick="showModal('addCarouselModal')"><i class="fas fa-plus"></i> 新增輪播項目</button>
                    </div>
                </div>

                <div class="table-wrapper">
                    <div class="table-container">
                        <table class="table" id="carouselTable">
                            <thead>
                                <tr>
                                    <th>預覽</th>
                                    <th>標題</th>
                                    <th>描述</th>
                                    <th>按鈕</th>
                                    <th>順序</th>
                                    <th>狀態</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($carousel_list)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 40px; color: var(--text-secondary-color);">
                                        <i class="fas fa-inbox fa-3x" style="margin-bottom: 16px; display: block;"></i>
                                        目前尚無輪播項目，請點擊「新增輪播項目」來新增。
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($carousel_list as $item): ?>
                                <tr>
                                    <td>
                                        <img src="../../Topics-frontend/frontend/<?php echo htmlspecialchars($item['image_url']); ?>" 
                                             alt="<?php echo htmlspecialchars($item['title']); ?>"
                                             style="width: 120px; height: 80px; object-fit: cover; border-radius: 4px; border: 1px solid var(--border-color);"
                                             onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%27120%27 height=%2780%27%3E%3Crect fill=%27%23f0f0f0%27 width=%27120%27 height=%2780%27/%3E%3Ctext x=%2750%25%27 y=%2750%25%27 text-anchor=%27middle%27 fill=%27%23999%27%3E無圖片%3C/text%3E%3C/svg%3E'">
                                    </td>
                                    <td><?php echo htmlspecialchars($item['title']); ?></td>
                                    <td style="max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                        <?php echo htmlspecialchars($item['description'] ?? ''); ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($item['button_text'])): ?>
                                            <?php echo htmlspecialchars($item['button_text']); ?>
                                            <?php if (!empty($item['button_link'])): ?>
                                                <br><small style="color: var(--text-secondary-color);"><?php echo htmlspecialchars($item['button_link']); ?></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color: var(--text-secondary-color);">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $item['display_order']; ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $item['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                            <?php echo $item['is_active'] ? '啟用' : '停用'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn-action btn-edit" onclick='editCarousel(<?php echo json_encode($item, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>編輯</button>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('確定要刪除此輪播項目嗎？');">
                                                <input type="hidden" name="action" value="delete_carousel">
                                                <input type="hidden" name="carousel_id" value="<?php echo $item['id']; ?>">
                                                <button type="submit" class="btn-action btn-delete">刪除</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- 新增輪播項目 Modal -->
    <div id="addCarouselModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">新增輪播項目</h3>
                <span class="close" onclick="closeModal('addCarouselModal')">&times;</span>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_carousel">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label"><span class="required-asterisk">*</span>標題</label>
                        <input type="text" name="title" class="form-control" ">
                    </div>
                    <div class="form-group">
                        <label class="form-label">描述</label>
                        <textarea name="description" class="form-control" placeholder="請輸入輪播描述..."></textarea>
                    </div>
                    <div class="form-group image-upload-section">
                        <label class="form-label image-option-label"><span class="required-asterisk">*</span>圖片</label>
                        <input type="radio" name="image_option" value="upload" id="upload_option" checked onchange="toggleImageOption()">
                        <label for="upload_option" style="font-weight: normal; margin-left: 5px;">上傳圖片</label>
                        <input type="radio" name="image_option" value="url" id="url_option" style="margin-left: 20px;" onchange="toggleImageOption()">
                        <label for="url_option" style="font-weight: normal; margin-left: 5px;">使用 URL</label>
                        
                        <div id="upload_section">
                            <input type="file" name="image" accept="image/*" class="form-control" style="margin-top: 10px;" onchange="previewImage(this, 'add_preview')">
                            <img id="add_preview" class="carousel-image-preview" style="display: none;">
                        </div>
                        <div id="url_section" style="display: none;" class="image-url-input">
                            <input type="url" name="image_url" class="form-control" placeholder="https://example.com/image.jpg">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">按鈕文字</label>
                        <input type="text" name="button_text" class="form-control" placeholder="例如：了解更多">
                    </div>
                    <div class="form-group">
                        <label class="form-label">按鈕連結</label>
                        <input type="text" name="button_link" class="form-control" placeholder="例如：QA.php">
                    </div>
                    <div class="form-group">
                        <label class="form-label">顯示順序</label>
                        <input type="number" name="display_order" class="form-control" value="0" min="0">
                        <small style="color: var(--text-secondary-color);">數字越小越靠前</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">狀態</label>
                        <label style="font-weight: normal; cursor: pointer;">
                            <input type="checkbox" name="is_active" value="1" checked style="margin-right: 8px;">
                            啟用此輪播項目
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal('addCarouselModal')">取消</button>
                    <button type="submit" class="btn btn-primary">新增</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 編輯輪播項目 Modal -->
    <div id="editCarouselModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">編輯輪播項目</h3>
                <span class="close" onclick="closeModal('editCarouselModal')">&times;</span>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_carousel">
                <input type="hidden" name="carousel_id" id="edit_carousel_id">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">標題</label>
                        <input type="text" name="title" id="edit_title" class="form-control" >
                    </div>
                    <div class="form-group">
                        <label class="form-label">描述</label>
                        <textarea name="description" id="edit_description" class="form-control"></textarea>
                    </div>
                    <div class="form-group image-upload-section">
                        <label class="form-label image-option-label">圖片</label>
                        <input type="radio" name="image_option_edit" value="keep" id="keep_option_edit" checked onchange="toggleImageOptionEdit()">
                        <label for="keep_option_edit" style="font-weight: normal; margin-left: 5px;">保持現有</label>
                        <input type="radio" name="image_option_edit" value="upload" id="upload_option_edit" style="margin-left: 20px;" onchange="toggleImageOptionEdit()">
                        <label for="upload_option_edit" style="font-weight: normal; margin-left: 5px;">上傳新圖片</label>
                        <input type="radio" name="image_option_edit" value="url" id="url_option_edit" style="margin-left: 20px;" onchange="toggleImageOptionEdit()">
                        <label for="url_option_edit" style="font-weight: normal; margin-left: 5px;">使用 URL</label>
                        
                        <div id="current_image_edit" style="margin-top: 10px;">
                            <img id="current_image_preview" class="carousel-image-preview">
                            <input type="hidden" name="image_url" id="edit_image_url">
                        </div>
                        <div id="upload_section_edit" style="display: none;">
                            <input type="file" name="image" accept="image/*" class="form-control" style="margin-top: 10px;" onchange="previewImage(this, 'edit_preview')">
                            <img id="edit_preview" class="carousel-image-preview" style="display: none;">
                        </div>
                        <div id="url_section_edit" style="display: none;" class="image-url-input">
                            <input type="url" name="image_url" id="edit_image_url_input" class="form-control" placeholder="https://example.com/image.jpg">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">按鈕文字</label>
                        <input type="text" name="button_text" id="edit_button_text" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">按鈕連結</label>
                        <input type="text" name="button_link" id="edit_button_link" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">顯示順序</label>
                        <input type="number" name="display_order" id="edit_display_order" class="form-control" min="0">
                        <small style="color: var(--text-secondary-color);">數字越小越靠前</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">狀態</label>
                        <label style="font-weight: normal; cursor: pointer;">
                            <input type="checkbox" name="is_active" id="edit_is_active" value="1" style="margin-right: 8px;">
                            啟用此輪播項目
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal('editCarouselModal')">取消</button>
                    <button type="submit" class="btn btn-primary">儲存</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function showModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            // 重置表單
            if (modalId === 'addCarouselModal') {
                document.querySelector('#addCarouselModal form').reset();
                document.getElementById('add_preview').style.display = 'none';
                document.getElementById('upload_option').checked = true;
                toggleImageOption();
            } else if (modalId === 'editCarouselModal') {
                document.querySelector('#editCarouselModal form').reset();
                document.getElementById('keep_option_edit').checked = true;
                toggleImageOptionEdit();
            }
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
        
        function toggleImageOption() {
            const uploadOption = document.getElementById('upload_option').checked;
            const urlOption = document.getElementById('url_option').checked;
            
            document.getElementById('upload_section').style.display = uploadOption ? 'block' : 'none';
            document.getElementById('url_section').style.display = urlOption ? 'block' : 'none';
            
            // 清除預覽
            if (urlOption) {
                document.getElementById('add_preview').style.display = 'none';
            }
        }
        
        function toggleImageOptionEdit() {
            const keepOption = document.getElementById('keep_option_edit').checked;
            const uploadOption = document.getElementById('upload_option_edit').checked;
            const urlOption = document.getElementById('url_option_edit').checked;
            
            document.getElementById('current_image_edit').style.display = keepOption ? 'block' : 'none';
            document.getElementById('upload_section_edit').style.display = uploadOption ? 'block' : 'none';
            document.getElementById('url_section_edit').style.display = urlOption ? 'block' : 'none';
        }
        
        function previewImage(input, previewId) {
            const preview = document.getElementById(previewId);
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                };
                reader.readAsDataURL(input.files[0]);
            } else {
                preview.style.display = 'none';
            }
        }

        function editCarousel(item) {
            document.getElementById('edit_carousel_id').value = item.id;
            document.getElementById('edit_title').value = item.title || '';
            document.getElementById('edit_description').value = item.description || '';
            document.getElementById('edit_button_text').value = item.button_text || '';
            document.getElementById('edit_button_link').value = item.button_link || '';
            document.getElementById('edit_display_order').value = item.display_order || 0;
            document.getElementById('edit_is_active').checked = item.is_active == 1;
            document.getElementById('edit_image_url').value = item.image_url || '';
            
            // 顯示當前圖片
            const currentPreview = document.getElementById('current_image_preview');
            if (item.image_url) {
                currentPreview.src = '../../Topics-frontend/frontend/' + item.image_url;
                currentPreview.style.display = 'block';
            } else {
                currentPreview.style.display = 'none';
            }
            
            // 重置選項
            document.getElementById('keep_option_edit').checked = true;
            toggleImageOptionEdit();
            
            showModal('editCarouselModal');
        }
    </script>
</body>
</html>