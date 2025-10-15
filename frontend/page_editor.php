<?php
// 引入統一的 session 設定檔
require_once __DIR__ . '/../../Topics-frontend/frontend/session_config.php';

// 檢查是否已登入，如果沒有登入則跳轉到登入頁面
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: login.php");
    exit;
}

// 獲取要編輯的頁面
$page = $_GET['page'] ?? 'homepage';

// 定義頁面配置
$page_configs = [
    'homepage' => [
        'name' => '首頁',
        'icon' => 'fas fa-home',
        'color' => '#1890ff',
        'type' => 'backend'
    ],
    'ai_chat' => [
        'name' => 'AI聊天',
        'icon' => 'fas fa-robot',
        'color' => '#722ed1',
        'type' => 'frontend',
        'file_path' => '../../Topics-frontend/frontend/AI.php'
    ],
    'teacher_profile' => [
        'name' => '教師檔案',
        'icon' => 'fas fa-user-tie',
        'color' => '#fa8c16',
        'type' => 'frontend',
        'file_path' => '../../Topics-frontend/frontend/teacher_profile.php'
    ],
    'cooperation' => [
        'name' => '產學合作',
        'icon' => 'fas fa-handshake',
        'color' => '#13c2c2',
        'type' => 'frontend',
        'file_path' => '../../Topics-frontend/frontend/cooperation_upload.php'
    ],
    'qa' => [
        'name' => 'qa',
        'icon' => 'fas fa-handshake',
        'color' => '#13c2c2',
        'type' => 'frontend',
        'file_path' => '../../Topics-frontend/frontend/qa.php'
    ]
];

$current_config = $page_configs[$page] ?? $page_configs['homepage'];
$page_title = $current_config['name'] . ' - 頁面編輯';
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Topics 後台管理系統</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f0f2f5;
            color: #262626;
            overflow-x: hidden;
        }
        
        /* 主介面樣式 */
        .dashboard {
            display: flex;
            min-height: 100vh;
        }
        
        /* 內容區域 */
        .content {
            padding: 24px;
        }
        
        /* 麵包屑 */
        .breadcrumb {
            margin-bottom: 16px;
            font-size: 16px;
            color: #8c8c8c;
        }
        
        .breadcrumb a {
            color: #1890ff;
            text-decoration: none;
        }
        
        .breadcrumb a:hover {
            text-decoration: underline;
        }
        
        /* 標籤頁 */
        .tabs {
            display: flex;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            border: 1px solid #f0f0f0;
            margin-bottom: 24px;
            overflow: hidden;
        }
        
        .tab {
            flex: 1;
            padding: 16px 24px;
            background: #fff;
            border: none;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            color: #8c8c8c;
            transition: all 0.3s;
            border-bottom: 3px solid transparent;
        }
        
        .tab.active {
            color: #1890ff;
            background: #f0f8ff;
            border-bottom-color: #1890ff;
        }
        
        .tab:hover {
            background: #f5f5f5;
        }
        
        /* 標籤內容 */
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        /* 表格區域 */
        .table-container {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            overflow: hidden;
            border: 1px solid #f0f0f0;
        }
        
        .table-header {
            padding: 16px 24px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #fafafa;
        }
        
        .table-title {
            font-size: 18px;
            font-weight: 600;
            color: #262626;
        }
        
        /* 卡片樣式 */
        .card {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            border: 1px solid #f0f0f0;
            margin-bottom: 24px;
        }
        
        .card-header {
            padding: 16px 24px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #fafafa;
        }
        
        .card-header h3 {
            font-size: 18px;
            font-weight: 600;
            color: #262626;
        }
        
        .card-body {
            padding: 24px;
        }
        
        /* 按鈕樣式 */
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
            gap: 6px;
        }
        
        .btn-primary {
            background: #1890ff;
            color: white;
            border-color: #1890ff;
        }
        
        .btn-primary:hover {
            background: #40a9ff;
            border-color: #40a9ff;
            color: white;
        }
        
        .btn-secondary {
            background: #fff;
            color: #595959;
            border-color: #d9d9d9;
        }
        
        .btn-secondary:hover {
            background: #f5f5f5;
            border-color: #40a9ff;
            color: #40a9ff;
        }
        
        /* 表單樣式 */
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #262626;
        }
        
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
            border-color: #1890ff;
            box-shadow: 0 0 0 2px rgba(24, 144, 255, 0.2);
        }
        
        .form-row {
            display: flex;
            gap: 16px;
        }
        
        .form-row .form-group {
            flex: 1;
        }
        
        /* 訊息樣式 */
        .message {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 16px;
            font-weight: 500;
        }

        .message.success {
            background: #f6ffed;
            border: 1px solid #b7eb8f;
            color: #52c41a;
        }

        .message.error {
            background: #fff2f0;
            border: 1px solid #ffccc7;
            color: #ff4d4f;
        }

        .loading {
            text-align: center;
            padding: 40px;
            color: #8c8c8c;
            font-size: 16px;
        }
        
        /* 模態對話框 */
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
            max-width: 520px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .modal-header {
            padding: 16px 24px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #fff;
            border-radius: 8px 8px 0 0;
        }
        
        .modal-title {
            font-size: 16px;
            font-weight: 600;
            color: #262626;
        }
        
        .close {
            color: #8c8c8c;
            font-size: 20px;
            font-weight: bold;
            cursor: pointer;
            padding: 4px;
            border-radius: 4px;
            transition: all 0.3s;
        }
        
        .close:hover {
            color: #262626;
            background: #f5f5f5;
        }
        
        .modal-body {
            padding: 24px;
        }
        
        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid #f0f0f0;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            background: #fafafa;
            border-radius: 0 0 8px 8px;
        }
        
        .btn-danger {
            background: #ff4d4f;
            color: white;
            border-color: #ff4d4f;
        }
        
        .btn-danger:hover {
            background: #ff7875;
            border-color: #ff7875;
            color: white;
        }
        
        /* 響應式設計 */
        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
            }
            
            .tabs {
                flex-direction: column;
            }
            
            .modal-content {
                width: 95%;
                margin: 20px;
            }
        }
    </style>
</head>
<body>
    <!-- 主介面 -->
    <div class="dashboard">
        <!-- 引入側邊欄 -->
        <?php include 'sidebar.php'; ?>
        
        <!-- 主內容區 -->
        <div class="main-content" id="mainContent">
            <!-- 引入標題欄 -->
            <?php include 'header.php'; ?>
            
            <!-- 內容區域 -->
            <div class="content">
                <div id="messageContainer"></div>
                
                <!-- 麵包屑 -->
                <div class="breadcrumb">
                    <a href="page_management.php">頁面管理</a> / <?php echo $current_config['name']; ?>
                </div>
                
                <?php if ($page === 'homepage'): ?>
                <!-- 首頁編輯器 -->
                <div class="tabs">
                    <button class="tab active" onclick="switchTab('carousel-items')">
                        <i class="fas fa-images"></i>
                        輪播項目
                    </button>
                    <button class="tab" onclick="switchTab('carousel-settings')">
                        <i class="fas fa-cog"></i>
                        輪播設定
                    </button>
                </div>
                
                <!-- 輪播項目管理 -->
                <div id="carousel-items" class="tab-content active">
                    <div class="table-container">
                        <div class="table-header">
                            <div class="table-title">輪播項目管理</div>
                            <button class="btn btn-primary" onclick="showAddModal()">
                                <i class="fas fa-plus"></i>
                                新增輪播項目
                            </button>
                        </div>
                        <div id="carouselList" class="carousel-list" style="padding: 24px;">
                            <div class="loading">載入中...</div>
                        </div>
                    </div>
                </div>
                
                <!-- 輪播設定 -->
                <div id="carousel-settings" class="tab-content">
                    <div class="table-container">
                        <div class="table-header">
                            <div class="table-title">輪播設定</div>
                        </div>
                        <div style="padding: 24px;">
                            <form id="settingsForm" class="settings-form">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label">自動輪播間隔 (毫秒)</label>
                                        <input type="number" class="form-control" id="autoSlideInterval" min="1000" max="30000" step="1000">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">啟用自動輪播</label>
                                        <select class="form-control" id="enableAutoSlide">
                                            <option value="1">啟用</option>
                                            <option value="0">停用</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label">顯示控制按鈕</label>
                                        <select class="form-control" id="enableControls">
                                            <option value="1">顯示</option>
                                            <option value="0">隱藏</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">顯示指示點</label>
                                        <select class="form-control" id="enableIndicators">
                                            <option value="1">顯示</option>
                                            <option value="0">隱藏</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i>
                                    儲存設定
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php elseif ($current_config['type'] === 'frontend'): ?>
                <!-- 前台頁面編輯器 -->
                <div class="tabs">
                    <button class="tab active" onclick="switchTab('content-editor')">
                        <i class="fas fa-edit"></i>
                        內容編輯
                    </button>
                    <button class="tab" onclick="switchTab('preview')">
                        <i class="fas fa-eye"></i>
                        預覽
                    </button>
                </div>
                
                <!-- 內容編輯器 -->
                <div id="content-editor" class="tab-content active">
                    <div class="table-container">
                        <div class="table-header">
                            <div class="table-title"><?php echo $current_config['name']; ?> 內容編輯</div>
                            <div style="display: flex; gap: 12px;">
                                <button class="btn btn-secondary" onclick="loadFile()">
                                    <i class="fas fa-sync"></i>
                                    重新載入
                                </button>
                                <button class="btn btn-primary" onclick="saveFile()">
                                    <i class="fas fa-save"></i>
                                    儲存檔案
                                </button>
                            </div>
                        </div>
                        <div style="padding: 24px;">
                            <div class="form-group">
                                <label class="form-label">檔案路徑</label>
                                <input type="text" class="form-control" id="filePath" value="<?php echo $current_config['file_path']; ?>" readonly>
                            </div>
                            <div class="form-group">
                                <label class="form-label">檔案內容</label>
                                <textarea id="fileContent" class="form-control" rows="20" style="font-family: 'Courier New', monospace; font-size: 14px;"></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- 預覽 -->
                <div id="preview" class="tab-content">
                    <div class="table-container">
                        <div class="table-header">
                            <div class="table-title"><?php echo $current_config['name']; ?> 預覽</div>
                            <button class="btn btn-secondary" onclick="refreshPreview()">
                                <i class="fas fa-refresh"></i>
                                重新整理預覽
                            </button>
                        </div>
                        <div style="padding: 24px;">
                            <iframe id="previewFrame" src="<?php echo $current_config['file_path']; ?>" style="width: 100%; height: 600px; border: 1px solid #ddd; border-radius: 4px;"></iframe>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <!-- 其他頁面編輯器 -->
                <div class="table-container">
                    <div class="table-header">
                        <div class="table-title"><?php echo $current_config['name']; ?> 內容管理</div>
                    </div>
                    <div style="padding: 24px;">
                        <div class="loading">
                            此頁面的編輯功能正在開發中...
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- 編輯輪播項目模態框 -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <span class="modal-title">編輯輪播項目</span>
                <span class="close" onclick="closeEditModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form id="editForm">
                    <input type="hidden" id="editId">
                    <div class="form-group">
                        <label class="form-label">標題 *</label>
                        <input type="text" class="form-control" id="editTitle" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">描述</label>
                        <textarea class="form-control" id="editDescription" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">圖片URL *</label>
                        <input type="url" class="form-control" id="editImageUrl" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">按鈕文字</label>
                            <input type="text" class="form-control" id="editButtonText">
                        </div>
                        <div class="form-group">
                            <label class="form-label">按鈕連結</label>
                            <input type="url" class="form-control" id="editButtonLink">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">顯示順序</label>
                            <input type="number" class="form-control" id="editDisplayOrder" min="0">
                        </div>
                        <div class="form-group">
                            <label class="form-label">狀態</label>
                            <select class="form-control" id="editIsActive">
                                <option value="1">啟用</option>
                                <option value="0">停用</option>
                            </select>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()">取消</button>
                <button type="button" class="btn btn-primary" onclick="saveEdit()">儲存</button>
            </div>
        </div>
    </div>
    
    <!-- 新增輪播項目模態框 -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <span class="modal-title">新增輪播項目</span>
                <span class="close" onclick="closeAddModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form id="addForm">
                    <div class="form-group">
                        <label class="form-label">標題 *</label>
                        <input type="text" class="form-control" id="addTitle" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">描述</label>
                        <textarea class="form-control" id="addDescription" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">圖片URL *</label>
                        <input type="url" class="form-control" id="addImageUrl" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">按鈕文字</label>
                            <input type="text" class="form-control" id="addButtonText">
                        </div>
                        <div class="form-group">
                            <label class="form-label">按鈕連結</label>
                            <input type="url" class="form-control" id="addButtonLink">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">顯示順序</label>
                            <input type="number" class="form-control" id="addDisplayOrder" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label class="form-label">狀態</label>
                            <select class="form-control" id="addIsActive">
                                <option value="1">啟用</option>
                                <option value="0">停用</option>
                            </select>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAddModal()">取消</button>
                <button type="button" class="btn btn-primary" onclick="saveAdd()">新增</button>
            </div>
        </div>
    </div>
    
    <script>
    const API_BASE_URL = 'http://100.79.58.120:5001';
    
    // 標籤頁切換
    function switchTab(tabName) {
        // 隱藏所有標籤內容
        document.querySelectorAll('.tab-content').forEach(content => {
            content.classList.remove('active');
        });
        
        // 移除所有標籤的active狀態
        document.querySelectorAll('.tab').forEach(tab => {
            tab.classList.remove('active');
        });
        
        // 顯示選中的標籤內容
        document.getElementById(tabName).classList.add('active');
        
        // 設置選中的標籤為active
        event.target.classList.add('active');
        
        // 載入對應的資料
        if (tabName === 'carousel-items') {
            loadCarouselItems();
        } else if (tabName === 'carousel-settings') {
            loadCarouselSettings();
        }
    }
    
    // 載入輪播項目
    async function loadCarouselItems() {
        try {
            const response = await fetch(`${API_BASE_URL}/admin/carousel`);
            const data = await response.json();
            
            if (response.ok) {
                displayCarouselItems(data.carousel_items);
            } else {
                showMessage('載入輪播項目失敗', 'error');
            }
        } catch (error) {
            console.error('Error loading carousel items:', error);
            showMessage('載入輪播項目失敗', 'error');
        }
    }
    
    // 顯示輪播項目列表
    function displayCarouselItems(items) {
        const container = document.getElementById('carouselList');
        
        if (!container) return;
        
        if (items.length === 0) {
            container.innerHTML = '<div class="loading">暫無輪播項目</div>';
            return;
        }
        
        container.innerHTML = items.map(item => `
            <div class="carousel-item" style="display: flex; align-items: center; gap: 16px; padding: 16px; border: 1px solid #f0f0f0; border-radius: 8px; margin-bottom: 12px;">
                <div class="carousel-preview" style="width: 120px; height: 80px; border-radius: 6px; background-size: cover; background-position: center; background-image: url('${item.image_url}');"></div>
                <div class="carousel-info" style="flex: 1;">
                    <h4 style="font-size: 16px; color: #262626; margin-bottom: 4px;">${item.title}</h4>
                    <div style="display: flex; gap: 16px; font-size: 12px; color: #8c8c8c;">
                        <span>順序: ${item.display_order}</span>
                        <span>狀態: <span style="padding: 2px 6px; border-radius: 4px; font-size: 11px; ${item.is_active ? 'background: #f6ffed; color: #52c41a; border: 1px solid #b7eb8f;' : 'background: #fff2f0; color: #ff4d4f; border: 1px solid #ffccc7;'}">${item.is_active ? '啟用' : '停用'}</span></span>
                    </div>
                </div>
                <div style="display: flex; gap: 8px;">
                    <button class="btn btn-secondary" onclick="editCarouselItem(${item.id})">
                        <i class="fas fa-edit"></i>
                        編輯
                    </button>
                    <button class="btn btn-danger" onclick="deleteCarouselItem(${item.id})">
                        <i class="fas fa-trash"></i>
                        刪除
                    </button>
                </div>
            </div>
        `).join('');
    }
    
    // 載入輪播設定
    async function loadCarouselSettings() {
        try {
            const response = await fetch(`${API_BASE_URL}/admin/carousel/settings`);
            const data = await response.json();
            
            if (response.ok) {
                const settings = data.settings;
                document.getElementById('autoSlideInterval').value = settings.auto_slide_interval || 5000;
                document.getElementById('enableAutoSlide').value = settings.enable_auto_slide || 1;
                document.getElementById('enableControls').value = settings.enable_controls || 1;
                document.getElementById('enableIndicators').value = settings.enable_indicators || 1;
            } else {
                showMessage('載入輪播設定失敗', 'error');
            }
        } catch (error) {
            console.error('Error loading carousel settings:', error);
            showMessage('載入輪播設定失敗', 'error');
        }
    }
    
    // 編輯輪播項目
    function editCarouselItem(id) {
        // 先獲取項目資料
        fetch(`${API_BASE_URL}/admin/carousel`)
            .then(response => response.json())
            .then(data => {
                if (data.carousel_items) {
                    const item = data.carousel_items.find(item => item.id == id);
                    if (item) {
                        // 填充編輯表單
                        document.getElementById('editId').value = item.id;
                        document.getElementById('editTitle').value = item.title || '';
                        document.getElementById('editDescription').value = item.description || '';
                        document.getElementById('editImageUrl').value = item.image_url || '';
                        document.getElementById('editButtonText').value = item.button_text || '';
                        document.getElementById('editButtonLink').value = item.button_link || '';
                        document.getElementById('editDisplayOrder').value = item.display_order || 0;
                        document.getElementById('editIsActive').value = item.is_active || 1;
                        
                        // 顯示編輯模態框
                        document.getElementById('editModal').style.display = 'block';
                    }
                }
            })
            .catch(error => {
                console.error('Error loading carousel item:', error);
                showMessage('載入輪播項目失敗', 'error');
            });
    }
    
    // 刪除輪播項目
    async function deleteCarouselItem(id) {
        if (!confirm('確定要刪除這個輪播項目嗎？')) {
            return;
        }
        
        try {
            const response = await fetch(`${API_BASE_URL}/admin/carousel/${id}`, {
                method: 'DELETE'
            });
            
            const result = await response.json();
            
            if (response.ok) {
                showMessage(result.message, 'success');
                loadCarouselItems(); // 重新載入列表
            } else {
                showMessage(result.message, 'error');
            }
        } catch (error) {
            console.error('Error deleting carousel item:', error);
            showMessage('刪除失敗', 'error');
        }
    }
    
    // 顯示新增模態框
    function showAddModal() {
        // 清空表單
        document.getElementById('addForm').reset();
        document.getElementById('addDisplayOrder').value = 0;
        document.getElementById('addIsActive').value = 1;
        
        // 顯示新增模態框
        document.getElementById('addModal').style.display = 'block';
    }
    
    // 關閉編輯模態框
    function closeEditModal() {
        document.getElementById('editModal').style.display = 'none';
    }
    
    // 關閉新增模態框
    function closeAddModal() {
        document.getElementById('addModal').style.display = 'none';
    }
    
    // 儲存編輯
    async function saveEdit() {
        const id = document.getElementById('editId').value;
        const data = {
            title: document.getElementById('editTitle').value,
            description: document.getElementById('editDescription').value,
            image_url: document.getElementById('editImageUrl').value,
            button_text: document.getElementById('editButtonText').value,
            button_link: document.getElementById('editButtonLink').value,
            display_order: parseInt(document.getElementById('editDisplayOrder').value),
            is_active: parseInt(document.getElementById('editIsActive').value)
        };
        
        if (!data.title || !data.image_url) {
            showMessage('標題和圖片URL為必填項目', 'error');
            return;
        }
        
        try {
            const response = await fetch(`${API_BASE_URL}/admin/carousel/${id}`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(data)
            });
            
            const result = await response.json();
            
            if (response.ok) {
                showMessage(result.message, 'success');
                closeEditModal();
                loadCarouselItems(); // 重新載入列表
            } else {
                showMessage(result.message, 'error');
            }
        } catch (error) {
            console.error('Error updating carousel item:', error);
            showMessage('更新失敗', 'error');
        }
    }
    
    // 儲存新增
    async function saveAdd() {
        const data = {
            title: document.getElementById('addTitle').value,
            description: document.getElementById('addDescription').value,
            image_url: document.getElementById('addImageUrl').value,
            button_text: document.getElementById('addButtonText').value,
            button_link: document.getElementById('addButtonLink').value,
            display_order: parseInt(document.getElementById('addDisplayOrder').value),
            is_active: parseInt(document.getElementById('addIsActive').value)
        };
        
        if (!data.title || !data.image_url) {
            showMessage('標題和圖片URL為必填項目', 'error');
            return;
        }
        
        try {
            const response = await fetch(`${API_BASE_URL}/admin/carousel`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(data)
            });
            
            const result = await response.json();
            
            if (response.ok) {
                showMessage(result.message, 'success');
                closeAddModal();
                loadCarouselItems(); // 重新載入列表
            } else {
                showMessage(result.message, 'error');
            }
        } catch (error) {
            console.error('Error creating carousel item:', error);
            showMessage('新增失敗', 'error');
        }
    }
    
    // 儲存輪播設定
    document.getElementById('settingsForm')?.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const data = {
            auto_slide_interval: parseInt(document.getElementById('autoSlideInterval').value),
            enable_auto_slide: parseInt(document.getElementById('enableAutoSlide').value),
            enable_controls: parseInt(document.getElementById('enableControls').value),
            enable_indicators: parseInt(document.getElementById('enableIndicators').value)
        };
        
        try {
            const response = await fetch(`${API_BASE_URL}/admin/carousel/settings`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(data)
            });
            
            const result = await response.json();
            
            if (response.ok) {
                showMessage(result.message, 'success');
            } else {
                showMessage(result.message, 'error');
            }
        } catch (error) {
            console.error('Error saving carousel settings:', error);
            showMessage('儲存設定失敗', 'error');
        }
    });
    
    // 顯示訊息
    function showMessage(message, type) {
        const messageContainer = document.getElementById('messageContainer');
        messageContainer.innerHTML = `<div class="message ${type}">${message}</div>`;
        
        setTimeout(() => {
            messageContainer.innerHTML = '';
        }, 3000);
    }
    
    // 載入檔案內容
    async function loadFile() {
        const filePath = document.getElementById('filePath').value;
        
        try {
            const response = await fetch('file_operations.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `action=read&file_path=${encodeURIComponent(filePath)}`
            });
            
            const result = await response.json();
            
            if (result.success) {
                document.getElementById('fileContent').value = result.content;
                showMessage('檔案載入成功', 'success');
            } else {
                showMessage('載入檔案失敗: ' + result.message, 'error');
            }
        } catch (error) {
            console.error('Error loading file:', error);
            showMessage('載入檔案失敗', 'error');
        }
    }
    
    // 儲存檔案
    async function saveFile() {
        const filePath = document.getElementById('filePath').value;
        const content = document.getElementById('fileContent').value;
        
        try {
            const response = await fetch('file_operations.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `action=write&file_path=${encodeURIComponent(filePath)}&content=${encodeURIComponent(content)}`
            });
            
            const result = await response.json();
            
            if (result.success) {
                showMessage('檔案儲存成功', 'success');
            } else {
                showMessage('儲存檔案失敗: ' + result.message, 'error');
            }
        } catch (error) {
            console.error('Error saving file:', error);
            showMessage('儲存檔案失敗', 'error');
        }
    }
    
    // 重新整理預覽
    function refreshPreview() {
        const previewFrame = document.getElementById('previewFrame');
        const filePath = document.getElementById('filePath').value;
        previewFrame.src = filePath + '?t=' + new Date().getTime();
    }
    
    // 點擊模態框外部關閉
    window.onclick = function(event) {
        const editModal = document.getElementById('editModal');
        const addModal = document.getElementById('addModal');
        
        if (event.target === editModal) {
            editModal.style.display = 'none';
        }
        
        if (event.target === addModal) {
            addModal.style.display = 'none';
        }
    }
    
    // 頁面載入時執行
    document.addEventListener('DOMContentLoaded', function() {
        // 如果是首頁，載入輪播項目
        if (window.location.search.includes('page=homepage')) {
            loadCarouselItems();
        }
        
        // 如果是前台頁面，載入檔案內容
        const currentPage = new URLSearchParams(window.location.search).get('page');
        if (currentPage && currentPage !== 'homepage') {
            loadFile();
        }
    });
    </script>
</body>
</html>
